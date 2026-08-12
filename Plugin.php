<?php namespace HumlnetCreative\Pages;

use HumlnetCreative\Pages\Components\Breadcrumbs;
use HumlnetCreative\Pages\Components\Homepage;
use HumlnetCreative\Pages\FormWidgets\BlockTypeSelector;
use HumlnetCreative\Pages\FormWidgets\ColorSchemeSelector;
use HumlnetCreative\Pages\FormWidgets\FaqUsage;
use HumlnetCreative\Pages\FormWidgets\RangeSelector;
use HumlnetCreative\Pages\FormWidgets\SliderMedia;
use HumlnetCreative\Pages\FormWidgets\SliderUsage;
use HumlnetCreative\Pages\Models\Page;
use HumlnetCreative\Pages\Models\SliderEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use October\Rain\Support\Facades\Event;
use System\Classes\PluginBase;
use HumlnetCreative\Pages\Components\Page as PageComponent;
use HumlnetCreative\Pages\Components\BuilderPage as BuilderPageComponent;
use HumlnetCreative\Pages\Components\BuilderBreadcrumbs;
use HumlnetCreative\Pages\Services\SectionRegistry;
use Tailor\Models\EntryRecord;
use Tailor\Models\StructureRecord;
use HumlnetCreative\Pages\Services\SliderRecordLifecycle;
use HumlnetCreative\Pages\Services\FaqRecordLifecycle;
use HumlnetCreative\Pages\Services\GalleryRecordLifecycle;
use HumlnetCreative\Pages\Contracts\RedirectManagerInterface;
use HumlnetCreative\Pages\Services\VdlpRedirectAdapter;

/**
 * Plugin class
 */
class Plugin extends PluginBase
{
    /** @var array Required October plugins. */
    public $require = ['Vdlp.Redirect'];

    /**
     * register method, called when the plugin is first registered.
     */
    public function register(): void
    {
        $this->app->singleton(RedirectManagerInterface::class, VdlpRedirectAdapter::class);

        if (!Schema::hasTable('system_plugin_versions')) {
            return;
        }

        DB::transaction(function(): void {
            $legacyCode = 'LZaplata.Pages';
            $pluginCode = 'HumlnetCreative.Pages';

            if (!DB::table('system_plugin_versions')->where('code', $legacyCode)->exists()
                || DB::table('system_plugin_versions')->where('code', $pluginCode)->exists()) {
                return;
            }

            DB::table('system_plugin_versions')->where('code', $legacyCode)->update(['code' => $pluginCode]);

            if (Schema::hasTable('system_plugin_history')) {
                DB::table('system_plugin_history')->where('code', $legacyCode)->update(['code' => $pluginCode]);
            }
        });
    }

    /**
     * boot method, called right before the request route.
     */
    public function boot()
    {
        StructureRecord::extend(function(StructureRecord $model) {
            SliderRecordLifecycle::bind($model);
            FaqRecordLifecycle::bind($model);
        });
        SliderEntry::extend(fn(SliderEntry $model) => SliderRecordLifecycle::bind($model));
        if (class_exists(\LZaplata\Gallery\Models\Gallery::class)) {
            \LZaplata\Gallery\Models\Gallery::extend(fn($model) => GalleryRecordLifecycle::bind($model));
        }

        Event::listen('backend.page.beforeDisplay', function($controller) {
            $isPagesController = str_starts_with($controller::class, 'HumlnetCreative\\Pages\\Controllers\\');
            $isTailorController = str_starts_with($controller::class, 'Tailor\\Controllers\\');
            if (!$isPagesController && !$isTailorController) {
                return;
            }
            $assetUrl = static function(string $relativePath): string {
                $absolutePath = __DIR__.'/assets/'.$relativePath;
                $version = is_file($absolutePath) ? filemtime($absolutePath) : 1;
                return '/plugins/humlnetcreative/pages/assets/'.$relativePath.'?v='.$version;
            };
            $controller->addJs($assetUrl('js/backend-save-hotkey.js'));
            if ($isPagesController) {
                $controller->addJs($assetUrl('js/backend-slider-editor.js'));
                $controller->addJs($assetUrl('js/backend-media-crop.js'));
                $controller->addJs($assetUrl('js/backend-media-editor.js'));
                $controller->addCss($assetUrl('css/backend-page-builder.css'));
                $controller->addCss($assetUrl('css/backend-media-crop.css'));
                $controller->addCss($assetUrl('css/backend-media-editor.css'));
            }
        });

        Event::listen('backend.form.extendFields', function($widget) {
            $model = $widget->model;
            if ($model instanceof EntryRecord && $model->blueprint_uuid === 'lzaplata_slider_slides'
                && !\Backend\Facades\BackendAuth::userHasPermission('humlnetcreative.pages.slider.publish')) {
                $widget->removeField('is_enabled');
            }

            if ($model instanceof EntryRecord && $model->blueprint_uuid === 'lzaplata_faq'
                && ($answer = $widget->getField('answer'))) {
                $answer->toolbarButtons = \Backend\Facades\BackendAuth::userHasPermission('humlnetcreative.pages.editor.html')
                    ? 'undo|redo||paragraphFormat|bold|italic|underline||align|formatOL|formatUL|outdent|indent||insertPageLink|insertHR|insertTable||fullscreen|html'
                    : 'undo|redo||paragraphFormat|bold|italic|underline||align|formatOL|formatUL|outdent|indent||insertPageLink|insertHR||fullscreen';
            }
        });

        Event::listen(["cms.pageLookup.listTypes", "pages.menuitem.listTypes"], function() {
            return [
                "page" => "humlnetcreative.pages::lang.menuitem.listtype.page.label",
                "builder-page" => "humlnetcreative.pages::lang.menuitem.listtype.builder_page.label",
            ];
        });

        Event::listen(["cms.pageLookup.getTypeInfo", "pages.menuitem.getTypeInfo"], function($type) {
            if ($type == "page") {
                return Page::getMenuTypeInfo($type);
            }

            if ($type === "builder-page") {
                return \HumlnetCreative\Pages\Models\BuilderPage::getMenuTypeInfo($type);
            }
        });

        Event::listen(["cms.pageLookup.resolveItem", "pages.menuitem.resolveItem"], function($type, $item, $url, $theme) {
            if ($type == "page") {
                return Page::resolveMenuItem($item, $url, $theme);
            }

            if ($type === "builder-page") {
                return \HumlnetCreative\Pages\Models\BuilderPage::resolveMenuItem($item, $url, $theme);
            }
        });
    }

