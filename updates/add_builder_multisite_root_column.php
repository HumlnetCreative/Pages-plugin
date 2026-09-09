<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddBuilderMultisiteRootColumn extends Migration
{
    public function up()
    {
        Schema::table('humlnetcreative_pages_builder_pages', function($table) {
            $table->unsignedBigInteger('site_root_id')->nullable()->index()->after('site_id');
        });
    }

    public function down()
    {
        Schema::table('humlnetcreative_pages_builder_pages', function($table) {
            $table->dropIndex(['site_root_id']);
            $table->dropColumn('site_root_id');
        });
    }
}
