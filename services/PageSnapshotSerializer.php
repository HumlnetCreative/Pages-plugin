<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Classes\Snapshot\PageSnapshot;
use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\MediaAsset;
use HumlnetCreative\Pages\Models\MediaUse;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use JsonException;
use October\Rain\Database\Model;

/** Builds deterministic snapshots from the normalized working tables. */
final class PageSnapshotSerializer
{
    public function fromPage(BuilderPage $page): PageSnapshot
    {
        $page->loadMissing('parent');
        $assets = [];
        $containerModels = $page->section_containers()
            ->with('columns_section')
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $containers = $containerModels->map(fn($container): array => [
            'uuid' => (string) $container->uuid,
            'kind' => (string) $container->kind,
            'parent_section_uuid' => $container->columns_section?->uuid,
            'title' => $container->title,
            'sort_order' => (int) $container->sort_order,
            'width_units' => (int) $container->width_units,
            'vertical_align' => (string) $container->vertical_align,
            'block_spacing' => (string) $container->block_spacing,
            'style' => $container->style ?: [],
        ])->all();
        $sectionModels = $page->sections()
            ->with(['container', 'items.media.asset', 'media.asset', 'slider', 'faq_group', 'gallery'])
            ->orderBy('container_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $sections = [];
        foreach ($sectionModels as $section) {
            $sections[] = $this->section($section, $assets);
        }

        ksort($assets, SORT_STRING);

        return new PageSnapshot(
            PageSnapshot::SCHEMA_VERSION,
            [
                'uuid' => (string) $page->uuid,
                'site_id' => $this->nullableInt($page->site_id),
                'site_root_id' => $this->nullableInt($page->site_root_id),
                'parent' => $this->reference($page->parent, $page->parent_id),
                'title' => (string) $page->title,
                'slug' => (string) $page->slug,
                'fullslug' => (string) $page->fullslug,
                'is_home' => (bool) $page->is_home,
                'is_published' => (bool) $page->is_published,
                'sort_order' => (int) $page->sort_order,
                'style' => $page->style ?: [],
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
            ],
            $containers,
            $sections,
            array_values($assets),
        );
    }

    /** @throws JsonException */
    public function serialize(PageSnapshot $snapshot): string
    {
        return json_encode(
            $this->canonicalize($snapshot->toArray()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function section(Section $section, array &$assets): array
    {
        return [
            'uuid' => (string) $section->uuid,
            'container_uuid' => (string) $section->container?->uuid,
            'type' => (string) $section->type,
            'shared' => [
                'slider' => $this->reference($section->slider, $section->slider_id),
                'faq_group' => $this->reference($section->faq_group, $section->faq_group_id),
                'gallery' => $this->reference($section->gallery, $section->gallery_id),
            ],
            'title' => $section->title,
            'is_published' => (bool) $section->is_published,
            'sort_order' => (int) $section->sort_order,
            'layout' => $section->layout ?: [],
            'style' => $section->style ?: [],
            'content' => $section->content ?: [],
            'media' => $section->media
                ->sortBy([['slot', 'asc'], ['id', 'asc']])
                ->map(function(MediaUse $use) use (&$assets): array {
                    return $this->mediaUse($use, $assets);
                })
                ->values()
                ->all(),
            'items' => $section->items
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->map(function(SectionItem $item) use (&$assets): array {
                    return $this->item($item, $assets);
                })
                ->values()
                ->all(),
        ];
    }

    private function item(SectionItem $item, array &$assets): array
    {
        return [
            'uuid' => (string) $item->uuid,
            'type' => (string) $item->type,
            'is_published' => (bool) $item->is_published,
            'sort_order' => (int) $item->sort_order,
            'style' => $item->style ?: [],
            'content' => $item->content ?: [],
            'media' => $item->media
                ->sortBy([['slot', 'asc'], ['id', 'asc']])
                ->map(function(MediaUse $use) use (&$assets): array {
                    return $this->mediaUse($use, $assets);
                })
                ->values()
                ->all(),
        ];
    }

    private function mediaUse(MediaUse $use, array &$assets): array
    {
        $asset = $use->asset;
        if (!$asset instanceof MediaAsset) {
            throw new \UnexpectedValueException("Médium {$use->uuid} nemá existující asset.");
        }

        $assets[$asset->uuid] ??= [
            'uuid' => (string) $asset->uuid,
            'site_id' => $this->nullableInt($asset->site_id),
            'disk' => (string) $asset->disk,
            'path' => (string) $asset->path,
            'original_name' => (string) $asset->original_name,
            'mime_type' => (string) $asset->mime_type,
            'size' => (int) $asset->size,
            'width' => $this->nullableInt($asset->width),
            'height' => $this->nullableInt($asset->height),
            'duration_ms' => $this->nullableInt($asset->duration_ms),
        ];

        return [
            'uuid' => (string) $use->uuid,
            'asset_uuid' => (string) $asset->uuid,
            'site_id' => $this->nullableInt($use->site_id),
            'slot' => (string) $use->slot,
            'crop' => $use->crop ?: [],
            'alt_text' => $use->alt_text,
            'is_decorative' => (bool) $use->is_decorative,
            'variants' => $use->variants ?: [],
        ];
    }

    private function reference(?Model $model, mixed $fallbackId = null): ?array
    {
        if (!$model) {
            return $fallbackId === null ? null : ['id' => $fallbackId];
        }

        $reference = ['id' => $model->getKey()];
        foreach (['uuid', 'site_root_id', 'slug'] as $attribute) {
            if ($model->getAttribute($attribute) !== null) {
                $reference[$attribute] = $model->getAttribute($attribute);
            }
        }

        return $reference;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function canonicalize(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
