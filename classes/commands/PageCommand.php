<?php namespace HumlnetCreative\Pages\Classes\Commands;

final class PageCommand
{
    public function __construct(
        public readonly string $name,
        public readonly int $pageId,
        public readonly int $expectedDraftVersion,
        public readonly array $payload = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'page_id' => $this->pageId,
            'expected_draft_version' => $this->expectedDraftVersion,
            'payload' => $this->payload,
        ];
    }

    public static function fromArray(array $value): self
    {
        return new self(
            (string) $value['name'],
            (int) $value['page_id'],
            (int) $value['expected_draft_version'],
            (array) ($value['payload'] ?? []),
        );
    }
}
