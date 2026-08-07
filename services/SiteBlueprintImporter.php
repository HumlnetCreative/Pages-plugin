<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use October\Rain\Parse\Yaml;
use Site;
use Tailor\Models\EntryRecord;

class SiteBlueprintImporter
{
    public function preview(string $path): array
    {
        $data = (new Yaml)->parseFile($path);
        $this->validate($data);
        return $data;
    }

    public function import(string $path): void
    {
        $this->importData($this->preview($path));
    }

    public function importData(array $data): void
    {
        $this->validate($data);
        if (BuilderPage::withoutGlobalScopes()->exists()) {
            throw new \ApplicationException('Import kostry funguje jen do čistého nového builderu.');
        }
        DB::transaction(function() use ($data) {
            foreach (($data['sites'] ?? [null]) as $siteId) {
                $create = function() use ($data, $siteId) {
                    $sliders = $this->createSliders($data['sliders'] ?? [], $siteId);
                    foreach ($data['pages'] as $pageData) {
                        $this->createPage($pageData, $siteId, null, $sliders);
                    }
                };
                $siteId === null ? $create() : Site::withContext($siteId, $create);
            }
        });
    }

    protected function createPage(array $data, ?int $siteId, ?BuilderPage $parent, array $sliders): BuilderPage
    {
        $page = BuilderPage::create([
            'uuid' => (string) Str::uuid(), 'site_id' => $siteId, 'parent_id' => $parent?->id,
            'title' => $data['title'], 'slug' => $data['slug'] ?? '', 'is_home' => (bool) ($data['home'] ?? false),
            'is_published' => (bool) ($data['published'] ?? true), 'sort_order' => (int) ($data['sort_order'] ?? 0),
            'style' => $data['style'] ?? [],
            'meta_title' => $data['meta_title'] ?? null, 'meta_description' => $data['meta_description'] ?? null,
        ]);
        foreach (($data['sections'] ?? []) as $index => $sectionData) {
            $defaults = SectionRegistry::instance()->defaults($sectionData['type']);
            $section = Section::create([
                'uuid' => (string) Str::uuid(), 'page_id' => $page->id, 'type' => $sectionData['type'],
                'slider_id' => isset($sectionData['slider']) ? ($sliders[$sectionData['slider']]->id ?? null) : null,
                'title' => $sectionData['title'] ?? null,
                'is_published' => (bool) ($sectionData['published'] ?? true), 'sort_order' => $index,
                'layout' => array_replace_recursive($defaults['layout'], $sectionData['layout'] ?? []),
                'style' => array_replace_recursive($defaults['style'], $sectionData['style'] ?? []),
                'content' => array_replace_recursive($defaults['content'], $sectionData['content'] ?? []),
            ]);
            foreach (($sectionData['items'] ?? []) as $itemIndex => $itemData) {
                SectionItem::create([
                    'uuid' => (string) Str::uuid(), 'section_id' => $section->id,
                    'is_published' => (bool) ($itemData['published'] ?? true), 'sort_order' => $itemIndex,
                    'content' => $itemData['content'] ?? [], 'style' => $itemData['style'] ?? [],
                ]);
            }
        }
        foreach (($data['children'] ?? []) as $child) { $this->createPage($child, $siteId, $page, $sliders); }
        return $page;
    }

    protected function createSliders(array $definitions, ?int $siteId): array
    {
        $result = [];
        foreach ($definitions as $definition) {
            $slider = EntryRecord::inSection('Slider\\Slider');
            $slider->site_id = $siteId;
            $slider->title = $definition['title'];
            $slider->slug = Str::slug($definition['ref']).'-'.Str::lower(Str::random(6));
            $slider->image_lg_width = (int) data_get($definition, 'dimensions.desktop.width', 1920);
            $slider->image_lg_height = (int) data_get($definition, 'dimensions.desktop.height', 550);
            $slider->image_sm_width = (int) data_get($definition, 'dimensions.mobile.width', 640);
            $slider->image_sm_height = (int) data_get($definition, 'dimensions.mobile.height', 640);
            $slider->save();

            foreach (($definition['slides'] ?? []) as $index => $slideData) {
                $slide = EntryRecord::inSection('Slider\\Slide');
                $slide->site_id = $siteId;
                $slide->title = $slideData['title'] ?? 'Položka '.($index + 1);
                $slide->slug = Str::slug($definition['ref'].'-'.$slide->title).'-'.Str::lower(Str::random(6));
                $slide->type = $slideData['type'] ?? 'image';
                $slide->title_type = $slideData['title_type'] ?? null;
                $slide->title_custom = $slideData['title_custom'] ?? null;
                $slide->text = $slideData['text'] ?? null;
                $slide->link = $slideData['link'] ?? null;
                $slide->link_type = $slideData['link_type'] ?? null;
                $slide->btn = $slideData['button'] ?? null;
                // A generated skeleton has no media; publication is enabled only after media validation in the editor.
                $slide->is_enabled = false;
                $slide->save();
                $slider->slides()->attach($slide);
            }
            $result[$definition['ref']] = $slider;
        }
        return $result;
    }

