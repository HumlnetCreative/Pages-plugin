<?php namespace HumlnetCreative\Pages\Updates;

use DB;
use Schema;
use October\Rain\Database\Updates\Migration;

class AdoptHumlnetcreativeNamespace extends Migration
{
    public function up()
    {
        $this->replaceAttachmentTypes('LZaplata\\Pages\\', 'HumlnetCreative\\Pages\\');
        $this->replacePermissions('lzaplata.pages', 'humlnetcreative.pages');
    }

    public function down()
    {
        $this->replaceAttachmentTypes('HumlnetCreative\\Pages\\', 'LZaplata\\Pages\\');
        $this->replacePermissions('humlnetcreative.pages', 'lzaplata.pages');
    }

    protected function replaceAttachmentTypes(string $from, string $to): void
    {
        if (!Schema::hasTable('system_files')) {
            return;
        }

        DB::table('system_files')
            ->where('attachment_type', 'like', strtok($from, '\\').'%')
            ->orderBy('id')
            ->each(function($file) use ($from, $to): void {
                if (!str_starts_with($file->attachment_type, $from)) {
                    return;
                }

                DB::table('system_files')->where('id', $file->id)->update([
                    'attachment_type' => str_replace($from, $to, $file->attachment_type),
                ]);
            });
    }

    protected function replacePermissions(string $from, string $to): void
    {
        foreach (['backend_users', 'backend_user_groups', 'backend_user_roles'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'permissions')) {
                continue;
            }

            DB::table($table)
                ->where('permissions', 'like', '%'.$from.'%')
                ->orderBy('id')
                ->each(function($record) use ($table, $from, $to): void {
                    DB::table($table)->where('id', $record->id)->update([
                        'permissions' => str_replace($from, $to, $record->permissions),
                    ]);
                });
        }
    }
}
