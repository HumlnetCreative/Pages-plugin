<?php namespace HumlnetCreative\Pages\Contracts;

use HumlnetCreative\Pages\Classes\Redirect\RedirectConflict;
use HumlnetCreative\Pages\Classes\Redirect\RedirectContext;
use HumlnetCreative\Pages\Classes\Redirect\RedirectWriteResult;

interface RedirectManagerInterface
{
    public function findConflict(string $source, RedirectContext $context): ?RedirectConflict;

    public function putExactPermanent(
        string $source,
        string $target,
        RedirectContext $context,
        bool $replaceManual = false,
    ): RedirectWriteResult;
}
