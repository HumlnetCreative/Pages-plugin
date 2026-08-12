<?php namespace HumlnetCreative\Pages\Models;

use HumlnetCreative\Pages\Services\SectionRegistry;
use Backend\Facades\BackendAuth;
use HumlnetCreative\Pages\Services\CompliancePolicy;
use Flash;
use Illuminate\Support\Str;
use October\Rain\Database\Model;
use October\Rain\Database\Traits\SoftDelete;
use October\Rain\Database\Traits\Sortable;
use HumlnetCreative\Pages\Services\PresentationService;
use HumlnetCreative\Pages\Services\DraftStateService;
use HumlnetCreative\Pages\Services\PageMutationGuard;

class Section extends Model
{
    use SoftDelete;
    use Sortable;

    public $table = 'humlnetcreative_pages_sections';
    protected $guarded = [];
    protected $dates = ['deleted_at'];
    protected $jsonable = ['layout', 'style', 'content'];
    protected bool $revisionWasNew = false;
    public $belongsTo = [
        'page' => [BuilderPage::class, 'key' => 'page_id'],
        'slider' => [SliderEntry::class, 'key' => 'slider_id'],
        'faq_group' => [FaqGroupEntry::class, 'key' => 'faq_group_id'],
        'gallery' => [\LZaplata\Gallery\Models\Gallery::class, 'key' => 'gallery_id'],
    ];
    public $hasMany = ['items' => [SectionItem::class, 'key' => 'section_id', 'order' => 'sort_order']];
    public $morphMany = ['media' => [MediaUse::class, 'name' => 'owner', 'order' => 'slot']];

    public function beforeSave()
    {
        $this->revisionWasNew = !$this->exists;
        if (!$this->uuid) {
            $this->uuid = (string) Str::uuid();
        }
        app(PageMutationGuard::class)->assertWritable($this->page);
        if (!SectionRegistry::instance()->has($this->type)) {
            throw new \ValidationException(['type' => 'Neznámý typ sekce.']);
        }
        $permission = SectionRegistry::instance()->definition($this->type)['permission'];
        if (BackendAuth::getUser() && !BackendAuth::userHasPermission($permission)) {
            throw new \ValidationException(['type' => 'Nemáte oprávnění upravovat tento typ sekce.']);
        }
        $this->validateBuilderOptions();
        $this->applyComplianceIssues(CompliancePolicy::sectionIssues($this->type, $this->content ?: []));
    }

    public function afterSave()
    {
        app(DraftStateService::class)->touch(
            (int) $this->page_id,
            $this->revisionWasNew ? 'section.added' : 'section.changed',
            $this,
        );
        $this->revisionWasNew = false;
    }

    public function beforeDelete()
    {
        app(PageMutationGuard::class)->assertWritable($this->page);
    }

    public function afterDelete()
    {
        app(DraftStateService::class)->touch((int) $this->page_id, 'section.deleted', $this);
    }

    public function getTypeOptions(): array
    {
        return SectionRegistry::instance()->optionsForBackendUser();
    }

    public function getTypeLabelAttribute(): string
    {
        return SectionRegistry::instance()->all()[$this->type]['label'] ?? $this->type;
    }

    public function getPresentationAttribute(): array
    {
        return (new PresentationService())->build($this);
    }

    /** Published questions from the reusable Tailor FAQ group in editorial order. */
    public function getFaqItemsAttribute()
    {
        if (!$this->faq_group || !$this->faq_group->is_enabled) {
            return collect();
        }

        return collect($this->faq_group?->questions ?: [])
            ->filter(fn($question) => (bool) $question->is_enabled)
            ->sortBy('sort_order')
            ->values();
    }

    /** Whether the first section can render the page's single primary heading itself. */
    public function getProvidesPageHeadingAttribute(): bool
    {
        if ($this->type === 'hero') {
            return true;
        }
        if ($this->type === 'text') {
            return trim((string) data_get($this->content, 'heading')) !== '';
        }
        if ($this->type === 'carousel') {
            return trim((string) data_get($this->presentation, 'slides.0.title')) !== '';
        }

        return false;
    }

    protected function applyComplianceIssues(array $issues): void
    {
        if (!$issues || CompliancePolicy::mode() === 'off') {
            return;
        }
        if (CompliancePolicy::shouldBlock()) {
            throw new \ValidationException(['content' => implode(' ', $issues)]);
        }
        if (BackendAuth::getUser()) {
            Flash::warning(implode(' ', $issues));
        }
    }

