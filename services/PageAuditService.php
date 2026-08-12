<?php namespace HumlnetCreative\Pages\Services;

use Backend\Facades\BackendAuth;
use HumlnetCreative\Pages\Models\PageAuditLog;
use Illuminate\Support\Facades\Schema;

final class PageAuditService
{
    public function record(
        ?int $pageId,
        string $action,
        mixed $subject = null,
        array $payload = [],
        ?string $aggregateKey = null,
    ): ?PageAuditLog {
        if (!Schema::hasTable('humlnetcreative_pages_audit_logs')) {
            return null;
        }

        $user = BackendAuth::getUser();
        $sessionId = app()->runningInConsole() ? null : app(EditorSessionService::class)->id();
        $attributes = [
            'page_id' => $pageId,
            'user_id' => $user?->id,
            'editor_session_uuid' => $sessionId,
            'action' => $action,
            'subject_type' => is_object($subject) ? $subject::class : null,
            'subject_id' => is_object($subject) && method_exists($subject, 'getKey') ? $subject->getKey() : null,
            'subject_uuid' => is_object($subject) ? ($subject->uuid ?? null) : null,
            'aggregate_key' => $aggregateKey,
            'payload' => $payload,
        ];

        if ($aggregateKey && $sessionId) {
            $existing = PageAuditLog::where('page_id', $pageId)
                ->where('editor_session_uuid', $sessionId)
                ->where('aggregate_key', $aggregateKey)
                ->first();
            if ($existing) {
                $existing->payload = array_replace_recursive($existing->payload ?: [], $payload);
                $existing->save();

                return $existing;
            }
        }

        return PageAuditLog::create($attributes);
    }
}
