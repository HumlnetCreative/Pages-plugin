<?php namespace HumlnetCreative\Pages\Tests;

use HumlnetCreative\Pages\Classes\Commands\PageCommand;
use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionContainer;
use HumlnetCreative\Pages\Services\ColumnsLayout;
use HumlnetCreative\Pages\Services\PageCommandService;
use HumlnetCreative\Pages\Services\PageSnapshotHydrator;
use HumlnetCreative\Pages\Services\PageSnapshotSerializer;
use HumlnetCreative\Pages\Services\PageStructureService;
use HumlnetCreative\Pages\Classes\Snapshot\PageSnapshot;
use PluginTestCase;

final class ColumnsWorkflowTest extends PluginTestCase
{
    public function testEveryCuratedRatioCreatesMatchingZones(): void
    {
        $page = $this->page('pomer');
        $commands = app(PageCommandService::class);
        $version = (int) $page->fresh()->draft_version;

        foreach (ColumnsLayout::RATIOS as $ratio => $units) {
            $result = $commands->execute(new PageCommand('section.create', $page->id, $version, [
                'type' => 'columns',
                'title' => 'Sloupce '.$ratio,
                'content' => ['ratio' => $ratio],
            ]));
            $version = $result->draftVersion;
            $columns = Section::where('uuid', $result->data['section_uuid'])->firstOrFail();

            $this->assertSame($units, $columns->zones()->orderBy('sort_order')->pluck('width_units')->map(fn($value) => (int) $value)->all());
            $this->assertSame(SectionContainer::KIND_ROOT, $columns->container->kind);
        }
    }

    public function testPlacementRejectsNestedColumnsAndSectionsThatNeedMoreWidth(): void
    {
        $page = $this->page('omezeni');
        $commands = app(PageCommandService::class);
        $columnsResult = $commands->execute(new PageCommand('section.create', $page->id, (int) $page->fresh()->draft_version, [
            'type' => 'columns', 'content' => ['ratio' => '1:1'],
        ]));
        $columns = Section::where('uuid', $columnsResult->data['section_uuid'])->firstOrFail();
        $zone = $columns->zones()->firstOrFail();

        $textResult = $commands->execute(new PageCommand('section.create', $page->id, $columnsResult->draftVersion, [
            'type' => 'text', 'target_container_uuid' => $zone->uuid,
        ]));
        $this->assertSame($zone->id, Section::where('uuid', $textResult->data['section_uuid'])->value('container_id'));

        try {
            $commands->execute(new PageCommand('section.create', $page->id, $textResult->draftVersion, [
                'type' => 'cards', 'target_container_uuid' => $zone->uuid,
            ]));
            $this->fail('Karty nemají projít v zóně o šířce jedné jednotky.');
        }
        catch (\ValidationException $exception) {
            $this->assertStringContainsString('širší zónu', $exception->getMessage());
        }

        $this->expectException(\ValidationException::class);
        $commands->execute(new PageCommand('section.move', $page->id, $textResult->draftVersion, [
            'uuid' => $columns->uuid, 'target_container_uuid' => $zone->uuid,
        ]));
    }

    public function testMoveAndSafeColumnsDeleteAreUndoableWithoutContentLoss(): void
    {
        $page = $this->page('bezpecne-rozbaleni');
        $commands = app(PageCommandService::class);
        $created = $commands->execute(new PageCommand('section.create', $page->id, (int) $page->fresh()->draft_version, [
            'type' => 'columns', 'content' => ['ratio' => '1:1'],
        ]));
        $columns = Section::where('uuid', $created->data['section_uuid'])->firstOrFail();
        $zones = $columns->zones()->orderBy('sort_order')->get();
        $first = $commands->execute(new PageCommand('section.create', $page->id, $created->draftVersion, [
            'type' => 'text', 'title' => 'První v zóně', 'target_container_uuid' => $zones[0]->uuid,
        ]));
        $second = $commands->execute(new PageCommand('section.create', $page->id, $first->draftVersion, [
            'type' => 'cta', 'title' => 'Druhý v zóně', 'target_container_uuid' => $zones[1]->uuid,
        ]));

        $deleted = $commands->execute(new PageCommand('section.delete', $page->id, $second->draftVersion, [
            'uuid' => $columns->uuid,
        ]));
        $root = $page->fresh()->root_container;
        $this->assertSoftDeleted('humlnetcreative_pages_sections', ['uuid' => $columns->uuid]);
        $this->assertSame(
            [$first->data['section_uuid'], $second->data['section_uuid']],
            $root->sections()->orderBy('sort_order')->pluck('uuid')->all(),
        );

        $undone = $commands->undo($page->id, $deleted->draftVersion);
        $restored = Section::where('uuid', $columns->uuid)->firstOrFail();
        $this->assertSame(2, $restored->zones()->count());
        $this->assertSame($first->data['section_uuid'], $restored->zones()->orderBy('sort_order')->first()->sections()->first()->uuid);
        $this->assertSame($deleted->draftVersion + 1, $undone->draftVersion);
    }

