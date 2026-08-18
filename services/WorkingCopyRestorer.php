<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Classes\Snapshot\PageSnapshot;
use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\MediaAsset;
use HumlnetCreative\Pages\Models\MediaUse;
use HumlnetCreative\Pages\Models\PageRevision;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use Illuminate\Support\Facades\DB;
use stdClass;

final class WorkingCopyRestorer
{
    public function restoreRevision(PageRevision $revision, bool $asDraft): BuilderPage
    {
        $snapshot = PageSnapshot::fromArray((array) $revision->snapshot);

        $page = DraftStateService::withoutTracking(function() use ($revision, $snapshot, $asDraft): BuilderPage {
            return DB::transaction(function() use ($revision, $snapshot, $asDraft): BuilderPage {
                $page = BuilderPage::withoutGlobalScopes()->lockForUpdate()->findOrFail($revision->page_id);
                $pageData = $snapshot->page;
                $parentId = data_get($pageData, 'parent.id');
                DB::table($page->getTable())->where('id', $page->id)->update([
                    'site_id' => $pageData['site_id'],
                    'site_root_id' => $pageData['site_root_id'],
                    'parent_id' => $parentId,
                    'title' => $pageData['title'],
                    'slug' => $pageData['slug'],
                    'fullslug' => $pageData['fullslug'],
                    'is_home' => $pageData['is_home'],
                    'is_published' => $pageData['is_published'],
                    'sort_order' => $pageData['sort_order'],
                    'style' => $this->json($pageData['style']),
                    'meta_title' => $pageData['meta_title'],
                    'meta_description' => $pageData['meta_description'],
                    'deletion_mode' => null,
                    'deletion_target_page_id' => null,
                    'has_draft' => $asDraft,
                    'draft_version' => DB::raw('draft_version + 1'),
                    'draft_started_at' => $asDraft ? now() : null,
                    'updated_at' => now(),
                ]);

                $containerIds = [];
                foreach ($snapshot->containers as $containerData) {
                    if ($containerData['kind'] === 'root') {
                        $containerIds[$containerData['uuid']] = $this->upsertContainer((int) $page->id, $containerData, null);
                    }
                }

                $orderedSections = collect($snapshot->sections)->sortBy(function(array $section) use ($snapshot): int {
                    $kind = collect($snapshot->containers)->firstWhere('uuid', $section['container_uuid'])['kind'] ?? 'zone';
                    return $kind === 'root' ? 0 : 1;
                })->values();
                $wantedSectionIds = [];
                $deferred = [];
                foreach ($orderedSections as $sectionData) {
                    $containerId = $containerIds[$sectionData['container_uuid']] ?? null;
                    if (!$containerId) {
                        $deferred[] = $sectionData;
                        continue;
                    }
                    $sectionId = $this->upsertSection((int) $page->id, $containerId, $sectionData);
                    $wantedSectionIds[] = $sectionId;
                }

                $wantedContainerIds = array_values($containerIds);
                foreach ($snapshot->containers as $containerData) {
                    if ($containerData['kind'] !== 'zone') {
                        continue;
                    }
                    $parentId = DB::table('humlnetcreative_pages_sections')
                        ->where('page_id', $page->id)
                        ->where('uuid', $containerData['parent_section_uuid'])
                        ->value('id');
                    if (!$parentId) {
                        throw new \UnexpectedValueException('Nelze obnovit zónu bez nadřazených Sloupců.');
                    }
                    $containerIds[$containerData['uuid']] = $this->upsertContainer((int) $page->id, $containerData, (int) $parentId);
                    $wantedContainerIds[] = $containerIds[$containerData['uuid']];
                }

                foreach ($deferred as $sectionData) {
                    $containerId = $containerIds[$sectionData['container_uuid']] ?? null;
                    if (!$containerId) {
                        throw new \UnexpectedValueException('Nelze obnovit sekci bez existujícího kontejneru.');
                    }
                    $wantedSectionIds[] = $this->upsertSection((int) $page->id, $containerId, $sectionData);
                }

                foreach ($snapshot->sections as $sectionData) {
                    $sectionId = (int) DB::table('humlnetcreative_pages_sections')->where('uuid', $sectionData['uuid'])->value('id');
                    $this->syncMedia(Section::class, $sectionId, (array) ($sectionData['media'] ?? []));

                    $wantedItemIds = [];
                    foreach ((array) ($sectionData['items'] ?? []) as $itemData) {
                        $itemId = $this->upsertItem($sectionId, $itemData);
                        $wantedItemIds[] = $itemId;
                        $this->syncMedia(SectionItem::class, $itemId, (array) ($itemData['media'] ?? []));
                    }
                    DB::table('humlnetcreative_pages_section_items')
                        ->where('section_id', $sectionId)
                        ->when($wantedItemIds, fn($query) => $query->whereNotIn('id', $wantedItemIds))
                        ->update(['deleted_at' => now(), 'updated_at' => now()]);
                }

                DB::table('humlnetcreative_pages_sections')
                    ->where('page_id', $page->id)
                    ->when($wantedSectionIds, fn($query) => $query->whereNotIn('id', $wantedSectionIds))
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
                DB::table('humlnetcreative_pages_section_containers')
                    ->where('page_id', $page->id)
                    ->where('kind', 'zone')
                    ->when($wantedContainerIds, fn($query) => $query->whereNotIn('id', $wantedContainerIds))
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);

                app(PageAuditService::class)->record($page->id, $asDraft ? 'history.restored' : 'draft.discarded', $page, [
                    'revision_id' => $revision->id,
                    'version' => $revision->version,
                ]);

                return BuilderPage::withoutGlobalScopes()->findOrFail($page->id);
            });
        });

