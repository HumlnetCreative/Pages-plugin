<?php

namespace HumlnetCreative\Pages\Models;

use Backend\Models\ImportModel;
use Cms\Classes\Theme;
use Cms\Models\ThemeData;

class ThemeImport extends ImportModel
{
    /**
     * @var array
     */
    public array $rules = [];

    /**
     * Import theme data, merging parent theme DB values as base before applying overrides.
     *
     * @param  array $results
     * @param  mixed $sessionKey
     * @return void
     * @throws \Exception
     */
    public function importData($results, $sessionKey = null): void
    {
        foreach ($results as $row => $data) {
            try {
                $theme = Theme::load($data["code"]);
                $themeData = ThemeData::forTheme($theme);
                $parentCode = $theme->getConfigValue("parent");

                if ($parentCode) {
                    $parentTheme = Theme::load($parentCode);
                    $parentThemeData = ThemeData::forTheme($parentTheme);

                    $staticAttributes = [
                        "id",
                        "theme",
                        "data",
                        "created_at",
                        "updated_at",
                    ];

                    $parentDynamicAttributes = array_diff_key(
                        $parentThemeData->getAttributes(),
                        array_flip($staticAttributes)
                    );

                    $themeData->setRawAttributes(
                        $themeData->getAttributes() + $parentDynamicAttributes,
                        true
                    );
                }

                foreach ($data as $key => $value) {
                    if ($key == "code") continue;

                    $themeData->{$key} = $value;
                }

                $themeData->save();

                $this->logUpdated();
            } catch (\Exception $exception) {
                $this->logError($row, $exception->getMessage());
            }
        }
    }
}
