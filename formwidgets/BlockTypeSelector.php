<?php namespace HumlnetCreative\Pages\FormWidgets;

use Backend\Classes\FormWidgetBase;

/**
 * BlockTypeSelector Form Widget
 *
 * @link https://docs.octobercms.com/3.x/extend/forms/form-widgets.html
 */
class BlockTypeSelector extends FormWidgetBase
{
    /**
     * @var string
     */
    protected $defaultAlias = "blocktypeselector";

    public function init()
    {
    }

    public function render()
    {
        $this->prepareVars();
        return $this->makePartial('blocktypeselector');
    }

    /**
     * @return void
     */
    public function prepareVars(): void
    {
        $this->vars["model"] = $this->model;
        $this->vars["name"] = $this->formField->getName();
        $this->vars["value"] = $this->getLoadValue();
        $this->vars["id"] = $this->formField->getId();
        $this->vars["options"] = $this->formField->options();
    }

    public function loadAssets()
    {
        $this->addCss('css/blocktypeselector.css');
        $this->addJs('js/blocktypeselector.js');
    }

    public function getSaveValue($value)
    {
        return $value;
    }
}
