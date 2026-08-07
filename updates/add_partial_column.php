<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddPartialColumn extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_contents', function ($table) {
            $table->string('partial', 100)->nullable();
        });
    }

    public function down()
    {

    }
}
