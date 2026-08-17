<?php namespace HumlnetCreative\Pages\Services;

use Backend\Facades\BackendAuth;
use Backend\Models\User;
use HumlnetCreative\Pages\Classes\Commands\DraftVersionConflictException;
use HumlnetCreative\Pages\Classes\Commands\PageCommand;
use HumlnetCreative\Pages\Classes\Commands\PageCommandResult;
use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\MediaAsset;
use HumlnetCreative\Pages\Models\MediaUse;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use HumlnetCreative\Pages\Models\SectionContainer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Single transactional mutation API shared by every Page Builder editor. */
final class PageCommandService
{
    private const SECTION_FIELDS = ['title', 'is_published', 'layout', 'style', 'content', 'slider_id', 'faq_group_id', 'gallery_id'];
    private const ITEM_FIELDS = ['is_published', 'style', 'content'];

    public function execute(PageCommand $command, bool $recordHistory = true): PageCommandResult
    {
        if ($command->name === 'section.copy') {
            return $this->copySection($command);
        }

        $user = BackendAuth::getUser();
        $historyMeta = [];
        $result = DB::transaction(function() use ($command, $user, &$historyMeta): PageCommandResult {
            $page = BuilderPage::withoutGlobalScopes()->lockForUpdate()->findOrFail($command->pageId);
            $this->assertContext($page, $command, $user);
            $historyMeta = $this->historyMeta($page, $command, $user);

            [$data, $inverse] = DraftStateService::withoutTracking(
                fn(): array => $this->dispatch($page, $command, $user),
            );
            $newVersion = (int) $page->draft_version + 1;
            DB::table($page->getTable())->where('id', $page->id)->update([
                'has_draft' => true,
                'draft_version' => $newVersion,
                'draft_started_at' => DB::raw('COALESCE(draft_started_at, CURRENT_TIMESTAMP)'),
                'updated_at' => now(),
            ]);

            $inverse = $inverse ? new PageCommand($inverse->name, $page->id, $newVersion, $inverse->payload) : null;
            app(PageAuditService::class)->record($page->id, 'command.'.$command->name, $page, [
                'expected_draft_version' => $command->expectedDraftVersion,
                'draft_version' => $newVersion,
                'payload' => $this->auditPayload($command->payload),
            ]);

            return new PageCommandResult($page->id, $newVersion, $data, $inverse);
        });

        if ($recordHistory && $result->inverse) {
            $redo = new PageCommand($command->name, $command->pageId, $result->draftVersion, $command->payload);
            app(PageCommandHistory::class)->push($command->pageId, $result->inverse, $redo, $historyMeta);
        }

        return $result;
    }

    public function undo(int $pageId, int $expectedDraftVersion): PageCommandResult
    {
        $history = app(PageCommandHistory::class);
        $entry = $history->popUndo($pageId);
        if (!$entry) {
            throw new \ApplicationException('V této relaci už není co vrátit zpět.');
        }

        try {
            $undo = PageCommand::fromArray($entry['undo']);
            $result = $this->execute(new PageCommand($undo->name, $pageId, $expectedDraftVersion, $undo->payload), false);
            $history->pushRedo($pageId, [
                'undo' => $entry['undo'],
                'redo' => ($result->inverse ?: PageCommand::fromArray($entry['redo']))->toArray(),
                'meta' => $entry['meta'] ?? [],
            ], $result->draftVersion);

            return $result;
        }
        catch (DraftVersionConflictException|ModelNotFoundException $exception) {
            $history->clear($pageId);
            throw new \ApplicationException('Pracovní kopie se změnila mimo tuto historii. Undo/Redo bylo bezpečně vymazáno; obnovte editor.');
        }
        catch (\Throwable $exception) {
            $history->pushUndoEntry($pageId, $entry);
            throw $exception;
        }
    }

    public function redo(int $pageId, int $expectedDraftVersion): PageCommandResult
    {
        $history = app(PageCommandHistory::class);
        $entry = $history->popRedo($pageId);
        if (!$entry) {
            throw new \ApplicationException('V této relaci už není co provést znovu.');
        }

        try {
            $redo = PageCommand::fromArray($entry['redo']);
            $result = $this->execute(new PageCommand($redo->name, $pageId, $expectedDraftVersion, $redo->payload), false);
            $history->pushUndoEntry($pageId, [
                'undo' => ($result->inverse ?: PageCommand::fromArray($entry['undo']))->toArray(),
                'redo' => $entry['redo'],
                'meta' => $entry['meta'] ?? [],
            ], $result->draftVersion);

            return $result;
        }
        catch (DraftVersionConflictException|ModelNotFoundException $exception) {
            $history->clear($pageId);
            throw new \ApplicationException('Pracovní kopie se změnila mimo tuto historii. Undo/Redo bylo bezpečně vymazáno; obnovte editor.');
        }
        catch (\Throwable $exception) {
            $history->pushRedo($pageId, $entry);
            throw $exception;
        }
    }

    public function jump(int $pageId, int $expectedDraftVersion, int $targetPosition): ?PageCommandResult
    {
        $history = app(PageCommandHistory::class);
        $timeline = $history->timeline($pageId);
        if ($targetPosition < 0 || $targetPosition > $timeline['total']) {
            throw new \ApplicationException('Vybraný bod historie už není dostupný.');
        }

        $version = $expectedDraftVersion;
        $result = null;
        while ($history->timeline($pageId)['position'] > $targetPosition) {
            $result = $this->undo($pageId, $version);
            $version = $result->draftVersion;
        }
        while ($history->timeline($pageId)['position'] < $targetPosition) {
            $result = $this->redo($pageId, $version);
            $version = $result->draftVersion;
        }

        return $result;
    }

    private function dispatch(BuilderPage $page, PageCommand $command, ?User $user): array
    {
        return match ($command->name) {
            'section.create' => $this->createSection($page, $command->payload),
            'section.update' => $this->updateSection($page, $command->payload),
            'section.visibility' => $this->visibilitySection($page, $command->payload),
            'section.delete' => $this->deleteSection($page, $command->payload),
            'section.delete_many' => $this->deleteSections($page, $command->payload),
            'section.restore' => $this->restoreSection($page, $command->payload),
            'section.restore_many' => $this->restoreSections($page, $command->payload),
            'section.reorder' => $this->reorderSections($page, $command->payload),
            'section.move' => $this->moveSection($page, $command->payload),
            'section.duplicate' => $this->duplicateSection($page, $command->payload),
            'section.paste' => $this->pasteSection($page, $command->payload),
            'columns.restore' => $this->restoreColumns($page, $command->payload),
            'columns.configure' => $this->configureColumns($page, $command->payload),
            'columns.restore_configuration' => $this->restoreColumnsConfiguration($page, $command->payload),
            'item.create' => $this->createItem($page, $command->payload),
            'item.update' => $this->updateItem($page, $command->payload),
            'item.visibility' => $this->visibilityItem($page, $command->payload),
            'item.delete' => $this->deleteItem($page, $command->payload),
            'item.delete_many' => $this->deleteItems($page, $command->payload),
            'item.restore' => $this->restoreItem($page, $command->payload),
            'item.restore_many' => $this->restoreItems($page, $command->payload),
            'item.reorder' => $this->reorderItems($page, $command->payload),
            'item.move' => $this->moveItem($page, $command->payload),
            'item.duplicate' => $this->duplicateItem($page, $command->payload),
            default => throw new \InvalidArgumentException("Neznámý Page Builder příkaz {$command->name}."),
        };
    }

