<?php namespace HumlnetCreative\Pages\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddPageDeletionProposals extends Migration
{
    public function up()
    {
        $tableName = 'humlnetcreative_pages_builder_pages';
        if (!Schema::hasColumn($tableName, 'deletion_mode')) {
            Schema::table($tableName, function($table) {
                $table->string('deletion_mode', 20)->nullable()->after('published_at');
            });
        }
        if (!Schema::hasColumn($tableName, 'deletion_target_page_id')) {
            Schema::table($tableName, function($table) {
                $table->unsignedBigInteger('deletion_target_page_id')->nullable()->after('deletion_mode');
            });
        }
        if (!Schema::hasIndex($tableName, ['deletion_mode'])) {
            Schema::table($tableName, fn($table) => $table->index('deletion_mode', 'hucr_pages_deletion_mode_idx'));
        }
        if (!Schema::hasIndex($tableName, ['deletion_target_page_id'])) {
            Schema::table($tableName, fn($table) => $table->index('deletion_target_page_id', 'hucr_pages_deletion_target_idx'));
        }
    }

    public function down()
    {
        Schema::table('humlnetcreative_pages_builder_pages', function($table) {
            $table->dropColumn(['deletion_mode', 'deletion_target_page_id']);
        });
    }
}
