<?php

namespace HumlnetCreative\Pages\Updates;

use October\Rain\Database\Updates\Migration;
use October\Rain\Support\Facades\Schema;

class AddItemsPerPageColumn extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table("lzaplata_pages_blocks", function ($table): void {
            $table->integer("items_per_page")->default(4);
        });

        Schema::table("lzaplata_pages_contents", function ($table): void {
            $table->integer("items_per_page")->default(12);
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {

    }
}