    private function historyMeta(BuilderPage $page, PageCommand $command, ?User $user): array
    {
        $section = null;
        $item = null;
        $uuid = (string) ($command->payload['uuid'] ?? '');
        if ((str_starts_with($command->name, 'section.') || str_starts_with($command->name, 'columns.')) && $uuid !== '') {
            $section = Section::withTrashed()->where('page_id', $page->id)->where('uuid', $uuid)->first();
        }
        elseif (str_starts_with($command->name, 'item.') && $uuid !== '') {
            $item = SectionItem::withTrashed()->with('section')->where('uuid', $uuid)->first();
            $section = $item?->section;
        }
        $sectionName = trim((string) ($section?->title ?: data_get($command->payload, 'title')));
        $sectionName = $sectionName !== '' ? ' „'.Str::limit($sectionName, 45).'“' : '';
        $count = count((array) ($command->payload['uuids'] ?? []));
        $label = match ($command->name) {
            'section.create' => 'Přidat sekci'.$sectionName,
            'section.update', 'section.visibility' => 'Upravit sekci'.$sectionName,
            'section.delete' => 'Odstranit sekci'.$sectionName,
            'section.delete_many' => 'Odstranit sekce'.($count ? ' ('.$count.')' : ''),
            'section.reorder' => 'Změnit pořadí sekcí',
            'section.move' => 'Přesunout sekci'.$sectionName,
            'section.duplicate' => 'Duplikovat sekci'.$sectionName,
            'section.paste' => 'Vložit zkopírovanou sekci',
            'columns.configure', 'columns.restore_configuration' => 'Upravit rozložení Sloupců'.$sectionName,
            'item.create' => 'Přidat položku do sekce'.$sectionName,
            'item.update', 'item.visibility' => 'Upravit položku v sekci'.$sectionName,
            'item.delete' => 'Odstranit položku ze sekce'.$sectionName,
            'item.delete_many' => 'Odstranit položky'.($count ? ' ('.$count.')' : ''),
            'item.reorder' => 'Změnit pořadí položek'.$sectionName,
            'item.move' => 'Přesunout položku',
            'item.duplicate' => 'Duplikovat položku'.$sectionName,
            default => 'Změnit stránku',
        };

        return [
            'label' => $label,
            'time' => now()->format('H:i:s'),
            'user' => $user?->full_name ?: $user?->login ?: 'Systém',
            'coalesce_key' => in_array($command->name, ['section.update', 'item.update'], true) && $uuid !== ''
                ? $command->name.':'.$uuid
                : null,
        ];
    }

    private function assertContext(BuilderPage $page, PageCommand $command, ?User $user): void
    {
        $actual = (int) $page->draft_version;
        if ($actual !== $command->expectedDraftVersion) {
            throw new DraftVersionConflictException($command->expectedDraftVersion, $actual);
        }
        if (!$user && !app()->runningInConsole()) {
            throw new \ApplicationException('Příkaz vyžaduje přihlášeného backendového uživatele.');
        }
        if (!$user) {
            return;
        }
        $this->assertPermission($user, 'humlnetcreative.pages.draft.edit');
        app(PageEditLockService::class)->assertWritable($page, $user, app(EditorSessionService::class)->id());

        $operationPermission = match (true) {
            str_contains($command->name, 'reorder'), in_array($command->name, ['item.move', 'section.move'], true) => 'humlnetcreative.pages.structure.reorder',
            in_array($command->name, ['section.duplicate', 'item.duplicate'], true) => 'humlnetcreative.pages.structure.duplicate',
            in_array($command->name, ['section.copy', 'section.paste'], true) => 'humlnetcreative.pages.structure.copy',
            str_ends_with($command->name, '.create'), str_contains($command->name, '.delete'), str_contains($command->name, '.restore') => 'humlnetcreative.pages.structure.create_delete',
            default => null,
        };
        if ($operationPermission) {
            $this->assertPermission($user, $operationPermission);
        }
        $changes = (array) ($command->payload['changes'] ?? []);
        if (array_intersect(['layout', 'style'], array_keys($changes))) {
            $this->assertPermission($user, 'humlnetcreative.pages.structure.appearance');
        }
        if (str_starts_with($command->name, 'columns.')) {
            $this->assertPermission($user, 'humlnetcreative.pages.structure.appearance');
            $this->assertPermission($user, 'humlnetcreative.pages.structure.reorder');
        }
        $type = $this->commandSectionType($page, $command);
        if ($type && SectionRegistry::instance()->has($type)) {
            $this->assertPermission($user, SectionRegistry::instance()->definition($type)['permission']);
        }
        if (in_array($command->name, ['section.delete_many', 'section.restore_many'], true)) {
            $uuids = (array) ($command->payload['uuids'] ?? array_column((array) ($command->payload['records'] ?? []), 'uuid'));
            $types = Section::withTrashed()->where('page_id', $page->id)->whereIn('uuid', $uuids)->pluck('type')->unique();
            foreach ($types as $batchType) {
                if (SectionRegistry::instance()->has($batchType)) {
                    $this->assertPermission($user, SectionRegistry::instance()->definition($batchType)['permission']);
                }
            }
        }
    }