        app(PageCommandHistory::class)->clear($page->id);

        return $page;
    }

    public function discard(BuilderPage $page): BuilderPage
    {
        if (!$page->published_revision_id) {
            throw new \ApplicationException('Stránka zatím nemá publikovanou verzi. Lze ji pouze odstranit.');
        }

        return $this->restoreRevision(PageRevision::findOrFail($page->published_revision_id), false);
    }

    public function restoreDeleted(BuilderPage $page): BuilderPage
    {
        $restored = DB::transaction(function() use ($page): BuilderPage {
            $deleted = BuilderPage::withoutGlobalScopes()->lockForUpdate()->findOrFail($page->id);
            if (!$deleted->deleted_at || !$deleted->published_revision_id) {
                throw new \ApplicationException('Záznam není publikovaná stránka v koši.');
            }

            DB::table($deleted->getTable())->where('id', $deleted->id)->update([
                'deleted_at' => null,
                'deletion_mode' => null,
                'deletion_target_page_id' => null,
                'updated_at' => now(),
            ]);
            $restored = $this->restoreRevision(PageRevision::findOrFail($deleted->published_revision_id), true);
            app(PageAuditService::class)->record($restored->id, 'trash.restored', $restored, [
                'revision_id' => $deleted->published_revision_id,
            ]);

            return $restored;
        });

        return $restored;
    }

    /** Soft-deletes a never-published page and releases only its truly orphaned media. */
    public function deleteUnpublished(BuilderPage $page): void
    {
        if ($page->published_revision_id) {
            throw new \ApplicationException('Publikovanou stránku nelze odstranit touto operací.');
        }

        $released = DraftStateService::withoutTracking(function() use ($page): array {
            return DB::transaction(function() use ($page): array {
                $locked = BuilderPage::withoutGlobalScopes()->lockForUpdate()->findOrFail($page->id);
                if ($locked->published_revision_id) {
                    throw new \ApplicationException('Stránka už byla publikována a nelze ji přímo odstranit.');
                }

                $sectionIds = DB::table('humlnetcreative_pages_sections')
                    ->where('page_id', $locked->id)
                    ->pluck('id');
                $itemIds = DB::table('humlnetcreative_pages_section_items')
                    ->whereIn('section_id', $sectionIds)
                    ->pluck('id');
                // Resolve UUIDs before removing uses so cleanup can recheck all references after commit.
                $mediaRows = DB::table('humlnetcreative_pages_media_uses')
                    ->where(function($query) use ($sectionIds, $itemIds): void {
                        $query->where(function($sections) use ($sectionIds): void {
                            $sections->where('owner_type', Section::class)->whereIn('owner_id', $sectionIds);
                        })->orWhere(function($items) use ($itemIds): void {
                            $items->where('owner_type', SectionItem::class)->whereIn('owner_id', $itemIds);
                        });
                    })
                    ->get(['id', 'uuid', 'media_asset_id']);
                $assetUuids = MediaAsset::whereIn('id', $mediaRows->pluck('media_asset_id'))->pluck('uuid', 'id');
                $released = $mediaRows->map(function($row) use ($assetUuids): stdClass {
                    return (object) [
                        'media_use_uuid' => $row->uuid,
                        'media_asset_uuid' => $assetUuids[$row->media_asset_id] ?? '',
                    ];
                })->filter(fn(stdClass $row) => $row->media_asset_uuid !== '')->all();

                DB::table('humlnetcreative_pages_media_uses')->whereIn('id', $mediaRows->pluck('id'))->delete();
                DB::table('humlnetcreative_pages_section_items')->whereIn('id', $itemIds)->update(['deleted_at' => now(), 'updated_at' => now()]);
                DB::table('humlnetcreative_pages_sections')->whereIn('id', $sectionIds)->update(['deleted_at' => now(), 'updated_at' => now()]);
                DB::table('humlnetcreative_pages_section_containers')->where('page_id', $locked->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
                DB::table('humlnetcreative_pages_builder_pages')->where('id', $locked->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
                DB::table('humlnetcreative_pages_edit_locks')->where('page_id', $locked->id)->delete();
                app(PageAuditService::class)->record($locked->id, 'draft.deleted', $locked);

                return $released;
            });
        });

        app(MediaReferenceService::class)->cleanupReleasedReferences($released);
        app(PageCommandHistory::class)->clear($page->id);
    }

    private function upsertContainer(int $pageId, array $data, ?int $parentSectionId): int
    {
        $existing = DB::table('humlnetcreative_pages_section_containers')->where('uuid', $data['uuid'])->first();
        if (!$existing && $data['kind'] === 'root') {
            // Version 1 snapshots receive a deterministic virtual root UUID during upgrade;
            // restore them into the page's already existing physical root.
            $existing = DB::table('humlnetcreative_pages_section_containers')
                ->where('page_id', $pageId)->where('kind', 'root')->whereNull('deleted_at')->first();
        }
        $values = [
            'page_id' => $pageId,
            'parent_section_id' => $parentSectionId,
            'kind' => $data['kind'],
            'title' => $data['title'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'width_units' => (int) ($data['width_units'] ?? 1),
            'vertical_align' => $data['vertical_align'] ?? 'top',
            'block_spacing' => $data['block_spacing'] ?? 'standard',
            'style' => $this->json((array) ($data['style'] ?? [])),
            'deleted_at' => null,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('humlnetcreative_pages_section_containers')->where('id', $existing->id)->update($values);
            return (int) $existing->id;
        }

        return (int) DB::table('humlnetcreative_pages_section_containers')->insertGetId($values + [
            'uuid' => $data['uuid'],
            'created_at' => now(),
        ]);
    }

    private function upsertSection(int $pageId, int $containerId, array $data): int
    {
        $existing = DB::table('humlnetcreative_pages_sections')->where('uuid', $data['uuid'])->first();
        $values = [
            'page_id' => $pageId,
            'container_id' => $containerId,
            'type' => $data['type'],
            'slider_id' => data_get($data, 'shared.slider.id'),
            'faq_group_id' => data_get($data, 'shared.faq_group.id'),
            'gallery_id' => data_get($data, 'shared.gallery.id'),
            'title' => $data['title'],
            'is_published' => $data['is_published'],
            'sort_order' => $data['sort_order'],
            'layout' => $this->json($data['layout']),
            'style' => $this->json($data['style']),
            'content' => $this->json($data['content']),
            'deleted_at' => null,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('humlnetcreative_pages_sections')->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        return (int) DB::table('humlnetcreative_pages_sections')->insertGetId($values + [
            'uuid' => $data['uuid'],
            'created_at' => now(),
        ]);
    }

    private function upsertItem(int $sectionId, array $data): int
    {
        $existing = DB::table('humlnetcreative_pages_section_items')->where('uuid', $data['uuid'])->first();
        $values = [
            'section_id' => $sectionId,
            'type' => $data['type'],
            'is_published' => $data['is_published'],
            'sort_order' => $data['sort_order'],
            'style' => $this->json($data['style']),
            'content' => $this->json($data['content']),
            'deleted_at' => null,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('humlnetcreative_pages_section_items')->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        return (int) DB::table('humlnetcreative_pages_section_items')->insertGetId($values + [
            'uuid' => $data['uuid'],
            'created_at' => now(),
        ]);
    }

    private function syncMedia(string $ownerType, int $ownerId, array $uses): void
    {
        $assetIds = MediaAsset::whereIn('uuid', collect($uses)->pluck('asset_uuid')->all())->pluck('id', 'uuid');
        $wantedUseIds = [];
        foreach ($uses as $data) {
            $assetId = $assetIds[$data['asset_uuid']] ?? null;
            if (!$assetId) {
                throw new \UnexpectedValueException("Nelze obnovit chybějící mediální asset {$data['asset_uuid']}.");
            }
            $existing = DB::table('humlnetcreative_pages_media_uses')->where('uuid', $data['uuid'])->first();
            $values = [
                'media_asset_id' => $assetId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'site_id' => $data['site_id'],
                'slot' => $data['slot'],
                'crop' => $this->json($data['crop']),
                'alt_text' => $data['alt_text'],
                'is_decorative' => $data['is_decorative'],
                'variants' => $this->json($data['variants']),
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('humlnetcreative_pages_media_uses')->where('id', $existing->id)->update($values);
                $wantedUseIds[] = (int) $existing->id;
            }
            else {
                $wantedUseIds[] = (int) DB::table('humlnetcreative_pages_media_uses')->insertGetId($values + [
                    'uuid' => $data['uuid'],
                    'created_at' => now(),
                ]);
            }
        }

        $extras = MediaUse::where('owner_type', $ownerType)->where('owner_id', $ownerId)
            ->when($wantedUseIds, fn($query) => $query->whereNotIn('id', $wantedUseIds))
            ->get();
        foreach ($extras as $extra) {
            $extra->delete();
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
