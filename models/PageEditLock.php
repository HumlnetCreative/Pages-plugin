<?php namespace HumlnetCreative\Pages\Models;

use October\Rain\Database\Model;

final class PageEditLock extends Model
{
    public $table = 'humlnetcreative_pages_edit_locks';
    protected $guarded = [];
    protected $dates = ['acquired_at', 'heartbeat_at'];

    public $belongsTo = [
        'page' => [BuilderPage::class, 'key' => 'page_id'],
        'user' => [\Backend\Models\User::class, 'key' => 'user_id'],
    ];
}