    protected function validate(array $data): void
    {
        if (($data['version'] ?? null) !== 1 || empty($data['pages']) || !is_array($data['pages'])) {
            throw new \ApplicationException('Manifest musí obsahovat version: 1 a seznam pages.');
        }
        if (!array_is_list($data['pages'])) {
            throw new \ApplicationException('Hodnota pages musí být YAML seznam.');
        }

        $sites = $data['sites'] ?? [null];
        if (!is_array($sites) || !array_is_list($sites) || !$sites) {
            throw new \ApplicationException('Hodnota sites musí být neprázdný YAML seznam.');
        }
        $knownSiteIds = array_map('intval', Site::listSiteIds());
        foreach ($sites as $siteId) {
            if ($siteId !== null && (!is_numeric($siteId) || !in_array((int) $siteId, $knownSiteIds, true))) {
                throw new \ApplicationException("Manifest odkazuje na neexistující site ID: {$siteId}.");
            }
        }

        $registry = SectionRegistry::instance();
        $sliderDefinitions = $data['sliders'] ?? [];
        if (!is_array($sliderDefinitions) || !array_is_list($sliderDefinitions)) {
            throw new \ApplicationException('Hodnota sliders musí být YAML seznam.');
        }
        $sliderRefs = [];
        foreach ($sliderDefinitions as $slider) {
            if (!is_array($slider) || empty(trim((string) ($slider['ref'] ?? ''))) || empty(trim((string) ($slider['title'] ?? '')))) {
                throw new \ApplicationException('Každý Slider musí mít neprázdné ref a title.');
            }
            if (isset($sliderRefs[$slider['ref']])) {
                throw new \ApplicationException('Duplicitní ref Slideru: '.$slider['ref'].'.');
            }
            $sliderRefs[$slider['ref']] = true;
            foreach (['desktop', 'mobile'] as $viewport) {
                foreach (['width', 'height'] as $dimension) {
                    $value = data_get($slider, "dimensions.{$viewport}.{$dimension}");
                    if ($value !== null && (!is_numeric($value) || (int) $value < 1)) {
                        throw new \ApplicationException("Rozměr {$viewport}.{$dimension} Slideru {$slider['ref']} musí být kladné číslo.");
                    }
                }
            }
            $slides = $slider['slides'] ?? [];
            if (!is_array($slides) || !array_is_list($slides)) {
                throw new \ApplicationException("Položky Slideru {$slider['ref']} musí být YAML seznam.");
            }
            foreach ($slides as $slide) {
                if (!is_array($slide) || !in_array($slide['type'] ?? 'image', ['image', 'video'], true)) {
                    throw new \ApplicationException("Slider {$slider['ref']} obsahuje neplatný typ položky.");
                }
            }
        }
        $homeCount = 0;
        $validatePages = function(array $pages, bool $isRoot = true) use (&$validatePages, &$homeCount, $registry, $sliderRefs): void {
            foreach ($pages as $pageIndex => $page) {
                if (!is_array($page) || empty(trim((string) ($page['title'] ?? '')))) {
                    throw new \ApplicationException('Každá stránka manifestu musí mít neprázdný title.');
                }
                if (isset($page['published']) && !is_bool($page['published'])) {
                    throw new \ApplicationException('Hodnota published stránky musí být true nebo false.');
                }
                if (!empty($page['home'])) {
                    if (!$isRoot) {
                        throw new \ApplicationException('Úvodní stránka nesmí být v children.');
                    }
                    $homeCount++;
                }
                $sections = $page['sections'] ?? [];
                if (!is_array($sections) || !array_is_list($sections)) {
                    throw new \ApplicationException('Hodnota sections musí být YAML seznam.');
                }
                foreach ($sections as $section) {
                    if (!is_array($section) || empty($section['type']) || !$registry->has($section['type'])) {
                        $type = is_array($section) ? ($section['type'] ?? '(chybí)') : '(neplatná hodnota)';
                        throw new \ApplicationException("Manifest obsahuje neznámý typ sekce: {$type}.");
                    }
                    if (isset($section['published']) && !is_bool($section['published'])) {
                        throw new \ApplicationException("Hodnota published sekce {$section['type']} musí být true nebo false.");
                    }
                    if ($section['type'] === 'carousel' && (empty($section['slider']) || !isset($sliderRefs[$section['slider']]))) {
                        throw new \ApplicationException('Každá sekce carousel musí odkazovat na existující Slider pomocí slider: ref.');
                    }
                    $items = $section['items'] ?? [];
                    if (!is_array($items) || !array_is_list($items)) {
                        throw new \ApplicationException("Hodnota items sekce {$section['type']} musí být YAML seznam.");
                    }
                    if ($items && !$registry->itemsSupported($section['type'])) {
                        throw new \ApplicationException("Sekce {$section['type']} nepodporuje položky items.");
                    }
                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            throw new \ApplicationException("Každá položka sekce {$section['type']} musí být mapování.");
                        }
                        if (isset($item['published']) && !is_bool($item['published'])) {
                            throw new \ApplicationException("Hodnota published položky sekce {$section['type']} musí být true nebo false.");
                        }
                        foreach (['style', 'content'] as $mapping) {
                            if (isset($item[$mapping]) && !is_array($item[$mapping])) {
                                throw new \ApplicationException("{$mapping} položky sekce {$section['type']} musí být mapování.");
                            }
                        }
                    }
                    foreach (['layout', 'style', 'content'] as $mapping) {
                        if (isset($section[$mapping]) && !is_array($section[$mapping])) {
                            throw new \ApplicationException("{$mapping} sekce {$section['type']} musí být mapování.");
                        }
                    }
                }
                if (!empty($page['children'])) {
                    if (!is_array($page['children']) || !array_is_list($page['children'])) {
                        throw new \ApplicationException('Hodnota children musí být YAML seznam.');
                    }
                    $validatePages($page['children'], false);
                }
            }
        };
        $validatePages($data['pages']);
        if ($homeCount !== 1) {
            throw new \ApplicationException('Manifest musí obsahovat právě jednu kořenovou stránku s home: true.');
        }
    }
}
