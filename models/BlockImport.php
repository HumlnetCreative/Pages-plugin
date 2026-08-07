<?php

namespace HumlnetCreative\Pages\Models;

use Backend\Models\ImportModel;
use Illuminate\Support\Facades\DB;

class BlockImport extends ImportModel
{
    /**
     * @var array
     */
    public array $rules = [];

    /**
     * Insert rows directly via the query builder to bypass Block's beforeSave()
     * rewrite of the options column and jsonable double-encoding.
     *
     * @param  $results
     * @param  $sessionKey
     * @return void
     */
    public function importData($results, $sessionKey = null): void
    {
        $block = new Block();
        $table = $block->getTable();

        $updateExisting = (bool) ($this->update_existing ?? false);

        foreach ($results as $row => $data) {
            try {
                $attributes = [];

                foreach ($data as $column => $value) {
                    $attributes[$column] = $value === "" ? null : $value;
                }

                $id = $attributes["id"] ?? null;
                $existing = $id && DB::table($table)->where("id", $id)->exists();

                if ($existing && $updateExisting) {
                    DB::table($table)->where("id", $id)->update($attributes);

                    $this->logUpdated();
                } elseif (!$existing) {
                    DB::table($table)->insert($attributes);

                    $this->logCreated();
                } else {
                    $this->logSkipped($row, "Row with id $id already exists");
                }
            } catch (\Exception $exception) {
                $this->logError($row, $exception->getMessage());
            }
        }
    }
}
