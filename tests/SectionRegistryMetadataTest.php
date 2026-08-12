<?php namespace HumlnetCreative\Pages\Tests;

use HumlnetCreative\Pages\Services\SectionRegistry;
use PluginTestCase;

final class SectionRegistryMetadataTest extends PluginTestCase
{
    public function testCoreTypesExposeCanvasAndColumnMetadata(): void
    {
        $registry = new SectionRegistry();

        $this->assertFalse($registry->allowedInColumns('hero'));
        $this->assertFalse($registry->allowedInColumns('carousel'));
        $this->assertSame(1, $registry->minimumWidthUnits('text'));
        $this->assertSame(2, $registry->minimumWidthUnits('image_text'));
        $this->assertTrue($registry->supportsFillHeight('cta'));
        $this->assertSame('media', $registry->category('gallery'));
        $this->assertSame('gallery.images', $registry->wireframe('gallery')['item_count']);
    }
}
