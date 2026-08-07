<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddContentReorderAndVisibility extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_contents', function ($table) {
            $table->integer('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
        });
    }

    public function down()
    {

    }
}