    private function createSection(BuilderPage $page, array $payload): array
    {
        $type = (string) ($payload['type'] ?? '');
        if (!SectionRegistry::instance()->has($type)) {
            throw new \ValidationException(['type' => 'Neznámý typ sekce.']);
        }
        $defaults = SectionRegistry::instance()->defaults($type);
        $container = $this->targetContainer($page, $payload);
        $this->normalizeOrders('humlnetcreative_pages_sections', 'container_id', $container->id);
        $position = $this->position($container->sections(), $payload);
        $this->makeRoom('humlnetcreative_pages_sections', 'container_id', $container->id, $position);
        $section = Section::create([
            'uuid' => $payload['uuid'] ?? (string) Str::uuid(),
            'page_id' => $page->id,
            'container_id' => $container->id,
            'type' => $type,
            'title' => $payload['title'] ?? SectionRegistry::instance()->definition($type)['label'],
            'is_published' => (bool) ($payload['is_published'] ?? !in_array($type, ['carousel', 'accordion', 'gallery'], true)),
            'sort_order' => $position,
            'layout' => array_replace_recursive($defaults['layout'], (array) ($payload['layout'] ?? [])),
            'style' => array_replace_recursive($defaults['style'], (array) ($payload['style'] ?? [])),
            'content' => array_replace_recursive($defaults['content'], (array) ($payload['content'] ?? [])),
            'slider_id' => $payload['slider_id'] ?? null,
            'faq_group_id' => $payload['faq_group_id'] ?? null,
            'gallery_id' => $payload['gallery_id'] ?? null,
        ]);
        if ($type === 'columns') {
            $this->createColumnZones($section, ColumnsLayout::units((string) data_get($section->content, 'ratio', '1:1')));
        }

        return [['section_uuid' => $section->uuid], new PageCommand('section.delete', $page->id, 0, ['uuid' => $section->uuid, 'mode' => 'destructive'])];
    }

    private function updateSection(BuilderPage $page, array $payload): array
    {
        $section = $this->section($page, (string) ($payload['uuid'] ?? ''));
        $changes = array_intersect_key((array) ($payload['changes'] ?? []), array_flip(self::SECTION_FIELDS));
        if (!$changes) {
            throw new \ValidationException(['changes' => 'Příkaz neobsahuje žádnou povolenou změnu sekce.']);
        }
        $before = collect(array_keys($changes))->mapWithKeys(fn($key) => [$key => $section->getAttribute($key)])->all();
        $section->fill($changes)->save();

        return [['section_uuid' => $section->uuid], new PageCommand('section.update', $page->id, 0, ['uuid' => $section->uuid, 'changes' => $before])];
    }

    private function visibilitySection(BuilderPage $page, array $payload): array
    {
        return $this->updateSection($page, ['uuid' => $payload['uuid'] ?? null, 'changes' => ['is_published' => (bool) ($payload['visible'] ?? false)]]);
    }

    private function deleteSection(BuilderPage $page, array $payload): array
    {
        $section = $this->section($page, (string) ($payload['uuid'] ?? ''));
        if ($section->type === 'columns') {
            return $this->deleteColumns($page, $section, (string) ($payload['mode'] ?? 'unwrap'));
        }
        $container = $section->container;
        $this->normalizeOrders('humlnetcreative_pages_sections', 'container_id', $container->id);
        $section->refresh();
        $position = (int) $section->sort_order;
        $section->delete();
        $this->normalizeOrders('humlnetcreative_pages_sections', 'container_id', $container->id);

        return [['section_uuid' => $section->uuid], new PageCommand('section.restore', $page->id, 0, [
            'uuid' => $section->uuid, 'container_uuid' => $container->uuid, 'position' => $position,
        ])];
    }

    private function restoreSection(BuilderPage $page, array $payload): array
    {
        $section = Section::withTrashed()->where('page_id', $page->id)->where('uuid', $payload['uuid'] ?? '')->firstOrFail();
        $container = $this->targetContainer($page, $payload);
        $this->normalizeOrders('humlnetcreative_pages_sections', 'container_id', $container->id);
        $position = $this->position($container->sections(), $payload);
        $this->makeRoom('humlnetcreative_pages_sections', 'container_id', $container->id, $position);
        $section->restore();
        $section->container_id = $container->id;
        $section->sort_order = $position;
        $section->save();

        return [['section_uuid' => $section->uuid], new PageCommand('section.delete', $page->id, 0, ['uuid' => $section->uuid])];
    }

    private function deleteSections(BuilderPage $page, array $payload): array
    {
        $selectedUuids = array_values(array_unique((array) ($payload['uuids'] ?? [])));
        $selected = Section::where('page_id', $page->id)->whereIn('uuid', $selectedUuids)->with('zones.sections')->get();
        foreach ($selected->where('type', 'columns') as $columns) {
            $nestedUuids = $columns->zones->flatMap->sections->pluck('uuid')->all();
            if (array_intersect($selectedUuids, $nestedUuids)) {
                throw new \ValidationException(['sections' => 'Sloupce a blok uvnitř nich nelze odstranit v jednom hromadném výběru. Vyberte pouze Sloupce; jejich obsah se bezpečně rozbalí.']);
            }
        }
        $restores = [];
        foreach ($selectedUuids as $uuid) {
            [, $inverse] = $this->deleteSection($page, ['uuid' => $uuid]);
            $restores[] = ['name' => $inverse->name, 'payload' => $inverse->payload];
        }
        if (!$restores) {
            throw new \ValidationException(['uuids' => 'Vyberte alespoň jednu sekci.']);
        }

        return [['section_uuids' => $selectedUuids], new PageCommand('section.restore_many', $page->id, 0, ['records' => $restores])];
    }

    private function restoreSections(BuilderPage $page, array $payload): array
    {
        $uuids = [];
        foreach ((array) ($payload['records'] ?? []) as $record) {
            $name = $record['name'] ?? 'section.restore';
            [$data] = $name === 'columns.restore'
                ? $this->restoreColumns($page, (array) ($record['payload'] ?? []))
                : $this->restoreSection($page, (array) ($record['payload'] ?? $record));
            $uuids[] = $data['section_uuid'];
        }

        return [['section_uuids' => $uuids], new PageCommand('section.delete_many', $page->id, 0, ['uuids' => $uuids])];
    }

    private function reorderSections(BuilderPage $page, array $payload): array
    {
        $container = $this->targetContainer($page, $payload);
        $ordered = array_values((array) ($payload['ordered_uuids'] ?? []));
        $sections = $container->sections()->orderBy('sort_order')->get();
        $before = $sections->pluck('uuid')->all();
        $this->assertSameUuids($before, $ordered, 'sekcí');
        foreach ($ordered as $index => $uuid) {
            DB::table('humlnetcreative_pages_sections')->where('container_id', $container->id)->where('uuid', $uuid)->update(['sort_order' => $index + 1]);
        }

        return [['ordered_uuids' => $ordered], new PageCommand('section.reorder', $page->id, 0, [
            'container_uuid' => $container->uuid, 'ordered_uuids' => $before,
        ])];
    }

