<?php namespace HumlnetCreative\Pages\Components;

use Cms\Classes\ComponentBase;
use HumlnetCreative\Pages\Models\BuilderPage as BuilderPageModel;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BuilderPage extends ComponentBase
{
    public ?BuilderPageModel $record = null;
    public array $sections = [];

    public function componentDetails(): array
    {
        return ['name' => 'Page Builder', 'description' => 'Vykreslí novou generaci stránky.'];
    }

    public function defineProperties(): array
    {
        return ['value' => ['title' => 'Cesta', 'default' => '{{ :fullslug }}']];
    }

    public function onRun()
    {
        $slug = trim((string) $this->property('value'), '/');
        $query = BuilderPageModel::with([
            'sections' => fn($query) => $query->where('is_published', true)->with([
                'media.asset',
                'slider',
                'items' => fn($items) => $items->where('is_published', true)->with('media.asset'),
            ]),
        ]);
        $this->record = $slug === '' ? $query->where('is_home', true)->first() : $query->where('fullslug', $slug)->first();
        if (!$this->record || !$this->record->is_published) {
            throw new NotFoundHttpException();
        }
        $this->sections = $this->record->sections->sortBy('sort_order')->all();
        $this->page['builderRecord'] = $this->record;
        $this->page['builderSections'] = $this->sections;
    }
}
