<?php namespace HumlnetCreative\Pages\Services;

use Backend\Facades\BackendAuth;
use HumlnetCreative\Pages\Models\BuilderPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DraftStateService
{
    private static int $suppression = 0;

    public static function withoutTracking(callable $callback): mixed
    {
        self::$suppression++;
        try {
            return $callback();
        }
        finally {
            self::$suppression--;
        }
    }

    public function touch(
        int $pageId,
        string $action = 'draft.changed',
        mixed $subject = null,
        array $payload = [],
    ): void
    {
        if (self::$suppression > 0 || !$pageId || !Schema::hasColumn('humlnetcreative_pages_builder_pages', 'draft_version')) {
            return;
        }

        DB::table('humlnetcreative_pages_builder_pages')->where('id', $pageId)->update([
            'has_draft' => true,
            'draft_version' => DB::raw('draft_version + 1'),
            'draft_started_at' => DB::raw('COALESCE(draft_started_at, CURRENT_TIMESTAMP)'),
            'updated_at' => now(),
        ]);

        if (BackendAuth::getUser()) {
            $aggregate = is_object($subject) && isset($subject->uuid)
                ? 'content:'.$subject->uuid
                : 'page:'.$pageId;
            app(PageAuditService::class)->record($pageId, $action, $subject, $payload, $aggregate);
        }
    }

    public function page(int $pageId): ?BuilderPage
    {
        return BuilderPage::withoutGlobalScopes()->find($pageId);
    }
}
