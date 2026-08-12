<?php namespace HumlnetCreative\Pages\Models;

use October\Rain\Database\Model;

final class PageAuditLog extends Model
{
    public $table = 'humlnetcreative_pages_audit_logs';
    protected $guarded = [];
    protected $jsonable = ['payload'];

    public $belongsTo = [
        'page' => [BuilderPage::class, 'key' => 'page_id'],
        'user' => [\Backend\Models\User::class, 'key' => 'user_id'],
    ];
}
