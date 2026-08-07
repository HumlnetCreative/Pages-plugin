<?php namespace HumlnetCreative\Pages\FormWidgets;

use Backend\Classes\FormWidgetBase;
use Cms\Classes\Theme;
use Cms\Models\ThemeData;

/**
 * BlockTypeSelector Form Widget
 *
 * @link https://docs.octobercms.com/3.x/extend/forms/form-widgets.html
 */
class ColorSchemeSelector extends FormWidgetBase
{
    /**
     * @var string
     */
    protected $defaultAlias = "colorschemeselector";

    public function init()
    {
    }

    public function render()
    {
        $this->prepareVars();
        return $this->makePartial('colorschemeselector');
    }

    /**
     * @return void
     * @throws \ApplicationException
     */
    public function prepareVars(): void
    {
        $theme = Theme::getActiveTheme();
        $themeData = ThemeData::forTheme($theme);

        $this->vars["model"] = $this->model;
        // Include the form model prefix (for example Section[style][...]).
        // Without it, values displayed in a relation popup never reached the
        // RelationController save data and the Update button appeared inert.
        $this->vars["name"] = $this->getFieldName();
        $this->vars["value"] = $this->getLoadValue();
        $this->vars["id"] = $this->getId();
        $this->vars["options"] = $themeData["schemes"] ?? [];
    }

    public function loadAssets()
    {
        $this->addCss('css/colorschemeselector.css');
    }

    public function getSaveValue($value)
    {
        return $value === '__inherit__' || $value === '' ? null : $value;
    }
}
