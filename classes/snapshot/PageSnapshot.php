<?php namespace HumlnetCreative\Pages\Classes\Snapshot;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use HumlnetCreative\Pages\Services\ColumnsLayout;
use HumlnetCreative\Pages\Services\SectionRegistry;

/** Immutable, versioned representation of one complete Builder page. */
final class PageSnapshot
{
    public const SCHEMA_VERSION = 2;

    public function __construct(
        public readonly int $schemaVersion,
        public readonly array $page,
        public readonly array $containers,
        public readonly array $sections,
        public readonly array $mediaAssets,
    ) {
        $this->assertValid();
    }

    public static function fromArray(array $payload): self
    {
        if ((int) ($payload['schema_version'] ?? 0) === 1) {
            $payload = self::upgradeVersionOne($payload);
        }

        return new self(
            (int) ($payload['schema_version'] ?? 0),
            (array) ($payload['page'] ?? []),
            array_values((array) ($payload['containers'] ?? [])),
            array_values((array) ($payload['sections'] ?? [])),
            array_values((array) ($payload['media_assets'] ?? [])),
        );
    }

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'page' => $this->page,
            'containers' => $this->containers,
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

        $containerUuids = [];
        $rootUuids = [];
        foreach ($this->containers as $containerIndex => $container) {
            $uuid = $this->assertUuid($container['uuid'] ?? null, "kontejner {$containerIndex}", $seen);
            $containerUuids[$uuid] = $container;
            $kind = $container['kind'] ?? null;
            if (!in_array($kind, ['root', 'zone'], true)) {
                throw new InvalidArgumentException("Snapshot obsahuje neplatný typ kontejneru {$containerIndex}.");
            }
            if ($kind === 'root') {
                $rootUuids[] = $uuid;
            }
        }
        if (count($rootUuids) !== 1) {
            throw new InvalidArgumentException('Snapshot musí obsahovat právě jeden hlavní kontejner.');
        }

        $sectionTypes = [];
        foreach ($this->sections as $sectionIndex => $section) {
            $sectionUuid = $this->assertUuid($section['uuid'] ?? null, "sekce {$sectionIndex}", $seen);
            $sectionTypes[$sectionUuid] = (string) ($section['type'] ?? '');
            $containerUuid = $section['container_uuid'] ?? null;
            if (!is_string($containerUuid) || !isset($containerUuids[$containerUuid])) {
                throw new InvalidArgumentException("Sekce {$sectionIndex} odkazuje na neplatný kontejner.");
            }

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

        foreach ($containerUuids as $container) {
            if ($container['kind'] !== 'zone') {
                continue;
            }
            $parentUuid = $container['parent_section_uuid'] ?? null;
            if (!is_string($parentUuid) || ($sectionTypes[$parentUuid] ?? null) !== 'columns') {
                throw new InvalidArgumentException('Zóna snapshotu musí odkazovat na sekci Sloupce.');
            }
        }
        $this->assertColumnsStructure($containerUuids, $rootUuids[0]);

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

    private function assertColumnsStructure(array $containers, string $rootUuid): void
    {
        $registry = SectionRegistry::instance();
        foreach ($this->sections as $section) {
            $container = $containers[$section['container_uuid']];
            if ($section['type'] === 'columns') {
                if ($section['container_uuid'] !== $rootUuid) {
                    throw new InvalidArgumentException('Sloupce snapshotu musí být na hlavní úrovni.');
                }
                try {
                    $units = ColumnsLayout::units((string) data_get($section, 'content.ratio', '1:1'));
                }
                catch (\ValidationException $exception) {
                    throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
                }
                $zones = collect($containers)->where('kind', 'zone')->where('parent_section_uuid', $section['uuid'])->sortBy('sort_order')->values();
                if ($zones->count() !== count($units)) {
                    throw new InvalidArgumentException('Počet zón snapshotu neodpovídá poměru Sloupců.');
                }
                foreach ($zones as $index => $zone) {
                    if ((int) $zone['width_units'] !== $units[$index]) {
                        throw new InvalidArgumentException('Podíly zón snapshotu neodpovídají poměru Sloupců.');
                    }
                }
                continue;
            }
            if ($container['kind'] !== 'zone') {
                continue;
            }
            if (!$registry->allowedInColumns($section['type'])
                || $registry->minimumWidthUnits($section['type']) > (int) $container['width_units']) {
                throw new InvalidArgumentException("Sekce {$section['type']} není vhodná pro svou zónu Sloupců.");
            }
            if (data_get($section, 'layout.fill_height', false) && !$registry->supportsFillHeight($section['type'])) {
                throw new InvalidArgumentException("Sekce {$section['type']} nepodporuje vyplnění výšky.");
            }
        }
    }

    private static function upgradeVersionOne(array $payload): array
    {
        $pageUuid = (string) data_get($payload, 'page.uuid');
        if (!Str::isUuid($pageUuid)) {
            return $payload;
        }
        $rootUuid = Uuid::uuid5(Uuid::NAMESPACE_URL, 'humlnetcreative.pages/root/'.$pageUuid)->toString();
        $payload['schema_version'] = self::SCHEMA_VERSION;
        $payload['containers'] = [[
            'uuid' => $rootUuid,
            'kind' => 'root',
            'parent_section_uuid' => null,
            'title' => 'Hlavní obsah',
            'sort_order' => 1,
            'width_units' => 4,
            'vertical_align' => 'top',
            'block_spacing' => 'standard',
            'style' => [],
        ]];
        $sections = array_values((array) ($payload['sections'] ?? []));
        foreach ($sections as &$section) {
            $section['container_uuid'] = $rootUuid;
        }
        unset($section);
        $payload['sections'] = $sections;

        return $payload;
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
