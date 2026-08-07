<?php namespace HumlnetCreative\Pages\Updates;

use Backend\Models\UserRole;
use October\Rain\Database\Updates\Migration;

/** Creates a safe starting role without modifying a project's existing roles. */
class SeedClientRole extends Migration
{
    public function up()
    {
        if (UserRole::where('code', 'hucr-client')->exists()) {
            return;
        }
        UserRole::create([
            'name' => 'Klient', 'code' => 'hucr-client',
            'description' => 'Bezpečný výchozí redaktor Page Builderu',
            'permissions' => array_fill_keys([
                'humlnetcreative.pages.builder',
                'humlnetcreative.pages.section.text', 'humlnetcreative.pages.section.image_text',
                'humlnetcreative.pages.section.cards', 'humlnetcreative.pages.section.cta',
                'humlnetcreative.pages.section.accordion', 'humlnetcreative.pages.section.gallery',
                'humlnetcreative.pages.section.embed',
            ], 1),
        ]);
    }
}
