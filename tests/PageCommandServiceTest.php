<?php namespace HumlnetCreative\Pages\Tests;

use HumlnetCreative\Pages\Classes\Commands\DraftVersionConflictException;
use HumlnetCreative\Pages\Classes\Commands\PageCommand;
use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\MediaAsset;
use HumlnetCreative\Pages\Models\PageAuditLog;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use HumlnetCreative\Pages\Services\PageCommandService;
use HumlnetCreative\Pages\Services\PageCommandHistory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use PluginTestCase;

final class PageCommandServiceTest extends PluginTestCase
{
    public function testCreateUpdateDeleteUndoAndRedoUseOneVersionPerCommand(): void
    {
        $page = $this->page('prikazy');
        $commands = app(PageCommandService::class);
        $initialVersion = (int) $page->fresh()->draft_version;

        $created = $commands->execute(new PageCommand('section.create', $page->id, $initialVersion, [
            'type' => 'text',
            'content' => ['heading' => 'Nový text', 'text' => '<p>Obsah</p>'],
        ]));
        $uuid = $created->data['section_uuid'];
        $this->assertSame($initialVersion + 1, $created->draftVersion);
        $this->assertDatabaseHas('humlnetcreative_pages_sections', ['page_id' => $page->id, 'uuid' => $uuid]);

        $updated = $commands->execute(new PageCommand('section.update', $page->id, $created->draftVersion, [
            'uuid' => $uuid,
            'changes' => ['content' => ['heading' => 'Upravený text', 'text' => '<p>Obsah</p>']],
        ]));
        $this->assertSame('Upravený text', data_get(Section::where('uuid', $uuid)->first()->content, 'heading'));

        $undone = $commands->undo($page->id, $updated->draftVersion);
        $this->assertSame('Nový text', data_get(Section::where('uuid', $uuid)->first()->content, 'heading'));
        $redone = $commands->redo($page->id, $undone->draftVersion);
        $this->assertSame('Upravený text', data_get(Section::where('uuid', $uuid)->first()->content, 'heading'));
        $this->assertSame($initialVersion + 4, $redone->draftVersion);
        $this->assertSame(4, PageAuditLog::where('page_id', $page->id)->where('action', 'like', 'command.%')->count());
    }

    public function testStaleCommandIsRejectedWithoutMutation(): void
    {
        $page = $this->page('konflikt');
        $section = $this->cards($page, 'Karty');
        $actual = (int) $page->fresh()->draft_version;

        $this->expectException(DraftVersionConflictException::class);
        try {
            app(PageCommandService::class)->execute(new PageCommand('section.update', $page->id, $actual - 1, [
                'uuid' => $section->uuid,
                'changes' => ['title' => 'Nesmí se uložit'],
            ]));
        }
        finally {
            $this->assertSame('Karty', $section->fresh()->title);
            $this->assertSame($actual, (int) $page->fresh()->draft_version);
        }
    }

    public function testDuplicateAndCrossPagePasteCreateFreshLocalUuidsButKeepSharedAssets(): void
    {
        Storage::fake('media');
        $sourcePage = $this->page('zdroj');
        $targetPage = $this->page('cil');
        $section = $this->cards($sourcePage, 'Zdrojové karty');
        $item = SectionItem::create([
            'section_id' => $section->id,
            'type' => 'card',
            'is_published' => true,
            'sort_order' => 1,
            'content' => ['heading' => 'Karta'],
        ]);
        $asset = MediaAsset::create([
            'disk' => 'local', 'path' => 'pages/command-master.webp', 'original_name' => 'master.webp',
            'mime_type' => 'image/webp', 'size' => 100, 'width' => 1000, 'height' => 800,
        ]);
        $use = $item->media()->create([
            'media_asset_id' => $asset->id,
            'slot' => 'card_image',
            'alt_text' => 'Karta',
            'is_decorative' => false,
            'variants' => [],
        ]);
        $sourceVariant = 'pages-variants/'.$asset->uuid.'/'.$use->uuid.'/card-800.webp';
        Storage::disk('media')->put($sourceVariant, 'variant');
        $use->variants = ['webp' => ['800' => $sourceVariant]];
        $use->save();
        $sourceVersion = (int) $sourcePage->fresh()->draft_version;
        $commands = app(PageCommandService::class);

        $duplicate = $commands->execute(new PageCommand('section.duplicate', $sourcePage->id, $sourceVersion, ['uuid' => $section->uuid]));
        $duplicateSection = Section::where('uuid', $duplicate->data['section_uuid'])->firstOrFail();
        $duplicateItem = $duplicateSection->items()->firstOrFail();
        $duplicateUse = $duplicateItem->media()->firstOrFail();
        $this->assertNotSame($section->uuid, $duplicateSection->uuid);
        $this->assertNotSame($item->uuid, $duplicateItem->uuid);
        $this->assertNotSame($use->uuid, $duplicateUse->uuid);
        $this->assertSame($asset->id, $duplicateUse->media_asset_id);
        $this->assertNotSame($sourceVariant, data_get($duplicateUse->variants, 'webp.800'));
        Storage::disk('media')->assertExists(data_get($duplicateUse->variants, 'webp.800'));

        $commands->execute(new PageCommand('section.copy', $sourcePage->id, $duplicate->draftVersion, ['uuid' => $section->uuid]));
        $targetVersion = (int) $targetPage->fresh()->draft_version;
        $paste = $commands->execute(new PageCommand('section.paste', $targetPage->id, $targetVersion));
        $pasted = Section::where('uuid', $paste->data['section_uuid'])->firstOrFail();
        $this->assertSame($targetPage->id, $pasted->page_id);
        $this->assertNotSame($section->uuid, $pasted->uuid);
        $this->assertSame($asset->id, $pasted->items()->first()->media()->first()->media_asset_id);
    }

