<?php namespace HumlnetCreative\Pages\Components;

use Cms\Classes\ComponentBase;
use HumlnetCreative\Pages\Models\BuilderPage;

class BuilderBreadcrumbs extends ComponentBase
{
    public function componentDetails(): array
    {
        return ['name' => 'Builder breadcrumbs', 'description' => 'Drobečková navigace pro nový Page Builder.'];
    }

    public function breadcrumbs(): array
    {
        $page = $this->page['builderRecord'] ?? null;
        if (!$page instanceof BuilderPage) { return []; }
        $result = [];
        while ($page) {
            $result[] = ['title' => $page->title, 'url' => \Url::to($page->is_home ? '/' : '/'.$page->fullslug)];
            $page = $page->parent;
        }
        return array_reverse($result);
    }
}
