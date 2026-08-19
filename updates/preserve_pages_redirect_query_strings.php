<?php namespace HumlnetCreative\Pages\Updates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use October\Rain\Database\Updates\Migration;

final class PreservePagesRedirectQueryStrings extends Migration
{
    public function up(): void
    {
        $redirects = DB::table('vdlp_redirect_redirects')
            ->where('description', 'like', 'humlnetcreative.pages:%')
            ->where('status_code', 301);
        $redirectIds = $redirects->pluck('id')->map(fn($id) => (int) $id)->all();

        $redirects
            ->update([
                'ignore_query_parameters' => true,
                'keep_querystring' => true,
            ]);

        if ($redirectIds) {
            Event::dispatch('vdlp.redirect.changed', ['redirectIds' => $redirectIds]);
        }
    }

    public function down(): void
    {
        DB::table('vdlp_redirect_redirects')
            ->where('description', 'like', 'humlnetcreative.pages:%')
            ->where('status_code', 301)
            ->update(['keep_querystring' => false]);
    }
}