    private function moveSection(BuilderPage $page, array $payload): array
    {
        $section = $this->section($page, (string) ($payload['uuid'] ?? ''));
        if ($section->type === 'columns' && isset($payload['target_container_uuid'])) {
            $target = $this->targetContainer($page, $payload);
            if ($target->kind !== SectionContainer::KIND_ROOT) {
                throw new \ValidationException(['container' => 'Sloupce nelze vnořit do dalších Sloupců.']);
            }
        }
        $source = $section->container;
        $target = $this->targetContainer($page, $payload);
        $sourcePosition = (int) $section->sort_order;
        $this->normalizeOrders('humlnetcreative_pages_sections', 'container_id', $source->id);
        $this->normalizeOrders('humlnetcreative_pages_sections', 'container_id', $target->id);
        $targetSections = $target->sections()->orderBy('sort_order')->get()->reject(fn(Section $candidate) => $candidate->id === $section->id)->values();
        $position = min($targetSections->count() + 1, max(1, (int) ($payload['position'] ?? ($targetSections->count() + 1))));

        if ((int) $source->id !== (int) $target->id) {
            $section->container_id = $target->id;
            $section->sort_order = $targetSections->count() + 1;
            $section->unsetRelation('container');
            $section->save();
            $this->normalizeOrders('humlnetcreative_pages_sections', 'container_id', $source->id);
        }
        $targetSections->splice($position - 1, 0, [$section]);
        foreach ($targetSections as $index => $candidate) {
            DB::table('humlnetcreative_pages_sections')->where('id', $candidate->id)->update(['sort_order' => $index + 1]);
        }

        return [['section_uuid' => $section->uuid], new PageCommand('section.move', $page->id, 0, [
            'uuid' => $section->uuid,
            'target_container_uuid' => $source->uuid,
            'position' => $sourcePosition,
        ])];
    }

    private function duplicateSection(BuilderPage $page, array $payload): array
    {
        $source = $this->section($page, (string) ($payload['uuid'] ?? ''));
        $this->normalizeOrders('humlnetcreative_pages_sections', 'container_id', $source->container_id);
        $source->refresh();
        $payload['position'] ??= (int) $source->sort_order + 1;
        $payload['target_container_uuid'] ??= $source->container->uuid;
        $clone = $this->importSection($page, $this->exportSection($source), true, $payload);

        return [['section_uuid' => $clone->uuid], new PageCommand('section.delete', $page->id, 0, ['uuid' => $clone->uuid])];
    }

    private function copySection(PageCommand $command): PageCommandResult
    {
        $page = BuilderPage::withoutGlobalScopes()->findOrFail($command->pageId);
        $user = BackendAuth::getUser();
        $this->assertContext($page, $command, $user);
        $section = $this->section($page, (string) ($command->payload['uuid'] ?? ''));
        app(PageCommandClipboard::class)->put($this->exportSection($section));
        app(PageAuditService::class)->record($page->id, 'command.section.copy', $page, [
            'expected_draft_version' => $command->expectedDraftVersion,
            'section_uuid' => $section->uuid,
        ]);

        return new PageCommandResult($page->id, (int) $page->draft_version, ['copied_section_uuid' => $section->uuid]);
    }

    private function pasteSection(BuilderPage $page, array $payload): array
    {
        $clipboard = app(PageCommandClipboard::class)->get();
        if (!$clipboard) {
            throw new \ApplicationException('Schránka Page Builderu je prázdná nebo má nepodporovanou verzi.');
        }
        $clone = $this->importSection($page, $clipboard['section'], true, $payload);

        return [['section_uuid' => $clone->uuid], new PageCommand('section.delete', $page->id, 0, ['uuid' => $clone->uuid])];
    }

    private function createItem(BuilderPage $page, array $payload): array
    {
        $section = $this->section($page, (string) ($payload['section_uuid'] ?? ''));
        if (!SectionRegistry::instance()->itemsSupported($section->type)) {
            throw new \ValidationException(['section' => 'Tento typ sekce nepodporuje položky.']);
        }
        $position = $this->position($section->items(), $payload);
        $this->makeRoom('humlnetcreative_pages_section_items', 'section_id', $section->id, $position);
        $item = SectionItem::create([
            'uuid' => $payload['uuid'] ?? (string) Str::uuid(),
            'section_id' => $section->id,
            'type' => $payload['type'] ?? 'item',
            'is_published' => (bool) ($payload['is_published'] ?? true),
            'sort_order' => $position,
            'style' => (array) ($payload['style'] ?? []),
            'content' => (array) ($payload['content'] ?? []),
        ]);

        return [['item_uuid' => $item->uuid], new PageCommand('item.delete', $page->id, 0, ['uuid' => $item->uuid])];
    }

    private function updateItem(BuilderPage $page, array $payload): array
    {
        $item = $this->item($page, (string) ($payload['uuid'] ?? ''));
        $changes = array_intersect_key((array) ($payload['changes'] ?? []), array_flip(self::ITEM_FIELDS));
        if (!$changes) {
            throw new \ValidationException(['changes' => 'Příkaz neobsahuje žádnou povolenou změnu položky.']);
        }
        $before = collect(array_keys($changes))->mapWithKeys(fn($key) => [$key => $item->getAttribute($key)])->all();
        $item->fill($changes)->save();

        return [['item_uuid' => $item->uuid], new PageCommand('item.update', $page->id, 0, ['uuid' => $item->uuid, 'changes' => $before])];
    }

    private function visibilityItem(BuilderPage $page, array $payload): array
    {
        return $this->updateItem($page, ['uuid' => $payload['uuid'] ?? null, 'changes' => ['is_published' => (bool) ($payload['visible'] ?? false)]]);
    }

    private function deleteItem(BuilderPage $page, array $payload): array
    {
        $item = $this->item($page, (string) ($payload['uuid'] ?? ''));
        $position = (int) $item->sort_order;
        $sectionUuid = $item->section->uuid;
        $item->delete();

        return [['item_uuid' => $item->uuid], new PageCommand('item.restore', $page->id, 0, ['uuid' => $item->uuid, 'section_uuid' => $sectionUuid, 'position' => $position])];
    }

    private function restoreItem(BuilderPage $page, array $payload): array
    {
        $section = $this->section($page, (string) ($payload['section_uuid'] ?? ''));
        $item = SectionItem::withTrashed()->where('uuid', $payload['uuid'] ?? '')->firstOrFail();
        $item->section_id = $section->id;
        $item->sort_order = (int) ($payload['position'] ?? $item->sort_order);
        $item->restore();
        $item->save();

        return [['item_uuid' => $item->uuid], new PageCommand('item.delete', $page->id, 0, ['uuid' => $item->uuid])];
    }

    private function deleteItems(BuilderPage $page, array $payload): array
    {
        $restores = [];
        foreach (array_values(array_unique((array) ($payload['uuids'] ?? []))) as $uuid) {
            [, $inverse] = $this->deleteItem($page, ['uuid' => $uuid]);
            $restores[] = $inverse->payload;
        }
        if (!$restores) {
            throw new \ValidationException(['uuids' => 'Vyberte alespoň jednu položku.']);
        }

        return [['item_uuids' => array_column($restores, 'uuid')], new PageCommand('item.restore_many', $page->id, 0, ['records' => $restores])];
    }

