<?php namespace HumlnetCreative\Pages\Services;

use Backend\Models\User;
use Carbon\Carbon;
use HumlnetCreative\Pages\Classes\Revision\PageLockState;
use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\PageEditLock;
use Illuminate\Support\Facades\DB;

final class PageEditLockService
{
    public const TIMEOUT_MINUTES = 15;

    public function acquire(BuilderPage $page, User $user, string $sessionId): PageLockState
    {
        return DB::transaction(function() use ($page, $user, $sessionId): PageLockState {
            $lock = PageEditLock::where('page_id', $page->id)->lockForUpdate()->first();
            if ($lock && $this->isExpired($lock)) {
                $lock->delete();
                $lock = null;
            }

            if (!$lock) {
                $lock = PageEditLock::create([
                    'page_id' => $page->id,
                    'user_id' => $user->id,
                    'editor_session_uuid' => $sessionId,
                    'acquired_at' => now(),
                    'heartbeat_at' => now(),
                ]);

                return new PageLockState($lock, true);
            }

            $writable = (int) $lock->user_id === (int) $user->id
                && hash_equals((string) $lock->editor_session_uuid, $sessionId);
            if ($writable) {
                $lock->heartbeat_at = now();
                $lock->save();
            }

            return new PageLockState($lock, $writable);
        });
    }

    public function assertWritable(BuilderPage $page, User $user, string $sessionId): void
    {
        $state = $this->acquire($page, $user, $sessionId);
        if (!$state->writable) {
            $owner = $state->lock->user?->full_name ?: $state->lock->user?->login ?: 'jiný uživatel';
            throw new \ApplicationException("Stránku právě upravuje {$owner}. Otevřela se pouze pro čtení.");
        }
    }

    public function heartbeat(BuilderPage $page, User $user, string $sessionId): PageLockState
    {
        return $this->acquire($page, $user, $sessionId);
    }

    public function takeover(BuilderPage $page, User $user, string $sessionId): PageEditLock
    {
        return DB::transaction(function() use ($page, $user, $sessionId): PageEditLock {
            $lock = PageEditLock::where('page_id', $page->id)->lockForUpdate()->first();
            $previousUserId = $lock?->user_id;
            $lock ??= new PageEditLock();
            $lock->fill([
                'page_id' => $page->id,
                'user_id' => $user->id,
                'editor_session_uuid' => $sessionId,
                'acquired_at' => now(),
                'heartbeat_at' => now(),
            ]);
            $lock->save();

            app(PageAuditService::class)->record($page->id, 'lock.taken_over', $page, [
                'previous_user_id' => $previousUserId,
            ]);

            return $lock;
        });
    }

    public function release(BuilderPage $page, User $user, string $sessionId): bool
    {
        return PageEditLock::where('page_id', $page->id)
            ->where('user_id', $user->id)
            ->where('editor_session_uuid', $sessionId)
            ->delete() > 0;
    }

    public function isExpired(PageEditLock $lock): bool
    {
        return Carbon::parse($lock->heartbeat_at)->lt(now()->subMinutes(self::TIMEOUT_MINUTES));
    }
}
