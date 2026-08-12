<?php namespace HumlnetCreative\Pages\Classes\Commands;

final class PageCommandResult
{
    public function __construct(
        public readonly int $pageId,
        public readonly int $draftVersion,
        public readonly array $data = [],
        public readonly ?PageCommand $inverse = null,
    ) {
    }
}
