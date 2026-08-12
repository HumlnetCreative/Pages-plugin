<?php namespace HumlnetCreative\Pages\Classes\Revision;

use HumlnetCreative\Pages\Models\PageEditLock;

final class PageLockState
{
    public function __construct(
        public readonly PageEditLock $lock,
        public readonly bool $writable,
    ) {
    }
}
