<?php namespace HumlnetCreative\Pages\Classes\Snapshot;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Immutable, versioned representation of one complete Builder page. */
final class PageSnapshot
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public readonly int $schemaVersion,
        public readonly array $page,
        public readonly array $sections,
        public readonly array $mediaAssets,
    ) {
        $this->assertValid();
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            (int) ($payload['schema_version'] ?? 0),
            (array) ($payload['page'] ?? []),
            array_values((array) ($payload['sections'] ?? [])),
            array_values((array) ($payload['media_assets'] ?? [])),
        );
    }

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'page' => $this->page,
            'sections' => $this->sections,
            'media_assets' => $this->mediaAssets,
        ];
    }

    private function assertValid(): void
    {
        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Nepodporovaná verze schématu snapshotu %d.',
                $this->schemaVersion,
            ));
        }

        $seen = [];
        $this->assertUuid($this->page['uuid'] ?? null, 'stránka', $seen);

        foreach ($this->sections as $sectionIndex => $section) {
            $this->assertUuid($section['uuid'] ?? null, "sekce {$sectionIndex}", $seen);

            foreach ((array) ($section['media'] ?? []) as $mediaIndex => $media) {
                $this->assertUuid($media['uuid'] ?? null, "médium sekce {$sectionIndex}/{$mediaIndex}", $seen);
            }

            foreach ((array) ($section['items'] ?? []) as $itemIndex => $item) {
                $this->assertUuid($item['uuid'] ?? null, "položka {$sectionIndex}/{$itemIndex}", $seen);

                foreach ((array) ($item['media'] ?? []) as $mediaIndex => $media) {
                    $this->assertUuid($media['uuid'] ?? null, "médium položky {$sectionIndex}/{$itemIndex}/{$mediaIndex}", $seen);
                }
            }
        }

        $assetUuids = [];
        foreach ($this->mediaAssets as $assetIndex => $asset) {
            $uuid = $this->assertUuid($asset['uuid'] ?? null, "mediální asset {$assetIndex}", $seen);
            $assetUuids[$uuid] = true;
        }

        foreach ($this->sections as $section) {
            $this->assertMediaReferences((array) ($section['media'] ?? []), $assetUuids);
            foreach ((array) ($section['items'] ?? []) as $item) {
                $this->assertMediaReferences((array) ($item['media'] ?? []), $assetUuids);
            }
        }
    }

    private function assertUuid(mixed $uuid, string $location, array &$seen): string
    {
        if (!is_string($uuid) || !Str::isUuid($uuid)) {
            throw new InvalidArgumentException("Snapshot obsahuje neplatné UUID: {$location}.");
        }
        if (isset($seen[$uuid])) {
            throw new InvalidArgumentException("Snapshot obsahuje duplicitní UUID {$uuid}.");
        }

        $seen[$uuid] = true;

        return $uuid;
    }

    private function assertMediaReferences(array $mediaUses, array $assetUuids): void
    {
        foreach ($mediaUses as $mediaUse) {
            $assetUuid = $mediaUse['asset_uuid'] ?? null;
            if (!is_string($assetUuid) || !isset($assetUuids[$assetUuid])) {
                throw new InvalidArgumentException(sprintf(
                    'Snapshot obsahuje médium bez platné reference na asset %s.',
                    is_scalar($assetUuid) ? (string) $assetUuid : '(prázdná)',
                ));
            }
        }
    }
}
