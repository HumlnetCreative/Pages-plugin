<?php namespace HumlnetCreative\Pages\FormWidgets;

use Backend\Classes\FormWidgetBase;
use Backend\Facades\BackendAuth;
use HumlnetCreative\Pages\Models\Section;
use HumlnetCreative\Pages\Services\SliderLifecycle;

class SliderUsage extends FormWidgetBase
{
    protected $defaultAlias = 'sliderusage';

    public function render()
    {
        $this->vars['model'] = $this->model;
        $this->vars['sections'] = $this->model->exists
            ? Section::with('page')->where('slider_id', $this->model->id)->get()
            : collect();
        $this->vars['handler'] = $this->getEventHandler('onForceDeleteSlider');
        return $this->makePartial('sliderusage');
    }

    public function onForceDeleteSlider()
    {
        if (!BackendAuth::userHasPermission('humlnetcreative.pages.slider.force_delete')) {
            throw new \ApplicationException('K vynucenému odstranění nemáte oprávnění.');
        }
        if (!$this->model->exists) {
            return;
        }

        SliderLifecycle::forceDelete($this->model);

        \Flash::success('Slider byl odstraněn a dotčené bloky Prezentace byly skryty.');
        return \Redirect::to(\Backend::url('tailor/entries/slider-slider'));
    }
}
