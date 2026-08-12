<?php namespace HumlnetCreative\Pages\Models;

use Illuminate\Support\Str;
use October\Rain\Database\Model;

final class PageRevision extends Model
{
    public $table = 'humlnetcreative_pages_revisions';
    protected $guarded = [];
    protected $jsonable = ['snapshot'];

    public $belongsTo = [
        'page' => [BuilderPage::class, 'key' => 'page_id'],
        'publisher' => [\Backend\Models\User::class, 'key' => 'published_by'],
    ];

    public $hasMany = [
        'media_references' => [PageRevisionMedia::class, 'key' => 'revision_id'],
    ];

    public function beforeSave()
    {
        $this->uuid ??= (string) Str::uuid();
        if ($this->exists && $this->isDirty()) {
            throw new \LogicException('Publikovaný snapshot je neměnný.');
        }
    }
}
