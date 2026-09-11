<?php namespace HumlnetCreative\Pages\Services;

use Cms\Classes\Theme;
use Cms\Models\ThemeData;
use HumlnetCreative\Pages\Models\Section;
use Illuminate\Support\Str;
use HumlnetCreative\Pages\Models\SectionContainer;

/** Converts normalized sections to presentation-safe structural Canvas metadata. */
final class CanvasSectionPresenter
{
    private ?array $themeSchemes = null;

    public function present(Section $section): array
    {
        $registry = SectionRegistry::instance();
        $definition = $registry->definition($section->type);
        $wireframe = $registry->wireframe($section->type);
        $heading = $this->plainText(data_get($section, $wireframe['heading']));
        $text = $this->plainText($wireframe['text'] ? data_get($section, $wireframe['text']) : null);
        $source = $this->sharedSource($wireframe['shared_source'] ? data_get($section, $wireframe['shared_source']) : null);
        $checks = app(CanvasSectionChecks::class)->detailsForSection($section);
        $rawSchemeKey = trim((string) data_get($section->style, 'color_scheme'));
        [$effectiveSchemeKey, $schemeSource] = $rawSchemeKey !== ''
            ? [$rawSchemeKey, 'section']
            : $this->inheritedColorScheme($section);
        $scheme = $this->colorScheme($effectiveSchemeKey);
        $presentation = $section->type === 'carousel' ? $section->presentation : null;
        $motion = SectionMotion::presentation($section);

        return [
            'id' => (int) $section->id,
            'uuid' => (string) $section->uuid,
            'container_uuid' => (string) $section->container?->uuid,
            'nested' => $section->container?->kind === SectionContainer::KIND_ZONE,
            'type' => (string) $section->type,
            'type_label' => (string) $definition['label'],
            'title' => trim((string) $section->title) ?: (string) $definition['label'],
            'heading' => Str::limit($heading ?: trim((string) $section->title), 90),
            'heading_value' => $heading,
            'heading_editable' => $wireframe['heading'] === 'content.heading',
            'text' => Str::limit($text, 180),
            'item_count' => $this->itemCount($section, $wireframe['item_count'], $presentation),
            'item_previews' => $this->itemPreviews($section, $presentation),
            'shared_source' => Str::limit($source, 90),
            'visible' => (bool) $section->is_published,
            'width' => $this->widthLabel((string) data_get($section->layout, 'width', 'contained')),
            'width_value' => (string) data_get($section->layout, 'width', 'contained'),
            'spacing' => $this->spacingLabel((string) data_get($section->layout, 'spacing', 'standard')),
            'spacing_value' => (string) data_get($section->layout, 'spacing', 'standard'),
            'color_scheme' => $scheme['label'].($rawSchemeKey === '' && $effectiveSchemeKey !== '' ? ' (zděděné)' : ''),
            'color_scheme_value' => $rawSchemeKey,
            'color_scheme_inherited' => $rawSchemeKey === '',
            'color_scheme_source' => $schemeSource,
            'color_scheme_background' => $scheme['background'],
            'color_scheme_foreground' => $scheme['foreground'],
            'fill_height' => (bool) data_get($section->layout, 'fill_height', false),
            'supports_fill_height' => $registry->supportsFillHeight($section->type),
            'motion_supported' => $registry->supportsMotion($section->type),
            'motion_effect' => $motion['effect'],
            'motion_label' => $this->motionLabel($motion['effect']),
            'motion_duration_ms' => $motion['duration_ms'],
            'motion_delay_ms' => $motion['delay_ms'],
            'motion_stagger_items' => $motion['stagger_items'],
            'thumbnail_url' => $this->thumbnailUrl($section, $wireframe['thumbnail']),
            'checks' => $checks,
            'checks_severity' => collect($checks)->contains(fn(array $check): bool => $check['severity'] === 'error')
                ? 'error'
                : ($checks ? 'warning' : 'valid'),
            'ratio' => $section->type === 'columns' ? (string) data_get($section->content, 'ratio', '1:1') : null,
            'zones' => $section->type === 'columns' ? $section->zones->sortBy('sort_order')->map(fn(SectionContainer $zone): array => [
                'uuid' => (string) $zone->uuid,
                'title' => trim((string) $zone->title) ?: 'Zóna '.((int) $zone->sort_order),
                'sort_order' => (int) $zone->sort_order,
                'width_units' => (int) $zone->width_units,
                'vertical_align' => (string) $zone->vertical_align,
                'block_spacing' => (string) $zone->block_spacing,
                'color_scheme' => trim((string) data_get($zone->style, 'color_scheme')) ?: 'Dědí ze Sloupců',
                'sections' => $zone->sections->sortBy('sort_order')->map(fn(Section $nested): array => $this->present($nested))->values()->all(),
            ])->values()->all() : [],
        ];
    }

    private function thumbnailUrl(Section $section, ?string $slot): ?string
    {
        if ($slot && ($use = $section->media->firstWhere('slot', $slot))) {
            return app(MediaService::class)->backendPreviewUrl($use);
        }

        return null;
    }

