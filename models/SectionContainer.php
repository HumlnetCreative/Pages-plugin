<?php namespace HumlnetCreative\Pages\Models;

use HumlnetCreative\Pages\Services\DraftStateService;
use HumlnetCreative\Pages\Services\PageMutationGuard;
use Illuminate\Support\Str;
use October\Rain\Database\Model;
use October\Rain\Database\Traits\SoftDelete;
use October\Rain\Database\Traits\Sortable;

/** Explicit page root or one ordered zone owned by a Columns section. */
class SectionContainer extends Model
{
    use SoftDelete;
    use Sortable;

    public const KIND_ROOT = 'root';
    public const KIND_ZONE = 'zone';

    public $table = 'humlnetcreative_pages_section_containers';
    protected $guarded = [];
    protected $dates = ['deleted_at'];
    protected $jsonable = ['style'];
    protected bool $revisionWasNew = false;

    public $belongsTo = [
        'page' => [BuilderPage::class, 'key' => 'page_id'],
        'columns_section' => [Section::class, 'key' => 'parent_section_id'],
    ];

    public $hasMany = [
        'sections' => [Section::class, 'key' => 'container_id', 'order' => 'sort_order'],
    ];

    public function beforeSave(): void
    {
        $this->revisionWasNew = !$this->exists;
        if (!$this->uuid) {
            $this->uuid = (string) Str::uuid();
        }
        app(PageMutationGuard::class)->assertWritable($this->page);

        if (!in_array($this->kind, [self::KIND_ROOT, self::KIND_ZONE], true)) {
            throw new \ValidationException(['kind' => 'Neplatný typ kontejneru.']);
        }
        if (!in_array($this->vertical_align ?: 'top', ['top', 'center', 'bottom'], true)) {
            throw new \ValidationException(['vertical_align' => 'Neplatné svislé zarovnání zóny.']);
        }
        if (!in_array($this->block_spacing ?: 'standard', ['none', 'small', 'standard', 'large'], true)) {
            throw new \ValidationException(['block_spacing' => 'Neplatná mezera mezi bloky v zóně.']);
        }
        if ($this->kind === self::KIND_ROOT) {
            $this->parent_section_id = null;
            $this->width_units = 4;
            $duplicate = static::where('page_id', $this->page_id)->where('kind', self::KIND_ROOT)
                ->where('id', '<>', $this->id ?: 0)->exists();
            if ($duplicate) {
                throw new \ValidationException(['kind' => 'Stránka může mít jen jeden hlavní kontejner.']);
            }
            return;
        }

        $columns = $this->columns_section;
        if (!$columns || $columns->type !== 'columns' || (int) $columns->page_id !== (int) $this->page_id) {
            throw new \ValidationException(['columns_section' => 'Zóna musí patřit Sloupcům na stejné stránce.']);
        }
        if ($columns->container?->kind !== self::KIND_ROOT) {
            throw new \ValidationException(['columns_section' => 'Sloupce nelze vnořit do jiné zóny.']);
        }
        if ((int) $this->width_units < 1 || (int) $this->width_units > 3) {
            throw new \ValidationException(['width_units' => 'Podíl zóny musí být v rozsahu 1 až 3.']);
        }
        if (!$this->exists && static::where('parent_section_id', $columns->id)->count() >= 4) {
            throw new \ValidationException(['columns_section' => 'Sloupce mohou obsahovat nejvýše čtyři zóny.']);
        }
    }

    public function afterSave(): void
    {
        app(DraftStateService::class)->touch(
            (int) $this->page_id,
            $this->revisionWasNew ? 'container.added' : 'container.changed',
            $this,
        );
        $this->revisionWasNew = false;
    }

    public function beforeDelete(): void
    {
        app(PageMutationGuard::class)->assertWritable($this->page);
        if ($this->kind === self::KIND_ROOT) {
            throw new \ValidationException(['kind' => 'Hlavní kontejner stránky nelze odstranit.']);
        }
    }

    public function afterDelete(): void
    {
        app(DraftStateService::class)->touch((int) $this->page_id, 'container.deleted', $this);
    }
}