    public function testDuplicateColumnsCopiesZonesAndNestedSectionsWithFreshUuids(): void
    {
        $page = $this->page('duplikace-sloupcu');
        $commands = app(PageCommandService::class);
        $created = $commands->execute(new PageCommand('section.create', $page->id, (int) $page->fresh()->draft_version, [
            'type' => 'columns', 'content' => ['ratio' => '1:2'],
        ]));
        $source = Section::where('uuid', $created->data['section_uuid'])->firstOrFail();
        $nested = $commands->execute(new PageCommand('section.create', $page->id, $created->draftVersion, [
            'type' => 'cards', 'target_container_uuid' => $source->zones()->orderBy('sort_order')->get()[1]->uuid,
        ]));
        $duplicate = $commands->execute(new PageCommand('section.duplicate', $page->id, $nested->draftVersion, [
            'uuid' => $source->uuid,
        ]));
        $clone = Section::where('uuid', $duplicate->data['section_uuid'])->firstOrFail();

        $this->assertNotSame($source->uuid, $clone->uuid);
        $this->assertSame([1, 2], $clone->zones()->orderBy('sort_order')->pluck('width_units')->map(fn($value) => (int) $value)->all());
        $this->assertNotSame($source->zones()->pluck('uuid')->all(), $clone->zones()->pluck('uuid')->all());
        $this->assertNotSame($nested->data['section_uuid'], $clone->zones()->with('sections')->get()->flatMap->sections->first()->uuid);
    }

    public function testRatioChangeRejectsContentThatWouldNoLongerFit(): void
    {
        $page = $this->page('zmena-pomeru');
        $commands = app(PageCommandService::class);
        $created = $commands->execute(new PageCommand('section.create', $page->id, (int) $page->fresh()->draft_version, [
            'type' => 'columns', 'content' => ['ratio' => '1:2'],
        ]));
        $columns = Section::where('uuid', $created->data['section_uuid'])->firstOrFail();
        $cards = $commands->execute(new PageCommand('section.create', $page->id, $created->draftVersion, [
            'type' => 'cards', 'target_container_uuid' => $columns->zones()->orderBy('sort_order')->get()[1]->uuid,
        ]));

        try {
            $commands->execute(new PageCommand('columns.configure', $page->id, $cards->draftVersion, [
                'uuid' => $columns->uuid, 'ratio' => '1:1',
            ]));
            $this->fail('Zúžení zóny s Kartami mělo být odmítnuto.');
        }
        catch (\ValidationException $exception) {
            $this->assertStringContainsString('větší podíl', $exception->getMessage());
        }
        $this->assertSame('1:2', data_get($columns->fresh()->content, 'ratio'));
    }

