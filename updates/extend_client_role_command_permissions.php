<?php namespace HumlnetCreative\Pages\Updates;

use Backend\Models\UserRole;
use October\Rain\Database\Updates\Migration;

class ExtendClientRoleCommandPermissions extends Migration
{
    public function up()
    {
        $role = UserRole::where('code', 'hucr-client')->first();
        if (!$role) {
            return;
        }

        $permissions = (array) $role->permissions;
        foreach ([
            'humlnetcreative.pages.structure.create_delete',
            'humlnetcreative.pages.structure.reorder',
            'humlnetcreative.pages.structure.duplicate',
            'humlnetcreative.pages.structure.copy',
        ] as $permission) {
            $permissions[$permission] = 1;
        }
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
        foreach ([
            'humlnetcreative.pages.structure.create_delete',
            'humlnetcreative.pages.structure.reorder',
            'humlnetcreative.pages.structure.duplicate',
            'humlnetcreative.pages.structure.copy',
        ] as $permission) {
            unset($permissions[$permission]);
        }
        $role->permissions = $permissions;
        $role->save();
    }
}
