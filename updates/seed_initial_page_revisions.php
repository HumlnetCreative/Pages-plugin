<?php namespace HumlnetCreative\Pages\Updates;

use HumlnetCreative\Pages\Services\PagePublicationService;
use October\Rain\Database\Updates\Migration;

class SeedInitialPageRevisions extends Migration
{
    public function up()
    {
        app(PagePublicationService::class)->seedInitialSnapshots();
    }
}
