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
                'section_fields' => ['heading', 'text', 'position', 'cta', 'media'],
                'section_style_fields' => ['heading', 'text', 'cta'],
                'section_media_slots' => ['hero_desktop', 'hero_mobile'],
                'defaults' => ['layout' => ['width' => 'full', 'spacing' => 'none'], 'content' => ['position' => 'left-center']],
            ],
            'carousel' => [
                'label' => 'Prezentace', 'permission' => 'humlnetcreative.pages.section.carousel', 'items' => false,
                'section_fields' => ['slider', 'carousel_options'],
                'section_style_fields' => ['container', 'heading', 'text', 'cta'],
                'defaults' => ['layout' => ['width' => 'full', 'spacing' => 'none'], 'content' => [
                    'autoplay' => true, 'autoplay_delay' => 5000, 'navigation' => true,
                    'pagination' => true, 'overlay' => true, 'playback_control' => true, 'position' => 'left-center',
                ]],
            ],
            'text' => [
                'label' => 'Text', 'permission' => 'humlnetcreative.pages.section.text', 'items' => false,
                'section_fields' => ['heading', 'text'], 'section_style_fields' => ['heading', 'text'],
            ],
            'image_text' => [
                'label' => 'Text s obrázkem', 'permission' => 'humlnetcreative.pages.section.image_text', 'items' => false,
                'section_fields' => ['heading', 'text', 'image_position', 'image_text_gap', 'cta', 'media'],
                'section_style_fields' => ['heading', 'text', 'cta'], 'section_media_slots' => ['image_text'],
                'defaults' => ['layout' => ['image_text_gap' => 'standard'], 'content' => ['image_position' => 'left']],
            ],
            'cards' => [
                'label' => 'Karty', 'permission' => 'humlnetcreative.pages.section.cards', 'items' => true,
                'section_fields' => ['heading', 'text', 'columns', 'items'],
                'section_style_fields' => ['heading', 'text'],
                'item_fields' => ['heading', 'icon', 'text', 'cta', 'style', 'move_card', 'media'],
                'item_style_fields' => ['heading', 'text', 'cta'], 'item_media_slots' => ['card_image'],
                'defaults' => ['content' => ['columns' => 3]],
            ],
            'cta' => [
                'label' => 'CTA / pruh', 'permission' => 'humlnetcreative.pages.section.cta', 'items' => false,
                'section_fields' => ['heading', 'text', 'cta'],
                'section_style_fields' => ['container', 'heading', 'text', 'cta'],
            ],
            'accordion' => [
                'label' => 'FAQ', 'permission' => 'humlnetcreative.pages.section.accordion', 'items' => false,
                'section_fields' => ['heading', 'faq_group'], 'section_style_fields' => ['heading'],
            ],
            'gallery' => [
                'label' => 'Galerie', 'permission' => 'humlnetcreative.pages.section.gallery', 'items' => false,
                'section_fields' => ['heading', 'gallery', 'gallery_columns'], 'section_style_fields' => ['heading'],
                'defaults' => ['content' => ['gallery_columns' => 3]],
            ],
            'files' => ['label' => 'Soubory ke stažení', 'permission' => 'humlnetcreative.pages.section.files', 'items' => false, 'enabled' => false],
            'form' => ['label' => 'Formulář', 'permission' => 'humlnetcreative.pages.section.form', 'items' => false, 'enabled' => false],
            'embed' => [
                'label' => 'Vložený obsah', 'permission' => 'humlnetcreative.pages.section.embed', 'items' => false,
                'section_fields' => ['embed'], 'section_style_fields' => [],
            ],
            'posts' => ['label' => 'Příspěvky', 'permission' => 'humlnetcreative.pages.section.posts', 'items' => false, 'enabled' => false],
            'opening_hours' => ['label' => 'Otevírací doba', 'permission' => 'humlnetcreative.pages.section.opening_hours', 'items' => false, 'enabled' => false],
            'pricelist' => ['label' => 'Ceník', 'permission' => 'humlnetcreative.pages.section.pricelist', 'items' => false, 'enabled' => false],
            'timeline' => ['label' => 'Časová osa', 'permission' => 'humlnetcreative.pages.section.timeline', 'items' => false, 'enabled' => false],
            'links' => ['label' => 'Odkazy', 'permission' => 'humlnetcreative.pages.section.links', 'items' => false, 'enabled' => false],
            'flash_messages' => ['label' => 'Flash zprávy', 'permission' => 'humlnetcreative.pages.section.flash_messages', 'items' => false, 'enabled' => false],
        ];

        $theme = Theme::getActiveTheme();
        $path = $theme ? $theme->getPath().'/config/page-builder.php' : null;
        if ($path && is_file($path)) {
            foreach ((require $path) as $type => $themeDefinition) {
                $this->definitions[$type] = array_replace($this->definitions[$type] ?? [], $themeDefinition);
            }
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
