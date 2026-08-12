<?php namespace HumlnetCreative\Pages\Tests;

use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\MediaAsset;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use HumlnetCreative\Pages\Services\PageSnapshotHydrator;
use HumlnetCreative\Pages\Services\PageSnapshotSerializer;
use PluginTestCase;

final class SnapshotRoundTripTest extends PluginTestCase
{
    public function testSnapshotRoundTripPreservesPageTreeItemsAndMedia(): void
    {
        $page = BuilderPage::create([
            'title' => 'Test snapshotu',
            'slug' => 'test-snapshotu',
            'is_published' => true,
            'sort_order' => 3,
            'style' => ['color_scheme' => 'primary'],
            'meta_title' => 'SEO titulek',
            'meta_description' => 'SEO popis',
        ]);
        $section = Section::create([
            'page_id' => $page->id,
            'type' => 'cards',
            'title' => 'Interní název',
            'is_published' => true,
            'sort_order' => 2,
            'layout' => ['width' => 'wide', 'spacing' => 'small'],
            'style' => ['color_scheme' => 'secondary'],
            'content' => ['heading' => 'Karty', 'columns' => 2],
        ]);
        $item = SectionItem::create([
            'section_id' => $section->id,
            'type' => 'card',
            'is_published' => false,
            'sort_order' => 1,
            'style' => ['color_scheme' => 'tertiary'],
            'content' => ['heading' => 'První karta', 'text' => 'Obsah'],
        ]);
        $asset = MediaAsset::create([
            'site_id' => 1,
            'disk' => 'local',
            'path' => 'pages/tests/master.webp',
            'original_name' => 'master.webp',
            'mime_type' => 'image/webp',
            'size' => 1234,
            'width' => 1200,
            'height' => 900,
        ]);
        $item->media()->create([
            'media_asset_id' => $asset->id,
            'site_id' => 1,
            'slot' => 'card_image',
            'crop' => ['x' => 10, 'y' => 20, 'width' => 800, 'height' => 600],
            'alt_text' => 'Popis obrázku',
            'is_decorative' => false,
            'variants' => ['webp' => ['800' => 'pages-variants/card-800.webp']],
        ]);

        $serializer = new PageSnapshotSerializer();
        $snapshot = $serializer->fromPage($page->fresh());
        $json = $serializer->serialize($snapshot);
        $hydrated = (new PageSnapshotHydrator())->fromJson($json);

        $this->assertSame($json, $serializer->serialize($hydrated));
        $this->assertSame('test-snapshotu', $hydrated->page['fullslug']);
        $this->assertSame('První karta', $hydrated->sections[0]['items'][0]['content']['heading']);
        $this->assertSame($asset->uuid, $hydrated->sections[0]['items'][0]['media'][0]['asset_uuid']);
        $this->assertSame('pages/tests/master.webp', $hydrated->mediaAssets[0]['path']);
    }
}
