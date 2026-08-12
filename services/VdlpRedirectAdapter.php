<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Classes\Redirect\RedirectConflict;
use HumlnetCreative\Pages\Classes\Redirect\RedirectConflictException;
use HumlnetCreative\Pages\Classes\Redirect\RedirectContext;
use HumlnetCreative\Pages\Classes\Redirect\RedirectWriteResult;
use HumlnetCreative\Pages\Contracts\RedirectManagerInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Vdlp\Redirect\Models\Category;
use Vdlp\Redirect\Models\Redirect;

/** The only Pages service allowed to depend on Vdlp.Redirect persistence. */
final class VdlpRedirectAdapter implements RedirectManagerInterface
{
    private const CATEGORY = 'HUCR Pages — systémová přesměrování';
    private const DESCRIPTION_PREFIX = 'humlnetcreative.pages:';

    public function __construct(private readonly Dispatcher $events)
    {
    }

    public function findConflict(string $source, RedirectContext $context): ?RedirectConflict
    {
        $source = $this->path($source);
        $rules = $this->sourceRules($source)->get();

        if ($rules->isEmpty()) {
            return null;
        }
        if ($rules->count() > 1) {
            return new RedirectConflict(
                RedirectConflict::AMBIGUOUS,
                $source,
                $rules->pluck('id')->map(fn($id) => (int) $id)->all(),
            );
        }

        /** @var Redirect $rule */
        $rule = $rules->first();
        if (!$this->isOwnedBy($rule, $context)) {
            return new RedirectConflict(
                $this->isPagesRule($rule) ? RedirectConflict::AMBIGUOUS : RedirectConflict::MANUAL,
                $source,
                [(int) $rule->id],
                $rule->to_url,
            );
        }

        return null;
    }

    public function putExactPermanent(
        string $source,
        string $target,
        RedirectContext $context,
        bool $replaceManual = false,
    ): RedirectWriteResult {
        $source = $this->path($source);
        $target = $this->path($target);
        if ($source === $target) {
            throw new InvalidArgumentException('Zdroj a cíl redirectu nesmějí být stejné.');
        }

        $conflict = $this->findConflict($source, $context);
        if ($conflict && (!$replaceManual || $conflict->type === RedirectConflict::AMBIGUOUS)) {
            throw new RedirectConflictException($conflict);
        }

        [$result, $changedIds] = DB::transaction(function() use ($source, $target, $context, $conflict): array {
            $rule = $this->sourceRules($source)->first();
            $created = !$rule;
            $rule ??= new Redirect();
            $category = Category::query()->where('name', self::CATEGORY)->first() ?: new Category();
            if (!$category->exists) {
                $category->name = self::CATEGORY;
                $category->save();
            }

            $rule->fill([
                'category_id' => $category->id,
                'match_type' => Redirect::TYPE_EXACT,
                'target_type' => Redirect::TARGET_TYPE_PATH_URL,
                'from_scheme' => Redirect::SCHEME_AUTO,
                'from_url' => $source,
                'to_scheme' => Redirect::SCHEME_AUTO,
                'to_url' => $target,
                'status_code' => 301,
                'sort_order' => $rule->sort_order ?: 0,
                'is_enabled' => true,
                'test_lab' => false,
                'system' => true,
                'description' => $this->description($context),
                'ignore_query_parameters' => true,
                'keep_querystring' => false,
                'ignore_case' => false,
                'ignore_trailing_slash' => true,
            ]);
            $rule->save();

            $changedIds = [(int) $rule->id];
            $flattened = $this->flattenOwnedTargets($source, $target, $context, $changedIds);

            return [new RedirectWriteResult((int) $rule->id, $created, $flattened), $changedIds];
        });

        DB::afterCommit(fn() => $this->events->dispatch('vdlp.redirect.changed', [
            'redirectIds' => array_values(array_unique($changedIds)),
        ]));

        return $result;
    }

    private function flattenOwnedTargets(
        string $previousTarget,
        string $finalTarget,
        RedirectContext $context,
        array &$changedIds,
    ): int {
        $count = 0;
        $pendingTargets = [$previousTarget];
        $seenTargets = [];

        while ($pendingTargets) {
            $target = array_shift($pendingTargets);
            if (isset($seenTargets[$target])) {
                continue;
            }
            $seenTargets[$target] = true;

            $rules = Redirect::query()
                ->where('match_type', Redirect::TYPE_EXACT)
                ->where('target_type', Redirect::TARGET_TYPE_PATH_URL)
                ->where('to_url', $target)
                ->where('description', $this->description($context))
                ->get();

            foreach ($rules as $rule) {
                if ($rule->from_url === $finalTarget) {
                    continue;
                }
                $pendingTargets[] = $rule->from_url;
                $rule->to_url = $finalTarget;
                $rule->system = true;
                $rule->save();
                $changedIds[] = (int) $rule->id;
                $count++;
            }
        }

        return $count;
    }

    private function sourceRules(string $source)
    {
        return Redirect::query()
            ->where('match_type', Redirect::TYPE_EXACT)
            ->where('from_url', $source);
    }

    private function isPagesRule(Redirect $rule): bool
    {
        return str_starts_with((string) $rule->description, self::DESCRIPTION_PREFIX);
    }

    private function isOwnedBy(Redirect $rule, RedirectContext $context): bool
    {
        return $this->isPagesRule($rule) && $rule->description === $this->description($context);
    }

    private function description(RedirectContext $context): string
    {
        return self::DESCRIPTION_PREFIX.$context->key();
    }

    private function path(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('Redirect Pages vyžaduje čistou cestu bez query a fragmentu.');
        }
        if (parse_url($path, PHP_URL_HOST) !== null) {
            throw new InvalidArgumentException('Redirect Pages očekává lokální cestu, nikoliv absolutní URL.');
        }

        $path = '/'.ltrim($path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }
}
