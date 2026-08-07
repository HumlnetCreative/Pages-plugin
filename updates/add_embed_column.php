<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class add_embed_column extends Migration
{
    public function up()
    {
        Schema::table('lzaplata_pages_contents', function ($table) {
            $table->text('embed')->nullable();
        });
    }

    public function down()
    {

    }
}
