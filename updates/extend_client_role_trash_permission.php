<?php namespace HumlnetCreative\Pages\Updates;

use Backend\Models\UserRole;
use October\Rain\Database\Updates\Migration;

class ExtendClientRoleTrashPermission extends Migration
{
    public function up()
    {
        $role = UserRole::where('code', 'hucr-client')->first();
        if (!$role) {
            return;
        }

        $permissions = (array) $role->permissions;
        $permissions['humlnetcreative.pages.builder.trash'] = 1;
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
        unset($permissions['humlnetcreative.pages.builder.trash']);
        $role->permissions = $permissions;
        $role->save();
    }
}
