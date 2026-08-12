<?php namespace HumlnetCreative\Pages\Models;

use Illuminate\Support\Str;
use Backend\Facades\BackendAuth;
use HumlnetCreative\Pages\Services\SectionRegistry;
use HumlnetCreative\Pages\Services\CompliancePolicy;
use Flash;
use Cms\Classes\Theme;
use October\Rain\Database\Model;
use October\Rain\Database\Traits\SoftDelete;
use October\Rain\Database\Traits\Sortable;
use HumlnetCreative\Pages\Services\DraftStateService;
use HumlnetCreative\Pages\Services\PageMutationGuard;

class SectionItem extends Model
{
    use SoftDelete;
    use Sortable;

    public $table = 'humlnetcreative_pages_section_items';
    protected $guarded = [];
    protected $dates = ['deleted_at'];
    protected $jsonable = ['style', 'content'];
    protected bool $revisionWasNew = false;
    public $belongsTo = ['section' => [Section::class, 'key' => 'section_id']];
    public $morphMany = ['media' => [MediaUse::class, 'name' => 'owner', 'order' => 'slot']];

    public function beforeSave()
    {
        $this->revisionWasNew = !$this->exists;
        $this->uuid ??= (string) Str::uuid();
        $section = $this->section;
        app(PageMutationGuard::class)->assertWritable($section?->page);
        if ($section && SectionRegistry::instance()->has($section->type)) {
            if (!SectionRegistry::instance()->itemsSupported($section->type)) {
                throw new \ValidationException(['section' => 'Tento typ sekce nepodporuje samostatné položky.']);
            }
            $permission = SectionRegistry::instance()->definition($section->type)['permission'];
            if (BackendAuth::getUser() && !BackendAuth::userHasPermission($permission)) {
                throw new \ValidationException(['section' => 'Nemáte oprávnění upravovat položky tohoto typu sekce.']);
            }
            $issues = CompliancePolicy::itemIssues($section->type, $this->content ?: []);
            if ($issues && CompliancePolicy::mode() !== 'off') {
                if (CompliancePolicy::shouldBlock()) {
                    throw new \ValidationException(['content' => implode(' ', $issues)]);
                }
                if (BackendAuth::getUser()) {
                    Flash::warning(implode(' ', $issues));
                }
            }
        }
    }

    public function afterSave()
    {
        if ($this->section?->page_id) {
            app(DraftStateService::class)->touch(
                (int) $this->section->page_id,
                $this->revisionWasNew ? 'section_item.added' : 'section_item.changed',
                $this,
            );
        }
        $this->revisionWasNew = false;
    }

    public function beforeDelete()
    {
        app(PageMutationGuard::class)->assertWritable($this->section?->page);
    }

    public function afterDelete()
    {
        if ($this->section?->page_id) {
            app(DraftStateService::class)->touch((int) $this->section->page_id, 'section_item.deleted', $this);
        }
    }

    public function moveToSection(Section $target): void
    {
        if ($target->page_id !== $this->section->page_id || $target->type !== 'cards') {
            throw new \ValidationException(['section' => 'Kartu lze přesunout jen do bloku Karty na stejné stránce.']);
        }
        $this->section_id = $target->id;
        $this->sort_order = ((int) $target->items()->max('sort_order')) + 1;
        $this->save();
    }

    public function getIconOptions(): array
    {
        $options = ['' => 'Bez ikony'];
        $theme = Theme::getActiveTheme();
        $path = $theme ? $theme->getPath().'/config/page-builder-icons.php' : null;
        $config = $path && is_file($path) ? require $path : [];
        foreach (($config['material'] ?? []) as $key => $label) {
            $options['material:'.$key] = 'Material Symbols — '.$label;
        }
        foreach (($config['custom'] ?? []) as $key => $assetPath) {
            $options['custom:'.$assetPath] = 'Vlastní SVG — '.$key;
        }
        return $options;
    }

    public function getBackendLabelAttribute(): string
    {
        $heading = trim((string) data_get($this->content, 'heading'));
        if ($heading !== '') {
            return $heading;
        }
        $fileName = $this->media()->with('asset')->first()?->asset?->original_name;
        return $fileName ?: 'Položka #'.($this->id ?: 'nová');
    }
}
