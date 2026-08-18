<?php namespace HumlnetCreative\Pages\Tests;

use HumlnetCreative\Pages\Models\BuilderPage;
use HumlnetCreative\Pages\Models\PageRevision;
use HumlnetCreative\Pages\Services\PagePublicationPreflight;
use HumlnetCreative\Pages\Services\PagePublicationService;
use HumlnetCreative\Pages\Services\PageDeletionService;
use HumlnetCreative\Pages\Services\WorkingCopyRestorer;
use Illuminate\Support\Facades\DB;
use PluginTestCase;
use System\Models\SiteDefinition;
use Vdlp\Redirect\Models\Redirect;

final class PagePublicationPreflightTest extends PluginTestCase
{
    public function testPublishedProjectionCollisionStopsPublicationBeforeRevisionWrite(): void
    {
        $publication = app(PagePublicationService::class);
        $formerOwner = $this->page('puvodni', 'Původní vlastník');
        $publication->publish($formerOwner);
        $formerOwner->slug = 'nova-cesta';
        $formerOwner->save();

        $candidate = $this->page('puvodni', 'Nový kandidát');
        $result = app(PagePublicationPreflight::class)->inspect($candidate);

        $this->assertFalse($result->passes());
        $this->assertStringContainsString('stále veřejně používá', implode(' ', $result->errors));

        try {
            $publication->publish($candidate);
            $this->fail('Publikace měla skončit kolizí veřejné URL.');
        }
        catch (\ValidationException $exception) {
            $this->assertStringContainsString('/puvodni', $exception->getMessage());
        }
        $this->assertSame(0, PageRevision::where('page_id', $candidate->id)->count());
    }

    public function testReservedRouteIsRecheckedAtPublicationTime(): void
    {
        $publication = app(PagePublicationService::class);
        $page = $this->page('bezpecna', 'Bezpečná');
        $published = $publication->publish($page);

        // Simulates a path becoming reserved after the draft was saved, without
        // relying on a mutable theme fixture in this integration test.
        DB::table($page->getTable())->where('id', $page->id)->update([
            'slug' => 'api',
            'fullslug' => 'api',
            'has_draft' => true,
        ]);

        try {
            $publication->publish($page->fresh());
            $this->fail('Publikace rezervované cesty měla selhat.');
        }
        catch (\ValidationException $exception) {
            $this->assertStringContainsString('rezervovaná', $exception->getMessage());
        }
        $this->assertSame($published->id, $page->fresh()->published_revision_id);
        $this->assertSame(1, PageRevision::where('page_id', $page->id)->count());
    }

    public function testManualRedirectConflictDoesNotOverwriteRuleOrPublishedSnapshot(): void
    {
        $publication = app(PagePublicationService::class);
        $page = $this->page('stara-cesta', 'Stránka');
        $published = $publication->publish($page);
        $page->slug = 'nova-cesta';
        $page->save();
        $manual = $this->manualRedirect('/stara-cesta', '/rucni-cil');

        $result = app(PagePublicationPreflight::class)->inspect($page->fresh());
        $this->assertFalse($result->passes());
        $this->assertStringContainsString('ruční redirect', implode(' ', $result->errors));

        try {
            $publication->publish($page->fresh());
            $this->fail('Publikace s ručním redirectem měla selhat.');
        }
        catch (\ValidationException) {
            // Expected.
        }

        $this->assertSame('/rucni-cil', $manual->fresh()->to_url);
        $this->assertFalse((bool) $manual->fresh()->system);
        $this->assertSame($published->id, $page->fresh()->published_revision_id);

        $publication->publish($page->fresh(), null, true);
        $replaced = $manual->fresh();
        $this->assertSame('nova-cesta', $replaced->to_url);
        $this->assertTrue((bool) $replaced->system);
        $this->assertStringStartsWith('humlnetcreative.pages:', (string) $replaced->description);
    }

