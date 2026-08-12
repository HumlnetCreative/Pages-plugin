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

        $this->assertSame('/konecna', Redirect::findOrFail($manual->id)->to_url);
        $this->assertNull($adapter->findConflict('/stara', $context));
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
