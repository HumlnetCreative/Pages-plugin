<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddContentFluidAndGutterColumns extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_contents', function ($table) {
            $table->boolean('is_fluid')->default(false);
            $table->boolean('no_gutters')->default(false);
            $table->string('no_gutters_breakpoint', 3)->default('lg');
        });
    }

    public function down()
    {

    }
}
