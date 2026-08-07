<?php namespace HumlnetCreative\Pages\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class V2Changes extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table("lzaplata_pages_blocks", function (Blueprint $table): void {
            $table->dropColumn("padding_top");

            $table->json("padding")->after("no_gutters_breakpoint")->nullable();
            $table->tinyInteger("options_inherited")->default(1);
            $table->json("options")->nullable();

            $table->renameColumn("blog_category_id", "posts_category_id");
            $table->renameColumn("blog_sort_order", "posts_sort_order");
        });

        Schema::table("lzaplata_pages_contents", function (Blueprint $table): void {
            $table->json("padding")->after("no_gutters_breakpoint")->nullable();
            $table->tinyInteger("options_inherited")->default(1);
            $table->json("options")->nullable();

            $table->renameColumn("blog_category_id", "posts_category_id");
            $table->renameColumn("blog_sort_order", "posts_sort_order");
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table("lzaplata_pages_blocks", function (Blueprint $table): void {
            $table->dropColumn("padding");
            $table->dropColumn("options_inherited");
            $table->dropColumn("options");

            $table->boolean("padding_top")->after("no_gutters_breakpoint")->default(true);

            $table->renameColumn("posts_category_id", "blog_category_id");
            $table->renameColumn("posts_sort_order", "blog_sort_order");
        });

        Schema::table("lzaplata_pages_contents", function (Blueprint $table): void {
            $table->dropColumn("padding");
            $table->dropColumn("options_inherited");
            $table->dropColumn("options");

            $table->renameColumn("posts_category_id", "blog_category_id");
            $table->renameColumn("posts_sort_order", "blog_sort_order");
        });
    }
}