    public function testParentUrlChangeReportsEveryPublishedDescendantAndPublishesBranchAtomically(): void
    {
        $publication = app(PagePublicationService::class);
        $parent = $this->page('rodic', 'Rodič');
        $publication->publish($parent);
        $child = BuilderPage::create([
            'title' => 'Potomek',
            'slug' => 'potomek',
            'parent_id' => $parent->id,
            'is_published' => true,
            'sort_order' => 1,
            'style' => [],
        ]);
        $publication->publish($child);

        $parent->slug = 'novy-rodic';
        $parent->save();
        $result = app(PagePublicationPreflight::class)->inspect($parent->fresh());

        $this->assertFalse($result->passes());
        $this->assertCount(1, $result->descendants());
        $this->assertSame('/rodic/potomek', $result->descendants()[0]->oldPath);
        $this->assertSame('/novy-rodic/potomek', $result->descendants()[0]->newPath);
        $this->assertStringContainsString('Publikujte celou větev atomicky', implode(' ', $result->errors));

        $publication->publish($parent->fresh());

        $this->assertSame('/novy-rodic', '/'.$parent->fresh()->published_fullslug);
        $this->assertSame('/novy-rodic/potomek', '/'.$child->fresh()->published_fullslug);
        $this->assertSame('novy-rodic', Redirect::where('from_url', '/rodic')->value('to_url'));
        $this->assertSame('novy-rodic/potomek', Redirect::where('from_url', '/rodic/potomek')->value('to_url'));
    }

    public function testDescendantRedirectConflictRollsBackWholeBranchBeforeAnyWrite(): void
    {
        $publication = app(PagePublicationService::class);
        $parent = $this->page('vetev', 'Větev');
        $parentRevision = $publication->publish($parent);
        $child = BuilderPage::create([
            'title' => 'Konfliktní potomek',
            'slug' => 'potomek',
            'parent_id' => $parent->id,
            'is_published' => true,
            'sort_order' => 1,
            'style' => [],
        ]);
        $childRevision = $publication->publish($child);
        $manual = $this->manualRedirect('/vetev/potomek', '/rucni-cil');

        $parent->slug = 'nova-vetev';
        $parent->save();
        try {
            $publication->publish($parent->fresh());
            $this->fail('Konflikt potomka měl zastavit publikaci celé větve.');
        }
        catch (\ValidationException $exception) {
            $this->assertStringContainsString('ruční redirect', $exception->getMessage());
        }

        $this->assertSame($parentRevision->id, $parent->fresh()->published_revision_id);
        $this->assertSame($childRevision->id, $child->fresh()->published_revision_id);
        $this->assertDatabaseMissing('vdlp_redirect_redirects', ['from_url' => '/vetev']);
        $this->assertSame('/rucni-cil', $manual->fresh()->to_url);
    }

    public function testRepeatedRenameFlattensOwnedRedirectChain(): void
    {
        $publication = app(PagePublicationService::class);
        $page = $this->page('prvni', 'Řetězec');
        $publication->publish($page);

        $page->slug = 'druha';
        $page->save();
        $publication->publish($page->fresh());
        $page = $page->fresh();
        $page->slug = 'treti';
        $page->save();
        $publication->publish($page->fresh());

        $this->assertSame('treti', Redirect::where('from_url', '/prvni')->value('to_url'));
        $this->assertSame('treti', Redirect::where('from_url', '/druha')->value('to_url'));
        $this->assertDatabaseMissing('vdlp_redirect_redirects', [
            'from_url' => '/prvni',
            'to_url' => 'druha',
        ]);
    }

    public function testRenamingBackToPreviousPublishedUrlCannotCreateRedirectCycle(): void
    {
        $publication = app(PagePublicationService::class);
        $page = $this->page('prvni', 'Návrat URL');
        $publication->publish($page);

        $page->slug = 'druha';
        $page->save();
        $publication->publish($page->fresh());

        $page = $page->fresh();
        $page->slug = 'prvni';
        $page->save();
        $publication->publish($page->fresh());

        $this->assertSame('prvni', $page->fresh()->published_fullslug);
        $this->assertDatabaseMissing('vdlp_redirect_redirects', ['from_url' => '/prvni']);
        $this->assertSame('prvni', Redirect::where('from_url', '/druha')->value('to_url'));
    }

