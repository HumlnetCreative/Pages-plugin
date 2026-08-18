<?php namespace HumlnetCreative\Pages\Classes\Publication;

final class PublicationPreflightResult
{
    /** @param PublicationUrlChange[] $changes */
    public function __construct(
        public readonly array $changes,
        public readonly array $errors = [],
    ) {
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function root(): PublicationUrlChange
    {
        return $this->changes[0];
    }

    /** @return PublicationUrlChange[] */
    public function descendants(): array
    {
        return array_values(array_filter($this->changes, fn(PublicationUrlChange $change) => !$change->isRoot));
    }

    public function assertPasses(): void
    {
        if (!$this->passes()) {
            throw new \ValidationException(['publication' => implode("\n", $this->errors)]);
        }
    }
}