    public function testReorderAndMoveValidateCompleteSetsAndPageBoundary(): void
    {
        $page = $this->page('poradi');
        $first = $this->cards($page, 'První');
        $second = $this->cards($page, 'Druhé');
        $item = SectionItem::create([
            'section_id' => $first->id,
            'type' => 'card',
            'is_published' => true,
            'sort_order' => 1,
            'content' => ['heading' => 'Přesouvaná karta'],
        ]);
        $commands = app(PageCommandService::class);
        $version = (int) $page->fresh()->draft_version;

        $reordered = $commands->execute(new PageCommand('section.reorder', $page->id, $version, [
            'ordered_uuids' => [$second->uuid, $first->uuid],
        ]));
        $this->assertSame([$second->uuid, $first->uuid], $page->sections()->orderBy('sort_order')->pluck('uuid')->all());

        $moved = $commands->execute(new PageCommand('item.move', $page->id, $reordered->draftVersion, [
            'uuid' => $item->uuid,
            'target_section_uuid' => $second->uuid,
        ]));
        $this->assertSame($second->id, $item->fresh()->section_id);
        $this->assertSame($reordered->draftVersion + 1, $moved->draftVersion);
    }

    public function testRepeatedUndoRedoOfCreateUsesTheCurrentGeneratedUuid(): void
    {
        $page = $this->page('opakovat-vytvoreni');
        $commands = app(PageCommandService::class);
        $created = $commands->execute(new PageCommand('section.create', $page->id, (int) $page->fresh()->draft_version, ['type' => 'text']));

        $undone = $commands->undo($page->id, $created->draftVersion);
        $redone = $commands->redo($page->id, $undone->draftVersion);
        $currentUuid = $redone->data['section_uuid'];
        $this->assertDatabaseHas('humlnetcreative_pages_sections', ['uuid' => $currentUuid, 'deleted_at' => null]);

        $commands->undo($page->id, $redone->draftVersion);
        $this->assertSoftDeleted('humlnetcreative_pages_sections', ['uuid' => $currentUuid]);
    }

    public function testDuplicateKeepsCentralSourceSelectionsWithoutDuplicatingThem(): void
    {
        $page = $this->page('sdilene-zdroje');
        $commands = app(PageCommandService::class);
        $definitions = [
            ['type' => 'carousel', 'key' => 'slider_id', 'value' => 701],
            ['type' => 'accordion', 'key' => 'faq_group_id', 'value' => 702],
            ['type' => 'gallery', 'key' => 'gallery_id', 'value' => 703],
        ];

        foreach ($definitions as $definition) {
            $source = Section::create([
                'page_id' => $page->id,
                'type' => $definition['type'],
                'title' => $definition['type'],
                'is_published' => false,
                'sort_order' => ((int) $page->sections()->max('sort_order')) + 1,
                'layout' => ['width' => 'contained', 'spacing' => 'standard'],
                'content' => [],
                $definition['key'] => $definition['value'],
            ]);
            $result = $commands->execute(new PageCommand(
                'section.duplicate',
                $page->id,
                (int) $page->fresh()->draft_version,
                ['uuid' => $source->uuid],
            ));
            $duplicate = Section::where('uuid', $result->data['section_uuid'])->firstOrFail();
            $this->assertSame($definition['value'], (int) $duplicate->{$definition['key']});
            $this->assertSame(1, Section::where($definition['key'], $definition['value'])->where('uuid', $source->uuid)->count());
        }
    }

