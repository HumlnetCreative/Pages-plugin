<?php namespace HumlnetCreative\Pages\Updates;

use DB;
use Schema;
use October\Rain\Database\Updates\Migration;

class AdoptHumlnetcreativeNamespace extends Migration
{
    public function up()
    {
        $this->replaceAttachmentTypes();
        $this->replacePermissions();
    }

    protected function replaceAttachmentTypes(): void
    {
        if (!Schema::hasTable('system_files')) {
            return;
        }

        DB::table('system_files')
            ->where('attachment_type', 'like', 'LZaplata%')
            ->orderBy('id')
            ->each(function($file): void {
                if (!str_starts_with($file->attachment_type, 'LZaplata\\Pages\\')) {
                    return;
                }

                DB::table('system_files')->where('id', $file->id)->update([
                    'attachment_type' => str_replace('LZaplata\\Pages\\', 'HumlnetCreative\\Pages\\', $file->attachment_type),
                ]);
            });
    }

    protected function replacePermissions(): void
    {
        foreach (['backend_users', 'backend_user_groups', 'backend_user_roles'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'permissions')) {
                continue;
            }

            DB::table($table)
                ->where('permissions', 'like', '%lzaplata.pages%')
                ->orderBy('id')
                ->each(function($record) use ($table): void {
                    DB::table($table)->where('id', $record->id)->update([
                        'permissions' => str_replace('lzaplata.pages', 'humlnetcreative.pages', $record->permissions),
                    ]);
                });
        }
    }
}
