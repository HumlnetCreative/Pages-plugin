<?php

namespace HumlnetCreative\Pages\Models;

use Backend\Models\ImportModel;

class ContentImport extends ImportModel
{
    /**
     * @var array
     */
    public array $rules = [];

    /**
     * @param $results
     * @param $sessionKey
     * @return void
     */
    public function importData($results, $sessionKey = null): void
    {
        foreach ($results as $row => $data) {
            try {
                $page = new Content();

                foreach ($data as $column => $value) {
                    $page->{$column} = $value;
                }

                $page->save();

                $this->logCreated();
            } catch (\Exception $exception) {
                $this->logError($row, $exception->getMessage());
            }
        }
    }
}
