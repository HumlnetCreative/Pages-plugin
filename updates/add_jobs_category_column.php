<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddJobsCategoryColumn extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_contents', function ($table) {
            $table->integer('jobs_category_id')->nullable()->unsigned();
            $table->string('post_page', 100)->nullable();
        });
    }

    public function down()
    {

    }
}
