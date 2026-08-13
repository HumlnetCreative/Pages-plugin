<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Classes\Commands\PageCommand;

final class PageCommandHistory
{
    public const MAX_ENTRIES = 100;

    public function push(int $pageId, PageCommand $undo, PageCommand $redo, array $meta = []): void
    {
        $undoStack = $this->stack($pageId, 'undo');
        $entry = ['undo' => $undo->toArray(), 'redo' => $redo->toArray(), 'meta' => $meta];
        $lastIndex = array_key_last($undoStack);
        $canCoalesce = $lastIndex !== null
            && !$this->canRedo($pageId)
            && ($meta['coalesce_key'] ?? null)
            && data_get($undoStack[$lastIndex], 'meta.coalesce_key') === $meta['coalesce_key'];
        if ($canCoalesce) {
            $previousRedo = (array) data_get($undoStack[$lastIndex], 'redo');
            $nextRedo = $redo->toArray();
            if (isset($previousRedo['payload']['changes'], $nextRedo['payload']['changes'])) {
                $nextRedo['payload']['changes'] = array_replace(
                    (array) $previousRedo['payload']['changes'],
                    (array) $nextRedo['payload']['changes'],
                );
            }
            $undoStack[$lastIndex]['redo'] = $nextRedo;
            $undoStack[$lastIndex]['meta'] = $meta;
        }
        else {
            $undoStack[] = $entry;
        }
        session()->put($this->key($pageId, 'undo'), array_slice($undoStack, -self::MAX_ENTRIES));
        session()->forget($this->key($pageId, 'redo'));
        $this->setHeadVersion($pageId, $undo->expectedDraftVersion);
    }

    public function popUndo(int $pageId): ?array
    {
        return $this->pop($pageId, 'undo');
    }

    public function popRedo(int $pageId): ?array
    {
        return $this->pop($pageId, 'redo');
    }

    public function pushRedo(int $pageId, array $entry, ?int $headVersion = null): void
    {
        $stack = $this->stack($pageId, 'redo');
        $stack[] = $entry;
        session()->put($this->key($pageId, 'redo'), array_slice($stack, -self::MAX_ENTRIES));
        if ($headVersion !== null) {
            $this->setHeadVersion($pageId, $headVersion);
        }
    }

    public function pushUndoEntry(int $pageId, array $entry, ?int $headVersion = null): void
    {
        $stack = $this->stack($pageId, 'undo');
        $stack[] = $entry;
        session()->put($this->key($pageId, 'undo'), array_slice($stack, -self::MAX_ENTRIES));
        if ($headVersion !== null) {
            $this->setHeadVersion($pageId, $headVersion);
        }
    }

    public function clear(int $pageId): void
    {
        session()->forget([
            $this->key($pageId, 'undo'),
            $this->key($pageId, 'redo'),
            $this->key($pageId, 'head_version'),
        ]);
    }

    /** Keeps valid history over reloads, but drops it after a real outside mutation. */
    public function reconcile(int $pageId, int $draftVersion): bool
    {
        if (!$this->canUndo($pageId) && !$this->canRedo($pageId)) {
            $this->setHeadVersion($pageId, $draftVersion);
            return true;
        }

        $headVersion = session()->get($this->key($pageId, 'head_version'));
        if (!is_numeric($headVersion) || (int) $headVersion !== $draftVersion) {
            $this->clear($pageId);
            $this->setHeadVersion($pageId, $draftVersion);
            return false;
        }

        return true;
    }

    /** Records a known in-session non-command save without throwing away command history. */
    public function synchronize(int $pageId, int $draftVersion): void
    {
        $this->setHeadVersion($pageId, $draftVersion);
    }

    public function canUndo(int $pageId): bool
    {
        return $this->stack($pageId, 'undo') !== [];
    }

    public function canRedo(int $pageId): bool
    {
        return $this->stack($pageId, 'redo') !== [];
    }

    /** Returns a stable chronological timeline and the cursor between applied and undone steps. */
    public function timeline(int $pageId): array
    {
        $undo = $this->stack($pageId, 'undo');
        $redo = array_reverse($this->stack($pageId, 'redo'));
        $entries = array_values(array_map(function(array $entry, int $index) use ($undo): array {
            return [
                'position' => $index + 1,
                'applied' => $index < count($undo),
                'label' => (string) data_get($entry, 'meta.label', 'Změna stránky'),
                'time' => (string) data_get($entry, 'meta.time', ''),
                'user' => (string) data_get($entry, 'meta.user', ''),
            ];
        }, array_merge($undo, $redo), array_keys(array_merge($undo, $redo))));

        return [
            'position' => count($undo),
            'total' => count($entries),
            'entries' => $entries,
        ];
    }

    private function pop(int $pageId, string $direction): ?array
    {
        $stack = $this->stack($pageId, $direction);
        $entry = array_pop($stack);
        session()->put($this->key($pageId, $direction), $stack);

        return $entry;
    }

    private function stack(int $pageId, string $direction): array
    {
        return (array) session()->get($this->key($pageId, $direction), []);
    }

    private function key(int $pageId, string $direction): string
    {
        return 'humlnetcreative.pages.commands.'.app(EditorSessionService::class)->id().'.'.$pageId.'.'.$direction;
    }

    private function setHeadVersion(int $pageId, int $draftVersion): void
    {
        session()->put($this->key($pageId, 'head_version'), $draftVersion);
    }
}