    private function restoreItems(BuilderPage $page, array $payload): array
    {
        $uuids = [];
        foreach ((array) ($payload['records'] ?? []) as $record) {
            [$data] = $this->restoreItem($page, (array) $record);
            $uuids[] = $data['item_uuid'];
        }

        return [['item_uuids' => $uuids], new PageCommand('item.delete_many', $page->id, 0, ['uuids' => $uuids])];
    }

    private function reorderItems(BuilderPage $page, array $payload): array
    {
        $section = $this->section($page, (string) ($payload['section_uuid'] ?? ''));
        $ordered = array_values((array) ($payload['ordered_uuids'] ?? []));
        $before = $section->items()->orderBy('sort_order')->pluck('uuid')->all();
        $this->assertSameUuids($before, $ordered, 'položek');
        foreach ($ordered as $index => $uuid) {
            DB::table('humlnetcreative_pages_section_items')->where('section_id', $section->id)->where('uuid', $uuid)->update(['sort_order' => $index + 1]);
        }

        return [['ordered_uuids' => $ordered], new PageCommand('item.reorder', $page->id, 0, ['section_uuid' => $section->uuid, 'ordered_uuids' => $before])];
    }

    private function moveItem(BuilderPage $page, array $payload): array
    {
        $item = $this->item($page, (string) ($payload['uuid'] ?? ''));
        $source = $item->section;
        $target = $this->section($page, (string) ($payload['target_section_uuid'] ?? ''));
        if (!$this->compatibleItemSections($source, $target)) {
            throw new \ValidationException(['section' => 'Položku nelze přesunout mezi těmito typy sekcí.']);
        }
        $sourcePosition = (int) $item->sort_order;
        $item->section_id = $target->id;
        $item->sort_order = $this->position($target->items(), $payload);
        $item->save();

        return [['item_uuid' => $item->uuid], new PageCommand('item.move', $page->id, 0, [
            'uuid' => $item->uuid,
            'target_section_uuid' => $source->uuid,
            'position' => $sourcePosition,
        ])];
    }

    private function duplicateItem(BuilderPage $page, array $payload): array
    {
        $source = $this->item($page, (string) ($payload['uuid'] ?? ''));
        $target = isset($payload['target_section_uuid'])
            ? $this->section($page, (string) $payload['target_section_uuid'])
            : $source->section;
        if (!$this->compatibleItemSections($source->section, $target)) {
            throw new \ValidationException(['section' => 'Položku nelze zkopírovat do tohoto typu sekce.']);
        }
        $clone = $this->importItem($target, $this->exportItem($source), true, $payload);

        return [['item_uuid' => $clone->uuid], new PageCommand('item.delete', $page->id, 0, ['uuid' => $clone->uuid])];
    }

    private function exportSection(Section $section): array
    {
        $section->loadMissing(['container', 'items.media', 'media', 'zones.sections.items.media', 'zones.sections.media']);

        $data = [
            'uuid' => $section->uuid,
            'container_uuid' => $section->container?->uuid,
            'type' => $section->type,
            'title' => $section->title,
            'is_published' => (bool) $section->is_published,
            'sort_order' => (int) $section->sort_order,
            'layout' => $section->layout ?: [],
            'style' => $section->style ?: [],
            'content' => $section->content ?: [],
            'slider_id' => $section->slider_id,
            'faq_group_id' => $section->faq_group_id,
            'gallery_id' => $section->gallery_id,
            'media' => $section->media->map(fn(MediaUse $use) => $this->exportMedia($use))->all(),
            'items' => $section->items->map(fn(SectionItem $item) => $this->exportItem($item))->all(),
        ];
        if ($section->type === 'columns') {
            $data['zones'] = $section->zones->sortBy('sort_order')->map(fn(SectionContainer $zone): array => [
                'uuid' => $zone->uuid,
                'title' => $zone->title,
                'sort_order' => (int) $zone->sort_order,
                'width_units' => (int) $zone->width_units,
                'vertical_align' => $zone->vertical_align,
                'block_spacing' => $zone->block_spacing,
                'style' => $zone->style ?: [],
                'sections' => $zone->sections->sortBy('sort_order')->map(fn(Section $nested) => $this->exportSection($nested))->all(),
            ])->values()->all();
        }

        return $data;
    }

    private function exportItem(SectionItem $item): array
    {
        $item->loadMissing('media');

        return [
            'uuid' => $item->uuid,
            'type' => $item->type,
            'is_published' => (bool) $item->is_published,
            'sort_order' => (int) $item->sort_order,
            'style' => $item->style ?: [],
            'content' => $item->content ?: [],
            'media' => $item->media->map(fn(MediaUse $use) => $this->exportMedia($use))->all(),
        ];
    }

    private function exportMedia(MediaUse $use): array
    {
        return [
            'uuid' => $use->uuid,
            'media_asset_id' => $use->media_asset_id,
            'site_id' => $use->site_id,
            'slot' => $use->slot,
            'crop' => $use->crop ?: [],
            'alt_text' => $use->alt_text,
            'is_decorative' => (bool) $use->is_decorative,
            'variants' => $use->variants ?: [],
        ];
    }

    private function importSection(BuilderPage $page, array $data, bool $freshUuids, array $payload): Section
    {
        $container = $this->targetContainer($page, $payload);
        $this->normalizeOrders('humlnetcreative_pages_sections', 'container_id', $container->id);
        $position = $this->position($container->sections(), $payload);
        $this->makeRoom('humlnetcreative_pages_sections', 'container_id', $container->id, $position);
        $section = Section::create([
            'uuid' => $freshUuids ? (string) Str::uuid() : $data['uuid'],
            'page_id' => $page->id,
            'container_id' => $container->id,
            'type' => $data['type'],
            'title' => $data['title'],
            'is_published' => $data['is_published'],
            'sort_order' => $position,
            'layout' => $data['layout'],
            'style' => $data['style'],
            'content' => $data['content'],
            'slider_id' => $data['slider_id'],
            'faq_group_id' => $data['faq_group_id'],
            'gallery_id' => $data['gallery_id'],
        ]);
        foreach ($data['media'] as $media) {
            $this->importMedia($section, $media);
        }
        foreach (array_values($data['items']) as $index => $itemData) {
            $this->importItem($section, $itemData, $freshUuids, ['position' => $index + 1]);
        }
        if ($section->type === 'columns') {
            foreach (array_values((array) ($data['zones'] ?? [])) as $zoneIndex => $zoneData) {
                $zone = SectionContainer::create([
                    'uuid' => $freshUuids ? (string) Str::uuid() : $zoneData['uuid'],
                    'page_id' => $page->id,
                    'parent_section_id' => $section->id,
                    'kind' => SectionContainer::KIND_ZONE,
                    'title' => $zoneData['title'] ?? 'Zóna '.($zoneIndex + 1),
                    'sort_order' => $zoneIndex + 1,
                    'width_units' => (int) $zoneData['width_units'],
                    'vertical_align' => $zoneData['vertical_align'] ?? 'top',
                    'block_spacing' => $zoneData['block_spacing'] ?? 'standard',
                    'style' => (array) ($zoneData['style'] ?? []),
                ]);
                foreach (array_values((array) ($zoneData['sections'] ?? [])) as $nestedIndex => $nestedData) {
                    $this->importSection($page, $nestedData, $freshUuids, [
                        'target_container_uuid' => $zone->uuid,
                        'position' => $nestedIndex + 1,
                    ]);
                }
            }
            if (!$section->zones()->exists()) {
                $this->createColumnZones($section, ColumnsLayout::units((string) data_get($section->content, 'ratio', '1:1')));
            }
        }

        return $section;
    }