    public function testLanguagePrefixedSiteUsesPublicPathsInPreflightAndRedirect(): void
    {
        $site = new SiteDefinition();
        $site->name = 'Čeština';
        $site->code = 'cs-preflight';
        $site->locale = 'cs';
        $site->is_enabled = true;
        $site->is_enabled_edit = true;
        $site->is_prefixed = true;
        $site->route_prefix = '/cs';
        $site->save();

        $page = BuilderPage::withoutGlobalScopes()->create([
            'title' => 'Jazyková cesta',
            'slug' => 'puvodni',
            'is_published' => true,
            'sort_order' => 1,
            'style' => [],
        ]);
        DB::table($page->getTable())->where('id', $page->id)->update(['site_id' => $site->id]);
        $page = BuilderPage::withoutGlobalScopes()->findOrFail($page->id);
        $publication = app(PagePublicationService::class);
        $this->assertSame('/cs/puvodni', app(PagePublicationPreflight::class)->inspect($page)->root()->newPath);
        $publication->publish($page);

        DB::table($page->getTable())->where('id', $page->id)->update([
            'slug' => 'nova',
            'fullslug' => 'nova',
            'has_draft' => true,
        ]);
        $page = BuilderPage::withoutGlobalScopes()->findOrFail($page->id);
        $this->assertSame('/cs/nova', app(PagePublicationPreflight::class)->inspect($page)->root()->newPath);
        $publication->publish($page);

        $redirect = Redirect::where('from_url', '/cs/puvodni')->firstOrFail();
        $this->assertSame('cs/nova', $redirect->to_url);
        $this->assertStringContainsString('site='.$site->id, (string) $redirect->description);
    }

    public function testDeletionProposalIsOnlyDraftAndDiscardRestoresWorkingCopy(): void
    {
        $page = $this->page('navrh-smazani', 'Návrh smazání');
        $publication = app(PagePublicationService::class);
        $publication->publish($page);

        $proposed = app(PageDeletionService::class)->propose($page->fresh(), 'gone');
        $this->assertSame('gone', $proposed->deletion_mode);
        $this->assertTrue((bool) $proposed->has_draft);
        $this->assertNotNull($publication->findPublishedByPath('navrh-smazani'));

        $restored = app(WorkingCopyRestorer::class)->discard($proposed);
        $this->assertNull($restored->deletion_mode);
        $this->assertFalse((bool) $restored->has_draft);
        $this->assertNotNull($publication->findPublishedByPath('navrh-smazani'));
    }

    public function testCancellingOnlyDeletionProposalDoesNotLeavePhantomDraft(): void
    {
        $page = $this->page('zruseny-navrh', 'Zrušený návrh');
        $publication = app(PagePublicationService::class);
        $publication->publish($page);
        $deletion = app(PageDeletionService::class);

        $proposal = $deletion->propose($page->fresh(), 'gone');
        $cancelled = $deletion->cancel($proposal);

        $this->assertNull($cancelled->deletion_mode);
        $this->assertFalse((bool) $cancelled->has_draft);

        $cancelled->title = 'Jiný pracovní titulek';
        $cancelled->save();
        $proposal = $deletion->propose($cancelled->fresh(), 'gone');
        $cancelledWithContent = $deletion->cancel($proposal);
        $this->assertTrue((bool) $cancelledWithContent->has_draft);
        $this->assertSame('Jiný pracovní titulek', $cancelledWithContent->title);
    }

