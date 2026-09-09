<?php namespace HumlnetCreative\Pages\Updates;

use HumlnetCreative\Pages\Contracts\RedirectManagerInterface;
use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Services\PagePublicationService;
use HumlnetCreative\Pages\Services\VdlpRedirectAdapter;
use October\Rain\Database\Updates\Migration;

class SeedInitialPageRevisions extends Migration
{
    public function up()
    {
        if (!BuilderPage::withoutGlobalScopes()->exists()) {
            return;
        }

        if (!app()->bound(RedirectManagerInterface::class)) {
            app()->singleton(RedirectManagerInterface::class, VdlpRedirectAdapter::class);
        }

        app(PagePublicationService::class)->seedInitialSnapshots();
    }

    public function down()
    {
        // Published revisions may have changed since this data migration ran.
        // Preserve them here; the owning schema migration handles full uninstall.
    }
}
