<?php namespace HumlnetCreative\Pages\Services;

use Backend\Facades\BackendAuth;
use Cms\Classes\Theme;

/**
 * Single registry shared by the builder, permissions, import validator and theme renderer.
 * Themes may add presentation-only section definitions in config/page-builder.php.
 */
class SectionRegistry
{
    protected static ?self $instance = null;
    protected array $definitions;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function __construct()
    {
        $this->definitions = [
            'hero' => [
                'label' => 'Hero', 'permission' => 'humlnetcreative.pages.section.hero', 'items' => false,
                'category' => 'media', 'allowed_in_columns' => false,
                'wireframe' => ['heading' => 'content.heading', 'text' => 'content.text', 'thumbnail' => 'hero_desktop'],
                'section_fields' => ['heading', 'text', 'position', 'cta', 'media'],
                'section_style_fields' => ['heading', 'text', 'cta'],
                'section_media_slots' => ['hero_desktop', 'hero_mobile'],
                'defaults' => ['layout' => ['width' => 'full', 'spacing' => 'none'], 'content' => ['position' => 'left-center']],
            ],
            'carousel' => [
                'label' => 'Prezentace', 'permission' => 'humlnetcreative.pages.section.carousel', 'items' => false,
                'category' => 'media', 'allowed_in_columns' => false,
                'wireframe' => ['heading' => 'title', 'shared_source' => 'slider', 'item_count' => 'presentation.slides'],
                'section_fields' => ['slider', 'carousel_options'],
                'section_style_fields' => ['container', 'heading', 'text', 'cta'],
                'defaults' => ['layout' => ['width' => 'full', 'spacing' => 'none'], 'content' => [
                    'autoplay' => true, 'autoplay_delay' => 5000, 'navigation' => true,
                    'pagination' => true, 'overlay' => true, 'playback_control' => true, 'position' => 'left-center',
                ]],
            ],
            'text' => [
                'label' => 'Text', 'permission' => 'humlnetcreative.pages.section.text', 'items' => false,
                'category' => 'basic', 'minimum_width_units' => 1, 'supports_fill_height' => true,
                'wireframe' => ['heading' => 'content.heading', 'text' => 'content.text'],
                'section_fields' => ['heading', 'text'], 'section_style_fields' => ['heading', 'text'],
            ],
            'image_text' => [
                'label' => 'Text s obrázkem', 'permission' => 'humlnetcreative.pages.section.image_text', 'items' => false,
                'category' => 'media', 'minimum_width_units' => 2, 'supports_fill_height' => true,
                'wireframe' => ['heading' => 'content.heading', 'text' => 'content.text', 'thumbnail' => 'image_text'],
                'section_fields' => ['heading', 'text', 'image_position', 'image_text_gap', 'cta', 'media'],
                'section_style_fields' => ['heading', 'text', 'cta'], 'section_media_slots' => ['image_text'],
                'defaults' => ['layout' => ['image_text_gap' => 'standard'], 'content' => ['image_position' => 'left']],
            ],
            'cards' => [
                'label' => 'Karty', 'permission' => 'humlnetcreative.pages.section.cards', 'items' => true,
                'category' => 'basic', 'minimum_width_units' => 2,
                'wireframe' => ['heading' => 'content.heading', 'text' => 'content.text', 'item_count' => 'items'],
                'section_fields' => ['heading', 'text', 'columns', 'items'],
                'section_style_fields' => ['heading', 'text'],
                'item_fields' => ['heading', 'icon', 'text', 'cta', 'style', 'move_card', 'media'],
                'item_style_fields' => ['heading', 'text', 'cta'], 'item_media_slots' => ['card_image'],
                'defaults' => ['content' => ['columns' => 3]],
            ],
            'cta' => [
                'label' => 'CTA / pruh', 'permission' => 'humlnetcreative.pages.section.cta', 'items' => false,
                'category' => 'basic', 'minimum_width_units' => 1, 'supports_fill_height' => true,
                'wireframe' => ['heading' => 'content.heading', 'text' => 'content.text'],
                'section_fields' => ['heading', 'text', 'cta'],
                'section_style_fields' => ['container', 'heading', 'text', 'cta'],
            ],
            'accordion' => [
                'label' => 'FAQ', 'permission' => 'humlnetcreative.pages.section.accordion', 'items' => false,
                'category' => 'basic', 'minimum_width_units' => 1,
                'wireframe' => ['heading' => 'content.heading', 'shared_source' => 'faq_group', 'item_count' => 'faq_items'],
                'section_fields' => ['heading', 'faq_group'], 'section_style_fields' => ['heading'],
            ],
            'gallery' => [
                'label' => 'Galerie', 'permission' => 'humlnetcreative.pages.section.gallery', 'items' => false,
                'category' => 'media', 'minimum_width_units' => 1,
                'wireframe' => ['heading' => 'content.heading', 'shared_source' => 'gallery', 'item_count' => 'gallery.images'],
                'section_fields' => ['heading', 'gallery', 'gallery_columns'], 'section_style_fields' => ['heading'],
                'defaults' => ['content' => ['gallery_columns' => 3]],
            ],
            'columns' => [
                'label' => 'Sloupce', 'permission' => 'humlnetcreative.pages.section.columns', 'items' => false,
                'category' => 'structure', 'allowed_in_columns' => false, 'minimum_width_units' => 4,
                'wireframe' => ['heading' => 'title', 'item_count' => 'zones'],
                'section_fields' => ['columns_layout'], 'section_style_fields' => [],
                'defaults' => [
                    'layout' => [
                        'width' => 'contained', 'spacing' => 'standard', 'columns_gap' => 'standard',
                        'tablet_behavior' => 'keep', 'mobile_order' => 'default', 'full_padding' => 'safe',
                    ],
                    'content' => ['ratio' => '1:1'],
                ],
            ],
            'files' => ['label' => 'Soubory ke stažení', 'permission' => 'humlnetcreative.pages.section.files', 'items' => false, 'enabled' => false, 'category' => 'basic', 'minimum_width_units' => 1],
            'form' => ['label' => 'Formulář', 'permission' => 'humlnetcreative.pages.section.form', 'items' => false, 'enabled' => false, 'category' => 'project', 'minimum_width_units' => 1],
            'embed' => [
                'label' => 'Vložený obsah', 'permission' => 'humlnetcreative.pages.section.embed', 'items' => false,
                'category' => 'media', 'minimum_width_units' => 2,
                'wireframe' => ['heading' => 'title', 'text' => 'content.embed'],
                'section_fields' => ['embed'], 'section_style_fields' => [],
            ],
            'posts' => ['label' => 'Příspěvky', 'permission' => 'humlnetcreative.pages.section.posts', 'items' => false, 'enabled' => false, 'category' => 'project', 'minimum_width_units' => 2],
            'opening_hours' => ['label' => 'Otevírací doba', 'permission' => 'humlnetcreative.pages.section.opening_hours', 'items' => false, 'enabled' => false, 'category' => 'project', 'minimum_width_units' => 1],
            'pricelist' => ['label' => 'Ceník', 'permission' => 'humlnetcreative.pages.section.pricelist', 'items' => false, 'enabled' => false, 'category' => 'project', 'minimum_width_units' => 1],
            'timeline' => ['label' => 'Časová osa', 'permission' => 'humlnetcreative.pages.section.timeline', 'items' => false, 'enabled' => false, 'category' => 'project', 'minimum_width_units' => 1],
            'links' => ['label' => 'Odkazy', 'permission' => 'humlnetcreative.pages.section.links', 'items' => false, 'enabled' => false, 'category' => 'project', 'minimum_width_units' => 1],
            'flash_messages' => ['label' => 'Flash zprávy', 'permission' => 'humlnetcreative.pages.section.flash_messages', 'items' => false, 'enabled' => false, 'category' => 'project', 'minimum_width_units' => 1],
        ];

        $theme = Theme::getActiveTheme();
        $path = $theme ? $theme->getPath().'/config/page-builder.php' : null;
        if ($path && is_file($path)) {
            foreach ((require $path) as $type => $themeDefinition) {
                $this->definitions[$type] = array_replace($this->definitions[$type] ?? [], $themeDefinition);
            }
        }

        foreach ($this->definitions as $type => $definition) {
            $this->definitions[$type] = array_replace([
                'category' => 'project',
                'allowed_in_columns' => true,
                'minimum_width_units' => 1,
                'supports_fill_height' => false,
                'wireframe' => ['heading' => 'content.heading', 'text' => null, 'thumbnail' => null, 'item_count' => null, 'shared_source' => null],
            ], $definition, [
                'wireframe' => array_replace([
                    'heading' => 'content.heading',
                    'text' => null,
                    'thumbnail' => null,
                    'item_count' => null,
                    'shared_source' => null,
                ], $definition['wireframe'] ?? []),
            ]);
        }
    }

