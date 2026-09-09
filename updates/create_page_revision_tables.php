<?php namespace HumlnetCreative\Pages\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreatePageRevisionTables extends Migration
{
    public function up()
    {
        Schema::table('humlnetcreative_pages_builder_pages', function($table) {
            $table->unsignedBigInteger('published_revision_id')->nullable()->index()->after('meta_description');
            $table->string('published_fullslug', 255)->nullable()->index()->after('published_revision_id');
            $table->unsignedBigInteger('published_parent_id')->nullable()->index()->after('published_fullslug');
            $table->unsignedInteger('published_sort_order')->default(0)->after('published_parent_id');
            $table->boolean('published_is_published')->default(false)->after('published_sort_order');
            $table->boolean('has_draft')->default(true)->after('published_is_published');
            $table->unsignedBigInteger('draft_version')->default(1)->after('has_draft');
            $table->timestamp('draft_started_at')->nullable()->after('draft_version');
            $table->timestamp('published_at')->nullable()->after('draft_started_at');
            $table->unique(['site_id', 'published_fullslug'], 'hucr_pages_builder_published_path_unique');
        });

        Schema::create('humlnetcreative_pages_revisions', function($table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('page_id')->index();
            $table->uuid('page_uuid')->index();
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('schema_version');
            $table->longText('snapshot');
            $table->unsignedBigInteger('published_by')->nullable()->index();
            $table->timestamps();
            $table->unique(['page_id', 'version'], 'hucr_pages_revision_page_version_unique');
        });

        Schema::create('humlnetcreative_pages_revision_media', function($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('revision_id')->index();
            $table->unsignedBigInteger('media_asset_id')->nullable()->index();
            $table->uuid('media_asset_uuid')->index();
            $table->uuid('media_use_uuid')->index();
            $table->json('variants')->nullable();
            $table->timestamps();
            $table->unique(['revision_id', 'media_use_uuid'], 'hucr_pages_revision_media_use_unique');
        });

        Schema::create('humlnetcreative_pages_edit_locks', function($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('page_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->uuid('editor_session_uuid')->index();
            $table->timestamp('acquired_at');
            $table->timestamp('heartbeat_at')->index();
            $table->timestamps();
        });

        Schema::create('humlnetcreative_pages_audit_logs', function($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('page_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->uuid('editor_session_uuid')->nullable()->index();
            $table->string('action', 100)->index();
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->uuid('subject_uuid')->nullable()->index();
            $table->string('aggregate_key', 255)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('humlnetcreative_pages_audit_logs');
        Schema::dropIfExists('humlnetcreative_pages_edit_locks');
        Schema::dropIfExists('humlnetcreative_pages_revision_media');
        Schema::dropIfExists('humlnetcreative_pages_revisions');

        Schema::table('humlnetcreative_pages_builder_pages', function($table) {
            $table->dropUnique('hucr_pages_builder_published_path_unique');
            $table->dropIndex(['published_revision_id']);
            $table->dropIndex(['published_fullslug']);
            $table->dropIndex(['published_parent_id']);
            $table->dropColumn([
                'published_revision_id', 'published_fullslug', 'published_parent_id',
                'published_sort_order', 'published_is_published', 'has_draft',
                'draft_version', 'draft_started_at', 'published_at',
            ]);
        });
    }
}