    private function createColumnZones(Section $columns, array $units): void
    {
        foreach ($units as $index => $widthUnits) {
            SectionContainer::create([
                'page_id' => $columns->page_id,
                'parent_section_id' => $columns->id,
                'kind' => SectionContainer::KIND_ZONE,
                'title' => 'Zóna '.($index + 1),
                'sort_order' => $index + 1,
                'width_units' => $widthUnits,
                'vertical_align' => 'top',
                'block_spacing' => 'standard',
                'style' => [],
            ]);
        }
    }

    private function deleteColumns(BuilderPage $page, Section $columns, string $mode): array
    {
        if (!in_array($mode, ['unwrap', 'destructive'], true)) {
            throw new \ValidationException(['mode' => 'Neplatný způsob odstranění Sloupců.']);
        }
        $structure = $this->exportSection($columns);
        $root = $columns->container;
        if ($root?->kind !== SectionContainer::KIND_ROOT) {
            throw new \ValidationException(['container' => 'Sloupce musí být na hlavní úrovni.']);
        }
        $position = (int) $columns->sort_order;
        $nested = $columns->zones->sortBy('sort_order')->flatMap(
            fn(SectionContainer $zone) => $zone->sections->sortBy('sort_order')
        )->values();

        if ($mode === 'unwrap') {
            $rootOrder = $root->sections()->orderBy('sort_order')->get()->reject(fn(Section $section) => $section->id === $columns->id);
            $before = $rootOrder->filter(fn(Section $section) => (int) $section->sort_order < $position);
            $after = $rootOrder->filter(fn(Section $section) => (int) $section->sort_order > $position);
            $ordered = $before->concat($nested)->concat($after)->values();
            foreach ($ordered as $index => $section) {
                DB::table('humlnetcreative_pages_sections')->where('id', $section->id)->update([
                    'container_id' => $root->id,
                    'sort_order' => $index + 1,
                    'updated_at' => now(),
                ]);
            }
        }
        else {
            foreach ($nested as $section) {
                $section->delete();
            }
        }
        foreach ($columns->zones as $zone) {
            $zone->delete();
        }
        $columns->delete();
        $this->normalizeOrders('humlnetcreative_pages_sections', 'container_id', $root->id);

        return [['section_uuid' => $columns->uuid, 'unwrapped' => $mode === 'unwrap'], new PageCommand(
            'columns.restore', $page->id, 0, ['structure' => $structure, 'position' => $position, 'mode' => $mode]
        )];
    }

    private function restoreColumns(BuilderPage $page, array $payload): array
    {
        $data = (array) ($payload['structure'] ?? []);
        $columns = Section::withTrashed()->where('page_id', $page->id)->where('uuid', $data['uuid'] ?? '')->firstOrFail();
        $root = $this->targetContainer($page, ['target_container_uuid' => $data['container_uuid'] ?? null]);
        $position = $this->position($root->sections(), $payload);
        $this->makeRoom('humlnetcreative_pages_sections', 'container_id', $root->id, $position);
        $columns->restore();
        $columns->container_id = $root->id;
        $columns->sort_order = $position;
        $columns->unsetRelation('container');
        $columns->save();

        foreach (array_values((array) ($data['zones'] ?? [])) as $zoneIndex => $zoneData) {
            $zone = SectionContainer::withTrashed()->where('uuid', $zoneData['uuid'])->first();
            if (!$zone) {
                $zone = new SectionContainer(['uuid' => $zoneData['uuid']]);
            }
            $zone->fill([
                'page_id' => $page->id,
                'parent_section_id' => $columns->id,
                'kind' => SectionContainer::KIND_ZONE,
                'title' => $zoneData['title'] ?? 'Zóna '.($zoneIndex + 1),
                'sort_order' => $zoneIndex + 1,
                'width_units' => (int) $zoneData['width_units'],
                'vertical_align' => $zoneData['vertical_align'] ?? 'top',
                'block_spacing' => $zoneData['block_spacing'] ?? 'standard',
                'style' => (array) ($zoneData['style'] ?? []),
                'deleted_at' => null,
            ]);
            $zone->save();
            foreach (array_values((array) ($zoneData['sections'] ?? [])) as $nestedIndex => $nestedData) {
                $nested = Section::withTrashed()->where('page_id', $page->id)->where('uuid', $nestedData['uuid'])->firstOrFail();
                if ($nested->trashed()) {
                    $nested->restore();
                }
                $nested->container_id = $zone->id;
                $nested->sort_order = $nestedIndex + 1;
                $nested->unsetRelation('container');
                $nested->save();
            }
        }
        $this->normalizeOrders('humlnetcreative_pages_sections', 'container_id', $root->id);

        return [['section_uuid' => $columns->uuid], new PageCommand('section.delete', $page->id, 0, [
            'uuid' => $columns->uuid, 'mode' => $payload['mode'] ?? 'unwrap',
        ])];
    }

