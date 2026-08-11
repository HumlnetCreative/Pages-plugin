<?php namespace HumlnetCreative\Pages\Components;

use Cms\Classes\ComponentBase;
use HumlnetCreative\Pages\Models\BuilderPage as BuilderPageModel;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Support\Facades\URL;

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
                'faq_group.questions',
                'gallery.images',
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
        $this->page['builderFaqSchemaJson'] = $this->buildFaqSchemaJson();
    }

    protected function buildFaqSchemaJson(): ?string
    {
        $questions = collect($this->sections)
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
