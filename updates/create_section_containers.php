<?php namespace HumlnetCreative\Pages\Updates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use October\Rain\Database\Updates\Migration;
use Schema;

/** Adds explicit root and column-zone containers without changing section order or rendering. */
class CreateSectionContainers extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('humlnetcreative_pages_section_containers')) {
            Schema::create('humlnetcreative_pages_section_containers', function($table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('page_id')->index();
                $table->unsignedBigInteger('parent_section_id')->nullable()->index();
                $table->string('kind', 20)->default('zone')->index();
                $table->string('title', 160)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedTinyInteger('width_units')->default(1);
                $table->string('vertical_align', 20)->default('top');
                $table->string('block_spacing', 20)->default('standard');
                $table->json('style')->nullable();
                $table->softDeletes();
                $table->timestamps();
                $table->index(['page_id', 'kind'], 'hucr_pages_containers_page_kind');
            });
        }

        if (!Schema::hasColumn('humlnetcreative_pages_sections', 'container_id')) {
            Schema::table('humlnetcreative_pages_sections', function($table) {
                $table->unsignedBigInteger('container_id')->nullable()->index()->after('page_id');
            });
        }

        $now = now();
        DB::table('humlnetcreative_pages_builder_pages')->orderBy('id')->each(function($page) use ($now): void {
            $rootId = DB::table('humlnetcreative_pages_section_containers')
                ->where('page_id', $page->id)
                ->where('kind', 'root')
                ->whereNull('deleted_at')
                ->value('id');
            if (!$rootId) {
                $rootId = DB::table('humlnetcreative_pages_section_containers')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'page_id' => $page->id,
                    'parent_section_id' => null,
                    'kind' => 'root',
                    'title' => 'Hlavní obsah',
                    'sort_order' => 1,
                    'width_units' => 4,
                    'vertical_align' => 'top',
                    'block_spacing' => 'standard',
                    'style' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('humlnetcreative_pages_sections')
                ->where('page_id', $page->id)
                ->whereNull('container_id')
                ->update(['container_id' => $rootId]);
        });
    }

    public function down()
    {
        if (Schema::hasColumn('humlnetcreative_pages_sections', 'container_id')) {
            Schema::table('humlnetcreative_pages_sections', function($table) {
                $table->dropIndex(['container_id']);
                $table->dropColumn('container_id');
            });
        }
        Schema::dropIfExists('humlnetcreative_pages_section_containers');
    }
}
