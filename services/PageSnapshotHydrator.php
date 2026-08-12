<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Classes\Snapshot\PageSnapshot;
use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\FaqGroupEntry;
use HumlnetCreative\Pages\Models\MediaAsset;
use HumlnetCreative\Pages\Models\MediaUse;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use HumlnetCreative\Pages\Models\SliderEntry;
use Illuminate\Support\Collection;
use JsonException;
use UnexpectedValueException;

/** Rehydrates a validated in-memory DTO; DB restoration belongs to the revision layer. */
final class PageSnapshotHydrator
{
    /** @throws JsonException */
    public function fromJson(string $json): PageSnapshot
    {
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new UnexpectedValueException('Snapshot musí být JSON objekt.');
        }

        return PageSnapshot::fromArray($payload);
    }

    public function fromArray(array $payload): PageSnapshot
    {
        return PageSnapshot::fromArray($payload);
    }

    /** Builds a read-only model graph compatible with the existing theme renderer. */
    public function toPage(PageSnapshot $snapshot, ?int $pageId = null): BuilderPage
    {
        $assets = [];
        foreach ($snapshot->mediaAssets as $assetData) {
            $asset = new MediaAsset();
            $asset->setRawAttributes($assetData, true);
            $asset->exists = true;
            $assets[$assetData['uuid']] = $asset;
        }

        $sections = [];
        foreach ($snapshot->sections as $sectionData) {
            $section = new Section();
            $shared = (array) ($sectionData['shared'] ?? []);
            $section->setRawAttributes([
                'uuid' => $sectionData['uuid'],
                'type' => $sectionData['type'],
                'slider_id' => data_get($shared, 'slider.id'),
                'faq_group_id' => data_get($shared, 'faq_group.id'),
                'gallery_id' => data_get($shared, 'gallery.id'),
                'title' => $sectionData['title'] ?? null,
                'is_published' => (bool) ($sectionData['is_published'] ?? false),
                'sort_order' => (int) ($sectionData['sort_order'] ?? 0),
                'layout' => $this->json($sectionData['layout'] ?? []),
                'style' => $this->json($sectionData['style'] ?? []),
                'content' => $this->json($sectionData['content'] ?? []),
            ], true);
            $section->exists = true;
            $section->setRelation('media', $this->mediaUses((array) ($sectionData['media'] ?? []), $assets));

            $items = [];
            foreach ((array) ($sectionData['items'] ?? []) as $itemData) {
                $item = new SectionItem();
                $item->setRawAttributes([
                    'uuid' => $itemData['uuid'],
                    'type' => $itemData['type'],
                    'is_published' => (bool) ($itemData['is_published'] ?? false),
                    'sort_order' => (int) ($itemData['sort_order'] ?? 0),
                    'style' => $this->json($itemData['style'] ?? []),
                    'content' => $this->json($itemData['content'] ?? []),
                ], true);
                $item->exists = true;
                $item->setRelation('section', $section);
                $item->setRelation('media', $this->mediaUses((array) ($itemData['media'] ?? []), $assets));
                $items[] = $item;
            }
            $section->setRelation('items', new Collection($items));
            $this->hydrateSharedRelations($section);
            $sections[] = $section;
        }

        $pageData = $snapshot->page;
        $page = new BuilderPage();
        $page->setRawAttributes([
            'id' => $pageId,
            'uuid' => $pageData['uuid'],
            'site_id' => $pageData['site_id'] ?? null,
            'site_root_id' => $pageData['site_root_id'] ?? null,
            'title' => $pageData['title'],
            'slug' => $pageData['slug'],
            'fullslug' => $pageData['fullslug'],
            'is_home' => (bool) $pageData['is_home'],
            'is_published' => (bool) $pageData['is_published'],
            'sort_order' => (int) $pageData['sort_order'],
            'style' => $this->json($pageData['style'] ?? []),
            'meta_title' => $pageData['meta_title'] ?? null,
            'meta_description' => $pageData['meta_description'] ?? null,
        ], true);
        $page->exists = true;
        $page->setRelation('sections', new Collection($sections));
        foreach ($sections as $section) {
            $section->setRelation('page', $page);
        }

        return $page;
    }

    private function mediaUses(array $uses, array $assets): Collection
    {
        return new Collection(array_map(function(array $useData) use ($assets): MediaUse {
            $assetUuid = $useData['asset_uuid'];
            if (!isset($assets[$assetUuid])) {
                throw new UnexpectedValueException("Snapshot odkazuje na chybějící asset {$assetUuid}.");
            }
            $use = new MediaUse();
            $use->setRawAttributes([
                'uuid' => $useData['uuid'],
                'site_id' => $useData['site_id'] ?? null,
                'slot' => $useData['slot'],
                'crop' => $this->json($useData['crop'] ?? []),
                'alt_text' => $useData['alt_text'] ?? null,
                'is_decorative' => (bool) ($useData['is_decorative'] ?? false),
                'variants' => $this->json($useData['variants'] ?? []),
            ], true);
            $use->exists = true;
            $use->setRelation('asset', $assets[$assetUuid]);

            return $use;
        }, $uses));
    }

    private function hydrateSharedRelations(Section $section): void
    {
        $slider = $section->slider_id ? SliderEntry::find($section->slider_id) : null;
        $faq = $section->faq_group_id ? FaqGroupEntry::with('questions')->find($section->faq_group_id) : null;
        $gallery = null;
        if ($section->gallery_id && class_exists(\LZaplata\Gallery\Models\Gallery::class)) {
            $gallery = \LZaplata\Gallery\Models\Gallery::with('images')->find($section->gallery_id);
        }
        $section->setRelation('slider', $slider);
        $section->setRelation('faq_group', $faq);
        $section->setRelation('gallery', $gallery);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
