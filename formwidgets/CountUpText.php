<?php namespace HumlnetCreative\Pages\FormWidgets;

use Backend\Classes\FormWidgetBase;
use HumlnetCreative\Pages\Services\CountUpMarkup;

/** One-line text editor that can annotate exact numeric selections. */
final class CountUpText extends FormWidgetBase
{
    protected $defaultAlias = 'countuptext';

    public function render()
    {
        $this->vars['name'] = $this->getFieldName();
        $this->vars['value'] = CountUpMarkup::normalizeInline($this->getLoadValue());
        $this->vars['id'] = $this->getId();
        $this->vars['readOnly'] = (bool) ($this->formField->disabled || $this->previewMode);

        return $this->makePartial('countuptext');
    }

    public function loadAssets(): void
    {
        $this->addCss('css/countuptext.css');
        $this->addJs('js/countuptext.js');
    }

    public function getSaveValue($value): string
    {
        return CountUpMarkup::normalizeInline($value);
    }
}
