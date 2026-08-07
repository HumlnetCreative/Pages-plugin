<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class BuilderTablesCreate extends Migration
{
    public function up()
    {
        Schema::create('lzaplata_pages_pages', function($table)
        {
            $table->increments('id')->unsigned();
            $table->string('title', 100);
            $table->string('slug', 100);
            $table->string('fullslug', 255)->nullable();
            $table->integer('parent_id')->nullable()->unsigned();
            $table->integer('sort_order')->default(0);
            $table->string('menu', 50)->nullable();
            $table->string('breadcrumb', 10)->default('auto');
            $table->text('breadcrumb_url')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('lzaplata_pages_contents', function($table)
        {
            $table->increments('id')->unsigned();
            $table->string('title', 100);
            $table->string('type', 20)->nullable(false)->default('text');
            $table->string('heading', 100)->nullable();
            $table->text('text')->nullable();
            $table->integer('gallery_id')->nullable()->unsigned();
            $table->integer('contacts_category_id')->nullable()->unsigned();
            $table->integer('blog_category_id')->nullable()->unsigned();
            $table->integer('files_category_id')->nullable()->unsigned();
            $table->integer('page_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('lzaplata_pages_pages');
        Schema::dropIfExists('lzaplata_pages_contents');
    }
}
