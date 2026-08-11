<?php namespace HumlnetCreative\Pages\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddFaqBuilderIntegration extends Migration
{
    public function up()
    {
        Schema::table('humlnetcreative_pages_sections', function($table) {
            $table->unsignedBigInteger('faq_group_id')->nullable()->index()->after('slider_id');
        });
    }

    public function down()
    {
        Schema::table('humlnetcreative_pages_sections', fn($table) => $table->dropColumn('faq_group_id'));
    }
}
