<?php namespace HumlnetCreative\Pages\Tests;

use HumlnetCreative\Pages\Services\CountUpMarkup;
use PluginTestCase;

final class CountUpMarkupTest extends PluginTestCase
{
    /** @dataProvider localizedNumbers */
    public function testItAcceptsLocalizedExactNumbers(string $number, float $value, int $decimals): void
    {
        $parsed = CountUpMarkup::parseNumber($number);

        $this->assertNotNull($parsed);
        $this->assertSame($value, $parsed['value']);
        $this->assertSame($decimals, $parsed['decimals']);
    }

    public static function localizedNumbers(): array
    {
        return [
            ['1125', 1125.0, 0],
            ['1 125', 1125.0, 0],
            ["1\u{00A0}125,50", 1125.5, 2],
            ["−15,7", -15.7, 1],
        ];
    }

    public function testItCanonicalizesOnlyExactSafeMarkers(): void
    {
        $html = '<p>Až <span class="legacy" data-hucr-count-up data-hucr-count-up-start="10" data-hucr-count-up-end="1 125">1 125</span> km</p>';

        $this->assertSame(
            '<p>Až <span data-hucr-count-up data-hucr-count-up-start="10" data-hucr-count-up-end="1125">1 125</span> km</p>',
            CountUpMarkup::normalizeRichText($html),
        );
        $this->assertSame(['1 125'], CountUpMarkup::values($html));
    }

    public function testInvalidOrNestedMarkersBecomePlainText(): void
    {
        $this->assertSame(
            'Dojezd 1 125 km a hodnota abc',
            CountUpMarkup::normalizeInline('Dojezd <span data-hucr-count-up><b>1 125</b></span> km a hodnota <span data-hucr-count-up>abc</span>'),
        );
        $this->assertNull(CountUpMarkup::parseNumber('675 / 1 535'));
        $this->assertNull(CountUpMarkup::parseNumber('Až 1125 km'));
    }

    public function testInlineNormalizationRemovesUnrelatedHtmlAndKeepsMarkers(): void
    {
        $this->assertSame(
            'Až <span data-hucr-count-up>1 125</span> km',
            CountUpMarkup::normalizeInline('<strong>Až</strong> <span data-hucr-count-up>1 125</span> <em>km</em>'),
        );
    }
}
