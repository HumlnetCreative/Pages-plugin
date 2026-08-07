<?php namespace HumlnetCreative\Pages\Components;

use Cms\Classes\ComponentBase;
use Cms\Classes\PageManager;
use Cms\Classes\Theme;
use HumlnetCreative\Pages\Models\Page;

/**
 * Breadcrumbs Component
 *
 * @link https://docs.octobercms.com/3.x/extend/cms-components.html
 */
class Breadcrumbs extends ComponentBase
{
    public function componentDetails()
    {
        return [
            "name" => "humlnetcreative.pages::lang.component.breadcrumbs.name",
            "description" => "humlnetcreative.pages::lang.component.breadcrumbs.description",
        ];
    }

    /**
     * @link https://docs.octobercms.com/3.x/element/inspector-types.html
     */
    public function defineProperties()
    {
        return [];
    }

    /**
     * @return array
     */
    public function breadcrumbs(): array
    {
        $breadcrumbs = [];

        if (isset($this->page["page"])) {
            $column = $this->page["page"]->property("column");
            $value = $this->page["page"]->property("value");
            $pageCode = $this->property("pageCode") ?: $this->page["page"]->page->page->getBaseFilename();
            $theme = Theme::getActiveTheme();
            $page = Page::where($column, $value)->first();

            if ($page) {
                $i = 1;

                $breadcrumbs[] = [
                    "title" => $page->title,
                    "url"   => $page->breadcrumb == "auto" ?
                        Page::getPageUrl($pageCode, $page, $theme) :
                        PageManager::url($page->breadcrumb_url),
                ];

                $iterator = function (Page $page) use (&$breadcrumbs, &$iterator, &$pageCode, &$theme): void {
                    $breadcrumbs[] = [
                        "title" => $page->title,
                        "url"   => $page->breadcrumb == "auto" ?
                            Page::getPageUrl($pageCode, $page, $theme) :
                            PageManager::url($page->breadcrumb_url),
                    ];

                    if ($page->parent) {
                        $iterator($page->parent);
                    }
                };

                if ($page->parent) {
                    $iterator($page->parent);
                }
            }
        }

        return array_reverse($breadcrumbs);
    }
}
