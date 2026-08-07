<?php namespace HumlnetCreative\Pages\FormWidgets;

use Backend\Classes\FormWidgetBase;
use Cms\Classes\Theme;
use Cms\Models\ThemeData;

/**
 * BlockTypeSelector Form Widget
 *
 * @link https://docs.octobercms.com/3.x/extend/forms/form-widgets.html
 */
class RangeSelector extends FormWidgetBase
{
    /**
     * @var string
     */
    protected $defaultAlias = "rangeselector";

    /**
     * @var int
     */
    public int $min = 0;

    /**
     * @var int
     */
    public int $max = 100;

    /**
     * @var float
     */
    public float $step = 1;

    /**
     * @var int
     */
    public int $default = 0;

    /**
     * @var string|null
     */
    public ?string $unit = null;

    /**
     * @return void
     */
    public function init(): void
    {
        $this->fillFromConfig([
            "min",
            "max",
            "step",
            "default",
            "unit",
        ]);
    }

    public function render()
    {
        $this->prepareVars();
        return $this->makePartial('rangeselector');
    }

    /**
     * @return void
     */
    public function prepareVars(): void
    {
        $this->vars["name"] = $this->formField->getName();
        $this->vars["value"] = $this->getLoadValue();
        $this->vars["id"] = $this->formField->getId();
        $this->vars["min"] = $this->min;
        $this->vars["max"] = $this->max;
        $this->vars["step"] = $this->step;
        $this->vars["default"] = $this->default;
        $this->vars["unit"] = $this->unit;
    }

    public function loadAssets()
    {
        $this->addCss('css/rangeselector.css');
        $this->addJs('js/rangeselector.js');
    }

    public function getSaveValue($value)
    {
        return $value;
    }
}
