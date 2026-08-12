<?php namespace HumlnetCreative\Pages\Classes\Redirect;

final class RedirectConflict
{
    public const MANUAL = 'manual';
    public const AMBIGUOUS = 'ambiguous';

    public function __construct(
        public readonly string $type,
        public readonly string $source,
        public readonly array $redirectIds,
        public readonly ?string $target = null,
    ) {
    }
}
