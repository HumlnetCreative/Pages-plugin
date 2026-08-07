<?php

namespace HumlnetCreative\Pages\Helpers;

use Cms\Classes\Partial;
use Cms\Classes\Theme;

class Content
{
    /**
     * @param $model
     * @param $formField
     * @return array
     * @throws \ApplicationException
     */
    public static function getPartialOptions($model, $formField): array
    {
        $theme = Theme::getActiveTheme();
        $partials = Partial::listInTheme($theme);
        $partialOptions = [];

        foreach ($partials as $partial) {
            if ($formField->fieldName == "contacts_partial" && preg_match("@_contact/[a-z]+@", $partial->getBaseFileName())) {
                $partialOptions[$partial->getBaseFileName()] = $partial->getBaseFileName();
            }
        }

        asort($partialOptions);

        return $partialOptions;
    }

    /**
     * @return string[]
     */
    public static function getRowColsOptions(): array
    {
        return [
            "1"     => "1",
            "2"     => "2",
            "3"     => "3",
            "4"     => "4",
            "5"     => "5",
            "6"     => "6",
        ];
    }
}
