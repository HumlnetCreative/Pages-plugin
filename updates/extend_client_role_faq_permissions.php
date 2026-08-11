<?php namespace HumlnetCreative\Pages\Updates;

use Backend\Models\UserRole;
use October\Rain\Database\Updates\Migration;

class ExtendClientRoleFaqPermissions extends Migration
{
    public function up()
    {
        $role = UserRole::where('code', 'hucr-client')->first();
        if (!$role) {
            return;
        }

        $permissions = (array) $role->permissions;
        foreach ([
            'humlnetcreative.pages.faq.manage',
            'humlnetcreative.pages.faq.select',
            'tailor.entry.humlnetcreative_faq_groups',
            'tailor.entry.humlnetcreative_faq_groups.create',
            'tailor.entry.humlnetcreative_faq_groups.publish',
            'tailor.entry.lzaplata_faq',
            'tailor.entry.lzaplata_faq.create',
            'tailor.entry.lzaplata_faq.publish',
        ] as $permission) {
            $permissions[$permission] = 1;
        }

        $role->permissions = $permissions;
        $role->save();
    }
}
