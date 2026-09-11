<?php namespace HumlnetCreative\Pages\Tests;

use HumlnetCreative\Pages\Services\SectionMotion;
use PluginTestCase;

final class SectionMotionTest extends PluginTestCase
{
    public function testEmptyConfigurationIsAStableNoOp(): void
    {
        $this->assertSame([
            'effect' => 'none',
            'duration' => 'normal',
            'duration_ms' => 500,
            'delay_ms' => 0,
            'stagger_items' => false,
        ], SectionMotion::normalize('text', []));
    }

    public function testSupportedMotionIsNormalizedForPresentation(): void
    {
        $this->assertSame([
            'effect' => 'fade-up',
            'duration' => 'slow',
            'duration_ms' => 700,
            'delay_ms' => 200,
            'stagger_items' => true,
        ], SectionMotion::normalize('cards', [
            'effect' => 'fade-up',
            'duration' => 'slow',
            'delay_ms' => '200',
            'stagger_items' => '1',
        ]));
    }

    /** @dataProvider invalidConfigurations */
    public function testInvalidOrUnsupportedConfigurationIsRejected(string $type, array $motion): void
    {
        $this->expectException(\ValidationException::class);
        SectionMotion::normalize($type, $motion);
    }

    public static function invalidConfigurations(): array
    {
        return [
            'unsupported section' => ['hero', ['effect' => 'fade']],
            'unknown effect' => ['text', ['effect' => 'spin']],
            'unknown duration' => ['text', ['effect' => 'fade', 'duration' => 'instant']],
            'unbounded delay' => ['text', ['effect' => 'fade', 'delay_ms' => 900]],
            'stagger on scalar section' => ['text', ['effect' => 'fade', 'stagger_items' => true]],
            'unknown option' => ['text', ['effect' => 'fade', 'repeat' => true]],
        ];
    }
}
