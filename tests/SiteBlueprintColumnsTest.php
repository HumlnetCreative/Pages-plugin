<?php namespace HumlnetCreative\Pages\Tests;

use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Services\SiteBlueprintImporter;
use PluginTestCase;

final class SiteBlueprintColumnsTest extends PluginTestCase
{
    public function testManifestImportsValidColumnsAndNestedSections(): void
    {
        app(SiteBlueprintImporter::class)->importData([
            'version' => 1,
            'sites' => [null],
            'pages' => [[
                'title' => 'Úvod', 'home' => true,
                'sections' => [[
                    'type' => 'columns',
                    'content' => ['ratio' => '2:1'],
                    'layout' => ['tablet_behavior' => 'stack', 'mobile_order' => 'reverse'],
                    'zones' => [
                        ['sections' => [['type' => 'cards', 'content' => ['heading' => 'Karty']]]],
                        ['style' => ['color_scheme' => 'secondary'], 'sections' => [['type' => 'text', 'content' => ['heading' => 'Text']]]],
                    ],
                ]],
            ]],
        ]);

        $page = BuilderPage::where('is_home', true)->firstOrFail();
        $columns = Section::where('page_id', $page->id)->where('type', 'columns')->firstOrFail();
        $this->assertSame([2, 1], $columns->zones()->orderBy('sort_order')->pluck('width_units')->map(fn($value) => (int) $value)->all());
        $this->assertSame(['cards', 'text'], $columns->zones()->orderBy('sort_order')->with('sections')->get()->flatMap->sections->pluck('type')->all());
    }

    public function testManifestRejectsNestedColumns(): void
    {
        $this->expectException(\ApplicationException::class);
        $this->expectExceptionMessage('nelze vložit do zóny');

        app(SiteBlueprintImporter::class)->importData([
            'version' => 1,
            'pages' => [[
                'title' => 'Úvod', 'home' => true,
                'sections' => [[
                    'type' => 'columns', 'content' => ['ratio' => '1:1'],
                    'zones' => [
                        ['sections' => [['type' => 'columns', 'content' => ['ratio' => '1:1'], 'zones' => [[], []]]]],
                        ['sections' => []],
                    ],
                ]],
            ]],
        ]);
    }
}
