<?php namespace HumlnetCreative\Pages\Tests;

use HumlnetCreative\Pages\Classes\Redirect\RedirectConflict;
use HumlnetCreative\Pages\Classes\Redirect\RedirectConflictException;
use HumlnetCreative\Pages\Classes\Redirect\RedirectContext;
use HumlnetCreative\Pages\Contracts\RedirectManagerInterface;
use PluginTestCase;
use Vdlp\Redirect\Models\Redirect;

final class VdlpRedirectAdapterContractTest extends PluginTestCase
{
    public function testManualConflictRequiresExplicitReplacement(): void
    {
        $manual = $this->redirect('/puvodni', '/rucni-cil');
        $adapter = app(RedirectManagerInterface::class);
        $context = new RedirectContext(1);

        $conflict = $adapter->findConflict('/puvodni', $context);

        $this->assertSame(RedirectConflict::MANUAL, $conflict?->type);
        $this->assertSame([$manual->id], $conflict?->redirectIds);

        $this->expectException(RedirectConflictException::class);
        $adapter->putExactPermanent('/puvodni', '/novy-cil', $context);
    }

    public function testExplicitReplacementAndOwnedChainFlattening(): void
    {
        $manual = $this->redirect('/stara', '/mezilehla');
        $adapter = app(RedirectManagerInterface::class);
        $context = new RedirectContext(1);

        $replacement = $adapter->putExactPermanent('/stara', '/mezilehla', $context, true);
        $this->assertFalse($replacement->created);
        $this->assertSame($manual->id, $replacement->redirectId);

        $adapter->putExactPermanent('/mezilehla', '/konecna', $context);

        $this->assertSame('konecna', Redirect::findOrFail($manual->id)->to_url);
        $this->assertNull($adapter->findConflict('/stara', $context));
    }

    public function testTargetsStayRelativeSoVdlpAddsRuntimeInstallationBasePath(): void
    {
        $adapter = app(RedirectManagerInterface::class);
        $context = new RedirectContext(1);

        $first = $adapter->putExactPermanent('/puvodni', '/cil', $context);
        $firstRule = Redirect::findOrFail($first->redirectId);
        $this->assertSame('/puvodni', $firstRule->from_url);
        $this->assertSame('cil', $firstRule->to_url);
        $this->assertTrue((bool) $firstRule->ignore_query_parameters);
        $this->assertTrue((bool) $firstRule->keep_querystring);

        $homepage = $adapter->putExactPermanent('/stara-domu', '/', $context);
        $this->assertSame('./', Redirect::findOrFail($homepage->redirectId)->to_url);
    }

    public function testReturningToPreviousUrlRemovesObsoleteOwnedRedirectInsteadOfCreatingLoop(): void
    {
        $adapter = app(RedirectManagerInterface::class);
        $context = new RedirectContext(1);

        $first = $adapter->putExactPermanent('/prvni', '/druha', $context);
        $second = $adapter->putExactPermanent('/druha', '/prvni', $context);

        $this->assertNull(Redirect::find($first->redirectId));
        $this->assertSame('/druha', Redirect::findOrFail($second->redirectId)->from_url);
        $this->assertSame('prvni', Redirect::findOrFail($second->redirectId)->to_url);
    }

    public function testGoneRuleUsesExactSystem410WithoutTarget(): void
    {
        $adapter = app(RedirectManagerInterface::class);
        $result = $adapter->putGone('/odstranena', new RedirectContext(1));
        $rule = Redirect::findOrFail($result->redirectId);

        $this->assertSame(Redirect::TYPE_EXACT, $rule->match_type);
        $this->assertSame(Redirect::TARGET_TYPE_NONE, $rule->target_type);
        $this->assertSame(410, (int) $rule->status_code);
        $this->assertNull($rule->to_url);
        $this->assertTrue((bool) $rule->system);
        $this->assertTrue($adapter->removeOwned('/odstranena', new RedirectContext(1)));
        $this->assertNull(Redirect::find($result->redirectId));
        $this->assertFalse($adapter->removeOwned('/odstranena', new RedirectContext(1)));
    }

    private function redirect(string $source, string $target): Redirect
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