    /**
     * registerComponents used by the frontend.
     */
    public function registerComponents()
    {
        return [
            PageComponent::class    => "page",
            Breadcrumbs::class      => "breadcrumbs",
            Homepage::class         => "homepage",
            BuilderPageComponent::class => "builderPage",
            BuilderBreadcrumbs::class => "builderBreadcrumbs",
        ];
    }

    /**
     * registerSettings used by the backend.
     */
    public function registerSettings()
    {
    }

    /**
     * @return array
     */
    public function registerMarkupTags(): array
    {
        return [
            "filters" => [
                "bootstrap" => function (string $text): string {
                    return preg_replace_callback("~<table.*?</table>~is", function(array $matches): string {
                        $table = str_replace("<table", "<table class='table table-bordered'", $matches[0]);

                        return "<div class='table-responsive'>" . $table . "</div>";
                    }, $text);
                },
            ],
        ];
    }

    /**
     * @return array
     */
    public function registerFormWidgets(): array
    {
        return [
            BlockTypeSelector::class    => "blocktypeselector",
            ColorSchemeSelector::class  => "colorschemeselector",
            FaqUsage::class             => "faqusage",
            RangeSelector::class        => "rangeselector",
            SliderMedia::class          => "slidermedia",
            SliderUsage::class          => "sliderusage",
        ];
    }

    /**
     * @return array
     * @throws \SystemException
     */
    public function registerPermissions(): array
    {
        $yamlConfig = $this->getConfigurationFromYaml();
        $permissions = $yamlConfig["permissions"];

        foreach (Page::all() as $page) {
            $permissionName = str_replace("/", ".", $page->fullslug);

            $permissions["humlnetcreative.pages.structure." . $permissionName] = [
                "label" => $page->title,
                "tab"   => 'humlnetcreative.pages::lang.plugin.name',
                "order" => $page->sort_order,
            ];
        }

        foreach (SectionRegistry::instance()->all() as $type => $definition) {
            $permissions[$definition['permission']] = [
                'label' => 'Sekce: '.$definition['label'],
                'tab' => 'humlnetcreative.pages::lang.plugin.name',
            ];
        }

        $permissions['humlnetcreative.pages.builder'] = ['label' => 'Nový Page Builder', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.builder.import'] = ['label' => 'Import kostry webu', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.builder.trash'] = ['label' => 'Obnova obsahu z koše', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.editor.html'] = ['label' => 'Rich editor: HTML zdroj a tabulky', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.schemes.manage'] = ['label' => 'Vývojář: definice barevných schémat theme', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.slider.manage'] = ['label' => 'Prezentace: správa Sliderů a položek', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.slider.select'] = ['label' => 'Prezentace: výběr Slideru v Builderu', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.slider.publish'] = ['label' => 'Prezentace: publikování položek', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.slider.media.image'] = ['label' => 'Prezentace: nahrávání obrázků', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.slider.media.video'] = ['label' => 'Prezentace: nahrávání videí', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.slider.media.crop'] = ['label' => 'Prezentace: ořez a pozice médií', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.slider.media.delete'] = ['label' => 'Prezentace: odstraňování médií', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.slider.force_delete'] = ['label' => 'Prezentace: vynucené odstranění Slideru', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.faq.manage'] = ['label' => 'FAQ: správa skupin a otázek', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.faq.select'] = ['label' => 'FAQ: výběr skupiny v Builderu', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.faq.force_delete'] = ['label' => 'FAQ: vynucené odstranění skupiny', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.gallery.manage'] = ['label' => 'Galerie: správa galerií a obrázků', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];
        $permissions['humlnetcreative.pages.gallery.select'] = ['label' => 'Galerie: výběr galerie v Builderu', 'tab' => 'humlnetcreative.pages::lang.plugin.name'];

        return $permissions;
    }
}
