<?php namespace HumlnetCreative\Pages\Tests;

use HumlnetCreative\Pages\Services\SectionRegistry;
use October\Rain\Support\Facades\Event;
use PluginTestCase;

final class SectionRegistryMetadataTest extends PluginTestCase
{
    public function testCoreTypesExposeCanvasAndColumnMetadata(): void
    {
        $registry = new SectionRegistry();

        $this->assertFalse($registry->allowedInColumns('hero'));
        $this->assertFalse($registry->allowedInColumns('carousel'));
        $this->assertFalse($registry->allowedInColumns('columns'));
        $this->assertSame('structure', $registry->category('columns'));
        $this->assertSame(4, $registry->minimumWidthUnits('columns'));
        $this->assertSame(1, $registry->minimumWidthUnits('text'));
        $this->assertSame(2, $registry->minimumWidthUnits('image_text'));
        $this->assertTrue($registry->supportsFillHeight('cta'));
        $this->assertSame('media', $registry->category('gallery'));
        $this->assertSame('gallery.images', $registry->wireframe('gallery')['item_count']);
        $this->assertTrue($registry->supportsMotion('text'));
        $this->assertTrue($registry->supportsMotion('cards'));
        $this->assertTrue($registry->supportsMotionStagger('cards'));
        $this->assertTrue($registry->supportsMotionStagger('gallery'));
        $this->assertFalse($registry->supportsMotionStagger('text'));
        $this->assertFalse($registry->supportsMotion('hero'));
        $this->assertFalse($registry->supportsMotion('carousel'));
        $this->assertFalse($registry->supportsMotion('columns'));
        $this->assertFalse($registry->supportsMotion('embed'));
    }

    public function testProjectPluginCanRegisterASectionDefinition(): void
    {
        Event::listen('humlnetcreative.pages.extendSectionDefinitions', fn(): array => [
            'example_catalog' => [
                'label' => 'Example catalog',
                'permission' => 'example.catalog.manage',
                'items' => false,
                'section_fields' => ['heading', 'catalog_options'],
            ],
        ]);

        $registry = new SectionRegistry();

        $this->assertTrue($registry->has('example_catalog'));
        $this->assertSame(['heading', 'catalog_options'], $registry->sectionFields('example_catalog'));
        $this->assertSame('project', $registry->category('example_catalog'));
    }
}