    private function configureColumns(BuilderPage $page, array $payload): array
    {
        $columns = $this->section($page, (string) ($payload['uuid'] ?? ''));
        if ($columns->type !== 'columns') {
            throw new \ValidationException(['section' => 'Vybraná sekce není typu Sloupce.']);
        }
        $before = $this->columnsConfiguration($columns);
        $ratio = (string) ($payload['ratio'] ?? data_get($columns->content, 'ratio', '1:1'));
        $units = ColumnsLayout::units($ratio);
        $zones = $columns->zones()->with('sections')->orderBy('sort_order')->get()->values();

        foreach ($zones as $index => $zone) {
            $targetIndex = min($index, count($units) - 1);
            foreach ($zone->sections as $nested) {
                $this->assertSectionFitsZone($nested, $units[$targetIndex]);
            }
        }

        while ($zones->count() < count($units)) {
            $index = $zones->count();
            $zones->push(SectionContainer::create([
                'page_id' => $page->id,
                'parent_section_id' => $columns->id,
                'kind' => SectionContainer::KIND_ZONE,
                'title' => 'Zóna '.($index + 1),
                'sort_order' => $index + 1,
                'width_units' => $units[$index],
                'vertical_align' => 'top',
                'block_spacing' => 'standard',
                'style' => [],
            ]));
        }
        if ($zones->count() > count($units)) {
            $target = $zones[count($units) - 1];
            $nextOrder = (int) $target->sections()->max('sort_order');
            foreach ($zones->slice(count($units)) as $removed) {
                foreach ($removed->sections()->orderBy('sort_order')->get() as $nested) {
                    DB::table('humlnetcreative_pages_sections')->where('id', $nested->id)->update([
                        'container_id' => $target->id, 'sort_order' => ++$nextOrder, 'updated_at' => now(),
                    ]);
                }
                $removed->delete();
            }
            $zones = $zones->take(count($units))->values();
        }

        $zonePayloads = array_values((array) ($payload['zones'] ?? []));
        foreach ($zones as $index => $zone) {
            $settings = (array) ($zonePayloads[$index] ?? []);
            $zone->fill([
                'title' => $settings['title'] ?? $zone->title ?? 'Zóna '.($index + 1),
                'sort_order' => $index + 1,
                'width_units' => $units[$index],
                'vertical_align' => $settings['vertical_align'] ?? $zone->vertical_align ?? 'top',
                'block_spacing' => $settings['block_spacing'] ?? $zone->block_spacing ?? 'standard',
                'style' => array_key_exists('style', $settings) ? (array) $settings['style'] : ($zone->style ?: []),
            ])->save();
        }

        $allowedLayout = array_intersect_key((array) ($payload['layout'] ?? []), array_flip([
            'width', 'spacing', 'columns_gap', 'tablet_behavior', 'mobile_order', 'full_padding',
        ]));
        $generalChanges = array_intersect_key((array) ($payload['changes'] ?? []), array_flip([
            'title', 'is_published', 'style',
        ]));
        $columns->fill($generalChanges);
        $columns->content = array_replace($columns->content ?: [], ['ratio' => $ratio]);
        $columns->layout = array_replace($columns->layout ?: [], $allowedLayout);
        $columns->save();
        ColumnsLayout::validateZones($columns->fresh());

        return [['section_uuid' => $columns->uuid], new PageCommand('columns.restore_configuration', $page->id, 0, [
            'uuid' => $columns->uuid, 'configuration' => $before,
        ])];
    }

    private function restoreColumnsConfiguration(BuilderPage $page, array $payload): array
    {
        $columns = $this->section($page, (string) ($payload['uuid'] ?? ''));
        $configuration = (array) ($payload['configuration'] ?? []);
        $inverse = $this->columnsConfiguration($columns);
        $wantedZoneIds = [];

        foreach (array_values((array) ($configuration['zones'] ?? [])) as $index => $zoneData) {
            $zone = SectionContainer::withTrashed()->where('uuid', $zoneData['uuid'])->first();
            if (!$zone) {
                $zone = new SectionContainer(['uuid' => $zoneData['uuid']]);
            }
            $zone->fill([
                'page_id' => $page->id,
                'parent_section_id' => $columns->id,
                'kind' => SectionContainer::KIND_ZONE,
                'title' => $zoneData['title'] ?? 'Zóna '.($index + 1),
                'sort_order' => $index + 1,
                'width_units' => (int) $zoneData['width_units'],
                'vertical_align' => $zoneData['vertical_align'] ?? 'top',
                'block_spacing' => $zoneData['block_spacing'] ?? 'standard',
                'style' => (array) ($zoneData['style'] ?? []),
                'deleted_at' => null,
            ])->save();
            $wantedZoneIds[] = $zone->id;
            foreach (array_values((array) ($zoneData['section_uuids'] ?? [])) as $sectionIndex => $uuid) {
                DB::table('humlnetcreative_pages_sections')->where('page_id', $page->id)->where('uuid', $uuid)->update([
                    'container_id' => $zone->id, 'sort_order' => $sectionIndex + 1, 'updated_at' => now(),
                ]);
            }
        }
        $obsolete = $columns->zones()->whereNotIn('id', $wantedZoneIds)->get();
        foreach ($obsolete as $zone) {
            $zone->delete();
        }
        $columns->content = (array) ($configuration['content'] ?? []);
        $columns->layout = (array) ($configuration['layout'] ?? []);
        $columns->title = $configuration['title'] ?? $columns->title;
        $columns->is_published = (bool) ($configuration['is_published'] ?? $columns->is_published);
        $columns->style = (array) ($configuration['style'] ?? []);
        $columns->save();
        ColumnsLayout::validateZones($columns->fresh());

        return [['section_uuid' => $columns->uuid], new PageCommand('columns.restore_configuration', $page->id, 0, [
            'uuid' => $columns->uuid, 'configuration' => $inverse,
        ])];
    }

    private function columnsConfiguration(Section $columns): array
    {
        $columns->loadMissing('zones.sections');

        return [
            'title' => $columns->title,
            'is_published' => (bool) $columns->is_published,
            'style' => $columns->style ?: [],
            'content' => $columns->content ?: [],
            'layout' => $columns->layout ?: [],
            'zones' => $columns->zones->sortBy('sort_order')->map(fn(SectionContainer $zone): array => [
                'uuid' => $zone->uuid,
                'title' => $zone->title,
                'width_units' => (int) $zone->width_units,
                'vertical_align' => $zone->vertical_align,
                'block_spacing' => $zone->block_spacing,
                'style' => $zone->style ?: [],
                'section_uuids' => $zone->sections->sortBy('sort_order')->pluck('uuid')->all(),
            ])->values()->all(),
        ];
    }

    private function assertSectionFitsZone(Section $section, int $widthUnits): void
    {
        $registry = SectionRegistry::instance();
        if ($section->type === 'columns' || !$registry->allowedInColumns($section->type)) {
            throw new \ValidationException(['type' => 'Tento typ sekce nelze vložit do Sloupců.']);
        }
        if ($registry->minimumWidthUnits($section->type) > $widthUnits) {
            throw new \ValidationException(['type' => 'Obsah některé zóny vyžaduje větší podíl; před změnou poměru jej přesuňte.']);
        }
    }

