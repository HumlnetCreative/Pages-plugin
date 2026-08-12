<?php namespace HumlnetCreative\Pages\Classes\Redirect;

final class RedirectWriteResult
{
    public function __construct(
        public readonly int $redirectId,
        public readonly bool $created,
        public readonly int $flattenedCount,
    ) {
    }
}
