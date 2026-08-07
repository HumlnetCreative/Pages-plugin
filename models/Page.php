<?php namespace HumlnetCreative\Pages\Models;

use Backend\Facades\BackendAuth;
use Cms\Classes\Page as CmsPage;
use Cms\Classes\Theme;
use Model;
use October\Rain\Database\Traits\Multisite;
use October\Rain\Database\Traits\SimpleTree;
use October\Rain\Database\Traits\Sortable;
use RainLab\Pages\Classes\Menu as PagesMenu;
use Tailor\Models\EntryRecord;
use Tailor\Traits\BlueprintRelationModel;
use Url;

/**
 * Model
 */
class Page extends Model
{
    use \October\Rain\Database\Traits\Validation;
    use \October\Rain\Database\Traits\SoftDelete;
    use SimpleTree;
    use Sortable;
    use Multisite;
    use BlueprintRelationModel;

    /**
     * @var array dates to cast from the database.
     */
    protected $dates = ['deleted_at'];

    /**
     * @var string table in the database used by the model.
     */
    public $table = 'lzaplata_pages_pages';

    /**
     * @var array rules for validation.
     */
    public $rules = [
        "title"         => "required",
        "slug"          => ["required", "regex:/^[a-z0-9\/\:_\-\*\[\]\+\?\|]*$/i"],
        "fullslug"      => ["required", "regex:/^[a-z0-9\/\:_\-\*\[\]\+\?\|]*$/i"],
        "sort_order"    => "required",
    ];

    /**
     * @var array
     */
    public $hasMany = [
        "contents"  => Content::class,
    ];

    public $belongsTo = [
        "parent"    => Page::class,
        "slider"    => [
            EntryRecord::class,
            "blueprint" => "lzaplata_slider_sliders",
        ],
    ];

    /**
     * @var array
     */
    public $propagatable = [];

    /**
     * @var array
     */
    public $fillable = [
        "id",
    ];

    /**
     * @return void
     */
    public function afterSave()
    {
        $this->updateChildrenFullslug($this->id);
    }

    public function updateChildrenFullslug(int $parentId)
    {
        $chil = Page::where("parent_id", $parentId)->first();

        if ($chil) {
            $chil->fullslug = $this->fullslug . "/" . $chil->slug;
            $chil->save();
        }
    }

    /**
     * @param $type
     *
     * @return array
     */
    public static function getMenuTypeInfo($type)
    {
        $result = [];

        if ($type == "page") {
            $result = [
                "nesting"       => false,
                "dynamicItems"  => false,
                "references"    => self::listSubPageOptions(),
            ];
        }

        if ($result) {
            $theme = Theme::getActiveTheme();
            $pages = CmsPage::listInTheme($theme, true);
            $cmsPages = [];

            foreach ($pages as $page) {
                if (!$page->hasComponent("page")) {
                    continue;
                }

                $cmsPages[] = $page;
            }

            $result["cmsPages"] = $cmsPages;
        }

        return $result;
    }

    /**
     * @return array
     */
    protected static function listSubPageOptions()
    {
        $page = self::getNested();

        $iterator = function($pages) use (&$iterator) {
            $result = [];

            foreach ($pages as $page) {
                if (!$page->children) {
                    $result[$page->site_root_id] = $page->title;
                }
                else {
                    $result[$page->site_root_id] = [
                        "title" => $page->title,
                        "items" => $iterator($page->children)
                    ];
                }
            }

            return $result;
        };

        return $iterator($page);
    }

    /**
     * @param $item
     * @param $url
     * @param $theme
     *
     * @return array|void|null
     */
    public static function resolveMenuItem($item, $url, $theme)
    {
        $result = null;

        if ($item->type == "page") {
            if (!$item->reference || !$item->cmsPage) {
                return;
            }

            $page = self::where("site_root_id", $item->reference)
                ->first();

            if (!$page) {
                return;
            }

            $pageUrl = self::getPageUrl($item->cmsPage, $page, $theme);

            if (!$pageUrl) {
                return;
            }

            $pageUrl = Url::to($pageUrl);

            $result = [];
            $result["url"] = $pageUrl;
            $result["isActive"] = $pageUrl == $url;
            $result["mtime"] = $page->updated_at;

            if ($item->nesting) {
                $iterator = function($pages) use (&$iterator, &$item, &$theme, $url) {
                    $branch = [];

                    foreach ($pages as $p) {
                        $branchItem = [];
                        $branchItem["url"] = self::getPageUrl($item->cmsPage, $p, $theme);
                        $branchItem["isActive"] = $branchItem["url"] == $url;
                        $branchItem["title"] = $p->title;
                        $branchItem["mtime"] = $p->updated_at;

                        if ($p->children) {
                            $branchItem["items"] = $iterator($p->children);
                        }

                        $branch[] = $branchItem;
                    }

                    return $branch;
                };

                $result["items"] = $iterator($page->children);
            }
        }

        return $result;
    }

    /**
     * @param $pageCode
     * @param $category
     * @param $theme
     *
     * @return string|void
     */
    public static function getPageUrl($pageCode, $page, $theme)
    {
        $cmsPage = CmsPage::loadCached($theme, $pageCode);

        if (!$cmsPage) {
            return;
        }

        $properties = $cmsPage->getComponentProperties("page");

        if (!isset($properties["column"]) || !isset($properties["value"])) {
            return;
        }

        if (!preg_match('/^\{\{([^\}]+)\}\}$/', $properties["value"], $matches)) {
            return;
        }

        $paramName = substr(trim($matches[1]), 1);
        $columnName = $properties["column"];
        $url = CmsPage::url($cmsPage->getBaseFileName(), [$paramName => $page->{$columnName}]);

        return $url;
    }

    /**
     * @param $fields
     * @param $context
     *
     * @return void
     */
    public function filterFields($fields, $context = null)
    {
        if (BackendAuth::userHasPermission("humlnetcreative.pages.page.reorder")) {
            $parent = Page::find($fields->parent->value);

            if ($context === "refresh") {
                if ($parent) {
                    $fields->fullslug->value = $parent->fullslug . "/" . $fields->slug->value;
                    $fields->menu->value = $parent->menu;

                    $latestSibling = Page::where("parent_id", $parent->id)
                        ->orderBy("sort_order", "desc")
                        ->first();
                } else {
                    $fields->fullslug->value = $fields->slug->value;

                    $latestSibling = Page::where("parent_id", null)
                        ->orderBy("sort_order", "desc")
                        ->first();
                }

                if ($latestSibling) {
                    $order = $latestSibling->sort_order + 10;
                } else {
                    $order = 10;
                }

                $fields->sort_order->value = $order;
            }
        }
    }

    /**
     * @param $query
     * @param $model
     * @return void
     */
    public function scopeFilterParents($query, $model): void
    {
        $forbiddenParentIds = [];

        foreach ($query->get() as $parent) {
            $permissionName = str_replace("/", ".", $parent->fullslug);

            if (BackendAuth::userHasAccess("humlnetcreative.pages.structure") && !BackendAuth::userHasAccess("humlnetcreative.pages.structure.$permissionName")) {
                $forbiddenParentIds[] = $parent->id;
            }
        }

        $query->whereNotIn("id", $forbiddenParentIds);
    }


    /**
     * @return array
     */
    public function getMenuOptions(): array
    {
        $result = [];
        $theme = Theme::getEditTheme();
        $menus = PagesMenu::listInTheme($theme, true);

        foreach ($menus as $menu) {
            $result[$menu->code] = $menu->name;
        }

        return $result;
    }
}
