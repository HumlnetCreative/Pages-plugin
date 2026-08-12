<?php namespace HumlnetCreative\Pages\Updates;

use Backend\Models\UserRole;
use October\Rain\Database\Updates\Migration;

final class ExtendClientRoleAppearancePermission extends Migration
{
    public function up(): void
    {
        $role = UserRole::where('code', 'hucr-client')->first();
        if (!$role) {
            return;
        }

        $permissions = (array) $role->permissions;
        $permissions['humlnetcreative.pages.structure.appearance'] = 1;
        $role->permissions = $permissions;
        $role->save();
    }

    public function down(): void
    {
        $role = UserRole::where('code', 'hucr-client')->first();
        if (!$role) {
            return;
        }

        $permissions = (array) $role->permissions;
        unset($permissions['humlnetcreative.pages.structure.appearance']);
        $role->permissions = $permissions;
        $role->save();
    }
}
