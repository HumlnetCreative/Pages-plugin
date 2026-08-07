<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddBuilderPageStyleColumn extends Migration
{
    public function up()
    {
        Schema::table('humlnetcreative_pages_builder_pages', function($table) {
            $table->json('style')->nullable()->after('sort_order');
        });
    }

    public function down()
    {
        Schema::table('humlnetcreative_pages_builder_pages', function($table) {
            $table->dropColumn('style');
        });
    }
}
