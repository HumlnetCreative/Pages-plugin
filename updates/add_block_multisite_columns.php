<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddBlockMultisiteColumns extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_blocks', function ($table) {
            $table->integer('site_id')->nullable()->index();
            $table->integer('site_root_id')->nullable()->index();
        });
    }

    public function down()
    {

    }
}
