<?php namespace HumlnetCreative\Pages\Services;

use Cms\Classes\Theme;
use Cms\Models\ThemeData;
use HumlnetCreative\Pages\Models\Section;
use Illuminate\Support\Str;

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
        $text = $this->plainText(data_get($section, $wireframe['text']));
        $source = $this->sharedSource(data_get($section, $wireframe['shared_source']));
        $checks = app(CanvasSectionChecks::class)->forSection($section);
        $scheme = $this->colorScheme((string) data_get($section->style, 'color_scheme'));

        return [
            'id' => (int) $section->id,
            'uuid' => (string) $section->uuid,
            'type' => (string) $section->type,
            'type_label' => (string) $definition['label'],
            'title' => trim((string) $section->title) ?: (string) $definition['label'],
            'heading' => Str::limit($heading ?: trim((string) $section->title), 90),
            'heading_value' => $heading,
            'heading_editable' => $wireframe['heading'] === 'content.heading',
            'text' => Str::limit($text, 180),
            'item_count' => $this->itemCount($section, $wireframe['item_count']),
            'shared_source' => Str::limit($source, 90),
            'visible' => (bool) $section->is_published,
            'width' => $this->widthLabel((string) data_get($section->layout, 'width', 'contained')),
            'spacing' => $this->spacingLabel((string) data_get($section->layout, 'spacing', 'standard')),
            'color_scheme' => $scheme['label'],
            'color_scheme_background' => $scheme['background'],
            'color_scheme_foreground' => $scheme['foreground'],
            'thumbnail_url' => $this->thumbnailUrl($section, $wireframe['thumbnail']),
            'checks' => $checks,
        ];
    }

    private function thumbnailUrl(Section $section, ?string $slot): ?string
    {
        if ($slot && ($use = $section->media->firstWhere('slot', $slot))) {
            return app(MediaService::class)->backendPreviewUrl($use);
        }
        if ($section->type === 'carousel') {
            $slide = $section->presentation['slides'][0] ?? null;
            $use = $slide['poster_desktop'] ?? $slide['desktop'] ?? null;
            return $use ? app(MediaService::class)->backendPreviewUrl($use) : null;
        }
        if ($section->type === 'gallery' && ($image = $section->gallery?->images?->first())) {
            return $image->getThumbUrl(480, 270, ['mode' => 'crop']);
        }
        if ($section->type === 'cards') {
            $use = $section->items->flatMap->media->first();
            return $use ? app(MediaService::class)->backendPreviewUrl($use) : null;
        }

        return null;
    }

    private function plainText(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
    }

    private function itemCount(Section $section, ?string $path): ?int
    {
        if (!$path) {
            return null;
        }

        $value = data_get($section, $path);
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
