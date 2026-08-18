<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\PageRevision;
use HumlnetCreative\Pages\Classes\Snapshot\PageSnapshot;
use Illuminate\Support\Facades\DB;

final class PageDeletionService
{
    public function __construct(private readonly PageSnapshotSerializer $serializer)
    {
    }

    public function propose(BuilderPage $page, string $mode, ?int $targetPageId = null): BuilderPage
    {
        if (!$page->published_revision_id) {
            throw new \ApplicationException('Nepublikovanou stránku odstraňte přímo; návrh odstranění není potřeba.');
        }
        if (!in_array($mode, ['gone', 'parent', 'page'], true)) {
            throw new \ValidationException(['deletion_mode' => 'Vyberte způsob vyřízení původní URL.']);
        }
        if ($mode === 'page' && !$targetPageId) {
            throw new \ValidationException(['deletion_target_page_id' => 'Vyberte cílovou stránku přesměrování.']);
        }

        return DB::transaction(function() use ($page, $mode, $targetPageId): BuilderPage {
            $locked = BuilderPage::withoutGlobalScopes()->lockForUpdate()->findOrFail($page->id);
            DB::table($locked->getTable())->where('id', $locked->id)->update([
                'deletion_mode' => $mode,
                'deletion_target_page_id' => $mode === 'page' ? $targetPageId : null,
                'has_draft' => true,
                'draft_version' => DB::raw('draft_version + 1'),
                'draft_started_at' => DB::raw('COALESCE(draft_started_at, CURRENT_TIMESTAMP)'),
                'updated_at' => now(),
            ]);
            app(PageAuditService::class)->record($locked->id, 'deletion.proposed', $locked, [
                'mode' => $mode,
                'target_page_id' => $mode === 'page' ? $targetPageId : null,
            ]);

            return BuilderPage::withoutGlobalScopes()->findOrFail($locked->id);
        });
    }

    public function cancel(BuilderPage $page): BuilderPage
    {
        return DB::transaction(function() use ($page): BuilderPage {
            $locked = BuilderPage::withoutGlobalScopes()->lockForUpdate()->findOrFail($page->id);
            DB::table($locked->getTable())->where('id', $locked->id)->update([
                'deletion_mode' => null,
                'deletion_target_page_id' => null,
                'draft_version' => DB::raw('draft_version + 1'),
                'updated_at' => now(),
            ]);
            $restored = BuilderPage::withoutGlobalScopes()->findOrFail($locked->id);
            $publishedRevision = PageRevision::find($restored->published_revision_id);
            if ($publishedRevision) {
                $working = $this->serializer->serialize($this->serializer->fromPage($restored));
                $published = $this->serializer->serialize(PageSnapshot::fromArray((array) $publishedRevision->snapshot));
                if (hash_equals($published, $working)) {
                    DB::table($locked->getTable())->where('id', $locked->id)->update([
                        'has_draft' => false,
                        'draft_started_at' => null,
                    ]);
                }
            }
            app(PageAuditService::class)->record($locked->id, 'deletion.cancelled', $locked);

            return BuilderPage::withoutGlobalScopes()->findOrFail($locked->id);
        });
    }
}
