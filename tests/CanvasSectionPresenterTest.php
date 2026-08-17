<?php namespace HumlnetCreative\Pages\Tests;

use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use HumlnetCreative\Pages\Models\MediaUse;
use HumlnetCreative\Pages\Services\CanvasSectionPresenter;
use HumlnetCreative\Pages\Services\CanvasSectionChecks;
use PluginTestCase;

class CanvasSectionPresenterTest extends PluginTestCase
{
    public function testItPresentsRegistryDrivenStructuralMetadataWithoutHtml(): void
    {
        $section = new Section([
            'uuid' => 'section-uuid',
            'type' => 'cards',
            'title' => 'Interní karty',
            'is_published' => false,
            'layout' => ['width' => 'wide', 'spacing' => 'large'],
            'style' => ['color_scheme' => 'dark'],
            'content' => [
                'heading' => '<strong>Nadpis karet</strong>',
                'text' => '<p>Text s <em>HTML</em> značkami.</p>',
            ],
        ]);
        $firstItem = new SectionItem(['uuid' => 'item-1', 'content' => ['heading' => 'První karta']]);
        $firstItem->setRelation('media', collect([new MediaUse([
            'slot' => 'card_image',
            'variants' => ['webp' => ['small' => 'canvas/prvni-karta.webp']],
        ])]));
        $secondItem = new SectionItem(['uuid' => 'item-2', 'content' => ['heading' => 'Druhá karta']]);
        $secondItem->setRelation('media', collect());
        $section->setRelation('items', collect([$firstItem, $secondItem]));

        $result = (new CanvasSectionPresenter())->present($section);

        $this->assertSame('section-uuid', $result['uuid']);
        $this->assertSame('Karty', $result['type_label']);
        $this->assertSame('Nadpis karet', $result['heading']);
        $this->assertSame('Nadpis karet', $result['heading_value']);
        $this->assertSame('Text s HTML značkami.', $result['text']);
        $this->assertSame(2, $result['item_count']);
        $this->assertSame(['První karta', 'Druhá karta'], array_column($result['item_previews'], 'label'));
        $this->assertStringContainsString('/storage/app/media/canvas/prvni-karta.webp', $result['item_previews'][0]['image_url']);
        $this->assertNull($result['item_previews'][1]['image_url']);
        $this->assertFalse($result['visible']);
        $this->assertSame('Široká', $result['width']);
        $this->assertSame('Velká mezera', $result['spacing']);
        $this->assertSame('dark', $result['color_scheme']);
        $this->assertArrayHasKey('color_scheme_background', $result);
        $this->assertArrayHasKey('color_scheme_foreground', $result);
    }

    public function testItTranslatesEveryCanvasWidthAndSpacingValue(): void
    {
        $presenter = new CanvasSectionPresenter();
        $widths = [
            'contained' => 'V kontejneru',
            'wide' => 'Široká',
            'full' => 'Přes celou šířku',
        ];
        $spacings = [
            'none' => 'Bez mezery',
            'small' => 'Malá mezera',
            'standard' => 'Standardní mezera',
            'large' => 'Velká mezera',
        ];

        foreach ($widths as $value => $label) {
            $result = $presenter->present(new Section([
                'type' => 'text',
                'layout' => ['width' => $value, 'spacing' => 'standard'],
            ]));
            $this->assertSame($label, $result['width']);
        }
        foreach ($spacings as $value => $label) {
            $result = $presenter->present(new Section([
                'type' => 'text',
                'layout' => ['width' => 'contained', 'spacing' => $value],
            ]));
            $this->assertSame($label, $result['spacing']);
        }
    }

    public function testItPresentsEveryCoreSectionTypeForCanvas(): void
    {
        $types = [
            'hero' => 'Hero',
            'carousel' => 'Prezentace',
            'text' => 'Text',
            'image_text' => 'Text s obrázkem',
            'cards' => 'Karty',
            'cta' => 'CTA / pruh',
            'accordion' => 'FAQ',
            'gallery' => 'Galerie',
            'embed' => 'Vložený obsah',
        ];
        $presenter = new CanvasSectionPresenter();

        foreach ($types as $type => $label) {
            $section = new Section([
                'uuid' => 'section-'.$type,
                'type' => $type,
                'title' => $label,
                'content' => [],
                'layout' => [],
                'style' => [],
            ]);
            $section->setRelation('items', collect());
            $section->setRelation('media', collect());
            $section->setRelation('slider', null);
            $section->setRelation('faq_group', null);
            $section->setRelation('gallery', null);

            $result = $presenter->present($section);

            $this->assertSame($type, $result['type']);
            $this->assertSame($label, $result['type_label']);
            $this->assertSame('section-'.$type, $result['uuid']);
        }
    }

    public function testColumnsDoNotPretendTheirOwnTitleIsASharedSource(): void
    {
        $section = new Section([
            'uuid' => 'section-columns',
            'type' => 'columns',
            'title' => 'Sloupce',
            'content' => ['ratio' => '1:1'],
            'layout' => [],
            'style' => [],
        ]);
        $section->setRelation('items', collect());
        $section->setRelation('media', collect());
        $section->setRelation('zones', collect());

        $result = (new CanvasSectionPresenter())->present($section);

        $this->assertSame('', $result['shared_source']);
        $this->assertSame('1:1', $result['ratio']);
    }

    public function testChecksExposeMissingSharedSourcesAndEmbedAccessibility(): void
    {
        $checks = new CanvasSectionChecks();

        $gallery = new Section(['type' => 'gallery', 'content' => []]);
        $gallery->setRelation('items', collect());
        $gallery->setRelation('media', collect());
        $this->assertContains('Není vybraná zdrojová galerie.', $checks->forSection($gallery));

        $embed = new Section([
            'type' => 'embed',
            'content' => ['embed' => '<iframe src="https://example.test"></iframe>'],
        ]);
        $embed->setRelation('items', collect());
        $embed->setRelation('media', collect());
        $this->assertContains('Vložený iframe musí mít výstižný atribut title.', $checks->forSection($embed));
    }
}
