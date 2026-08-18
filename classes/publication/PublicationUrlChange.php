<?php namespace HumlnetCreative\Pages\Classes\Publication;

use HumlnetCreative\Pages\Classes\Redirect\RedirectContext;

final class PublicationUrlChange
{
    public function __construct(
        public readonly int $pageId,
        public readonly string $title,
        public readonly ?string $oldPath,
        public readonly string $newPath,
        public readonly string $newFullslug,
        public readonly RedirectContext $context,
        public readonly bool $isRoot,
        public readonly ?string $deletionMode = null,
        public readonly ?string $deletionTargetPath = null,
        public readonly bool $reactivatesUrl = false,
    ) {
    }

    public function needsRedirect(): bool
    {
        return $this->oldPath !== null && $this->oldPath !== $this->newPath;
    }

    public function retiresUrl(): bool
    {
        return $this->deletionMode !== null;
    }
}
