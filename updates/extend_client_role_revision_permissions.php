<?php namespace HumlnetCreative\Pages\Updates;

use Backend\Models\UserRole;
use October\Rain\Database\Updates\Migration;

class ExtendClientRoleRevisionPermissions extends Migration
{
    public function up()
    {
        $role = UserRole::where('code', 'hucr-client')->first();
        if (!$role) {
            return;
        }

        $permissions = (array) $role->permissions;
        foreach ([
            'humlnetcreative.pages.draft.edit',
            'humlnetcreative.pages.draft.publish',
            'humlnetcreative.pages.draft.discard',
            'humlnetcreative.pages.history.restore',
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
            'humlnetcreative.pages.draft.edit',
            'humlnetcreative.pages.draft.publish',
            'humlnetcreative.pages.draft.discard',
            'humlnetcreative.pages.history.restore',
        ] as $permission) {
            unset($permissions[$permission]);
        }
        $role->permissions = $permissions;
        $role->save();
    }
}