    public function testPublishingGoneProposalSoftDeletesPageAndCreates410(): void
    {
        $page = $this->page('trvale-pryc', 'Trvale pryč');
        $publication = app(PagePublicationService::class);
        $publication->publish($page);
        $proposal = app(PageDeletionService::class)->propose($page->fresh(), 'gone');

        $publication->publish($proposal);

        $this->assertNull($publication->findPublishedByPath('trvale-pryc'));
        $this->assertNotNull(DB::table($page->getTable())->where('id', $page->id)->value('deleted_at'));
        $this->assertSame(2, PageRevision::where('page_id', $page->id)->count());
        $rule = Redirect::where('from_url', '/trvale-pryc')->firstOrFail();
        $this->assertSame(410, (int) $rule->status_code);
        $this->assertSame(Redirect::TARGET_TYPE_NONE, $rule->target_type);

        $deleted = BuilderPage::withoutGlobalScopes()->findOrFail($page->id);
        $restored = app(WorkingCopyRestorer::class)->restoreDeleted($deleted);
        $this->assertTrue((bool) $restored->has_draft);
        $this->assertNull($publication->findPublishedByPath('trvale-pryc'));
        $this->assertNotNull(Redirect::where('from_url', '/trvale-pryc')->first());

        $publication->publish($restored);
        $this->assertNotNull($publication->findPublishedByPath('trvale-pryc'));
        $this->assertNull(Redirect::where('from_url', '/trvale-pryc')->first());
        $this->assertNull(DB::table($page->getTable())->where('id', $page->id)->value('deleted_at'));
    }

    public function testPublishingDeletionCanRedirectToSelectedPublishedPage(): void
    {
        $publication = app(PagePublicationService::class);
        $target = $this->page('cil', 'Cíl');
        $publication->publish($target);
        $removed = $this->page('odstranovana', 'Odstraňovaná');
        $publication->publish($removed);
        $proposal = app(PageDeletionService::class)->propose($removed->fresh(), 'page', $target->id);

        $publication->publish($proposal);

        $rule = Redirect::where('from_url', '/odstranovana')->firstOrFail();
        $this->assertSame(301, (int) $rule->status_code);
        $this->assertSame('cil', $rule->to_url);
        $this->assertNull($publication->findPublishedByPath('odstranovana'));
        $this->assertNotNull($publication->findPublishedByPath('cil'));
    }

    public function testDeletionWithPublishedChildFailsWithoutPartialWrites(): void
    {
        $publication = app(PagePublicationService::class);
        $parent = $this->page('mazany-rodic', 'Mazáný rodič');
        $publication->publish($parent);
        $child = BuilderPage::create([
            'title' => 'Živý potomek',
            'slug' => 'potomek',
            'parent_id' => $parent->id,
            'is_published' => true,
            'sort_order' => 1,
            'style' => [],
        ]);
        $publication->publish($child);
        $proposal = app(PageDeletionService::class)->propose($parent->fresh(), 'gone');

        try {
            $publication->publish($proposal);
            $this->fail('Odstranění rodiče s publikovaným potomkem mělo selhat.');
        }
        catch (\ValidationException $exception) {
            $this->assertStringContainsString('publikované potomky', $exception->getMessage());
        }

        $this->assertNotNull($publication->findPublishedByPath('mazany-rodic'));
        $this->assertNotNull($publication->findPublishedByPath('mazany-rodic/potomek'));
        $this->assertDatabaseMissing('vdlp_redirect_redirects', ['from_url' => '/mazany-rodic']);
        $this->assertSame(1, PageRevision::where('page_id', $parent->id)->count());
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

    private function manualRedirect(string $source, string $target): Redirect
    {
        return Redirect::create([
            'match_type' => Redirect::TYPE_EXACT,
            'target_type' => Redirect::TARGET_TYPE_PATH_URL,
            'from_scheme' => Redirect::SCHEME_AUTO,
            'from_url' => $source,
            'to_scheme' => Redirect::SCHEME_AUTO,
            'to_url' => $target,
            'status_code' => 301,
            'sort_order' => 0,
            'is_enabled' => true,
            'test_lab' => false,
            'system' => false,
            'ignore_query_parameters' => true,
            'keep_querystring' => false,
            'ignore_case' => false,
            'ignore_trailing_slash' => true,
        ]);
    }
}
