<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddVariablesColumn extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_contents', function ($table) {
            $table->text('variables')->nullable();
        });

        Schema::table('lzaplata_pages_blocks', function ($table) {
            $table->text('variables')->nullable();
        });
    }

    public function down()
    {

    }
}