    private function importItem(Section $section, array $data, bool $freshUuid, array $payload): SectionItem
    {
        $position = $this->position($section->items(), $payload, ((int) $data['sort_order']) + 1);
        $this->makeRoom('humlnetcreative_pages_section_items', 'section_id', $section->id, $position);
        $item = SectionItem::create([
            'uuid' => $freshUuid ? (string) Str::uuid() : $data['uuid'],
            'section_id' => $section->id,
            'type' => $data['type'],
            'is_published' => $data['is_published'],
            'sort_order' => $position,
            'style' => $data['style'],
            'content' => $data['content'],
        ]);
        foreach ($data['media'] as $media) {
            $this->importMedia($item, $media);
        }

        return $item;
    }

    private function importMedia(Section|SectionItem $owner, array $data): MediaUse
    {
        $sourceUuid = (string) ($data['uuid'] ?? '');
        $newUuid = (string) Str::uuid();
        $asset = MediaAsset::findOrFail((int) $data['media_asset_id']);
        $variants = $this->copyVariantFiles(
            (array) ($data['variants'] ?? []),
            (string) $asset->uuid,
            $newUuid,
        );

        unset($data['uuid']);

        return $owner->media()->create(array_replace($data, [
            'uuid' => $newUuid,
            'variants' => $variants,
        ]));
    }

    /** Each copied use owns its derivatives even though the private master stays shared. */
    private function copyVariantFiles(array $variants, string $assetUuid, string $newUuid): array
    {
        foreach ($variants as &$formatVariants) {
            foreach ((array) $formatVariants as $size => $sourcePath) {
                $basename = basename((string) $sourcePath);
                $targetPath = 'pages-variants/'.$assetUuid.'/'.$newUuid.'/'.$basename;
                if (!Storage::disk('media')->exists((string) $sourcePath)) {
                    throw new \ApplicationException('Mediální varianta kopírovaného bloku už není dostupná. Obnovte zdrojové médium a akci zopakujte.');
                }
                Storage::disk('media')->copy((string) $sourcePath, $targetPath);
                $formatVariants[$size] = $targetPath;
            }
        }
        unset($formatVariants);

        return $variants;
    }

    private function position($relation, array $payload, ?int $fallback = null): int
    {
        $maximum = (int) $relation->count() + 1;
        $position = isset($payload['position'])
            ? (int) $payload['position']
            : ($fallback ?? $maximum);

        return min($maximum, max(1, $position));
    }

    private function targetContainer(BuilderPage $page, array $payload): SectionContainer
    {
        $uuid = (string) ($payload['target_container_uuid'] ?? $payload['container_uuid'] ?? '');
        if ($uuid !== '') {
            return $page->section_containers()->where('uuid', $uuid)->firstOrFail();
        }

        $root = $page->root_container;
        if (!$root) {
            throw new \UnexpectedValueException('Stránka nemá hlavní kontejner obsahu.');
        }

        return $root;
    }

    /** Maintains the command API invariant that visible records use ordinal positions 1…N. */
    private function normalizeOrders(string $table, string $foreignKey, int $foreignId): void
    {
        $ids = DB::table($table)
            ->where($foreignKey, $foreignId)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id');
        foreach ($ids as $index => $id) {
            DB::table($table)->where('id', $id)->update(['sort_order' => $index + 1]);
        }
    }

    private function makeRoom(string $table, string $foreignKey, int $foreignId, int $position): void
    {
        DB::table($table)
            ->where($foreignKey, $foreignId)
            ->whereNull('deleted_at')
            ->where('sort_order', '>=', $position)
            ->increment('sort_order');
    }

    private function section(BuilderPage $page, string $uuid): Section
    {
        return $page->sections()->where('uuid', $uuid)->firstOrFail();
    }

    private function item(BuilderPage $page, string $uuid): SectionItem
    {
        return SectionItem::where('uuid', $uuid)
            ->whereHas('section', fn($query) => $query->where('page_id', $page->id))
            ->firstOrFail();
    }

    private function commandSectionType(BuilderPage $page, PageCommand $command): ?string
    {
        if (str_starts_with($command->name, 'columns.')) {
            $uuid = $command->payload['uuid'] ?? data_get($command->payload, 'structure.uuid');
            return Section::withTrashed()->where('page_id', $page->id)->where('uuid', $uuid ?? '')->value('type') ?: 'columns';
        }
        if ($command->name === 'section.create') {
            return $command->payload['type'] ?? null;
        }
        if ($command->name === 'section.paste') {
            return data_get(app(PageCommandClipboard::class)->get(), 'section.type');
        }
        if (str_starts_with($command->name, 'section.')) {
            if (isset($command->payload['uuids'][0])) {
                return Section::withTrashed()->where('page_id', $page->id)->where('uuid', $command->payload['uuids'][0])->value('type');
            }
            if (isset($command->payload['records'][0]['uuid'])) {
                return Section::withTrashed()->where('page_id', $page->id)->where('uuid', $command->payload['records'][0]['uuid'])->value('type');
            }
            return Section::withTrashed()->where('page_id', $page->id)->where('uuid', $command->payload['uuid'] ?? '')->value('type');
        }
        if (str_starts_with($command->name, 'item.')) {
            if (isset($command->payload['section_uuid'])) {
                return Section::withTrashed()->where('page_id', $page->id)->where('uuid', $command->payload['section_uuid'])->value('type');
            }
            $itemUuid = $command->payload['uuid']
                ?? data_get($command->payload, 'uuids.0')
                ?? data_get($command->payload, 'records.0.uuid');
            return SectionItem::withTrashed()->where('uuid', $itemUuid ?? '')
                ->with('section')->first()?->section?->type;
        }

        return null;
    }

    private function compatibleItemSections(Section $source, Section $target): bool
    {
        return (int) $source->page_id === (int) $target->page_id
            && $source->type === $target->type
            && SectionRegistry::instance()->itemsSupported($target->type);
    }

    private function assertSameUuids(array $current, array $requested, string $label): void
    {
        $a = $current;
        $b = $requested;
        sort($a);
        sort($b);
        if ($a !== $b) {
            throw new \ValidationException(['order' => "Pořadí musí obsahovat právě všechny UUID {$label} bez duplicit."]);
        }
    }

    private function assertPermission(User $user, string $permission): void
    {
        if (!$user->hasAccess($permission)) {
            throw new \ApplicationException('Pro tuto operaci nemáte oprávnění.');
        }
    }

    private function auditPayload(array $payload): array
    {
        $sanitize = function(array $value) use (&$sanitize): array {
            foreach ($value as $key => $item) {
                if (in_array((string) $key, ['content', 'style', 'layout'], true)) {
                    $value[$key] = is_array($item) ? ['changed_keys' => array_keys($item)] : '[changed]';
                }
                elseif (is_array($item)) {
                    $value[$key] = $sanitize($item);
                }
            }

            return $value;
        };

        return $sanitize($payload);
    }
}
