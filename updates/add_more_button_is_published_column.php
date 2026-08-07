<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddMoreButtonIsPublishedColumn extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_blocks', function ($table) {
            $table->boolean('more_button_is_published')->default(false);
        });
    }

    public function down()
    {

    }
}
