<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Classes\Snapshot\PageSnapshot;
use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\MediaAsset;
use HumlnetCreative\Pages\Models\PageRevision;
use HumlnetCreative\Pages\Models\PageRevisionMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use HumlnetCreative\Pages\Contracts\RedirectManagerInterface;
use HumlnetCreative\Pages\Classes\Publication\PublicationUrlChange;

final class PagePublicationService
{
    public const RETAINED_REVISIONS = 10;

    public function __construct(
        private readonly PageSnapshotSerializer $serializer,
        private readonly PageSnapshotHydrator $hydrator,
        private readonly PagePublicationPreflight $preflight,
        private readonly RedirectManagerInterface $redirects,
    ) {
    }

    public function publish(
        BuilderPage $page,
        ?int $userId = null,
        bool $replaceManualRedirects = false,
    ): PageRevision
    {
        [$revisions, $releasedReferences, $pageIds] = DraftStateService::withoutTracking(function() use ($page, $userId, $replaceManualRedirects): array {
            return DB::transaction(function() use ($page, $userId, $replaceManualRedirects): array {
                /** @var BuilderPage $lockedPage */
                $lockedPage = BuilderPage::withoutGlobalScopes()->lockForUpdate()->findOrFail($page->id);
                $plan = $this->preflight->assertBranchPublishable($lockedPage, $replaceManualRedirects);
                $pageIds = array_map(fn(PublicationUrlChange $change) => $change->pageId, $plan->changes);
                $lockedPages = BuilderPage::withoutGlobalScopes()
                    ->whereIn('id', $pageIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // Re-run against the locked state so a concurrent publication cannot
                // invalidate the plan between the read-only preflight and its writes.
                $plan = $this->preflight->assertBranchPublishable(
                    $lockedPages->get($lockedPage->id),
                    $replaceManualRedirects,
                );
                $lockedPageIds = $lockedPages->keys()->map(fn($id) => (int) $id)->all();
                $recheckedPageIds = array_map(fn(PublicationUrlChange $change) => $change->pageId, $plan->changes);
                if (array_diff($recheckedPageIds, $lockedPageIds)) {
                    throw new \ValidationException([
                        'publication' => 'Struktura větve se během publikace změnila. Obnovte stránku a publikaci zopakujte.',
                    ]);
                }
                $changingPublishedIds = collect($plan->changes)
                    ->filter(fn(PublicationUrlChange $change) => $change->needsRedirect())
                    ->map(fn(PublicationUrlChange $change) => $change->pageId)
                    ->all();
                if ($changingPublishedIds) {
                    DB::table($lockedPage->getTable())
                        ->whereIn('id', $changingPublishedIds)
                        ->update(['published_fullslug' => null]);
                }

                $revisions = [];
                $releasedReferences = [];
                foreach ($plan->changes as $change) {
                    /** @var BuilderPage $branchPage */
                    $branchPage = $lockedPages->get($change->pageId);
                    if ((string) $branchPage->fullslug !== $change->newFullslug) {
                        DB::table($branchPage->getTable())->where('id', $branchPage->id)->update([
                            'fullslug' => $change->newFullslug,
                            'updated_at' => now(),
                        ]);
                        $branchPage->fullslug = $change->newFullslug;
                    }
                    $this->assertPublishedParent($branchPage, $pageIds);
                    [$revision, $released] = $this->publishLockedPage($branchPage, $userId);
                    $revisions[$branchPage->id] = $revision;
                    $releasedReferences = array_merge($releasedReferences, $released);

                    if ($change->retiresUrl()) {
                        $this->retirePublishedPage($branchPage, $change, $replaceManualRedirects);
                    }
                    else {
                        if ($change->reactivatesUrl) {
                            $this->redirects->removeOwned($change->oldPath ?? $change->newPath, $change->context);
                        }
                        if ($change->needsRedirect()) {
                            $this->redirects->putExactPermanent(
                                $change->oldPath,
                                $change->newPath,
                                $change->context,
                                $replaceManualRedirects,
                            );
                        }
                    }
                }

                return [$revisions, $releasedReferences, $pageIds];
            });
        });

        // Filesystem cleanup is deliberately outside the DB transaction. A rollback can
        // therefore never remove a file still referenced by a retained revision.
        app(MediaReferenceService::class)->cleanupReleasedReferences($releasedReferences);
        foreach ($pageIds as $pageId) {
            app(PageCommandHistory::class)->clear($pageId);
        }

        return $revisions[$page->id];
    }

    private function assertPublishedParent(BuilderPage $page, array $branchPageIds): void
    {
        if (!$page->parent_id || in_array((int) $page->parent_id, $branchPageIds, true)) {
            return;
        }

        $parent = BuilderPage::withoutGlobalScopes()->find($page->parent_id);
        if (!$parent?->published_revision_id || !$parent->published_is_published) {
            throw new \ValidationException(['parent' => 'Nadřazená stránka musí být nejprve publikovaná.']);
        }
    }

    private function retirePublishedPage(
        BuilderPage $page,
        PublicationUrlChange $change,
        bool $replaceManualRedirects,
    ): void
    {
        if ($change->deletionMode === 'gone') {
            $this->redirects->putGone($change->oldPath, $change->context, $replaceManualRedirects);
        }
        else {
            $this->redirects->putExactPermanent(
                $change->oldPath,
                $change->deletionTargetPath,
                $change->context,
                $replaceManualRedirects,
            );
        }

        DB::table($page->getTable())->where('id', $page->id)->update([
            'published_is_published' => false,
            'has_draft' => false,
            'draft_started_at' => null,
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('humlnetcreative_pages_edit_locks')->where('page_id', $page->id)->delete();
        app(PageAuditService::class)->record($page->id, 'page.deleted.published', $page, [
            'mode' => $change->deletionMode,
            'source' => $change->oldPath,
            'target' => $change->deletionTargetPath,
        ]);
    }

    /** @return array{PageRevision, array} */
    private function publishLockedPage(BuilderPage $page, ?int $userId): array
    {
        $page->unsetRelation('parent');
        $snapshot = $this->serializer->fromPage($page);
        $version = ((int) PageRevision::where('page_id', $page->id)->max('version')) + 1;
        $revision = PageRevision::create([
            'uuid' => (string) Str::uuid(),
            'page_id' => $page->id,
            'page_uuid' => $page->uuid,
            'version' => $version,
            'schema_version' => $snapshot->schemaVersion,
            'snapshot' => $snapshot->toArray(),
            'published_by' => $userId,
        ]);
        $this->storeMediaReferences($revision, $snapshot);

        DB::table($page->getTable())->where('id', $page->id)->update([
            'published_revision_id' => $revision->id,
            'published_fullslug' => $snapshot->page['fullslug'],
            'published_parent_id' => data_get($snapshot->page, 'parent.id'),
            'published_sort_order' => $snapshot->page['sort_order'],
            'published_is_published' => $snapshot->page['is_published'],
            'has_draft' => false,
            'draft_started_at' => null,
            'published_at' => now(),
            'updated_at' => now(),
        ]);

        $releasedReferences = $this->pruneHistory($page->id, $revision->id);
        app(PageAuditService::class)->record($page->id, 'draft.published', $page, [
            'revision_id' => $revision->id,
            'version' => $version,
            'path' => $snapshot->page['fullslug'],
        ]);

        return [$revision, $releasedReferences];
    }

    public function seedInitialSnapshots(): int
    {
        $count = 0;
        BuilderPage::withoutGlobalScopes()
            ->whereNull('published_revision_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(50, function($pages) use (&$count): void {
                foreach ($pages as $page) {
                    $this->publish($page);
                    $count++;
                }
            });

        return $count;
    }

    public function findPublishedByPath(string $path): ?BuilderPage
    {
        $path = trim($path, '/');
        $query = BuilderPage::query()
            ->where('published_is_published', true)
            ->whereNotNull('published_revision_id');
        $record = $path === ''
            ? $query->where('published_fullslug', '')->first()
            : $query->where('published_fullslug', $path)->first();

        return $record ? $this->hydratePublished($record) : null;
    }

    public function findPublishedById(int $pageId, array $seen = []): ?BuilderPage
    {
        if (isset($seen[$pageId])) {
            return null;
        }
        $record = BuilderPage::withoutGlobalScopes()
            ->where('published_is_published', true)
            ->whereNotNull('published_revision_id')
            ->find($pageId);

        return $record ? $this->hydratePublished($record, $seen) : null;
    }

    public function hydratePublished(BuilderPage $projection, array $seen = []): BuilderPage
    {
        $revision = PageRevision::find($projection->published_revision_id);
        if (!$revision) {
            throw new \UnexpectedValueException("Stránka {$projection->id} odkazuje na chybějící publikovaný snapshot.");
        }

        $snapshot = PageSnapshot::fromArray((array) $revision->snapshot);
        $page = $this->hydrator->toPage($snapshot, (int) $projection->id);
        $page->published_revision_id = $revision->id;
        $page->published_at = $projection->published_at;
        $page->setRelation('published_revision', $revision);

        app(PageStructureService::class)->prepare($page);

        $seen[(int) $projection->id] = true;
        $parent = $projection->published_parent_id
            ? $this->findPublishedById((int) $projection->published_parent_id, $seen)
            : null;
        $page->setRelation('parent', $parent);

        return $page;
    }

    private function storeMediaReferences(PageRevision $revision, PageSnapshot $snapshot): void
    {
        $uses = [];
        foreach ($snapshot->sections as $section) {
            $uses = array_merge($uses, $section['media'] ?? []);
            foreach ($section['items'] ?? [] as $item) {
                $uses = array_merge($uses, $item['media'] ?? []);
            }
        }

        $assetIds = MediaAsset::whereIn('uuid', collect($uses)->pluck('asset_uuid')->unique()->all())
            ->pluck('id', 'uuid');
        foreach ($uses as $use) {
            PageRevisionMedia::create([
                'revision_id' => $revision->id,
                'media_asset_id' => $assetIds[$use['asset_uuid']] ?? null,
                'media_asset_uuid' => $use['asset_uuid'],
                'media_use_uuid' => $use['uuid'],
                'variants' => $use['variants'] ?? [],
            ]);
        }
    }

    private function pruneHistory(int $pageId, int $currentRevisionId): array
    {
        $obsolete = PageRevision::where('page_id', $pageId)
            ->where('id', '<>', $currentRevisionId)
            ->orderByDesc('version')
            ->get()
            ->slice(self::RETAINED_REVISIONS - 1);
        $released = [];
        foreach ($obsolete as $revision) {
            $released = array_merge($released, $revision->media_references()->get()->all());
            $revision->media_references()->delete();
            $revision->delete();
        }

        return $released;
    }
}
