<?php

namespace HumlnetCreative\Pages\Updates;

use October\Rain\Database\Updates\Migration;
use October\Rain\Support\Facades\Schema;

class AddRowColsColumn extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table("lzaplata_pages_contents", function ($table): void {
            $table->integer("row_cols")->default(4);
        });
    }

    public function down(): void
    {

    }
}
