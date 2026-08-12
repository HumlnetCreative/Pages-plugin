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
use HumlnetCreative\Pages\Models\MediaUse;
use HumlnetCreative\Pages\Models\PageAuditLog;
use HumlnetCreative\Pages\Models\PageRevision;
use HumlnetCreative\Pages\Models\SliderMediaContext;
use HumlnetCreative\Pages\Services\EditorSessionService;
use HumlnetCreative\Pages\Services\MediaService;
use HumlnetCreative\Pages\Services\PageEditLockService;
use HumlnetCreative\Pages\Services\PagePublicationService;
use HumlnetCreative\Pages\Services\SectionRegistry;
use HumlnetCreative\Pages\Services\WorkingCopyRestorer;
use Backend\Facades\BackendAuth;
use Illuminate\Support\Facades\Storage;
use Flash;
use Tailor\Classes\BlueprintIndexer;

class BuilderPages extends Controller
{
    public $implement = [FormController::class, ListController::class, RelationController::class];
    public $formConfig = 'config_form.yaml';
    public $listConfig = 'config_list.yaml';
    public $relationConfig = 'config_relation.yaml';
    public $requiredPermissions = ['humlnetcreative.pages.builder'];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('HumlnetCreative.Pages', 'main-menu-item', 'side-menu-builder');
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
        $latestLifecycleAudit = $page->has_draft
            ? PageAuditLog::where('page_id', $page->id)
                ->whereIn('action', ['history.restored', 'draft.published', 'draft.discarded'])
                ->orderByDesc('id')
                ->first()
            : null;
        $this->vars['draftSourceVersion'] = $latestLifecycleAudit?->action === 'history.restored'
            ? (int) data_get($latestLifecycleAudit->payload, 'version')
            : null;

        return $result;
    }

    public function onSaveAndPublish($recordId = null)
    {
        $this->assertPermission('humlnetcreative.pages.draft.publish', 'Nemáte oprávnění publikovat koncept stránky.');
        $page = $this->pageForRequest($recordId);
        $this->assertWritablePage($page);

        // Save the form working copy first. Any validation failure prevents publication.
        $this->asExtension('FormController')->update_onSave($page->id);
        $page = BuilderPage::withoutGlobalScopes()->findOrFail($page->id);
        app(PagePublicationService::class)->publish($page, BackendAuth::getUser()?->id);
        Flash::success('Koncept byl uložen a publikován jako nová verze.');

        return Backend::redirect('humlnetcreative/pages/builderpages/update/'.$page->id);
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

    /** Published deletion is a later explicit URL proposal, never an immediate form action. */
    public function onDelete($recordId = null)
    {
        $page = $this->pageForRequest($recordId);
        $this->assertWritablePage($page);
        if ($page->published_revision_id) {
            throw new \ApplicationException('Publikovanou stránku nelze odstranit přímo. Návrh odstranění a volba 410/301 budou součástí etapy URL workflow.');
        }

        app(PageEditLockService::class)->release($page, BackendAuth::getUser(), app(EditorSessionService::class)->id());

        return $this->asExtension('FormController')->update_onDelete($page->id);
    }

    public function onDeleteUnpublishedFromList(): array
    {
        $this->assertPermission('humlnetcreative.pages.draft.edit', 'Nemáte oprávnění odstranit nepublikovanou stránku.');
        $page = $this->pageForRequest();
        if ($page->published_revision_id) {
            throw new \ApplicationException('Publikovanou stránku nelze odstranit přímo. Její řízené odstranění s volbou 410/301 patří do etapy URL workflow.');
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
            'content[embed]', 'media', 'items',
        ];
        $allowedFields = $this->expandFieldGroups(SectionRegistry::instance()->sectionFields($type), $fieldMap);

        foreach (array_diff($specializedFields, $allowedFields) as $fieldName) {
            $widget->removeField($fieldName);
        }

        $this->pruneStyleFields($widget, SectionRegistry::instance()->sectionStyleFields($type));

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
            $target = Section::create([
                'page_id' => $source->page_id, 'type' => 'cards', 'title' => 'Nový blok Karet',
                'is_published' => true, 'sort_order' => ((int) Section::where('page_id', $source->page_id)->max('sort_order')) + 1,
                'layout' => ['width' => 'contained', 'spacing' => 'standard'], 'content' => ['columns' => 3],
            ]);
        }
        else {
            $target = Section::findOrFail((int) $targetId);
        }
        $item->moveToSection($target);
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
        $defaults = SectionRegistry::instance()->defaults($type);
        $requiresSource = in_array($type, ['carousel', 'accordion', 'gallery'], true);
        Section::create([
            'page_id' => $page->id, 'type' => $type, 'title' => $definition['label'],
            // Relational sections need their content source selected before publication.
            'is_published' => !$requiresSource,
            'sort_order' => ((int) $page->sections()->max('sort_order')) + 1,
            'layout' => $defaults['layout'], 'style' => $defaults['style'], 'content' => $defaults['content'],
        ]);
        Flash::success($requiresSource
            ? 'Sekce byla přidána jako skrytá. Vyberte její obsahový zdroj a potom ji publikujte.'
            : 'Sekce byla přidána na konec stránky.');

        return $this->relationRefresh('sections');
    }

    protected function allowedSectionTypes(): array
    {
        return array_keys(SectionRegistry::instance()->optionsForBackendUser());
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
