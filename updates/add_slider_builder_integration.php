<?php namespace HumlnetCreative\Pages\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddSliderBuilderIntegration extends Migration
{
    public function up()
    {
        Schema::table('humlnetcreative_pages_sections', function($table) {
            $table->unsignedBigInteger('slider_id')->nullable()->index()->after('type');
        });

        Schema::table('humlnetcreative_pages_media_assets', function($table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('height');
        });

        Schema::create('humlnetcreative_pages_slider_media_contexts', function($table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('slider_id')->index();
            $table->unsignedBigInteger('slide_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['slider_id', 'slide_id', 'site_id'], 'hucr_pages_slider_media_context_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('humlnetcreative_pages_slider_media_contexts');
        Schema::table('humlnetcreative_pages_media_assets', fn($table) => $table->dropColumn('duration_ms'));
        Schema::table('humlnetcreative_pages_sections', fn($table) => $table->dropColumn('slider_id'));
    }
}
