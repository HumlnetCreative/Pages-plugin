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
            'count_up' => ['enabled' => false, 'duration' => 'normal', 'duration_ms' => 2000],
            'has_reveal' => false,
            'has_count_up' => false,
            'is_active' => false,
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
            'count_up' => ['enabled' => false, 'duration' => 'normal', 'duration_ms' => 2000],
            'has_reveal' => true,
            'has_count_up' => false,
            'is_active' => true,
        ], SectionMotion::normalize('cards', [
            'effect' => 'fade-up',
            'duration' => 'slow',
            'delay_ms' => '200',
            'stagger_items' => '1',
        ]));
    }

    /** @dataProvider countUpDurations */
    public function testCountUpIsIndependentOfReveal(string $duration, int $milliseconds): void
    {
        $motion = SectionMotion::normalize('cards', [
            'effect' => 'none',
            'count_up' => ['enabled' => true, 'duration' => $duration],
        ]);

        $this->assertFalse($motion['has_reveal']);
        $this->assertTrue($motion['has_count_up']);
        $this->assertTrue($motion['is_active']);
        $this->assertSame($duration, $motion['count_up']['duration']);
        $this->assertSame($milliseconds, $motion['count_up']['duration_ms']);
    }

    public static function countUpDurations(): array
    {
        return [['fast', 1200], ['normal', 2000], ['slow', 4000]];
    }

    public function testDisabledCountUpNormalizesToNoOp(): void
    {
        $motion = SectionMotion::normalize('cards', [
            'count_up' => ['enabled' => false, 'duration' => 'slow'],
        ]);

        $this->assertFalse($motion['is_active']);
        $this->assertSame(['enabled' => false, 'duration' => 'normal', 'duration_ms' => 2000], $motion['count_up']);
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
            'count-up on unsupported section' => ['text', ['count_up' => ['enabled' => true]]],
            'invalid count-up speed' => ['cards', ['count_up' => ['enabled' => true, 'duration' => 'instant']]],
            'invalid count-up flag' => ['cards', ['count_up' => ['enabled' => 'yes']]],
            'unknown count-up option' => ['cards', ['count_up' => ['enabled' => true, 'repeat' => true]]],
        ];
    }
}
