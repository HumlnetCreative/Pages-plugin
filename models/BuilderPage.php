<?php namespace HumlnetCreative\Pages\Models;

use October\Rain\Database\Model;
use October\Rain\Database\Traits\SoftDelete;
use October\Rain\Database\Traits\Multisite;
use October\Rain\Database\Traits\SimpleTree;
use October\Rain\Database\Traits\Sortable;
use Illuminate\Support\Str;
use Cms\Classes\Page as CmsPage;
use Cms\Classes\Theme;
use Cms\Classes\Router as CmsRouter;
use Url;

class BuilderPage extends Model
{
    use SoftDelete;
    use SimpleTree;
    use Sortable;
    use Multisite;

    public $table = 'humlnetcreative_pages_builder_pages';
    public $propagatable = [];
    protected $guarded = [];
    protected $dates = ['deleted_at'];
    protected $jsonable = ['style'];
    public $rules = [
        'title' => 'required|max:160',
        'slug' => ['max:160', 'regex:/^(?:[a-z0-9]+(?:-[a-z0-9]+)*)?$/i'],
    ];

    public function beforeSave()
    {
        if (!$this->uuid) {
            $this->uuid = (string) Str::uuid();
        }
        if ($this->is_home) {
            $this->parent_id = null;
            $this->slug = '';
            $this->assertOnlyHomePage();
        }
        if (!$this->is_home && !$this->slug) {
            $this->slug = Str::slug($this->title);
        }
        $this->assertParentIsValid();
        $this->fullslug = $this->buildFullslug();
        if (!$this->is_home && ($this->isReservedRoute() || $this->conflictsWithExplicitCmsRoute())) {
            throw new \ValidationException(['slug' => 'Tato cesta je rezervovaná pro explicitní routu webu.']);
        }
        $this->assertFullslugIsUnique();
    }

    public function afterSave()
    {
        foreach ($this->children as $child) {
            $child->fullslug = $child->buildFullslug();
            $child->save();
        }
    }

    public $hasMany = [
        'sections' => [Section::class, 'key' => 'page_id', 'order' => 'sort_order'],
    ];

    /** Supplies Builder pages and compatible CMS templates to Page Finder. */
    public static function getMenuTypeInfo(string $type): array
    {
        if ($type !== 'builder-page') {
            return [];
        }

        $theme = Theme::getEditTheme() ?: Theme::getActiveTheme();
        $cmsPages = [];

        if ($theme) {
            foreach (CmsPage::listInTheme($theme, true) as $cmsPage) {
                if (!$cmsPage->hasComponent('builderPage')) {
                    continue;
                }

                $properties = $cmsPage->getComponentProperties('builderPage');
                if (!preg_match('/^\s*\{\{\s*:[a-zA-Z0-9_]+\s*\}\}\s*$/', (string) ($properties['value'] ?? ''))) {
                    continue;
                }

                $cmsPages[] = $cmsPage;
            }
        }

        return [
            'nesting' => false,
            'dynamicItems' => false,
            'references' => static::listPageFinderOptions(),
            'cmsPages' => $cmsPages,
        ];
    }

    /** Resolves a Page Finder selection to the selected Builder page URL. */
    public static function resolveMenuItem($item, string $url, Theme $theme): ?array
    {
        if ($item->type !== 'builder-page' || !$item->reference || !$item->cmsPage) {
            return null;
        }

        $page = static::find($item->reference);
        if (!$page || !$page->is_published) {
            return null;
        }

        $pageUrl = static::getPageFinderUrl((string) $item->cmsPage, $page, $theme);
        if (!$pageUrl) {
            return null;
        }

        $pageUrl = Url::to($pageUrl);

        return [
            'url' => $pageUrl,
            'isActive' => mb_strtolower($pageUrl) === mb_strtolower($url),
            'mtime' => $page->updated_at,
        ];
    }

    protected static function listPageFinderOptions(): array
    {
        $pages = static::where('is_published', true)->orderBy('sort_order')->getNested();

        $iterator = function($nodes) use (&$iterator): array {
            $result = [];

            foreach ($nodes as $page) {
                $children = $iterator($page->children);
                $result[$page->getKey()] = $children
                    ? ['title' => $page->title, 'items' => $children]
                    : $page->title;
            }

            return $result;
        };

        return $iterator($pages);
    }

    protected static function getPageFinderUrl(string $pageCode, self $page, Theme $theme): ?string
    {
        $cmsPage = CmsPage::loadCached($theme, $pageCode);
        if (!$cmsPage || !$cmsPage->hasComponent('builderPage')) {
            return null;
        }

        $properties = $cmsPage->getComponentProperties('builderPage');
        if (!preg_match('/^\s*\{\{\s*:([a-zA-Z0-9_]+)\s*\}\}\s*$/', (string) ($properties['value'] ?? ''), $matches)) {
            return null;
        }

        return CmsPage::url($cmsPage->getBaseFileName(), [$matches[1] => $page->fullslug]);
    }

    protected function buildFullslug(): string
    {
        if ($this->is_home) {
            return '';
        }
        $prefix = $this->parent ? trim($this->parent->fullslug, '/') : '';
        return trim($prefix . '/' . $this->slug, '/');
    }

    protected function assertOnlyHomePage(): void
    {
        $query = static::where('is_home', true)->where('id', '<>', $this->id ?: 0);
        is_null($this->site_id) ? $query->whereNull('site_id') : $query->where('site_id', $this->site_id);
        if ($query->exists()) {
            throw new \ValidationException(['is_home' => 'Tento web již má úvodní stránku.']);
        }
    }

    protected function assertFullslugIsUnique(): void
    {
        $query = static::where('fullslug', $this->fullslug)->where('id', '<>', $this->id ?: 0);
        is_null($this->site_id) ? $query->whereNull('site_id') : $query->where('site_id', $this->site_id);
        if ($query->exists()) {
            throw new \ValidationException(['slug' => 'Stránka se stejnou cestou už na tomto webu existuje.']);
        }
    }

    protected function assertParentIsValid(): void
    {
        if (!$this->parent_id) {
            return;
        }

        $parentId = (int) $this->parent_id;
        while ($parentId) {
            if ($this->exists && $parentId === (int) $this->id) {
                throw new \ValidationException(['parent' => 'Stránku nelze vložit pod ni samotnou ani pod jejího potomka.']);
            }
            $parentId = (int) (static::withoutGlobalScopes()->whereKey($parentId)->value('parent_id') ?: 0);
        }
    }

    protected function reservedRoutes(): array
    {
        $theme = Theme::getActiveTheme();
        $path = $theme ? $theme->getPath().'/config/reserved-routes.php' : null;
        return $path && is_file($path) ? require $path : [];
    }

    protected function isReservedRoute(): bool
    {
        $rootSegment = explode('/', $this->fullslug)[0] ?? '';
        return in_array($this->fullslug, $this->reservedRoutes(), true)
            || in_array($rootSegment, $this->reservedRoutes(), true);
    }

    protected function conflictsWithExplicitCmsRoute(): bool
    {
        $theme = Theme::getActiveTheme();
        if (!$theme) {
            return false;
        }
        $matched = (new CmsRouter($theme))->findByUrl('/'.$this->fullslug);
        return $matched && !in_array($matched->getFileName(), ['page.htm', 'homepage.htm'], true);
    }
}
