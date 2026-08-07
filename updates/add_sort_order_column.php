<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddSortOrderColumn extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_contents', function ($table) {
            $table->string('blog_sort_order', 20)->nullable();
        });

        Schema::table('lzaplata_pages_blocks', function ($table) {
            $table->string('blog_sort_order', 20)->nullable();
        });
    }

    public function down()
    {

    }
}
