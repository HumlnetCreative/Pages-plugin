<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class add_flashmessages_category_column extends Migration
{
    public function up()
    {
        Schema::table("lzaplata_pages_blocks", function ($table) {
            $table->integer("flashmessages_category_id")->nullable()->unsigned();
        });

        Schema::table("lzaplata_pages_contents", function ($table) {
            $table->integer("flashmessages_category_id")->nullable()->unsigned();
        });
    }

    public function down()
    {
    }
}
