<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class BuilderTableCreateLzaplataPagesBlocks extends Migration
{
    public function up()
    {
        Schema::create('lzaplata_pages_blocks', function($table)
        {
            $table->increments('id')->unsigned();
            $table->string('title', 100);
            $table->string('heading', 100)->nullable();
            $table->boolean('is_fluid')->default(false);
            $table->boolean('no_gutters')->default(false);
            $table->string('no_gutters_breakpoint', 3)->default('lg');
            $table->boolean('padding_top')->default(true);
            $table->string('type', 20)->nullable(false)->default('text');
            $table->text('text')->nullable();
            $table->integer('blog_category_id')->nullable()->unsigned();
            $table->string('partial', 100)->nullable();
            $table->integer('row_cols')->default(4);
            $table->integer('switch_order')->default(0);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->integer('slider_id')->nullable()->unsigned();
            $table->text('embed')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('lzaplata_pages_blocks');
    }
}