    public function testRatioAndZoneSettingsChangeIsUndoableAndRedoable(): void
    {
        $page = $this->page('vratna-zmena-pomeru');
        $commands = app(PageCommandService::class);
        $created = $commands->execute(new PageCommand('section.create', $page->id, (int) $page->fresh()->draft_version, [
            'type' => 'columns', 'content' => ['ratio' => '1:1'],
        ]));
        $columns = Section::where('uuid', $created->data['section_uuid'])->firstOrFail();
        $nested = $commands->execute(new PageCommand('section.create', $page->id, $created->draftVersion, [
            'type' => 'text', 'title' => 'Obsah druhé zóny',
            'target_container_uuid' => $columns->zones()->orderBy('sort_order')->get()[1]->uuid,
        ]));

        $configured = $commands->execute(new PageCommand('columns.configure', $page->id, $nested->draftVersion, [
            'uuid' => $columns->uuid,
            'ratio' => '1:1:2',
            'layout' => ['columns_gap' => 'large', 'tablet_behavior' => 'stack'],
            'zones' => [
                ['vertical_align' => 'top'],
                ['vertical_align' => 'center', 'block_spacing' => 'large'],
                ['vertical_align' => 'bottom'],
            ],
        ]));
        $columns->refresh();
        $this->assertSame('1:1:2', data_get($columns->content, 'ratio'));
        $this->assertSame('large', data_get($columns->layout, 'columns_gap'));
        $this->assertSame([1, 1, 2], $columns->zones()->orderBy('sort_order')->pluck('width_units')->map(fn($value) => (int) $value)->all());
        $this->assertSame('center', $columns->zones()->orderBy('sort_order')->get()[1]->vertical_align);

        $undone = $commands->undo($page->id, $configured->draftVersion);
        $columns->refresh();
        $this->assertSame('1:1', data_get($columns->content, 'ratio'));
        $this->assertSame([1, 1], $columns->zones()->orderBy('sort_order')->pluck('width_units')->map(fn($value) => (int) $value)->all());
        $this->assertSame(
            $nested->data['section_uuid'],
            $columns->zones()->orderBy('sort_order')->get()[1]->sections()->firstOrFail()->uuid,
        );

        $commands->redo($page->id, $undone->draftVersion);
        $columns->refresh();
        $this->assertSame('1:1:2', data_get($columns->content, 'ratio'));
        $this->assertSame([1, 1, 2], $columns->zones()->orderBy('sort_order')->pluck('width_units')->map(fn($value) => (int) $value)->all());
    }

    public function testColumnsSnapshotRoundTripPreservesContainersAndNestedOrder(): void
    {
        $page = $this->page('snapshot-sloupcu');
        $commands = app(PageCommandService::class);
        $created = $commands->execute(new PageCommand('section.create', $page->id, (int) $page->fresh()->draft_version, [
            'type' => 'columns', 'content' => ['ratio' => '2:1:1'],
        ]));
        $columns = Section::where('uuid', $created->data['section_uuid'])->firstOrFail();
        $nested = $commands->execute(new PageCommand('section.create', $page->id, $created->draftVersion, [
            'type' => 'text', 'title' => 'Vnořený text',
            'target_container_uuid' => $columns->zones()->orderBy('sort_order')->get()[2]->uuid,
        ]));

        $serializer = app(PageSnapshotSerializer::class);
        $snapshot = $serializer->fromPage($page->fresh());
        $hydrated = app(PageSnapshotHydrator::class)->toPage(
            app(PageSnapshotHydrator::class)->fromJson($serializer->serialize($snapshot)),
            $page->id,
        );
        $root = app(PageStructureService::class)->prepare($hydrated, false);
        $hydratedColumns = $root->first();

        $this->assertSame(2, $snapshot->schemaVersion);
        $this->assertCount(4, $snapshot->containers);
        $this->assertSame('columns', $hydratedColumns->type);
        $this->assertSame([2, 1, 1], $hydratedColumns->zones->pluck('width_units')->map(fn($value) => (int) $value)->all());
        $this->assertSame($nested->data['section_uuid'], $hydratedColumns->zones[2]->sections[0]->uuid);
    }

    public function testVersionOneSnapshotGetsDeterministicRootContainer(): void
    {
        $page = $this->page('stary-snapshot');
        Section::create(['page_id' => $page->id, 'type' => 'text', 'sort_order' => 1]);
        $current = app(PageSnapshotSerializer::class)->fromPage($page->fresh())->toArray();
        $current['schema_version'] = 1;
        unset($current['containers']);
        foreach ($current['sections'] as &$section) {
            unset($section['container_uuid']);
        }
        unset($section);

        $first = PageSnapshot::fromArray($current);
        $second = PageSnapshot::fromArray($current);

        $this->assertSame(2, $first->schemaVersion);
        $this->assertSame($first->containers[0]['uuid'], $first->sections[0]['container_uuid']);
        $this->assertSame($first->containers[0]['uuid'], $second->containers[0]['uuid']);
    }

    private function page(string $slug): BuilderPage
    {
        return BuilderPage::create([
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'is_published' => true,
            'sort_order' => 1,
        ]);
    }
}
