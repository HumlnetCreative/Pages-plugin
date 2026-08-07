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
use HumlnetCreative\Pages\Services\MediaService;
use HumlnetCreative\Pages\Services\SectionRegistry;
use Backend\Facades\BackendAuth;
use Illuminate\Support\Facades\Storage;
use Flash;

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

    /** Keeps each section editor focused on fields rendered by that section type. */
    protected function pruneSectionForm($widget, string $type): void
    {
        $fieldMap = [
            'heading' => ['content[heading]'], 'text' => ['content[text]'],
            'position' => ['content[position]'], 'image_position' => ['content[image_position]'],
            'image_text_gap' => ['layout[image_text_gap]'],
            'autoplay' => ['content[autoplay]'],
            'slider' => ['slider', '_slider_actions'],
            'carousel_options' => ['content[autoplay]', 'content[autoplay_delay]', 'content[navigation]', 'content[pagination]', 'content[overlay]', 'content[position]'],
            'cta' => ['content[cta_label]', 'content[cta_url]'],
            'columns' => ['content[columns]'], 'embed' => ['content[embed]'],
            'media' => ['media'], 'items' => ['items'],
        ];
        $specializedFields = [
            'content[heading]', 'content[text]', 'content[position]', 'content[image_position]',
            'layout[image_text_gap]',
            'content[autoplay]', 'content[cta_label]', 'content[cta_url]', 'content[columns]',
            'slider', '_slider_actions', 'content[autoplay_delay]', 'content[navigation]', 'content[pagination]', 'content[overlay]',
            'content[embed]', 'media', 'items',
        ];
        $allowedFields = $this->expandFieldGroups(SectionRegistry::instance()->sectionFields($type), $fieldMap);

        foreach (array_diff($specializedFields, $allowedFields) as $fieldName) {
            $widget->removeField($fieldName);
        }

        $this->pruneStyleFields($widget, SectionRegistry::instance()->sectionStyleFields($type));

        if ($type === 'carousel' && ($positionField = $widget->getField('content[position]'))) {
            $positionField->tab = 'Prezentace';
            $positionField->label = 'Pozice obsahu';
        }

        // Changing a type in place would leave incompatible content and items behind.
        if ($typeField = $widget->getField('type')) {
            $typeField->readOnly = true;
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
            'previewUrl' => $this->backendUrlForCurrentRequest(
                'humlnetcreative/pages/builderpages/previewmedia/'.$use->id
            ),
        ]);
    }

    /**
     * Builds a backend URL that also works when October is installed in a
     * subdirectory. Backend::url() only reflects APP_URL, which may omit the
     * request base path (for example /pages-theme in local development).
     */
    protected function backendUrlForCurrentRequest(string $path): string
    {
        $backendUrl = Backend::url($path);
        $backendPath = parse_url($backendUrl, PHP_URL_PATH) ?: '/'.ltrim($path, '/');
        $basePath = rtrim((string) request()->getBasePath(), '/');

        if ($basePath !== '' && $backendPath !== $basePath && !str_starts_with($backendPath, $basePath.'/')) {
            $backendPath = $basePath.'/'.ltrim($backendPath, '/');
        }

        $query = parse_url($backendUrl, PHP_URL_QUERY);

        return $backendPath.($query ? '?'.$query : '');
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
        Section::create([
            'page_id' => $page->id, 'type' => $type, 'title' => $definition['label'],
            'is_published' => true, 'sort_order' => ((int) $page->sections()->max('sort_order')) + 1,
            'layout' => $defaults['layout'], 'style' => $defaults['style'], 'content' => $defaults['content'],
        ]);
        Flash::success('Sekce byla přidána na konec stránky.');
    }

    protected function allowedSectionTypes(): array
    {
        return array_keys(SectionRegistry::instance()->optionsForBackendUser());
    }

    protected function assertCanManageOwner($owner): void
    {
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
}
