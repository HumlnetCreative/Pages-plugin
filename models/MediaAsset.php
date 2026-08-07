<?php namespace HumlnetCreative\Pages\Models;

use Illuminate\Support\Str;
use October\Rain\Database\Model;

class MediaAsset extends Model
{
    public $table = 'humlnetcreative_pages_media_assets';
    protected $guarded = [];
    public $hasMany = ['uses' => [MediaUse::class, 'key' => 'media_asset_id']];

    public function beforeSave()
    {
        $this->uuid ??= (string) Str::uuid();
    }
}
