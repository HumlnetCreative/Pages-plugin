<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Classes\Snapshot\PageSnapshot;
use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\MediaAsset;
use HumlnetCreative\Pages\Models\PageRevision;
use HumlnetCreative\Pages\Models\PageRevisionMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PagePublicationService
{
    public const RETAINED_REVISIONS = 10;

    public function __construct(
        private readonly PageSnapshotSerializer $serializer,
        private readonly PageSnapshotHydrator $hydrator,
    ) {
    }

    public function publish(BuilderPage $page, ?int $userId = null): PageRevision
    {
        [$revision, $releasedReferences] = DraftStateService::withoutTracking(function() use ($page, $userId): array {
            return DB::transaction(function() use ($page, $userId): array {
                /** @var BuilderPage $lockedPage */
                $lockedPage = BuilderPage::withoutGlobalScopes()->lockForUpdate()->findOrFail($page->id);
                if ($lockedPage->parent_id) {
                    $parent = BuilderPage::withoutGlobalScopes()->find($lockedPage->parent_id);
                    if (!$parent?->published_revision_id || !$parent->published_is_published) {
                        throw new \ValidationException(['parent' => 'Nadřazená stránka musí být nejprve publikovaná.']);
                    }
                }

                $snapshot = $this->serializer->fromPage($lockedPage);
                $version = ((int) PageRevision::where('page_id', $lockedPage->id)->max('version')) + 1;
                $revision = PageRevision::create([
                    'uuid' => (string) Str::uuid(),
                    'page_id' => $lockedPage->id,
                    'page_uuid' => $lockedPage->uuid,
                    'version' => $version,
                    'schema_version' => $snapshot->schemaVersion,
                    'snapshot' => $snapshot->toArray(),
                    'published_by' => $userId,
                ]);
                $this->storeMediaReferences($revision, $snapshot);

                DB::table($lockedPage->getTable())->where('id', $lockedPage->id)->update([
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

                $releasedReferences = $this->pruneHistory($lockedPage->id, $revision->id);
                app(PageAuditService::class)->record($lockedPage->id, 'draft.published', $lockedPage, [
                    'revision_id' => $revision->id,
                    'version' => $version,
                    'path' => $snapshot->page['fullslug'],
                ]);

                return [$revision, $releasedReferences];
            });
        });

        // Filesystem cleanup is deliberately outside the DB transaction. A rollback can
        // therefore never remove a file still referenced by a retained revision.
        app(MediaReferenceService::class)->cleanupReleasedReferences($releasedReferences);
        app(PageCommandHistory::class)->clear($page->id);

        return $revision;
    }

    public function seedInitialSnapshots(): int
    {
        $count = 0;
        BuilderPage::withoutGlobalScopes()
            ->whereNull('published_revision_id')
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