    protected function validateBuilderOptions(): void
    {
        $width = data_get($this->layout, 'width', 'contained');
        $spacing = data_get($this->layout, 'spacing', 'standard');
        if (!in_array($width, ['contained', 'wide', 'full'], true)) {
            throw new \ValidationException(['layout' => 'Neplatná šířka sekce.']);
        }
        if (!in_array($spacing, ['none', 'small', 'standard', 'large'], true)) {
            throw new \ValidationException(['layout' => 'Neplatné vertikální odsazení sekce.']);
        }
        if ($this->type === 'hero' && !in_array(data_get($this->content, 'position', 'left-center'), [
            'left-top', 'left-center', 'left-bottom', 'center-top', 'center-center',
            'center-bottom', 'right-top', 'right-center', 'right-bottom',
        ], true)) {
            throw new \ValidationException(['content' => 'Neplatná pozice obsahu Hero.']);
        }
        if ($this->type === 'image_text' && !in_array(data_get($this->content, 'image_position', 'left'), ['left', 'right'], true)) {
            throw new \ValidationException(['content' => 'Neplatná pozice obrázku.']);
        }
        if ($this->type === 'image_text' && !in_array(data_get($this->layout, 'image_text_gap', 'standard'), ['small', 'standard', 'large'], true)) {
            throw new \ValidationException(['layout' => 'Neplatná mezera mezi obrázkem a textem.']);
        }
        if ($this->type === 'cards' && !in_array((int) data_get($this->content, 'columns', 3), [1, 2, 3, 4], true)) {
            throw new \ValidationException(['content' => 'Počet karet na řádku musí být 1 až 4.']);
        }
        if ($this->type === 'carousel' && $this->is_published && !$this->slider_id) {
            throw new \ValidationException(['slider' => 'Pro zobrazenou Prezentaci vyberte Slider.']);
        }
        if ($this->type === 'carousel' && $this->isDirty('slider_id') && BackendAuth::getUser()
            && !BackendAuth::userHasPermission('humlnetcreative.pages.slider.select')) {
            throw new \ValidationException(['slider' => 'Nemáte oprávnění měnit vybraný Slider.']);
        }
        if ($this->type === 'accordion' && $this->is_published && !$this->faq_group_id) {
            throw new \ValidationException(['faq_group' => 'Pro zobrazené FAQ vyberte FAQ skupinu.']);
        }
        if ($this->type === 'accordion' && $this->is_published && $this->faq_group_id
            && (!$this->faq_group || !$this->faq_group->is_enabled || $this->faq_items->isEmpty())) {
            throw new \ValidationException(['faq_group' => 'Publikovaná FAQ skupina musí obsahovat alespoň jednu publikovanou otázku s odpovědí.']);
        }
        if ($this->type === 'accordion' && $this->isDirty('faq_group_id') && BackendAuth::getUser()
            && !BackendAuth::userHasPermission('humlnetcreative.pages.faq.select')) {
            throw new \ValidationException(['faq_group' => 'Nemáte oprávnění měnit vybranou FAQ skupinu.']);
        }
        if ($this->type === 'gallery' && $this->is_published && !$this->gallery_id) {
            throw new \ValidationException(['gallery' => 'Pro zobrazenou Galerii vyberte zdrojovou galerii.']);
        }
        if ($this->type === 'gallery' && $this->is_published && $this->gallery_id && (!$this->gallery || $this->gallery->images->isEmpty())) {
            throw new \ValidationException(['gallery' => 'Publikovaná Galerie musí obsahovat alespoň jeden obrázek.']);
        }
        if ($this->type === 'gallery' && $this->isDirty('gallery_id') && BackendAuth::getUser()
            && !BackendAuth::userHasPermission('humlnetcreative.pages.gallery.select')) {
            throw new \ValidationException(['gallery' => 'Nemáte oprávnění měnit vybranou Galerii.']);
        }
        if ($this->type === 'gallery' && !in_array((int) data_get($this->content, 'gallery_columns', 3), [1, 2, 3, 4, 5, 6], true)) {
            throw new \ValidationException(['content' => 'Počet obrázků Galerie na řádku musí být 1 až 6.']);
        }
        if ($this->type === 'carousel') {
            $position = data_get($this->content, 'position', 'left-center');
            if (!in_array($position, ['left-top', 'left-center', 'left-bottom', 'center-top', 'center-center', 'center-bottom', 'right-top', 'right-center', 'right-bottom'], true)) {
                throw new \ValidationException(['content' => 'Neplatná pozice obsahu Prezentace.']);
            }
            if ((int) data_get($this->content, 'autoplay_delay', 5000) < 1000) {
                throw new \ValidationException(['content' => 'Interval automatického přehrávání musí být alespoň 1000 ms.']);
            }
        }
    }
}
