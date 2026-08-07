<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class RemovePostPageColumn extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_contents', function ($table) {
            $table->string('post_partial', 100)->nullable();
        });
    }

    public function down()
    {
        if (Schema::hasTable("lzaplata_pages_contents")) {
            Schema::table('lzaplata_pages_contents', function ($table) {
                $table->dropColumn('post_page');
            });
        }
    }
}
