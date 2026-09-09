<?php namespace HumlnetCreative\Pages\Updates;

use Backend\Models\UserRole;
use October\Rain\Database\Updates\Migration;

/** Creates a safe starting role without modifying a project's existing roles. */
class SeedClientRole extends Migration
{
    private const BASE_PERMISSIONS = [
        'humlnetcreative.pages.builder',
        'humlnetcreative.pages.section.text',
        'humlnetcreative.pages.section.image_text',
        'humlnetcreative.pages.section.cards',
        'humlnetcreative.pages.section.cta',
        'humlnetcreative.pages.section.accordion',
        'humlnetcreative.pages.section.gallery',
        'humlnetcreative.pages.section.embed',
    ];

    public function up()
    {
        if (UserRole::where('code', 'hucr-client')->exists()) {
            return;
        }
        UserRole::create([
            'name' => 'Klient', 'code' => 'hucr-client',
            'description' => 'Bezpečný výchozí redaktor Page Builderu',
            'permissions' => array_fill_keys(self::BASE_PERMISSIONS, 1),
        ]);
    }

    public function down()
    {
        $role = UserRole::where('code', 'hucr-client')->first();
        if (!$role) {
            return;
        }

        $permissions = (array) $role->permissions;
        ksort($permissions);
        $basePermissions = array_fill_keys(self::BASE_PERMISSIONS, 1);
        ksort($basePermissions);

        // Never delete a role that administrators have customized after installation.
        if ($role->name === 'Klient'
            && $role->description === 'Bezpečný výchozí redaktor Page Builderu'
            && $permissions === $basePermissions) {
            $role->delete();
        }
    }
}
