<?php namespace HumlnetCreative\Pages\Tests;

use Backend\Models\User;
use Carbon\Carbon;
use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\MediaAsset;
use HumlnetCreative\Pages\Models\PageAuditLog;
use HumlnetCreative\Pages\Models\PageEditLock;
use HumlnetCreative\Pages\Models\PageRevision;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionItem;
use HumlnetCreative\Pages\Services\PageEditLockService;
use HumlnetCreative\Pages\Services\PagePublicationService;
use HumlnetCreative\Pages\Services\WorkingCopyRestorer;
use Illuminate\Support\Facades\Storage;
use PluginTestCase;

final class PageRevisionWorkflowTest extends PluginTestCase
{
    public function testDraftStaysPrivateAndDiscardRestoresPublishedState(): void
    {
        $page = $this->page('puvodni', 'Původní titulek');
        $section = Section::create([
            'page_id' => $page->id,
            'type' => 'cards',
            'title' => 'Původní karty',
            'is_published' => true,
            'sort_order' => 1,
            'layout' => ['width' => 'contained', 'spacing' => 'standard'],
            'content' => ['heading' => 'Původní nadpis', 'columns' => 3],
        ]);
        $item = SectionItem::create([
            'section_id' => $section->id,
            'type' => 'card',
            'is_published' => true,
            'sort_order' => 1,
            'content' => ['heading' => 'Původní karta', 'text' => 'Původní text'],
        ]);
        $publication = app(PagePublicationService::class);
        $revision = $publication->publish($page);

        $page->title = 'Rozpracovaný titulek';
        $page->slug = 'koncept';
        $page->save();
        $section->content = ['heading' => 'Rozpracovaný nadpis', 'columns' => 2];
        $section->save();
        $item->delete();

        $draft = $page->fresh();
        $this->assertTrue((bool) $draft->has_draft);
        $this->assertSame('Rozpracovaný titulek', $draft->title);
        $this->assertSame('Původní titulek', $publication->findPublishedByPath('puvodni')->title);
        $this->assertNull($publication->findPublishedByPath('koncept'));

        $restored = app(WorkingCopyRestorer::class)->discard($draft);
        $this->assertFalse((bool) $restored->has_draft);
        $this->assertSame('Původní titulek', $restored->title);
        $this->assertSame('puvodni', $restored->fullslug);
        $this->assertSame($revision->id, $restored->published_revision_id);
        $this->assertSame('Původní nadpis', data_get($restored->sections()->first()->content, 'heading'));
        $this->assertSame('Původní karta', data_get($restored->sections()->first()->items()->first()->content, 'heading'));
    }

    public function testHistoryRestoreCreatesDraftWithoutChangingPublicVersion(): void
    {
        $page = $this->page('historie', 'Verze jedna');
        $publication = app(PagePublicationService::class);
        $first = $publication->publish($page);
        $page->title = 'Verze dvě';
        $page->save();
        $second = $publication->publish($page->fresh());

        $restored = app(WorkingCopyRestorer::class)->restoreRevision($first, true);

        $this->assertTrue((bool) $restored->has_draft);
        $this->assertSame('Verze jedna', $restored->title);
        $this->assertSame('Verze dvě', $publication->findPublishedByPath('historie')->title);
        $this->assertSame($second->id, $restored->published_revision_id);
        $this->assertDatabaseHas('humlnetcreative_pages_audit_logs', [
            'page_id' => $page->id,
            'action' => 'history.restored',
        ]);
    }

    public function testSavingUnchangedPublishedPageDoesNotCreatePhantomDraft(): void
    {
        $page = $this->page('beze-zmeny', 'Beze změny');
        app(PagePublicationService::class)->publish($page);

        $fresh = $page->fresh();
        $this->assertFalse((bool) $fresh->has_draft);
        $draftVersion = (int) $fresh->draft_version;
        $fresh->save();

        $fresh->refresh();
        $this->assertFalse((bool) $fresh->has_draft);
        $this->assertSame($draftVersion, (int) $fresh->draft_version);
    }

    public function testNewPageIsNotPublicBeforeFirstPublicationAndOnlyTenVersionsRemain(): void
    {
        $page = $this->page('nova', 'Nová stránka');
        $publication = app(PagePublicationService::class);
        $this->assertNull($publication->findPublishedByPath('nova'));

        for ($version = 1; $version <= 12; $version++) {
            $page->title = 'Verze '.$version;
            $page->save();
            $publication->publish($page->fresh());
        }

        $this->assertSame(PagePublicationService::RETAINED_REVISIONS, PageRevision::where('page_id', $page->id)->count());
        $this->assertSame([12, 11, 10, 9, 8, 7, 6, 5, 4, 3], PageRevision::where('page_id', $page->id)->orderByDesc('version')->pluck('version')->all());
        $this->assertSame('Verze 12', $publication->findPublishedByPath('nova')->title);
    }

    public function testNeverPublishedPageCanBeSoftDeletedWithItsOrphanedStructure(): void
    {
        $page = $this->page('ke-smazani', 'Ke smazání');
        $section = Section::create([
            'page_id' => $page->id,
            'type' => 'cards',
            'title' => 'Karty',
            'is_published' => true,
            'sort_order' => 1,
            'layout' => ['width' => 'contained', 'spacing' => 'standard'],
            'content' => ['heading' => 'Karty', 'columns' => 3],
        ]);
        $item = SectionItem::create([
            'section_id' => $section->id,
            'type' => 'card',
            'is_published' => true,
            'sort_order' => 1,
            'content' => ['heading' => 'Položka'],
        ]);

        app(WorkingCopyRestorer::class)->deleteUnpublished($page);

        $this->assertSoftDeleted('humlnetcreative_pages_builder_pages', ['id' => $page->id]);
        $this->assertSoftDeleted('humlnetcreative_pages_sections', ['id' => $section->id]);
        $this->assertSoftDeleted('humlnetcreative_pages_section_items', ['id' => $item->id]);
        $this->assertDatabaseHas('humlnetcreative_pages_audit_logs', [
            'page_id' => $page->id,
            'action' => 'draft.deleted',
        ]);
    }

