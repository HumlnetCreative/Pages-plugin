<?php namespace HumlnetCreative\Pages\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Behaviors\RelationController;
use Backend\Classes\Controller;
use Backend;
use BackendMenu;
use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use HumlnetCreative\Pages\Models\SectionContainer;
use HumlnetCreative\Pages\Models\MediaUse;
use HumlnetCreative\Pages\Models\PageAuditLog;
use HumlnetCreative\Pages\Models\PageRevision;
use HumlnetCreative\Pages\Models\SliderMediaContext;
use HumlnetCreative\Pages\Classes\Commands\PageCommand;
use HumlnetCreative\Pages\Services\EditorSessionService;
use HumlnetCreative\Pages\Services\MediaService;
use HumlnetCreative\Pages\Services\PageEditLockService;
use HumlnetCreative\Pages\Services\PagePublicationService;
use HumlnetCreative\Pages\Services\PagePublicationPreflight;
use HumlnetCreative\Pages\Services\PageDeletionService;
use HumlnetCreative\Pages\Services\PageCommandService;
use HumlnetCreative\Pages\Services\PageCommandHistory;
use HumlnetCreative\Pages\Services\PageStructureService;
use HumlnetCreative\Pages\Services\CanvasSectionPresenter;
use HumlnetCreative\Pages\Services\SectionRegistry;
use HumlnetCreative\Pages\Services\WorkingCopyRestorer;
use Backend\Facades\BackendAuth;
use Backend\Models\UserPreference;
use Illuminate\Support\Facades\Storage;
use Flash;
use Tailor\Classes\BlueprintIndexer;

class BuilderPages extends Controller
{
    private const EDITOR_VIEW_PREFERENCE = 'humlnetcreative.pages::builder.editor_view';
    private const CANVAS_PANELS_PREFERENCE = 'humlnetcreative.pages::builder.canvas_panels';

    public $implement = [FormController::class, ListController::class, RelationController::class];
    public $formConfig = 'config_form.yaml';
    public $listConfig = [
        'active' => 'config_list.yaml',
        'trash' => 'config_list_trash.yaml',
    ];
    public $relationConfig = 'config_relation.yaml';
    public $requiredPermissions = ['humlnetcreative.pages.builder'];

    public function __construct()
    {
        parent::__construct();
        $this->bodyClass = trim($this->bodyClass.' hucr-builder-workspace');
        BackendMenu::setContext('HumlnetCreative.Pages', 'main-menu-item', 'side-menu-builder');
    }

    public function trash()
    {
        $this->assertPermission('humlnetcreative.pages.builder.trash', 'Nemáte oprávnění zobrazit koš stránek.');
        $this->pageTitle = 'Koš stránek';

        return $this->asExtension('ListController')->index();
    }

    public function listExtendQuery($query, $definition = null): void
    {
        if ($definition === 'trash') {
            $query->onlyTrashed();
        }
    }

    public function onRestorePageFromTrash(): mixed
    {
        $this->assertPermission('humlnetcreative.pages.builder.trash', 'Nemáte oprávnění obnovovat stránky z koše.');
        $page = BuilderPage::withoutGlobalScopes()->findOrFail((int) request()->input('page_id'));
        $restored = app(WorkingCopyRestorer::class)->restoreDeleted($page);
        Flash::success('Stránka byla obnovena z koše jako koncept. Veřejná URL se změní až po publikaci.');

        return Backend::redirect('humlnetcreative/pages/builderpages/update/'.$restored->id);
    }

    /** Opens the working copy and acquires its single-editor lock. */
    public function update($recordId = null, $context = null)
    {
        $result = $this->asExtension('FormController')->update($recordId, $context);
        if ($this->fatalError) {
            return $result;
        }

        $page = $this->formGetModel();
        $user = BackendAuth::getUser();
        $canEdit = $user && BackendAuth::userHasPermission('humlnetcreative.pages.draft.edit');
        $state = $canEdit
            ? app(PageEditLockService::class)->acquire($page, $user, app(EditorSessionService::class)->id())
            : null;

        $this->vars['pageLockState'] = $state;
        $this->vars['pageReadOnly'] = !$state?->writable;
        $this->vars['builderPage'] = $page;
        $this->vars['builderEditorView'] = $this->editorViewPreference();
        $this->vars['canvasPanelPreferences'] = $this->canvasPanelPreferences();
        $this->vars['canvasSections'] = $this->canvasSections($page);
        $this->vars['canvasPageTree'] = $this->canvasPageTree($page);
        $this->vars['canvasCatalog'] = $this->canvasCatalog();
        $this->vars['commandTimeline'] = app(PageCommandHistory::class)->timeline($page->id);
        $this->vars['publicationPreflight'] = app(PagePublicationPreflight::class)->inspect($page, true);
        $latestLifecycleAudit = $page->has_draft
            ? PageAuditLog::where('page_id', $page->id)
                ->whereIn('action', ['history.restored', 'draft.published', 'draft.discarded'])
                ->orderByDesc('id')
                ->first()
            : null;
        $this->vars['draftSourceVersion'] = $latestLifecycleAudit?->action === 'history.restored'
            ? (int) data_get($latestLifecycleAudit->payload, 'version')
            : null;

        app(PageCommandHistory::class)->reconcile($page->id, (int) $page->draft_version);

        return $result;
    }

    /** Stores the table/canvas choice independently for every backend user. */
    public function onSetEditorView(): array
    {
        $view = (string) request()->input('editor_view');
        if (!in_array($view, ['table', 'canvas'], true)) {
            throw new \ApplicationException('Neplatný pohled editoru.');
        }

        UserPreference::forUser()->set(self::EDITOR_VIEW_PREFERENCE, $view);

        if ($view === 'canvas') {
            $page = $this->pageForRequest();
            $this->dispatchBrowserEventAsync('hucr:canvas-refresh');

            return [
                '#hucr-builder-canvas-content' => $this->makePartial('canvas', [
                    'canvasSections' => $this->canvasSections($page),
                    'pageReadOnly' => (bool) ($this->vars['pageReadOnly'] ?? true),
                    'canvasPanelPreferences' => $this->canvasPanelPreferences(),
                    'canvasPageTree' => $this->canvasPageTree($page),
                    'canvasCatalog' => $this->canvasCatalog(),
                    'builderPage' => $page,
                    'commandTimeline' => app(PageCommandHistory::class)->timeline($page->id),
                    'activeInspectorTab' => $this->activeInspectorTab(),
                ]),
            ];
        }

        return [];
    }

    /** Toggles a collapsible outer Canvas panel for the current backend user. */
    public function onToggleCanvasPanel(): array
    {
        $panel = (string) request()->input('panel');
        if (!in_array($panel, ['navigator', 'inspector'], true)) {
            throw new \ApplicationException('Neplatný panel Canvasu.');
        }

        $preferences = $this->canvasPanelPreferences();
        $preferences[$panel] = !$preferences[$panel];
        UserPreference::forUser()->set(self::CANVAS_PANELS_PREFERENCE, $preferences);

        return [];
    }

    /** Opens a Canvas side panel (and optionally one of the navigator sections). */
    public function onOpenCanvasPanel(): array
    {
        $panel = (string) request()->input('panel');
        if (!in_array($panel, ['navigator', 'inspector'], true)) {
            throw new \ApplicationException('Neplatný panel Canvasu.');
        }

        $preferences = $this->canvasPanelPreferences();
        $preferences[$panel] = false;
        $section = (string) request()->input('section');
        if ($panel === 'navigator' && in_array($section, ['pages', 'outline'], true)) {
            $preferences[$section] = false;
            $preferences[$section === 'pages' ? 'outline' : 'pages'] = true;
        }
        UserPreference::forUser()->set(self::CANVAS_PANELS_PREFERENCE, $preferences);

        return [];
    }

    /** Activates one navigator tool, opening the outer navigator first when needed. */
    public function onToggleCanvasNavigatorTool(): array
    {
        $section = (string) request()->input('section');
        if (!in_array($section, ['pages', 'outline'], true)) {
            throw new \ApplicationException('Neplatný nástroj navigátoru Canvasu.');
        }

        $preferences = $this->canvasPanelPreferences();
        $preferences['navigator'] = false;
        $preferences[$section] = false;
        $preferences[$section === 'pages' ? 'outline' : 'pages'] = true;
        UserPreference::forUser()->set(self::CANVAS_PANELS_PREFERENCE, $preferences);

        return [];
    }

