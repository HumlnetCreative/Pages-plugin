<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionContainer;
use Illuminate\Support\Collection;

/** Connects the flat normalized records into the one-level Columns graph used by renderers. */
final class PageStructureService
{
    public function prepare(BuilderPage $page, bool $visibleOnly = true): Collection
    {
        $containers = collect($page->section_containers)->filter(fn($container) => !$container->trashed())->values();
        $sections = collect($page->sections);
        if ($visibleOnly) {
            $sections = $sections->filter(fn(Section $section): bool => (bool) $section->is_published);
        }

        $columnsVisibility = $sections->where('type', 'columns')->mapWithKeys(fn(Section $section) => [$section->uuid => true]);
        if ($visibleOnly) {
            $sections = $sections->filter(function(Section $section) use ($columnsVisibility): bool {
                $container = $section->container;
                if (!$container || $container->kind === SectionContainer::KIND_ROOT) {
                    return true;
                }

                return isset($columnsVisibility[$container->columns_section?->uuid]);
            });
        }

        foreach ($sections as $section) {
            if ($visibleOnly) {
                $section->setRelation('items', collect($section->items)
                    ->filter(fn($item) => (bool) $item->is_published)
                    ->sortBy('sort_order')->values());
            }
            $zones = $containers->filter(fn(SectionContainer $container): bool =>
                $container->kind === SectionContainer::KIND_ZONE && $this->belongsToColumns($container, $section)
            )->sortBy('sort_order')->values();
            $section->setRelation('zones', $zones);
        }
        foreach ($containers as $container) {
            $container->setRelation('sections', $sections->filter(
                fn(Section $section): bool => $this->belongsToContainer($section, $container)
            )->sortBy('sort_order')->values());
        }

        $page->setRelation('sections', $sections->sortBy(
            fn(Section $section): string => (string) $section->container?->uuid.'/'.str_pad((string) $section->sort_order, 10, '0', STR_PAD_LEFT)
        )->values());
        $page->setRelation('section_containers', $containers);
        $root = $containers->firstWhere('kind', SectionContainer::KIND_ROOT);

        return $root?->sections ?? collect();
    }

    private function belongsToContainer(Section $section, SectionContainer $container): bool
    {
        if ($section->container_id && $container->id) {
            return (int) $section->container_id === (int) $container->id;
        }

        return $section->container?->uuid === $container->uuid;
    }

    private function belongsToColumns(SectionContainer $container, Section $columns): bool
    {
        if ($container->parent_section_id && $columns->id) {
            return (int) $container->parent_section_id === (int) $columns->id;
        }

        return $container->columns_section?->uuid === $columns->uuid;
    }
}
