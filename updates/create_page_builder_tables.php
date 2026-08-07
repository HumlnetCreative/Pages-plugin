<?php namespace HumlnetCreative\Pages\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

/** New-generation builder tables. Historical lzaplata_pages_* tables are deliberately untouched. */
class CreatePageBuilderTables extends Migration
{
    public function up()
    {
        Schema::create('humlnetcreative_pages_builder_pages', function($table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('title', 160);
            $table->string('slug', 160)->default('');
            $table->string('fullslug', 255)->default('')->index();
            $table->boolean('is_home')->default(false);
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('meta_title', 160)->nullable();
            $table->text('meta_description')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['site_id', 'fullslug'], 'hucr_pages_builder_site_slug_unique');
        });

        Schema::create('humlnetcreative_pages_sections', function($table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('page_id')->index();
            $table->string('type', 80)->index();
            $table->string('title', 160)->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('layout')->nullable();
            $table->json('style')->nullable();
            $table->json('content')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('humlnetcreative_pages_section_items', function($table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('section_id')->index();
            $table->string('type', 80)->default('item');
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('style')->nullable();
            $table->json('content')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('humlnetcreative_pages_media_assets', function($table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('disk', 64)->default('local');
            $table->string('path', 1024)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();
        });

        Schema::create('humlnetcreative_pages_media_uses', function($table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('media_asset_id')->index();
            $table->string('owner_type', 120);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('slot', 100);
            $table->json('crop')->nullable();
            $table->string('alt_text', 500)->nullable();
            $table->boolean('is_decorative')->default(false);
            $table->json('variants')->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id'], 'hucr_pages_media_use_owner_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('humlnetcreative_pages_media_uses');
        Schema::dropIfExists('humlnetcreative_pages_media_assets');
        Schema::dropIfExists('humlnetcreative_pages_section_items');
        Schema::dropIfExists('humlnetcreative_pages_sections');
        Schema::dropIfExists('humlnetcreative_pages_builder_pages');
    }
}
