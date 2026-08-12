<?php namespace HumlnetCreative\Pages\Models;

use October\Rain\Database\Model;

final class PageRevisionMedia extends Model
{
    public $table = 'humlnetcreative_pages_revision_media';
    protected $guarded = [];
    protected $jsonable = ['variants'];

    public $belongsTo = [
        'revision' => [PageRevision::class, 'key' => 'revision_id'],
        'asset' => [MediaAsset::class, 'key' => 'media_asset_id'],
    ];
}
