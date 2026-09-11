<?php namespace HumlnetCreative\Pages\Components;

use Cms\Classes\ComponentBase;
use HumlnetCreative\Pages\Models\BuilderPage as BuilderPageModel;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\View;
use Backend\Facades\BackendAuth;
use HumlnetCreative\Pages\Services\PagePublicationService;
use HumlnetCreative\Pages\Services\PageStructureService;
use HumlnetCreative\Pages\Services\SectionMotion;

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
        $preview = request()->query('builder_preview') === 'draft';
        if ($preview) {
            if (!BackendAuth::getUser() || !BackendAuth::userHasPermission('humlnetcreative.pages.draft.edit')) {
                return $this->notFoundResponse();
            }
            $this->record = $this->loadWorkingCopy($slug);
            $this->controller->setResponseHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $this->controller->setResponseHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate');
            $this->page['builderIsDraftPreview'] = true;
        }
        else {
            $this->record = app(PagePublicationService::class)->findPublishedByPath($slug);
        }
        if (!$this->record) {
            return $this->notFoundResponse();
        }
        $this->sections = app(PageStructureService::class)->prepare($this->record)->all();
        $this->page['builderRecord'] = $this->record;
        $this->page['builderSections'] = $this->sections;
        $this->page['builderFaqSchemaJson'] = $this->buildFaqSchemaJson();
        if ($this->hasRenderableMotion($this->sections)) {
            $css = plugins_path('humlnetcreative/pages/assets/css/frontend-motion.css');
            $js = plugins_path('humlnetcreative/pages/assets/js/frontend-motion.js');
            $this->addCss(URL::asset('plugins/humlnetcreative/pages/assets/css/frontend-motion.css').'?v='.(is_file($css) ? filemtime($css) : 1));
            $this->addJs(URL::asset('plugins/humlnetcreative/pages/assets/js/frontend-motion.js').'?v='.(is_file($js) ? filemtime($js) : 1), ['defer' => true]);
        }
    }

    private function hasRenderableMotion(array $sections, bool $topLevel = true): bool
    {
        foreach (array_values($sections) as $index => $section) {
            if ((!$topLevel || $index > 0) && SectionMotion::presentation($section)['effect'] !== 'none') {
                return true;
            }
            foreach ($section->zones ?? [] as $zone) {
                if ($this->hasRenderableMotion(($zone->sections ?? collect())->all(), false)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function notFoundResponse()
    {
        $this->setStatusCode(404);

        // A catch-all Builder route cannot call controller->run('404') when a theme has
        // no custom 404 page: the router would select the same catch-all recursively.
        return Response::make(View::make('cms::404'), 404, [
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
    }

    private function loadWorkingCopy(string $slug): ?BuilderPageModel
    {
        $query = BuilderPageModel::with([
            'section_containers.columns_section',
            'sections' => fn($query) => $query->where('is_published', true)->with([
                'container.columns_section',
                'media.asset',
                'slider',
                'faq_group.questions',
                'gallery.images',
                'items' => fn($items) => $items->where('is_published', true)->with('media.asset'),
            ]),
        ]);
        $record = $slug === '' ? $query->where('is_home', true)->first() : $query->where('fullslug', $slug)->first();

        return $record;
    }

    protected function buildFaqSchemaJson(): ?string
    {
        $questions = collect($this->record->sections)
            ->where('type', 'accordion')
            ->flatMap(fn($section) => $section->faq_items)
            ->unique(fn($question) => $question->blueprint_uuid.'-'.$question->id)
            ->map(function($question) {
                $answer = html_entity_decode(strip_tags((string) $question->answer), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $answer = trim((string) preg_replace('/\s+/u', ' ', $answer));

                return [
                    '@type' => 'Question',
                    'name' => trim((string) $question->title),
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $answer,
                    ],
                ];
            })
            ->filter(fn(array $question) => $question['name'] !== '' && $question['acceptedAnswer']['text'] !== '')
            ->values()
            ->all();

        if (!$questions) {
            return null;
        }

        $url = URL::current();
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            '@id' => $url.'#webpage',
            'url' => $url,
            'name' => $this->record->title,
            'inLanguage' => str_replace('_', '-', \Site::getSiteFromContext()?->locale ?: 'cs'),
            'mainEntity' => $questions,
        ];

        return json_encode(
            $schema,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: null;
    }
}
