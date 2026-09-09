<?php namespace HumlnetCreative\Pages\Updates;

use Illuminate\Support\Facades\DB;
use October\Rain\Database\Updates\Migration;
use Vdlp\Redirect\Classes\Contracts\PublishManagerInterface;

class NormalizePagesRedirectTargets extends Migration
{
    public function up(): void
    {
        $rules = DB::table('vdlp_redirect_redirects')
            ->where('description', 'like', 'humlnetcreative.pages:%')
            ->where('target_type', 'path_or_url')
            ->whereIn('status_code', [301, 302, 303])
            ->where('to_url', 'like', '/%')
            ->get(['id', 'to_url']);

        foreach ($rules as $rule) {
            $target = ltrim((string) $rule->to_url, '/');
            DB::table('vdlp_redirect_redirects')->where('id', $rule->id)->update([
                'to_url' => $target === '' ? './' : $target,
                'updated_at' => now(),
            ]);
        }

        if ($rules->isNotEmpty()) {
            resolve(PublishManagerInterface::class)->publish();
        }
    }

    public function down(): void
    {
        $rules = DB::table('vdlp_redirect_redirects')
            ->where('description', 'like', 'humlnetcreative.pages:%')
            ->where('target_type', 'path_or_url')
            ->whereIn('status_code', [301, 302, 303])
            ->get(['id', 'to_url']);

        $changed = false;
        foreach ($rules as $rule) {
            $target = trim((string) $rule->to_url);
            if ($target === '' || str_starts_with($target, '/') || parse_url($target, PHP_URL_SCHEME) !== null) {
                continue;
            }

            DB::table('vdlp_redirect_redirects')->where('id', $rule->id)->update([
                'to_url' => $target === './' ? '/' : '/'.$target,
                'updated_at' => now(),
            ]);
            $changed = true;
        }

        if ($changed) {
            resolve(PublishManagerInterface::class)->publish();
        }
    }
}