    public function testFailedPublicationRollsBackRevisionAndPublishedPointer(): void
    {
        $page = $this->page('atomicka', 'Platná verze');
        $section = Section::create([
            'page_id' => $page->id,
            'type' => 'text',
            'title' => 'Text',
            'is_published' => true,
            'sort_order' => 1,
            'layout' => ['width' => 'contained', 'spacing' => 'standard'],
            'content' => ['heading' => 'Platný obsah', 'text' => '<p>Text</p>'],
        ]);
        $publication = app(PagePublicationService::class);
        $published = $publication->publish($page);

        $section->media()->create([
            'media_asset_id' => 999999,
            'slot' => 'text_image',
            'alt_text' => null,
            'is_decorative' => true,
        ]);

        try {
            $publication->publish($page->fresh());
            $this->fail('Publikace s chybějícím mediálním assetem měla selhat.');
        }
        catch (\UnexpectedValueException) {
            // Expected preflight/serialization failure.
        }

        $fresh = $page->fresh();
        $this->assertSame($published->id, $fresh->published_revision_id);
        $this->assertSame(1, PageRevision::where('page_id', $page->id)->count());
        $this->assertSame('Platná verze', $publication->findPublishedByPath('atomicka')->title);
    }

    public function testMediaSurvivesHistoryAndIsCollectedAfterItsLastRevisionIsPruned(): void
    {
        Storage::fake('local');
        Storage::fake('media');
        $page = $this->page('media', 'Média');
        $section = Section::create([
            'page_id' => $page->id,
            'type' => 'hero',
            'title' => 'Hero',
            'is_published' => true,
            'sort_order' => 1,
            'layout' => ['width' => 'full', 'spacing' => 'none'],
            'content' => ['heading' => 'Hero', 'position' => 'left-center'],
        ]);
        $asset = MediaAsset::create([
            'disk' => 'local',
            'path' => 'pages/master.webp',
            'original_name' => 'master.webp',
            'mime_type' => 'image/webp',
            'size' => 100,
            'width' => 1200,
            'height' => 800,
        ]);
        $use = $section->media()->create([
            'media_asset_id' => $asset->id,
            'slot' => 'hero_desktop',
            'alt_text' => null,
            'is_decorative' => true,
            'variants' => ['webp' => ['1600' => 'pages-variants/'.$asset->uuid.'/pending/hero.webp']],
        ]);
        $use->variants = ['webp' => ['1600' => 'pages-variants/'.$asset->uuid.'/'.$use->uuid.'/hero.webp']];
        $use->save();
        Storage::disk('local')->put($asset->path, 'master');
        Storage::disk('media')->put('pages-variants/'.$asset->uuid.'/'.$use->uuid.'/hero.webp', 'variant');

        $publication = app(PagePublicationService::class);
        $publication->publish($page->fresh());
        $use->delete();
        Storage::disk('local')->assertExists($asset->path);
        Storage::disk('media')->assertExists('pages-variants/'.$asset->uuid.'/'.$use->uuid.'/hero.webp');

        for ($version = 2; $version <= 11; $version++) {
            $page->title = 'Média '.$version;
            $page->save();
            $publication->publish($page->fresh());
        }

        Storage::disk('local')->assertMissing($asset->path);
        Storage::disk('media')->assertMissing('pages-variants/'.$asset->uuid.'/'.$use->uuid.'/hero.webp');
        $this->assertDatabaseMissing('humlnetcreative_pages_media_assets', ['id' => $asset->id]);
    }

    public function testLockIsReadOnlyForAnotherSessionExpiresAndCanBeTakenOver(): void
    {
        $page = $this->page('zamek', 'Zámek');
        $firstUser = $this->user('prvni');
        $secondUser = $this->user('druhy');
        $service = app(PageEditLockService::class);

        $this->assertTrue($service->acquire($page, $firstUser, '11111111-1111-4111-8111-111111111111')->writable);
        $this->assertFalse($service->acquire($page, $secondUser, '22222222-2222-4222-8222-222222222222')->writable);

        $service->takeover($page, $secondUser, '22222222-2222-4222-8222-222222222222');
        $this->assertFalse($service->acquire($page, $firstUser, '11111111-1111-4111-8111-111111111111')->writable);
        $this->assertTrue($service->acquire($page, $secondUser, '22222222-2222-4222-8222-222222222222')->writable);

        PageEditLock::where('page_id', $page->id)->update(['heartbeat_at' => Carbon::now()->subMinutes(16)]);
        $this->assertTrue($service->acquire($page, $firstUser, '11111111-1111-4111-8111-111111111111')->writable);
        $this->assertSame($firstUser->id, PageEditLock::where('page_id', $page->id)->value('user_id'));
        $this->assertSame(1, PageAuditLog::where('page_id', $page->id)->where('action', 'lock.taken_over')->count());
    }

    private function page(string $slug, string $title): BuilderPage
    {
        return BuilderPage::create([
            'title' => $title,
            'slug' => $slug,
            'is_published' => true,
            'sort_order' => 1,
            'style' => [],
        ]);
    }

    private function user(string $login): User
    {
        return User::create([
            'login' => $login,
            'email' => $login.'@example.test',
            'first_name' => ucfirst($login),
            'last_name' => 'Editor',
            'password' => 'test-password',
            'password_confirmation' => 'test-password',
            'is_activated' => true,
        ]);
    }
}
