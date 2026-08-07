<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class add_slider_column extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_contents', function ($table) {
            $table->integer('slider_id')->nullable()->unsigned();
        });
    }

    public function down()
    {

    }
}
