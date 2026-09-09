<?php namespace HumlnetCreative\Pages\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddGalleryBuilderIntegration extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('humlnetcreative_pages_sections', 'gallery_id')) {
            Schema::table('humlnetcreative_pages_sections', function($table) {
                $table->unsignedInteger('gallery_id')->nullable()->index()->after('faq_group_id');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('humlnetcreative_pages_sections', 'gallery_id')) {
            Schema::table('humlnetcreative_pages_sections', function($table) {
                $table->dropIndex(['gallery_id']);
                $table->dropColumn('gallery_id');
            });
        }
    }
}
