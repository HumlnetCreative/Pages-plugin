<?php namespace HumlnetCreative\Pages\Tests;

use HumlnetCreative\Pages\Components\BuilderPage;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use PluginTestCase;
use ReflectionClass;

final class BuilderPageMotionAssetsTest extends PluginTestCase
{
    public function testOnlyRenderableCountUpRequestsMotionAssets(): void
    {
        $first = $this->countUpSection('<span data-hucr-count-up>10</span>');
        $plainSecond = $this->countUpSection('20');
        $markedSecond = $this->countUpSection('<span data-hucr-count-up>20</span>');

        $this->assertFalse($this->hasRenderableMotion([$first]));
        $this->assertFalse($this->hasRenderableMotion([$first, $plainSecond]));
        $this->assertTrue($this->hasRenderableMotion([$first, $markedSecond]));
    }

    public function testRevealStillRequestsAssetsWithoutCountUpMarkers(): void
    {
        $first = $this->countUpSection('10');
        $second = $this->countUpSection('20');
        $second->style = ['motion' => ['effect' => 'fade']];

        $this->assertTrue($this->hasRenderableMotion([$first, $second]));
    }

    private function countUpSection(string $heading): Section
    {
        $section = new Section([
            'type' => 'cards',
            'style' => ['motion' => ['count_up' => ['enabled' => true]]],
            'content' => ['heading' => $heading],
        ]);
        $section->setRelation('items', collect([new SectionItem(['content' => []])]));
        $section->setRelation('zones', collect());

        return $section;
    }

    private function hasRenderableMotion(array $sections): bool
    {
        $reflection = new ReflectionClass(BuilderPage::class);
        $component = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('hasRenderableMotion');

        return $method->invoke($component, $sections, true);
    }
}
