<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddMetaColumns extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_pages', function ($table) {
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();
        });
    }

    public function down()
    {

    }
}
