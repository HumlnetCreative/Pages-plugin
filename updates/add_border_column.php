<?php namespace HumlnetCreative\Pages\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class AddBorderColumn extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table("lzaplata_pages_blocks", function (Blueprint $table): void {
            $table->json("border")->after("padding")->nullable();
        });

        Schema::table("lzaplata_pages_contents", function (Blueprint $table): void {
            $table->json("border")->after("padding")->nullable();
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table("lzaplata_pages_blocks", function (Blueprint $table): void {
            $table->dropColumn("border");
        });

        Schema::table("lzaplata_pages_contents", function (Blueprint $table): void {
            $table->dropColumn("border");
        });
    }
}