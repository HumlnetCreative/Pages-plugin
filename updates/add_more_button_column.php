<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddMoreButtonColumn extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_blocks', function ($table) {
            $table->string('more_button_title')->nullable();
            $table->text('more_button_link')->nullable();
        });
    }

    public function down()
    {

    }
}
