<?php namespace HumlnetCreative\Pages\Updates;

use Backend\Models\UserRole;
use October\Rain\Database\Updates\Migration;

class ExtendClientRoleColumnsPermission extends Migration
{
    public function up()
    {
        $role = UserRole::where('code', 'hucr-client')->first();
        if (!$role) {
            return;
        }
        $permissions = (array) $role->permissions;
        $permissions['humlnetcreative.pages.section.columns'] = 1;
        $role->permissions = $permissions;
        $role->save();
    }

    public function down()
    {
        $role = UserRole::where('code', 'hucr-client')->first();
        if (!$role) {
            return;
        }
        $permissions = (array) $role->permissions;
        unset($permissions['humlnetcreative.pages.section.columns']);
        $role->permissions = $permissions;
        $role->save();
    }
}