    /** Saves simple Canvas fields through the same versioned command as every editor. */
    public function onQuickUpdateSection(): array
    {
        $page = $this->pageForRequest();
        $section = Section::where('page_id', $page->id)
            ->where('uuid', (string) request()->input('canvas_quick.uuid'))
            ->firstOrFail();
        $input = (array) request()->input('canvas_quick', []);
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new \ValidationException(['canvas_quick.title' => 'Interní název sekce je povinný.']);
        }

        $changes = [
            'title' => $title,
            'is_published' => request()->boolean('canvas_quick.visible'),
        ];
        if (SectionRegistry::instance()->wireframe($section->type)['heading'] === 'content.heading') {
            $content = (array) $section->content;
            data_set($content, 'heading', trim((string) ($input['heading'] ?? '')));
            $changes['content'] = $content;
        }
        $layout = (array) $section->layout;
        $layoutChanged = false;
        if (isset($input['width']) && in_array($input['width'], ['contained', 'wide', 'full'], true)
            && $input['width'] !== (string) data_get($layout, 'width', 'contained')) {
            $layout['width'] = $input['width'];
            $layoutChanged = true;
        }
        if (isset($input['spacing']) && in_array($input['spacing'], ['none', 'small', 'standard', 'large'], true)
            && $input['spacing'] !== (string) data_get($layout, 'spacing', 'standard')) {
            $layout['spacing'] = $input['spacing'];
            $layoutChanged = true;
        }
        if (SectionRegistry::instance()->supportsFillHeight($section->type) && $section->container?->kind === SectionContainer::KIND_ZONE) {
            $fillHeight = !empty($input['fill_height']);
            if ($fillHeight !== (bool) data_get($layout, 'fill_height', false)) {
                $layout['fill_height'] = $fillHeight;
                $layoutChanged = true;
            }
        }
        if ($layoutChanged) {
            $changes['layout'] = $layout;
        }
        if (array_key_exists('color_scheme', $input)) {
            $colorScheme = trim((string) $input['color_scheme']);
            $colorScheme = $colorScheme === '__inherit__' ? '' : $colorScheme;
            if ($colorScheme !== trim((string) data_get($section->style, 'color_scheme'))) {
                $style = (array) $section->style;
                $style['color_scheme'] = $colorScheme ?: null;
                $changes['style'] = $style;
            }
        }

        $changes = array_filter($changes, function(mixed $value, string $key) use ($section): bool {
            $current = $section->getAttribute($key);
            if ($key === 'is_published') {
                return (bool) $value !== (bool) $current;
            }

            return is_array($value)
                ? $value != (array) $current
                : $value !== $current;
        }, ARRAY_FILTER_USE_BOTH);
        if (!$changes) {
            return [];
        }

        $result = $this->runPageCommand($page, 'section.update', [
            'uuid' => $section->uuid,
            'changes' => $changes,
        ]);
        $this->announceCommandResult($result);
        $section->refresh()->load(['items', 'slider', 'faq_group', 'gallery.images']);
        $this->dispatchBrowserEventAsync('hucr:canvas-section-updated', [
            'section' => app(CanvasSectionPresenter::class)->present($section),
        ]);
        Flash::success('Rychlá úprava sekce byla uložena.');

