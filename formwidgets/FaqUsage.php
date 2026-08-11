<?php namespace HumlnetCreative\Pages\FormWidgets;

use Backend\Classes\FormWidgetBase;
use Backend\Facades\BackendAuth;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Services\FaqLifecycle;
use Tailor\Classes\BlueprintIndexer;

class FaqUsage extends FormWidgetBase
{
    protected $defaultAlias = 'faqusage';

    public function render()
    {
        $this->vars['model'] = $this->model;
        $this->vars['sections'] = $this->model->exists
            ? Section::with('page')->where('faq_group_id', $this->model->id)->get()
            : collect();
        $this->vars['handler'] = $this->getEventHandler('onForceDeleteFaqGroup');

        return $this->makePartial('faqusage');
    }

    public function onForceDeleteFaqGroup()
    {
        if (!BackendAuth::userHasPermission('humlnetcreative.pages.faq.force_delete')) {
            throw new \ApplicationException('K vynucenému odstranění nemáte oprávnění.');
        }

        if (!$this->model->exists) {
            return;
        }

        FaqLifecycle::forceDelete($this->model);

        \Flash::success('FAQ skupina byla odstraněna a dotčené FAQ bloky byly skryty.');
        $blueprint = BlueprintIndexer::instance()->findSectionByHandle('FAQ\\Group');
        return \Redirect::to(\Backend::url('tailor/entries/'.($blueprint?->handleSlug ?: 'f-a-q-group')));
    }
}
