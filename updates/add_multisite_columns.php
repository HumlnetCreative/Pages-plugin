<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddMultisiteColumns extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_pages', function ($table) {
            $table->integer('site_id')->nullable()->index();
            $table->integer('site_root_id')->nullable()->index();
        });

        Schema::table('lzaplata_pages_contents', function ($table) {
            $table->integer('site_id')->nullable()->index();
            $table->integer('site_root_id')->nullable()->index();
        });
    }

    public function down()
    {

    }
}
