<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Models\SectionContainer;

/** Curated Columns layouts and shared structural validation. */
final class ColumnsLayout
{
    public const RATIOS = [
        '1:1' => [1, 1],
        '1:2' => [1, 2],
        '2:1' => [2, 1],
        '1:3' => [1, 3],
        '3:1' => [3, 1],
        '2:1:1' => [2, 1, 1],
        '1:2:1' => [1, 2, 1],
        '1:1:2' => [1, 1, 2],
        '1:1:1:1' => [1, 1, 1, 1],
    ];

    public static function options(): array
    {
        return collect(array_keys(self::RATIOS))->mapWithKeys(fn(string $ratio) => [$ratio => str_replace(':', ' : ', $ratio)])->all();
    }

    public static function units(string $ratio): array
    {
        if (!isset(self::RATIOS[$ratio])) {
            throw new \ValidationException(['ratio' => 'Vyberte jeden ze schválených poměrů Sloupců.']);
        }

        return self::RATIOS[$ratio];
    }

    public static function validatePlacement(Section $section): void
    {
        $container = $section->container;
        if (!$container || (int) $container->page_id !== (int) $section->page_id) {
            throw new \ValidationException(['container' => 'Sekce musí patřit kontejneru stejné stránky.']);
        }

        if ($container->kind === SectionContainer::KIND_ROOT) {
            return;
        }
        if ($container->kind !== SectionContainer::KIND_ZONE) {
            throw new \ValidationException(['container' => 'Sekce používá neplatný kontejner.']);
        }
        if ($section->type === 'columns') {
            throw new \ValidationException(['container' => 'Sloupce nelze vnořit do dalších Sloupců.']);
        }

        $registry = SectionRegistry::instance();
        if (!$registry->allowedInColumns($section->type)) {
            throw new \ValidationException(['type' => 'Tento typ sekce lze použít pouze na hlavní úrovni stránky.']);
        }
        if ($registry->minimumWidthUnits($section->type) > (int) $container->width_units) {
            throw new \ValidationException(['type' => 'Tento typ sekce vyžaduje širší zónu Sloupců.']);
        }
        if (data_get($section->layout, 'fill_height', false) && !$registry->supportsFillHeight($section->type)) {
            throw new \ValidationException(['layout' => 'Tento typ sekce nepodporuje vyplnění výšky zóny.']);
        }
    }

    public static function validateZones(Section $columns): void
    {
        if ($columns->type !== 'columns') {
            throw new \InvalidArgumentException('Validaci zón lze použít pouze pro Sloupce.');
        }
        $expected = self::units((string) data_get($columns->content, 'ratio', '1:1'));
        $zones = $columns->zones()->orderBy('sort_order')->get();
        if ($zones->count() !== count($expected)) {
            throw new \ValidationException(['zones' => 'Počet zón neodpovídá zvolenému poměru Sloupců.']);
        }
        foreach ($zones as $index => $zone) {
            if ((int) $zone->width_units !== $expected[$index]) {
                throw new \ValidationException(['zones' => 'Podíly zón neodpovídají zvolenému poměru Sloupců.']);
            }
        }
    }
}