    public function testBulkDeleteIsOneVersionedUndoableCommand(): void
    {
        $page = $this->page('hromadne-smazani');
        $first = $this->cards($page, 'První');
        $second = $this->cards($page, 'Druhá');
        $commands = app(PageCommandService::class);
        $version = (int) $page->fresh()->draft_version;

        $deleted = $commands->execute(new PageCommand('section.delete_many', $page->id, $version, [
            'uuids' => [$first->uuid, $second->uuid],
        ]));
        $this->assertSame($version + 1, $deleted->draftVersion);
        $this->assertSoftDeleted('humlnetcreative_pages_sections', ['uuid' => $first->uuid]);
        $this->assertSoftDeleted('humlnetcreative_pages_sections', ['uuid' => $second->uuid]);

        $undone = $commands->undo($page->id, $deleted->draftVersion);
        $this->assertSame($deleted->draftVersion + 1, $undone->draftVersion);
        $this->assertDatabaseHas('humlnetcreative_pages_sections', ['uuid' => $first->uuid, 'deleted_at' => null]);
        $this->assertDatabaseHas('humlnetcreative_pages_sections', ['uuid' => $second->uuid, 'deleted_at' => null]);
    }

    public function testStaleUndoClearsInvalidSessionHistory(): void
    {
        $page = $this->page('stara-historie');
        $commands = app(PageCommandService::class);
        $created = $commands->execute(new PageCommand(
            'section.create',
            $page->id,
            (int) $page->fresh()->draft_version,
            ['type' => 'text'],
        ));
        $history = app(PageCommandHistory::class);
        $this->assertTrue($history->canUndo($page->id));

        try {
            $commands->undo($page->id, $created->draftVersion - 1);
            $this->fail('Stará verze Undo měla být odmítnuta.');
        }
        catch (\ApplicationException $exception) {
            $this->assertStringContainsString('Undo/Redo bylo bezpečně vymazáno', $exception->getMessage());
        }

        $this->assertFalse($history->canUndo($page->id));
        $this->assertFalse($history->canRedo($page->id));
    }

    public function testHistorySurvivesReloadOnlyWhileItsHeadMatchesTheDraft(): void
    {
        $page = $this->page('hlava-historie');
        $commands = app(PageCommandService::class);
        $created = $commands->execute(new PageCommand(
            'section.create',
            $page->id,
            (int) $page->fresh()->draft_version,
            ['type' => 'text'],
        ));
        $history = app(PageCommandHistory::class);

        $this->assertTrue($history->reconcile($page->id, $created->draftVersion));
        $this->assertTrue($history->canUndo($page->id));

        $this->assertFalse($history->reconcile($page->id, $created->draftVersion + 1));
        $this->assertFalse($history->canUndo($page->id));
        $this->assertFalse($history->canRedo($page->id));
    }

    public function testCreateAtCanvasInsertionPointMakesRoomWithoutLosingSections(): void
    {
        $page = $this->page('vlozeni-doprostred');
        $first = $this->cards($page, 'První');
        $second = $this->cards($page, 'Druhá');

        $result = app(PageCommandService::class)->execute(new PageCommand(
            'section.create',
            $page->id,
            (int) $page->fresh()->draft_version,
            ['type' => 'text', 'title' => 'Vložený text', 'position' => 2],
        ));

        $ordered = $page->sections()->orderBy('sort_order')->get();
        $this->assertCount(3, $ordered);
        $this->assertSame([$first->uuid, $result->data['section_uuid'], $second->uuid], $ordered->pluck('uuid')->all());
        $this->assertSame([1, 2, 3], $ordered->pluck('sort_order')->map(fn($value) => (int) $value)->all());
    }

