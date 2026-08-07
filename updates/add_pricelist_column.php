<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddPricelistColumn extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_contents', function ($table) {
            $table->integer('pricelist_id')->nullable()->unsigned();
        });
    }

    public function down()
    {

    }
}