        return [];
    }

    public function onSaveAndPublish($recordId = null)
    {
        $this->assertPermission('humlnetcreative.pages.draft.publish', 'Nemáte oprávnění publikovat koncept stránky.');
        $page = $this->pageForRequest($recordId);
        $this->assertWritablePage($page);

        // Save the form working copy first. Any validation failure prevents publication.
        $this->asExtension('FormController')->update_onSave($page->id);
        $page = BuilderPage::withoutGlobalScopes()->findOrFail($page->id);
        $deletionPublication = (bool) $page->deletion_mode;
        $replaceManualRedirects = request()->boolean('replace_manual_redirects');
        if ($replaceManualRedirects) {
            $this->assertPermission(
                'humlnetcreative.pages.redirect.override',
                'Nemáte oprávnění nahrazovat ruční přesměrování.',
            );
        }
        app(PagePublicationService::class)->publish(
            $page,
            BackendAuth::getUser()?->id,
            $replaceManualRedirects,
        );
        if ($deletionPublication) {
            Flash::forget('success');
            Flash::success('Odstranění bylo publikováno a původní URL byla bezpečně vyřízena. Stránka je v koši.');

            return Backend::redirect('humlnetcreative/pages/builderpages');
        }
        Flash::forget('success');
        Flash::success('Koncept byl uložen a publikován jako nová verze.');

        return Backend::redirect('humlnetcreative/pages/builderpages/update/'.$page->id);
    }

    /** Keeps the browser's optimistic draft version synchronized after normal page-field saves. */
    public function onSave($recordId = null)
    {
        if ($this->action === 'create') {
            return $this->asExtension('FormController')->create_onSave();
        }

        $page = $this->pageForRequest($recordId);
        $this->assertWritablePage($page);
        $response = $this->asExtension('FormController')->update_onSave($page->id);
        $page = BuilderPage::withoutGlobalScopes()->findOrFail($page->id);
        app(PageCommandHistory::class)->synchronize($page->id, (int) $page->draft_version);
        // October 4.3's response bridge maps this queue method to a non-blocking
        // browser event. The synchronous variant is mapped to an awaited event
        // and would prevent the relation DOM patches below from being applied.
        $this->dispatchBrowserEventAsync('hucr:draft-version', [
            'pageId' => $page->id,
            'draftVersion' => (int) $page->draft_version,
            'hasDraft' => (bool) $page->has_draft,
        ]);

        return $response;
    }

    /** Refreshes URL-derived form state after October finishes its normal form save. */
    public function onRefreshPublicationPreflight($recordId = null): array
    {
        $page = $this->pageForRequest($recordId);
        $publicationPreflight = app(PagePublicationPreflight::class)->inspect($page, true);
        $this->dispatchBrowserEventAsync('hucr:publication-preflight-refreshed', [
            'pageId' => (int) $page->id,
            'fullslug' => (string) $page->fullslug,
        ]);

        return [
            '#hucr-publication-preflight' => $this->makePartial('publication_preflight', [
                'publicationPreflight' => $publicationPreflight,
            ]),
        ];
    }

    /** Releases the soft lock when the standard Save & close action ends the editor. */
    public function formAfterSave($model): void
    {
        if (!$model instanceof BuilderPage || !request()->boolean('close') || !BackendAuth::getUser()) {
            return;
        }
        app(PageEditLockService::class)->release(
            $model,
            BackendAuth::getUser(),
            app(EditorSessionService::class)->id(),
        );
    }

    public function onDiscardDraft($recordId = null)
    {
        $this->assertPermission('humlnetcreative.pages.draft.discard', 'Nemáte oprávnění zahodit koncept stránky.');
        $page = $this->pageForRequest($recordId);
        $this->assertWritablePage($page);
        app(WorkingCopyRestorer::class)->discard($page);
        Flash::success('Koncept byl zahozen a pracovní kopie obnovena z publikované verze.');

        return Backend::redirect('humlnetcreative/pages/builderpages/update/'.$page->id);
    }

    public function onOpenHistory($recordId = null)
    {
        $page = $this->pageForRequest($recordId);

        return $this->makePartial('history', [
            'builderPage' => $page,
            'revisions' => $page->revisions()->with('publisher')->limit(PagePublicationService::RETAINED_REVISIONS)->get(),
            'canRestore' => BackendAuth::userHasPermission('humlnetcreative.pages.history.restore'),
        ]);
    }

    public function onOpenDeletionProposal($recordId = null)
    {
        $page = $this->pageForRequest($recordId);
        if (!$page->published_revision_id) {
            throw new \ApplicationException('Tato stránka zatím nebyla publikována a lze ji odstranit přímo.');
        }

        $targets = BuilderPage::withoutGlobalScopes()
            ->where('id', '<>', $page->id)
            ->where('published_is_published', true)
            ->whereNotNull('published_revision_id')
            ->when(is_null($page->site_id), fn($query) => $query->whereNull('site_id'))
            ->when(!is_null($page->site_id), fn($query) => $query->where('site_id', $page->site_id))
            ->orderBy('published_fullslug')
            ->get();

        return $this->makePartial('deletion_proposal', [
            'builderPage' => $page,
            'targets' => $targets,
            'hasPublishedParent' => $page->parent_id && $targets->contains('id', (int) $page->parent_id),
        ]);
    }

    public function onProposeDeletion($recordId = null)
    {
        $this->assertPermission('humlnetcreative.pages.structure.create_delete', 'Nemáte oprávnění navrhnout odstranění stránky.');
        $page = $this->pageForRequest($recordId);
        $this->assertWritablePage($page);
        app(PageDeletionService::class)->propose(
            $page,
            (string) request()->input('deletion_mode', 'gone'),
            request()->filled('deletion_target_page_id') ? (int) request()->input('deletion_target_page_id') : null,
        );
        Flash::success('Návrh odstranění byl uložen do konceptu. Veřejný web se změní až po publikaci.');

        return Backend::redirect('humlnetcreative/pages/builderpages/update/'.$page->id);
    }

    public function onCancelDeletionProposal($recordId = null)
    {
        $this->assertPermission('humlnetcreative.pages.structure.create_delete', 'Nemáte oprávnění zrušit návrh odstranění stránky.');
        $page = $this->pageForRequest($recordId);
        $this->assertWritablePage($page);
        app(PageDeletionService::class)->cancel($page);
        Flash::success('Návrh odstranění byl zrušen.');

        return Backend::redirect('humlnetcreative/pages/builderpages/update/'.$page->id);
    }

    public function onRestoreRevision($recordId = null)
    {
        $this->assertPermission('humlnetcreative.pages.history.restore', 'Nemáte oprávnění obnovovat historii stránky.');
        $page = $this->pageForRequest($recordId);
        $this->assertWritablePage($page);
        $revision = PageRevision::where('page_id', $page->id)->findOrFail((int) request()->input('revision_id'));
        app(WorkingCopyRestorer::class)->restoreRevision($revision, true);
        Flash::success("Verze {$revision->version} byla obnovena do konceptu. Veřejný web se nezměnil.");

        return Backend::redirect('humlnetcreative/pages/builderpages/update/'.$page->id);
    }

    public function onHeartbeat($recordId = null): array
    {
        $page = $this->pageForRequest($recordId);
        $state = app(PageEditLockService::class)->heartbeat(
            $page,
            BackendAuth::getUser(),
            app(EditorSessionService::class)->id(),
        );
        if (!$state->writable) {
            throw new \ApplicationException('Zámek stránky už patří jinému editoru. Obnovte stránku; další zápis je zablokovaný.');
        }

        return [];
    }

    public function onTakeoverLock($recordId = null)
    {
        $this->assertPermission('humlnetcreative.pages.lock.takeover', 'Nemáte oprávnění převzít zámek stránky.');
        $page = $this->pageForRequest($recordId);
        app(PageEditLockService::class)->takeover(
            $page,
            BackendAuth::getUser(),
            app(EditorSessionService::class)->id(),
        );
        Flash::success('Zámek stránky byl převzat.');

        return Backend::redirect('humlnetcreative/pages/builderpages/update/'.$page->id);
    }

    public function onReleaseLock($recordId = null)
    {
        $page = $this->pageForRequest($recordId);
        app(PageEditLockService::class)->release(
            $page,
            BackendAuth::getUser(),
            app(EditorSessionService::class)->id(),
        );

        return Backend::redirect('humlnetcreative/pages/builderpages');
    }

    public function onDelete($recordId = null)
    {
        $page = $this->pageForRequest($recordId);
        $this->assertWritablePage($page);
        if ($page->published_revision_id) {
            throw new \ApplicationException('Publikovanou stránku odstraňte řízeným návrhem 410/301 v editoru stránky.');
        }

        app(PageEditLockService::class)->release($page, BackendAuth::getUser(), app(EditorSessionService::class)->id());

        return $this->asExtension('FormController')->update_onDelete($page->id);
    }

    public function onDeleteUnpublishedFromList(): array
    {
        $this->assertPermission('humlnetcreative.pages.draft.edit', 'Nemáte oprávnění odstranit nepublikovanou stránku.');
        $page = $this->pageForRequest();
        if ($page->published_revision_id) {
            throw new \ApplicationException('Publikovanou stránku odstraňte řízeným návrhem 410/301 v editoru stránky.');
        }

        $this->assertWritablePage($page);
        app(WorkingCopyRestorer::class)->deleteUnpublished($page);
        Flash::success('Nepublikovaná stránka byla přesunuta do koše.');

        return $this->listRefresh();
    }

    public function relationExtendViewListWidget($widget, $field, $model)
    {
        $allowed = $this->allowedSectionTypes();
        if ($field === 'sections') {
            $widget->bindEvent('list.extendQueryBefore', fn($query) => $query->whereIn('type', $allowed));
        }
        elseif ($field === 'items') {
            $widget->bindEvent('list.extendQueryBefore', fn($query) => $query->whereHas('section', fn($section) => $section->whereIn('type', $allowed)));
        }

        $widget->bindEvent('list.beforeReorderStructure', function($moved) use ($field) {
            if (!in_array($field, ['sections', 'items'], true)) {
                return;
            }

            $ids = array_map('intval', (array) request()->input('sort_orders', []));
            if ($field === 'sections' && $moved instanceof Section) {
                $page = $moved->page;
                $ordered = Section::where('page_id', $page->id)->whereIn('id', $ids)
                    ->get()->keyBy('id');
                $uuids = array_values(array_filter(array_map(fn($id) => $ordered->get($id)?->uuid, $ids)));
                $result = $this->runPageCommand($page, 'section.reorder', ['ordered_uuids' => $uuids]);
            }
            elseif ($field === 'items' && $moved instanceof SectionItem) {
                $section = $moved->section;
                $page = $section->page;
                $ordered = SectionItem::where('section_id', $section->id)->whereIn('id', $ids)
                    ->get()->keyBy('id');
                $uuids = array_values(array_filter(array_map(fn($id) => $ordered->get($id)?->uuid, $ids)));
                $result = $this->runPageCommand($page, 'item.reorder', [
                    'section_uuid' => $section->uuid,
                    'ordered_uuids' => $uuids,
                ]);
            }
            else {
                return;
            }

            $this->announceCommandResult($result);

            // The command already persisted the complete order.
            return false;
        }, 100);
    }

    /** Routes October's existing relation popups through the shared command API. */
    public function onRelationManageCreate(): array
    {
        [$page, $field, $widget] = $this->commandRelationContext();
        $data = $this->normalizeRelationSaveData($widget->getSaveData());

        if ($field === 'sections') {
            $result = $this->runPageCommand($page, 'section.create', $data);
        }
        elseif ($field === 'items') {
            $section = $this->relationItemSection();
            $result = $this->runPageCommand($page, 'item.create', $data + ['section_uuid' => $section->uuid]);
        }
        else {
            throw new \ApplicationException('Tato relace není součástí Page Builder příkazů.');
        }

        $this->announceCommandResult($result);
        Flash::success($field === 'sections' ? 'Sekce byla přidána.' : 'Položka byla přidána.');

        return $this->asExtension('RelationController')->relationRefresh($field);
    }

    /** Action-prefixed handlers take precedence over October's behavior handlers. */
    public function update_onRelationManageCreate(): array
    {
        return $this->onRelationManageCreate();
    }

    public function onRelationManageUpdate(): array
    {
        [$page, $field, $widget] = $this->commandRelationContext();
        $model = $widget->model;
        $data = $this->normalizeRelationSaveData($widget->getSaveData());

        if ($field === 'sections' && $model instanceof Section) {
            if ($model->type === 'columns') {
                $result = $this->runPageCommand($page, 'columns.configure', [
                    'uuid' => $model->uuid,
                    'ratio' => data_get($data, 'content.ratio', data_get($model->content, 'ratio', '1:1')),
                    'layout' => (array) ($data['layout'] ?? []),
                    'zones' => (array) request()->input('columns_zones', []),
                    'changes' => $data,
                ]);
            }
            else {
                $result = $this->runPageCommand($page, 'section.update', ['uuid' => $model->uuid, 'changes' => $data]);
            }
        }
        elseif ($field === 'items' && $model instanceof SectionItem) {
            $result = $this->runPageCommand($page, 'item.update', ['uuid' => $model->uuid, 'changes' => $data]);
        }
        else {
            throw new \ApplicationException('Upravovaný záznam nepatří do Page Builderu.');
        }

        $this->announceCommandResult($result);
        Flash::success($field === 'sections' ? 'Sekce byla uložena.' : 'Položka byla uložena.');

        $response = $this->asExtension('RelationController')->relationRefresh($field);
        if ($field === 'sections') {
            $this->dispatchBrowserEventAsync('hucr:canvas-refresh', ['selectedUuid' => $model->uuid]);
            $response['#hucr-builder-canvas-content'] = $this->makePartial('canvas', [
                'canvasSections' => $this->canvasSections($page),
                'selectedCanvasUuid' => $model->uuid,
                'pageReadOnly' => (bool) ($this->vars['pageReadOnly'] ?? false),
                'canvasPanelPreferences' => $this->canvasPanelPreferences(),
                'canvasPageTree' => $this->canvasPageTree($page),
                'canvasCatalog' => $this->canvasCatalog(),
                'builderPage' => $page,
                'commandTimeline' => app(PageCommandHistory::class)->timeline($page->id),
                'activeInspectorTab' => $this->activeInspectorTab(),
            ]);
        }

        return $response;
    }

    public function update_onRelationManageUpdate(): array
    {
        return $this->onRelationManageUpdate();
    }

    public function onRelationManageDelete(): array
    {
        $page = $this->pageForRequest();
        $field = (string) request()->input('_relation_field');
        $this->asExtension('RelationController')->initRelation($page, $field);
        $ids = array_map('intval', (array) request()->input('checked', []));
        if ($field === 'sections') {
            $uuids = Section::where('page_id', $page->id)->whereIn('id', $ids)->pluck('uuid')->all();
            $name = 'section.delete_many';
        }
        elseif ($field === 'items') {
            $uuids = SectionItem::whereHas('section', fn($query) => $query->where('page_id', $page->id))
                ->whereIn('id', $ids)->pluck('uuid')->all();
            $name = 'item.delete_many';
        }
        else {
            throw new \ApplicationException('Tato relace není součástí Page Builder příkazů.');
        }
        $result = $this->runPageCommand($page, $name, ['uuids' => $uuids]);
        $this->announceCommandResult($result);
        Flash::success($field === 'sections' ? 'Sekce byly odstraněny.' : 'Položky byly odstraněny.');

        return $this->asExtension('RelationController')->relationRefresh($field);
    }

    public function update_onRelationManageDelete(): array
    {
        return $this->onRelationManageDelete();
    }

    /**
     * RelationController's delete toolbar posts this public button handler.
     * Intercept it before the behavior delegates to its own non-command delete.
     */
    public function onRelationButtonDelete(): array
    {
        return $this->onRelationManageDelete();
    }

    public function update_onRelationButtonDelete(): array
    {
        return $this->onRelationManageDelete();
    }

    public function relationExtendManageFormQuery($field, $query)
    {
        $allowed = $this->allowedSectionTypes();
        if ($field === 'sections') {
            $query->whereIn('type', $allowed);
        }
        elseif ($field === 'items') {
            $query->whereHas('section', fn($section) => $section->whereIn('type', $allowed));
        }
    }

    public function relationExtendManageFormWidget($widget, $field, $model)
    {
        // Relation popup tabs must not share the page form's URL hash
        // (for example both forms can contain a tab named "Vzhled").
        $widget->getTab('primary')?->linkable(false);
        $widget->getTab('secondary')?->linkable(false);

        $widget->bindEvent('form.extendFields', function($fields) use ($widget, $field) {
            if ($field === 'sections' && $widget->model instanceof Section && $widget->model->type) {
                $this->pruneSectionForm($widget, $widget->model->type);
            }
            elseif ($field === 'items' && $widget->model instanceof SectionItem) {
                $section = $this->resolveSectionForItemForm($widget->model);
                if ($section?->type) {
                    $this->pruneSectionItemForm($widget, $section->type);
                }
            }

            $buttons = BackendAuth::userHasPermission('humlnetcreative.pages.editor.html')
                ? 'undo|redo||paragraphFormat|bold|italic|underline||align|formatOL|formatUL|outdent|indent||insertPageLink|insertHR|insertTable||fullscreen|html'
                : 'undo|redo||paragraphFormat|bold|italic|underline||align|formatOL|formatUL|outdent|indent||insertPageLink|insertHR||fullscreen';

            foreach ($fields as $formField) {
                if ($formField->type === 'richeditor') {
                    $formField->toolbarButtons = $buttons;
                }
            }
        });
    }

    /** Opens an existing page on its editorial content unless a URL hash overrides it. */
    public function formExtendFields($form): void
    {
        if ($form->model instanceof BuilderPage && $form->model->exists) {
            $form->getTab('primary')?->activeTab('Obsah');
        }
    }

    /** Keeps each section editor focused on fields rendered by that section type. */
    protected function pruneSectionForm($widget, string $type): void
    {
        $fieldMap = [
            'heading' => ['content[heading]'], 'text' => ['content[text]'],
            'position' => ['content[position]'], 'image_position' => ['content[image_position]'],
            'image_text_gap' => ['layout[image_text_gap]'],
            'autoplay' => ['content[autoplay]'],
            'slider' => ['_carousel_slider', 'slider', '_slider_actions'],
            'faq_group' => ['_faq_group', 'faq_group', '_faq_actions'],
            'gallery' => ['_gallery_source', 'gallery', '_gallery_actions'],
            'gallery_columns' => ['content[gallery_columns]'],
            'carousel_options' => ['_carousel_appearance', '_carousel_playback', 'content[autoplay]', 'content[playback_control]', 'content[autoplay_delay]', 'content[navigation]', 'content[pagination]', 'content[overlay]', 'content[position]'],
            'cta' => ['content[cta_label]', 'content[cta_url]'],
            'columns' => ['content[columns]'], 'embed' => ['content[embed]'],
            'columns_layout' => ['_columns_layout', 'content[ratio]', 'layout[columns_gap]', 'layout[tablet_behavior]', 'layout[mobile_order]', 'layout[full_padding]', '_columns_zones'],
            'media' => ['media'], 'items' => ['items'],
        ];
        $specializedFields = [
            'content[heading]', 'content[text]', '_carousel_appearance', '_carousel_playback', '_carousel_slider', 'content[position]', 'content[image_position]',
            'layout[image_text_gap]',
            'content[autoplay]', 'content[playback_control]', 'content[cta_label]', 'content[cta_url]', 'content[columns]',
            'slider', '_slider_actions', 'content[autoplay_delay]', 'content[navigation]', 'content[pagination]', 'content[overlay]',
            '_faq_group', 'faq_group', '_faq_actions',
            '_gallery_source', 'gallery', '_gallery_actions',
            'content[gallery_columns]',
            'content[embed]', '_columns_layout', 'content[ratio]', 'layout[columns_gap]', 'layout[tablet_behavior]', 'layout[mobile_order]', 'layout[full_padding]', '_columns_zones', 'media', 'items',
        ];
        $allowedFields = $this->expandFieldGroups(SectionRegistry::instance()->sectionFields($type), $fieldMap);

        foreach (array_diff($specializedFields, $allowedFields) as $fieldName) {
            $widget->removeField($fieldName);
        }

        $this->pruneStyleFields($widget, SectionRegistry::instance()->sectionStyleFields($type));

        if (!SectionRegistry::instance()->supportsFillHeight($type)
            || $widget->model->container?->kind !== SectionContainer::KIND_ZONE) {
            $widget->removeField('layout[fill_height]');
        }

        if ($type === 'carousel' && ($positionField = $widget->getField('content[position]'))) {
            $positionField->tab = 'Prezentace';
            $positionField->label = 'Výchozí pozice textu';
            $positionField->comment = 'Použije se u položek Slideru, které nemají nastavenou vlastní pozici textu.';
        }

        // Changing a type in place would leave incompatible content and items behind.
        if ($typeField = $widget->getField('type')) {
            $typeField->readOnly = true;
        }

        if (in_array($type, ['text', 'accordion', 'gallery'], true)) {
            $activeTab = ['accordion' => 'FAQ', 'gallery' => 'Galerie'][$type] ?? 'Obsah';
            $widget->getTab('primary')?->activeTab($activeTab);
        }
    }

    public function onOpenSliderEditor()
    {
        if (!BackendAuth::userHasPermission('humlnetcreative.pages.slider.manage')) {
            throw new \ApplicationException('Nemáte oprávnění upravovat Slidery.');
        }
        $sliderId = (int) request()->input('slider_id');
        $this->vars['editorUrl'] = Backend::url('tailor/entries/slider-slider/'.($sliderId ?: 'create'));
        $this->vars['editorTitle'] = $sliderId ? 'Upravit Slider' : 'Vytvořit Slider';
        $this->vars['sharedUsages'] = $sliderId ? $this->sharedSourceUsages('slider_id', $sliderId) : collect();
        return $this->makePartial('slider_editor');
    }

    public function onOpenFaqEditor()
    {
        if (!BackendAuth::userHasPermission('humlnetcreative.pages.faq.manage')) {
            throw new \ApplicationException('Nemáte oprávnění upravovat FAQ skupiny.');
        }

        $groupId = (int) request()->input('faq_group_id');
        $blueprint = BlueprintIndexer::instance()->findSectionByHandle('FAQ\\Group');
        if (!$blueprint) {
            throw new \ApplicationException('Blueprint FAQ skupin není dostupný.');
        }

        $this->vars['editorUrl'] = Backend::url('tailor/entries/'.$blueprint->handleSlug.'/'.($groupId ?: 'create'));
        $this->vars['editorTitle'] = $groupId ? 'Upravit FAQ skupinu' : 'Vytvořit FAQ skupinu';
        $this->vars['sharedUsages'] = $groupId ? $this->sharedSourceUsages('faq_group_id', $groupId) : collect();

        return $this->makePartial('slider_editor');
    }

    public function onOpenGalleryEditor()
    {
        if (!BackendAuth::userHasPermission('humlnetcreative.pages.gallery.manage')) {
            throw new \ApplicationException('Nemáte oprávnění upravovat Galerie.');
        }

        $galleryId = (int) request()->input('gallery_id');
        $this->vars['editorUrl'] = Backend::url('lzaplata/gallery/galleries/'.($galleryId ? 'update/'.$galleryId : 'create'));
        $this->vars['editorTitle'] = $galleryId ? 'Upravit Galerii' : 'Vytvořit Galerii';
        $this->vars['sharedUsages'] = $galleryId ? $this->sharedSourceUsages('gallery_id', $galleryId) : collect();

        return $this->makePartial('slider_editor');
    }

    /** Applies the same focused editing experience to nested section items. */
    protected function pruneSectionItemForm($widget, string $sectionType): void
    {
        $fieldMap = [
            'heading' => ['content[heading]'], 'icon' => ['content[icon]'],
            'text' => ['content[text]'], 'cta' => ['content[cta_label]', 'content[cta_url]'],
            'style' => ['style[color_scheme]'], 'move_card' => ['move_card'], 'media' => ['media'],
        ];
        $optionalFields = [
            'content[heading]', 'content[icon]', 'content[text]', 'content[cta_label]', 'content[cta_url]',
            'style[color_scheme]', 'style[heading_color_scheme]', 'style[text_color_scheme]',
            'style[cta_color_scheme]', 'move_card', 'media',
        ];
        $allowedFields = $this->expandFieldGroups(SectionRegistry::instance()->itemFields($sectionType), $fieldMap);
        $allowedFields = array_merge($allowedFields, $this->styleFieldNames(SectionRegistry::instance()->itemStyleFields($sectionType)));

        foreach (array_diff($optionalFields, $allowedFields) as $fieldName) {
            $widget->removeField($fieldName);
        }
    }

    protected function expandFieldGroups(array $groups, array $fieldMap): array
    {
        $fields = [];
        foreach ($groups as $group) {
            $fields = array_merge($fields, $fieldMap[$group] ?? []);
        }
        return array_values(array_unique($fields));
    }

    protected function styleFieldNames(array $roles): array
    {
        return array_map(fn($role) => 'style['.$role.'_color_scheme]', $roles);
    }

    protected function pruneStyleFields($widget, array $roles): void
    {
        foreach (array_diff(['container', 'heading', 'text', 'cta'], $roles) as $role) {
            $widget->removeField('style['.$role.'_color_scheme]');
        }
    }

    protected function resolveSectionForItemForm(SectionItem $item): ?Section
    {
        if ($item->section_id) {
            return $item->section;
        }
        $extraConfig = json_decode((string) request()->input('_relation_extra_config'), true);
        $sectionId = (int) data_get($extraConfig, 'manageIds.sections', 0);
        return $sectionId ? Section::find($sectionId) : null;
    }

    /** Moves a card without exposing its relational implementation to the editor. */
    public function onMoveCard()
    {
        if (!BackendAuth::userHasPermission('humlnetcreative.pages.section.cards')) {
            throw new \ApplicationException('Nemáte oprávnění upravovat Karty.');
        }
        $item = SectionItem::findOrFail((int) request()->input('item_id'));
        $source = $item->section;
        if (!$source || $source->type !== 'cards') {
            throw new \ApplicationException('Přesouvat lze pouze položku sekce Karty.');
        }
        $targetId = request()->input('target_section_id');
        if ($targetId === '__new') {
            $created = $this->runPageCommand($source->page, 'section.create', [
                'type' => 'cards',
                'title' => 'Nový blok Karet',
            ]);
            $target = Section::where('page_id', $source->page_id)->where('uuid', $created->data['section_uuid'])->firstOrFail();
            request()->merge(['expected_draft_version' => $created->draftVersion]);
        }
        else {
            $target = Section::findOrFail((int) $targetId);
        }
        $result = $this->runPageCommand($source->page, 'item.move', [
            'uuid' => $item->uuid,
            'target_section_uuid' => $target->uuid,
        ]);
        $this->announceCommandResult($result);
        Flash::success('Karta byla přesunuta.');
    }

    public function onUploadMedia()
    {
        $upload = request()->file('media_file');
        if (!$upload) {
            throw new \ApplicationException('Vyberte obrázek k nahrání.');
        }
        $ownerKind = request()->input('media_owner_kind');
        $owner = $ownerKind === 'item'
            ? SectionItem::findOrFail((int) request()->input('media_owner_id'))
            : Section::findOrFail((int) request()->input('media_owner_id'));
        $this->assertCanManageOwner($owner);
        $slot = (string) request()->input('media_slot');
        $service = new MediaService();
        $service->assertSlotAllowed($owner, $slot);
        $asset = $service->storeMaster($upload, $owner->page?->site_id ?? $owner->section?->page?->site_id);
        $altText = trim((string) request()->input('media_alt_text'));
        $decorative = request()->boolean('media_decorative') || $altText === '';
        try {
            $newUse = $service->createUse(
                $asset,
                $owner::class,
                $owner->id,
                $slot,
                [],
                $altText ?: null,
                $decorative,
                $owner->page?->site_id ?? $owner->section?->page?->site_id
            );
        }
        catch (\Throwable $exception) {
            Storage::disk($asset->disk)->delete($asset->path);
            $asset->delete();
            throw $exception;
        }
        $owner->media()->where('slot', $slot)->where('id', '<>', $newUse->id)->get()->each(fn(MediaUse $use) => $use->delete());
        Flash::success('Obrázek byl uložen a optimalizované varianty byly vytvořeny.');

        return $this->refreshMediaEditor($owner);
    }

    /** Updates accessibility metadata without replacing or regenerating the image. */
    public function onUpdateMediaMetadata()
    {
        $use = MediaUse::findOrFail((int) request()->input('media_use_id'));
        $owner = $use->owner;
        $this->assertCanManageOwner($owner);
        $altText = trim((string) request()->input('media_alt_text'));

        $use->alt_text = $altText ?: null;
        $use->is_decorative = request()->boolean('media_decorative') || $altText === '';
        $use->save();
        Flash::success('Popis obrázku byl uložen.');

        return $this->refreshMediaEditor($owner);
    }

    public function onDeleteMedia()
    {
        $use = MediaUse::findOrFail((int) request()->input('media_use_id'));
        $owner = $use->owner;
        $this->assertCanManageOwner($owner);
        $use->delete();
        Flash::success('Použití obrázku bylo odstraněno.');

        return $this->refreshMediaEditor($owner);
    }

    /** Reuses an already uploaded private master in another responsive slot. */
    public function onReuseMedia()
    {
        $source = MediaUse::with('asset')->findOrFail((int) request()->input('media_use_id'));
        $owner = request()->input('media_owner_kind') === 'item'
            ? SectionItem::findOrFail((int) request()->input('media_owner_id'))
            : Section::findOrFail((int) request()->input('media_owner_id'));
        $this->assertCanManageOwner($source->owner);
        $this->assertCanManageOwner($owner);
        $slot = (string) request()->input('media_slot');
        $service = new MediaService();
        $service->assertSlotAllowed($owner, $slot);
        if ($source->owner_type === $owner::class && (int) $source->owner_id === (int) $owner->id && $source->slot === $slot) {
            Flash::info('Tento master už vybraný slot používá.');
            return $this->refreshMediaEditor($owner);
        }
        $newUse = $service->createUse(
            $source->asset, $owner::class, $owner->id, $slot, [],
            $source->alt_text, (bool) $source->is_decorative,
            $owner->page?->site_id ?? $owner->section?->page?->site_id
        );
        $owner->media()->where('slot', $slot)->where('id', '<>', $newUse->id)->get()->each(fn(MediaUse $use) => $use->delete());
        Flash::success('Existující master byl použit v novém slotu. Upravte jeho ořez podle potřeby.');

        return $this->refreshMediaEditor($owner);
    }

    /** Opens a large fixed-ratio editor over the private master image. */
    public function onLoadMediaCropEditor()
    {
        $use = MediaUse::with('asset')->findOrFail((int) request()->input('media_use_id'));
        $this->assertCanManageOwner($use->owner);
        $service = new MediaService();

        return $this->makePartial('$/humlnetcreative/pages/controllers/builderpages/_media_crop_editor.htm', [
            'mediaUse' => $use,
            'slotDefinition' => $service->slotDefinition($use->slot),
            'previewUrl' => $service->backendMasterUrl($use),
        ]);
    }

    /** Saves crop coordinates in original pixels and rebuilds public variants. */
    public function onApplyMediaCrop()
    {
        $use = MediaUse::with('asset')->findOrFail((int) request()->input('media_use_id'));
        $this->assertCanManageOwner($use->owner);
        $service = new MediaService();
        $use->crop = $service->validateCropSelection($use->asset, $use->slot, [
            'x' => request()->input('crop_x'),
            'y' => request()->input('crop_y'),
            'width' => request()->input('crop_width'),
            'height' => request()->input('crop_height'),
        ]);
        $use->variants = $service->regenerateVariants($use);
        $use->save();
        Flash::success('Ořez a optimalizované varianty byly uloženy.');

        return $this->refreshMediaEditor($use->owner);
    }

    /** Updates a use-specific fixed-ratio crop and refreshes its public variants. */
    public function onUpdateMediaCrop()
    {
        $use = MediaUse::with('asset')->findOrFail((int) request()->input('media_use_id'));
        $this->assertCanManageOwner($use->owner);
        $service = new MediaService();
        $use->crop = $service->cropFromFocus(
            $use->asset,
            $use->slot,
            (float) request()->input('focus_x', 50),
            (float) request()->input('focus_y', 50)
        );
        $use->variants = $service->regenerateVariants($use);
        $use->save();
        Flash::success('Ořez a optimalizované varianty byly aktualizovány.');

        return $this->refreshMediaEditor($use->owner);
    }

    /** Streams a private master only to an authenticated Page Builder user. */
    public function previewmedia(int $id)
    {
        $use = MediaUse::with('asset')->findOrFail($id);
        $this->assertCanManageOwner($use->owner);
        $asset = $use->asset;
        $downloadName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', basename($asset->original_name)) ?: 'image';
        return response()->file(Storage::disk($asset->disk)->path($asset->path), [
            'Content-Type' => $asset->mime_type,
            'Content-Disposition' => 'inline; filename="'.$downloadName.'"',
        ]);
    }

    public function onAddSection()
    {
        $page = BuilderPage::findOrFail((int) request()->input('builder_page_id'));
        $type = (string) request()->input('section_type');
        if (!SectionRegistry::instance()->has($type)) {
            throw new \ApplicationException('Neznámý typ sekce.');
        }
        $definition = SectionRegistry::instance()->definition($type);
        if (!BackendAuth::userHasPermission($definition['permission'])) {
            throw new \ApplicationException('Nemáte oprávnění vložit tento typ sekce.');
        }
        $requiresSource = in_array($type, ['carousel', 'accordion', 'gallery'], true);
        $payload = [
            'type' => $type,
            'title' => $definition['label'],
            'position' => max(1, (int) request()->input('builder_position', $page->sections()->count() + 1)),
            // Relational sections need their content source selected before publication.
            'is_published' => !$requiresSource,
        ];
        if ($containerUuid = trim((string) request()->input('target_container_uuid'))) {
            $payload['target_container_uuid'] = $containerUuid;
        }
        $result = $this->runPageCommand($page, 'section.create', $payload);
        $this->announceCommandResult($result);
        Flash::success($requiresSource
            ? 'Sekce byla přidána jako skrytá. Vyberte její obsahový zdroj a potom ji publikujte.'
            : 'Sekce byla přidána do stránky.');

        $selectedUuid = $result->data['section_uuid'];
        $this->dispatchBrowserEventAsync('hucr:canvas-refresh', ['selectedUuid' => $selectedUuid]);

        return $this->relationRefresh('sections') + [
            '#hucr-builder-canvas-content' => $this->makePartial('canvas', [
                'canvasSections' => $this->canvasSections($page),
                'selectedCanvasUuid' => $selectedUuid,
                'pageReadOnly' => (bool) ($this->vars['pageReadOnly'] ?? false),
                'canvasPanelPreferences' => $this->canvasPanelPreferences(),
                'canvasPageTree' => $this->canvasPageTree($page),
                'canvasCatalog' => $this->canvasCatalog(),
                'builderPage' => $page,
                'commandTimeline' => app(PageCommandHistory::class)->timeline($page->id),
                'activeInspectorTab' => $this->activeInspectorTab(),
            ]),
        ];
    }

    public function onDuplicateSection(): array
    {
        $page = $this->pageForRequest();
        $section = Section::where('page_id', $page->id)->findOrFail((int) request()->input('section_id'));
        $result = $this->runPageCommand($page, 'section.duplicate', ['uuid' => $section->uuid]);
        $this->announceCommandResult($result);
        Flash::success('Sekce byla duplikována včetně lokálního obsahu.');

        return $this->relationRefresh('sections');
    }

    /** Reorders top-level Canvas sections through the shared versioned command API. */
    public function onCanvasReorderSections(): array
    {
        $page = $this->pageForRequest();
        $orderedUuids = json_decode((string) request()->input('canvas_order', ''), true);
        if (!is_array($orderedUuids)) {
            throw new \ValidationException(['canvas_order' => 'Pořadí sekcí se nepodařilo načíst.']);
        }

        $result = $this->runPageCommand($page, 'section.reorder', [
            'ordered_uuids' => array_values(array_map('strval', $orderedUuids)),
        ]);
        $this->announceCommandResult($result);
        Flash::success('Pořadí sekcí bylo změněno.');

        return $this->commandRefresh($page);
    }

    /** Moves a section to an ordinal position in the root or a Columns zone. */
    public function onCanvasMoveSection(): array
    {
        $page = $this->pageForRequest();
        $result = $this->runPageCommand($page, 'section.move', [
            'uuid' => (string) request()->input('section_uuid'),
            'target_container_uuid' => (string) request()->input('target_container_uuid'),
            'position' => max(1, (int) request()->input('target_position', 1)),
        ]);
        $this->announceCommandResult($result);
        Flash::success('Sekce byla přesunuta.');

        return $this->commandRefresh($page, (string) $result->data['section_uuid']);
    }

    public function onOpenSectionMove()
    {
        $page = $this->pageForRequest();
        $section = Section::where('page_id', $page->id)->findOrFail((int) request()->input('section_id'));
        $registry = SectionRegistry::instance();
        $targets = $page->section_containers()->with('columns_section')->orderBy('kind')->orderBy('sort_order')->get()
            ->filter(function(SectionContainer $container) use ($section, $registry): bool {
                if ($container->kind === SectionContainer::KIND_ROOT) {
                    return true;
                }
                return $section->type !== 'columns'
                    && $registry->allowedInColumns($section->type)
                    && $registry->minimumWidthUnits($section->type) <= (int) $container->width_units;
            })->map(fn(SectionContainer $container): array => [
                'uuid' => $container->uuid,
                'label' => $container->kind === SectionContainer::KIND_ROOT
                    ? 'Hlavní úroveň'
                    : ($container->columns_section?->title ?: 'Sloupce').' › '.($container->title ?: 'Zóna'),
            ])->values()->all();

        return $this->makePartial('move_section', compact('section', 'targets'));
    }

    public function onMoveSectionFromTable(): array
    {
        $page = $this->pageForRequest();
        $result = $this->runPageCommand($page, 'section.move', [
            'uuid' => (string) request()->input('section_uuid'),
            'target_container_uuid' => (string) request()->input('target_container_uuid'),
            'position' => max(1, (int) request()->input('target_position', 1)),
        ]);
        $this->announceCommandResult($result);
        Flash::success('Sekce byla přesunuta.');

        return $this->commandRefresh($page, (string) request()->input('section_uuid'));
    }

    /** Duplicates the selected Canvas section and keeps the duplicate selected. */
    public function onCanvasDuplicateSection(): array
    {
        $page = $this->pageForRequest();
        $section = Section::where('page_id', $page->id)
            ->where('uuid', (string) request()->input('section_uuid'))
            ->firstOrFail();
        $result = $this->runPageCommand($page, 'section.duplicate', ['uuid' => $section->uuid]);
        $this->announceCommandResult($result);
        Flash::success('Sekce byla duplikována včetně lokálního obsahu.');

        return $this->commandRefresh($page, (string) $result->data['section_uuid']);
    }

    /** Toggles Canvas section visibility through an undoable command. */
    public function onCanvasToggleSectionVisibility(): array
    {
        $page = $this->pageForRequest();
        $section = Section::where('page_id', $page->id)
            ->where('uuid', (string) request()->input('section_uuid'))
            ->firstOrFail();
        $visible = !$section->is_published;
        $result = $this->runPageCommand($page, 'section.visibility', [
            'uuid' => $section->uuid,
            'visible' => $visible,
        ]);
        $this->announceCommandResult($result);
        Flash::success($visible ? 'Sekce je znovu viditelná.' : 'Sekce byla skryta.');

        return $this->commandRefresh($page, $section->uuid);
    }

    /** Soft-deletes the selected Canvas section through an undoable command. */
    public function onCanvasDeleteSection(): array
    {
        $page = $this->pageForRequest();
        $section = Section::where('page_id', $page->id)
            ->where('uuid', (string) request()->input('section_uuid'))
            ->firstOrFail();
        $result = $this->runPageCommand($page, 'section.delete', [
            'uuid' => $section->uuid,
            'mode' => $section->type === 'columns' ? (string) request()->input('delete_mode', 'unwrap') : null,
        ]);
        $this->announceCommandResult($result);
        Flash::success($section->type === 'columns'
            ? (request()->input('delete_mode', 'unwrap') === 'destructive'
                ? 'Sloupce včetně obsahu byly odstraněny. Změnu lze vrátit akcí Zpět.'
                : 'Sloupce byly odstraněny a jejich obsah bezpečně rozbalen. Změnu lze vrátit akcí Zpět.')
            : 'Sekce byla odstraněna. Lze ji vrátit akcí Zpět.');

        return $this->commandRefresh($page, '');
    }

    public function onCopySection(): array
    {
        $page = $this->pageForRequest();
        $section = Section::where('page_id', $page->id)->findOrFail((int) request()->input('section_id'));
        $this->runPageCommand($page, 'section.copy', ['uuid' => $section->uuid]);
        Flash::success('Sekce byla zkopírována do editorové schránky.');

        return [];
    }

    public function onPasteSection(): array
    {
        $page = $this->pageForRequest();
        $result = $this->runPageCommand($page, 'section.paste');
        $this->announceCommandResult($result);
        Flash::success('Sekce byla vložena s novými lokálními identifikátory.');

        return $this->relationRefresh('sections');
    }

    public function onDuplicateItem(): array
    {
        $item = SectionItem::findOrFail((int) request()->input('item_id'));
        $page = $item->section->page;
        $result = $this->runPageCommand($page, 'item.duplicate', ['uuid' => $item->uuid]);
        $this->announceCommandResult($result);
        Flash::success('Položka byla duplikována.');

        return $this->relationRefresh('items');
    }

    public function onUndoCommand(): array
    {
        $page = $this->pageForRequest();
        $result = app(PageCommandService::class)->undo($page->id, $this->expectedDraftVersion());
        $this->announceCommandResult($result);
        Flash::success('Poslední změna v této relaci byla vrácena.');

        return $this->commandRefresh($page);
    }

    public function onRedoCommand(): array
    {
        $page = $this->pageForRequest();
        $result = app(PageCommandService::class)->redo($page->id, $this->expectedDraftVersion());
        $this->announceCommandResult($result);
        Flash::success('Vrácená změna byla provedena znovu.');

        return $this->commandRefresh($page);
    }

    public function onJumpCommandHistory(): array
    {
        $page = $this->pageForRequest();
        $target = (int) request()->input('target_position', -1);
        $result = app(PageCommandService::class)->jump($page->id, $this->expectedDraftVersion(), $target);
        if (!$result) {
            return [];
        }

        $this->announceCommandResult($result);
        Flash::success('Koncept byl přesunut na vybraný bod historie relace.');

        return $this->commandRefresh($page);
    }

    protected function allowedSectionTypes(): array
    {
        return array_keys(SectionRegistry::instance()->optionsForBackendUser());
    }

    private function editorViewPreference(): string
    {
        $view = UserPreference::forUser()->get(self::EDITOR_VIEW_PREFERENCE, 'canvas');

        return in_array($view, ['table', 'canvas'], true) ? $view : 'canvas';
    }

    private function canvasPanelPreferences(): array
    {
        $stored = UserPreference::forUser()->get(self::CANVAS_PANELS_PREFERENCE, []);
        $pagesCollapsed = (bool) data_get($stored, 'pages', true);
        $outlineCollapsed = (bool) data_get($stored, 'outline', false);
        // Migrate the former independently collapsible sections to one active tool.
        if ($pagesCollapsed === $outlineCollapsed) {
            $pagesCollapsed = true;
            $outlineCollapsed = false;
        }

        return [
            'navigator' => (bool) data_get($stored, 'navigator', false),
            'inspector' => (bool) data_get($stored, 'inspector', false),
            'pages' => $pagesCollapsed,
            'outline' => $outlineCollapsed,
        ];
    }

    private function activeInspectorTab(): string
    {
        $tab = (string) request()->input('active_inspector_tab', 'edit');

        return in_array($tab, ['edit', 'layout', 'checks', 'history', 'page', 'page-style', 'seo'], true) ? $tab : 'edit';
    }

    private function canvasSections(BuilderPage $page): array
    {
        $allowed = array_keys(SectionRegistry::instance()->optionsForBackendUser());
        $sections = Section::with(['page', 'container.columns_section', 'items.media.asset', 'media.asset', 'slider.slides', 'faq_group.questions', 'gallery.images'])
            ->where('page_id', $page->id)
            ->whereIn('type', $allowed)
            ->orderBy('sort_order')
            ->get();
        $page->setRelation('sections', $sections);
        $page->setRelation('section_containers', $page->section_containers()->with('columns_section')->get());
        $rootSections = app(PageStructureService::class)->prepare($page, false);
        $presenter = app(CanvasSectionPresenter::class);

        return $rootSections->map(fn(Section $section) => $presenter->present($section))->all();
    }

    private function canvasPageTree(BuilderPage $currentPage): array
    {
        // Global scopes are disabled so the tree can apply the edited page's
        // explicit site context. Soft-deleted pages still belong exclusively
        // in the Trash view until they are restored as a draft.
        $query = BuilderPage::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('title');
        is_null($currentPage->site_id)
            ? $query->whereNull('site_id')
            : $query->where('site_id', $currentPage->site_id);
        $pages = $query->get();
        $byParent = $pages->groupBy(fn(BuilderPage $page) => (int) ($page->parent_id ?: 0));
        $build = function(int $parentId) use (&$build, $byParent, $currentPage): array {
            return $byParent->get($parentId, collect())->map(fn(BuilderPage $page) => [
                'id' => (int) $page->id,
                'title' => (string) $page->title,
                'path' => $page->is_home ? '/' : '/'.trim((string) $page->fullslug, '/'),
                'url' => Backend::url('humlnetcreative/pages/builderpages/update/'.$page->id),
                'current' => (int) $page->id === (int) $currentPage->id,
                'has_draft' => (bool) $page->has_draft,
                'published' => (bool) $page->published_revision_id,
                'children' => $build((int) $page->id),
            ])->all();
        };

        return $build(0);
    }

    private function canvasCatalog(): array
    {
        $labels = [
            'basic' => 'Základní obsah',
            'media' => 'Média',
            'structure' => 'Struktura',
            'project' => 'Projektové typy',
        ];
        $registry = SectionRegistry::instance();
        $groups = [];
        foreach ($registry->optionsForBackendUser() as $type => $label) {
            $category = $registry->category($type);
            $groups[$category]['label'] = $labels[$category] ?? 'Další';
            $groups[$category]['types'][] = [
                'type' => $type,
                'label' => $label,
                'allowed_in_columns' => $registry->allowedInColumns($type),
                'minimum_width_units' => $registry->minimumWidthUnits($type),
            ];
        }

        return array_values($groups);
    }

    protected function assertCanManageOwner($owner): void
    {
        if ($owner instanceof SliderMediaContext) {
            $canManageSliderMedia = BackendAuth::userHasPermission('humlnetcreative.pages.slider.media.image')
                || BackendAuth::userHasPermission('humlnetcreative.pages.slider.media.video')
                || BackendAuth::userHasPermission('humlnetcreative.pages.slider.media.crop');

            if (!$canManageSliderMedia) {
                throw new \ApplicationException('Nemáte oprávnění upravovat média Slideru.');
            }

            return;
        }

        $section = $owner instanceof SectionItem ? $owner->section : $owner;
        if (!$section instanceof Section || !SectionRegistry::instance()->has($section->type)) {
            throw new \ApplicationException('Médium není připojeno k platné sekci.');
        }
        if (!BackendAuth::userHasPermission(SectionRegistry::instance()->definition($section->type)['permission'])) {
            throw new \ApplicationException('Nemáte oprávnění upravovat tento typ sekce.');
        }
    }

    /** Refreshes only the media panel in the open relation popup. */
    protected function refreshMediaEditor($owner): array
    {
        $owner->unsetRelation('media');
        $kind = $owner instanceof SectionItem ? 'item' : 'section';
        $partial = $owner instanceof SectionItem
            ? '$/humlnetcreative/pages/models/sectionitem/_media.htm'
            : '$/humlnetcreative/pages/models/section/_media.htm';

        return [
            '#hucr-media-editor-'.$kind.'-'.$owner->id => $this->makePartial($partial, ['model' => $owner]),
        ];
    }

    private function pageForRequest($recordId = null): BuilderPage
    {
        $id = (int) ($recordId ?: request()->input('page_id') ?: request()->route('recordId'));
        if (!$id && isset($this->params[0])) {
            $id = (int) $this->params[0];
        }

        return BuilderPage::withoutGlobalScopes()->findOrFail($id);
    }

    private function commandRelationContext(): array
    {
        $page = $this->pageForRequest();
        $field = (string) request()->input('_relation_field');
        if (!in_array($field, ['sections', 'items'], true)) {
            throw new \ApplicationException('Neplatná Page Builder relace.');
        }
        $relation = $this->asExtension('RelationController');
        $relation->initRelation($page, $field);
        $widget = $relation->relationGetManageFormWidget();
        if (!$widget) {
            throw new \ApplicationException('Editor relačního záznamu se nepodařilo inicializovat.');
        }

        return [$page, $field, $widget];
    }

    private function relationItemSection(): Section
    {
        $extra = json_decode((string) request()->input('_relation_extra_config'), true);
        $sectionId = (int) data_get($extra, 'manageIds.sections', 0);

        return Section::findOrFail($sectionId);
    }

    private function normalizeRelationSaveData(array $data): array
    {
        foreach (['slider' => 'slider_id', 'faq_group' => 'faq_group_id', 'gallery' => 'gallery_id'] as $relation => $foreignKey) {
            if (!array_key_exists($relation, $data)) {
                continue;
            }
            $value = $data[$relation];
            $data[$foreignKey] = is_object($value) && method_exists($value, 'getKey') ? $value->getKey() : ($value ?: null);
            unset($data[$relation]);
        }

        return $data;
    }

    private function expectedDraftVersion(): int
    {
        if (!request()->has('expected_draft_version')) {
            throw new \ApplicationException('Chybí očekávaná verze konceptu. Obnovte editor a akci zopakujte.');
        }

        return (int) request()->input('expected_draft_version');
    }

    private function runPageCommand(BuilderPage $page, string $name, array $payload = [])
    {
        return app(PageCommandService::class)->execute(new PageCommand(
            $name,
            $page->id,
            $this->expectedDraftVersion(),
            $payload,
        ));
    }

    private function announceCommandResult($result): void
    {
        $history = app(PageCommandHistory::class);
        $this->dispatchBrowserEventAsync('hucr:draft-version', [
            'pageId' => $result->pageId,
            'draftVersion' => $result->draftVersion,
            'hasDraft' => true,
            'canUndo' => $history->canUndo($result->pageId),
            'canRedo' => $history->canRedo($result->pageId),
            'commandHistory' => $history->timeline($result->pageId),
        ]);
    }

    private function commandRefresh(BuilderPage $page, ?string $selectedUuid = null): array
    {
        $this->asExtension('RelationController')->initRelation($page, 'sections');
        $selectedUuid ??= (string) request()->input('selected_canvas_uuid');
        $this->dispatchBrowserEventAsync('hucr:canvas-refresh', ['selectedUuid' => $selectedUuid]);

        return $this->asExtension('RelationController')->relationRefresh('sections') + [
            '#hucr-builder-canvas-content' => $this->makePartial('canvas', [
                'canvasSections' => $this->canvasSections($page),
                'selectedCanvasUuid' => $selectedUuid,
                'pageReadOnly' => (bool) ($this->vars['pageReadOnly'] ?? false),
                'canvasPanelPreferences' => $this->canvasPanelPreferences(),
                'canvasPageTree' => $this->canvasPageTree($page),
                'canvasCatalog' => $this->canvasCatalog(),
                'builderPage' => $page,
                'commandTimeline' => app(PageCommandHistory::class)->timeline($page->id),
                'activeInspectorTab' => $this->activeInspectorTab(),
            ]),
        ];
    }

    private function assertWritablePage(BuilderPage $page): void
    {
        $this->assertPermission('humlnetcreative.pages.draft.edit', 'Nemáte oprávnění upravovat koncept stránky.');
        app(PageEditLockService::class)->assertWritable(
            $page,
            BackendAuth::getUser(),
            app(EditorSessionService::class)->id(),
        );
    }

    private function assertPermission(string $permission, string $message): void
    {
        if (!BackendAuth::userHasPermission($permission)) {
            throw new \ApplicationException($message);
        }
    }

    private function sharedSourceUsages(string $foreignKey, int $sourceId)
    {
        return Section::with('page')
            ->where($foreignKey, $sourceId)
            ->orderBy('page_id')
            ->orderBy('sort_order')
            ->get();
    }
}