    public function has(?string $type): bool { return isset($this->definitions[$type]) && ($this->definitions[$type]['enabled'] ?? true); }
    public function definition(string $type): array { return $this->definitions[$type]; }
    public function all(): array { return $this->definitions; }
    public function itemsSupported(string $type): bool { return (bool) ($this->definitions[$type]['items'] ?? false); }
    public function sectionFields(string $type): array { return $this->definitions[$type]['section_fields'] ?? []; }
    public function sectionStyleFields(string $type): array { return $this->definitions[$type]['section_style_fields'] ?? []; }
    public function itemFields(string $type): array { return $this->definitions[$type]['item_fields'] ?? []; }
    public function itemStyleFields(string $type): array { return $this->definitions[$type]['item_style_fields'] ?? []; }
    public function sectionMediaSlots(string $type): array { return $this->definitions[$type]['section_media_slots'] ?? []; }
    public function itemMediaSlots(string $type): array { return $this->definitions[$type]['item_media_slots'] ?? []; }
    public function category(string $type): string { return $this->definitions[$type]['category']; }
    public function allowedInColumns(string $type): bool { return (bool) $this->definitions[$type]['allowed_in_columns']; }
    public function minimumWidthUnits(string $type): int { return (int) $this->definitions[$type]['minimum_width_units']; }
    public function supportsFillHeight(string $type): bool { return (bool) $this->definitions[$type]['supports_fill_height']; }
    public function wireframe(string $type): array { return $this->definitions[$type]['wireframe']; }

    public function optionsForContext(?int $widthUnits = null): array
    {
        return collect($this->definitions)->filter(function(array $definition) use ($widthUnits): bool {
            if (!($definition['enabled'] ?? true) || !BackendAuth::userHasPermission($definition['permission'])) {
                return false;
            }
            if ($widthUnits === null) {
                return true;
            }

            return ($definition['allowed_in_columns'] ?? true)
                && (int) ($definition['minimum_width_units'] ?? 1) <= $widthUnits;
        })->mapWithKeys(fn(array $definition, string $key) => [$key => $definition['label']])->all();
    }

    public function defaults(string $type): array
    {
        return array_replace_recursive([
            'layout' => ['width' => 'contained', 'spacing' => 'standard'],
            'style' => [],
            'content' => [],
        ], $this->definitions[$type]['defaults'] ?? []);
    }

    public function optionsForBackendUser(): array
    {
        return collect($this->definitions)->filter(function(array $definition) {
            return ($definition['enabled'] ?? true) && BackendAuth::userHasPermission($definition['permission']);
        })->mapWithKeys(fn(array $definition, string $key) => [$key => $definition['label']])->all();
    }
}