    private function itemPreviews(Section $section, ?array $presentation): array
    {
        $media = app(MediaService::class);

        if ($section->type === 'carousel') {
            return collect($presentation['slides'] ?? [])->take(4)->values()->map(function(array $slide, int $index) use ($media): array {
                $use = $slide['poster_desktop'] ?? $slide['desktop'] ?? null;

                return [
                    'image_url' => $use ? $media->backendPreviewUrl($use) : null,
                    'label' => $this->plainText($slide['title'] ?? '') ?: 'Snímek '.($index + 1),
                ];
            })->all();
        }

        if ($section->type === 'cards') {
            return $section->items->take(4)->values()->map(function($item, int $index) use ($media): array {
                $use = $item->media->firstWhere('slot', 'card_image') ?: $item->media->first();

                return [
                    'image_url' => $use ? $media->backendPreviewUrl($use) : null,
                    'label' => $this->plainText(data_get($item->content, 'heading')) ?: 'Karta '.($index + 1),
                ];
            })->all();
        }

        if ($section->type === 'gallery') {
            return collect($section->gallery?->images ?: [])->take(4)->values()->map(function($image, int $index): array {
                return [
                    'image_url' => $image->getThumbUrl(320, 180, ['mode' => 'crop']),
                    'label' => trim((string) ($image->title ?: $image->description ?: $image->file_name)) ?: 'Obrázek '.($index + 1),
                ];
            })->all();
        }

        if ($section->type === 'accordion') {
            return $section->faq_items->take(4)->values()->map(fn($question, int $index): array => [
                'image_url' => null,
                'label' => $this->plainText($question->title) ?: 'Otázka '.($index + 1),
            ])->all();
        }

        return [];
    }

    private function plainText(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
    }

    private function itemCount(Section $section, ?string $path, ?array $presentation = null): ?int
    {
        if (!$path) {
            return null;
        }

        $value = $path === 'presentation.slides'
            ? data_get($presentation, 'slides', [])
            : data_get($section, $path);
        if (is_countable($value)) {
            return count($value);
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function sharedSource(mixed $source): string
    {
        if (!$source) {
            return '';
        }
        if (is_scalar($source)) {
            return trim((string) $source);
        }

        foreach (['title', 'name'] as $attribute) {
            if ($value = data_get($source, $attribute)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function widthLabel(string $value): string
    {
        return match ($value) {
            'contained' => 'V kontejneru',
            'wide' => 'Široká',
            'full' => 'Přes celou šířku',
            default => $value,
        };
    }

    private function spacingLabel(string $value): string
    {
        return match ($value) {
            'none' => 'Bez mezery',
            'small' => 'Malá mezera',
            'standard' => 'Standardní mezera',
            'large' => 'Velká mezera',
            default => $value,
        };
    }

    private function motionLabel(string $effect): string
    {
        return match ($effect) {
            'fade' => 'Prolnutí',
            'fade-up' => 'Prolnutí zdola',
            'fade-left' => 'Prolnutí zleva',
            'fade-right' => 'Prolnutí zprava',
            'scale-in' => 'Jemné přiblížení',
            default => 'Bez efektu',
        };
    }

    private function colorScheme(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return [
                'label' => 'Dědí ze stránky',
                'background' => null,
                'foreground' => null,
            ];
        }

        $scheme = collect($this->themeSchemes())->first(
            static fn (mixed $candidate): bool => trim((string) data_get($candidate, 'key')) === $key
        );

        return [
            'label' => trim((string) data_get($scheme, 'title')) ?: $key,
            'background' => $this->safeCssColor(data_get($scheme, 'bg')),
            'foreground' => $this->safeCssColor(data_get($scheme, 'text')),
        ];
    }

    /** @return array{0: string, 1: string} Effective key and inheritance source. */
    private function inheritedColorScheme(Section $section): array
    {
        $container = $section->container;
        if ($container?->kind === SectionContainer::KIND_ZONE) {
            $zoneScheme = trim((string) data_get($container->style, 'color_scheme'));
            if ($zoneScheme !== '') {
                return [$zoneScheme, 'zone'];
            }

            $columnsScheme = trim((string) data_get($container->columns_section?->style, 'color_scheme'));
            if ($columnsScheme !== '') {
                return [$columnsScheme, 'columns'];
            }
        }

        $pageScheme = trim((string) data_get($section->page?->style, 'color_scheme'));

        return [$pageScheme, $pageScheme === '' ? 'default' : 'page'];
    }

    private function themeSchemes(): array
    {
        if ($this->themeSchemes !== null) {
            return $this->themeSchemes;
        }

        $theme = Theme::getActiveTheme();
        if (!$theme) {
            return $this->themeSchemes = [];
        }

        return $this->themeSchemes = (array) (ThemeData::forTheme($theme)['schemes'] ?? []);
    }

    public function colorSchemeOptions(): array
    {
        return collect($this->themeSchemes())->mapWithKeys(function(mixed $scheme): array {
            $key = trim((string) data_get($scheme, 'key'));

            return $key === '' ? [] : [$key => trim((string) data_get($scheme, 'title')) ?: $key];
        })->all();
    }

    /** Theme-backed visual choices shared by the Canvas section and page inspectors. */
    public function colorSchemeChoices(): array
    {
        return collect($this->themeSchemes())->map(function(mixed $scheme): array {
            return [
                'key' => trim((string) data_get($scheme, 'key')),
                'title' => trim((string) data_get($scheme, 'title')),
                'background' => $this->safeCssColor(data_get($scheme, 'bg')),
                'foreground' => $this->safeCssColor(data_get($scheme, 'text')),
                'primary_background' => $this->safeCssColor(data_get($scheme, 'button_primary.bg')),
                'primary_border' => $this->safeCssColor(data_get($scheme, 'button_primary.border')),
                'secondary_background' => $this->safeCssColor(data_get($scheme, 'button_secondary.bg')),
                'secondary_border' => $this->safeCssColor(data_get($scheme, 'button_secondary.border')),
            ];
        })->filter(fn(array $scheme): bool => $scheme['key'] !== '')->values()->all();
    }

    private function safeCssColor(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        $functional = '(?:rgb|hsl)a?\([0-9.,%\s+\/-]+\)';

        return preg_match('/^(?:#[0-9a-f]{3,8}|'.$functional.')$/i', $value) ? $value : null;
    }
}