    public function testCanvasInsertionPositionStaysOrdinalAfterDeleteUndoAndGappedLegacyOrders(): void
    {
        $page = $this->page('stabilni-vlozeni');
        $first = $this->cards($page, 'První');
        $second = $this->cards($page, 'Druhá');
        $third = $this->cards($page, 'Třetí');
        $commands = app(PageCommandService::class);

        $deleted = $commands->execute(new PageCommand('section.delete', $page->id, (int) $page->fresh()->draft_version, [
            'uuid' => $second->uuid,
        ]));
        $restored = $commands->undo($page->id, $deleted->draftVersion);
        DB::table('humlnetcreative_pages_sections')->where('id', $first->id)->update(['sort_order' => 3]);
        DB::table('humlnetcreative_pages_sections')->where('id', $second->id)->update(['sort_order' => 8]);
        DB::table('humlnetcreative_pages_sections')->where('id', $third->id)->update(['sort_order' => 12]);

        $inserted = $commands->execute(new PageCommand('section.create', $page->id, $restored->draftVersion, [
            'type' => 'cta', 'title' => 'Mezi první a druhou', 'position' => 2,
        ]));
        $ordered = $page->sections()->orderBy('sort_order')->get();

        $this->assertSame(
            [$first->uuid, $inserted->data['section_uuid'], $second->uuid, $third->uuid],
            $ordered->pluck('uuid')->all(),
        );
        $this->assertSame([1, 2, 3, 4], $ordered->pluck('sort_order')->map(fn($value) => (int) $value)->all());

        $commands->undo($page->id, $inserted->draftVersion);
        $afterUndo = $page->sections()->orderBy('sort_order')->get();
        $this->assertSame([$first->uuid, $second->uuid, $third->uuid], $afterUndo->pluck('uuid')->all());
        $this->assertSame([1, 2, 3], $afterUndo->pluck('sort_order')->map(fn($value) => (int) $value)->all());
    }

    public function testNamedTimelineSurvivesUndoRedoAndCanJumpAcrossMultipleSteps(): void
    {
        $page = $this->page('pojmenovana-historie');
        $commands = app(PageCommandService::class);
        $created = $commands->execute(new PageCommand('section.create', $page->id, (int) $page->fresh()->draft_version, [
            'type' => 'text', 'title' => 'Historický text',
        ]));
        $updated = $commands->execute(new PageCommand('section.update', $page->id, $created->draftVersion, [
            'uuid' => $created->data['section_uuid'], 'changes' => ['title' => 'Upravený text'],
        ]));
        $history = app(PageCommandHistory::class);
        $timeline = $history->timeline($page->id);

        $this->assertSame(2, $timeline['position']);
        $this->assertSame(2, $timeline['total']);
        $this->assertSame('Přidat sekci „Historický text“', $timeline['entries'][0]['label']);
        $this->assertSame('Upravit sekci „Historický text“', $timeline['entries'][1]['label']);

        $atStart = $commands->jump($page->id, $updated->draftVersion, 0);
        $this->assertSame(0, $history->timeline($page->id)['position']);
        $this->assertSoftDeleted('humlnetcreative_pages_sections', ['uuid' => $created->data['section_uuid']]);

        $atEnd = $commands->jump($page->id, $atStart->draftVersion, 2);
        $this->assertSame(2, $history->timeline($page->id)['position']);
        $this->assertSame('Upravený text', Section::where('uuid', $atEnd->data['section_uuid'])->value('title'));
    }

    public function testSuccessiveUpdatesOfOneSectionAreOneUndoStep(): void
    {
        $page = $this->page('sloucena-historie');
        $section = $this->cards($page, 'Původní název');
        $commands = app(PageCommandService::class);

        $first = $commands->execute(new PageCommand('section.update', $page->id, (int) $page->fresh()->draft_version, [
            'uuid' => $section->uuid,
            'changes' => ['title' => 'Automaticky uložený název'],
        ]));
        $second = $commands->execute(new PageCommand('section.update', $page->id, $first->draftVersion, [
            'uuid' => $section->uuid,
            'changes' => ['title' => 'Konečný název'],
        ]));

        $history = app(PageCommandHistory::class);
        $this->assertSame(1, $history->timeline($page->id)['total']);

        $undone = $commands->undo($page->id, $second->draftVersion);
        $this->assertSame('Původní název', Section::where('uuid', $section->uuid)->value('title'));

        $commands->redo($page->id, $undone->draftVersion);
        $this->assertSame('Konečný název', Section::where('uuid', $section->uuid)->value('title'));
    }

    private function page(string $slug): BuilderPage
    {
        return BuilderPage::create([
            'title' => ucfirst($slug),
            'slug' => $slug,
            'is_published' => true,
            'sort_order' => 1,
            'style' => [],
        ]);
    }

    private function cards(BuilderPage $page, string $title): Section
    {
        return Section::create([
            'page_id' => $page->id,
            'type' => 'cards',
            'title' => $title,
            'is_published' => true,
            'sort_order' => ((int) $page->sections()->max('sort_order')) + 1,
            'layout' => ['width' => 'contained', 'spacing' => 'standard'],
            'content' => ['heading' => $title, 'columns' => 3],
        ]);
    }
}
