<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Classes\Publication\PublicationPreflightResult;
use HumlnetCreative\Pages\Classes\Publication\PublicationUrlChange;
use HumlnetCreative\Pages\Classes\Redirect\RedirectConflict;
use HumlnetCreative\Pages\Classes\Redirect\RedirectContext;
use HumlnetCreative\Pages\Contracts\RedirectManagerInterface;
use HumlnetCreative\Pages\Models\BuilderPage;
use System\Models\SiteDefinition;

/** Read-only publication plan and conflict check. No public state is mutated here. */
final class PagePublicationPreflight
{
    public function __construct(
        private readonly PageUrlPolicy $urlPolicy,
        private readonly RedirectManagerInterface $redirects,
    ) {
    }

    public function inspect(
        BuilderPage $root,
        bool $allowBranch = false,
        bool $replaceManualRedirects = false,
    ): PublicationPreflightResult
    {
        $root = BuilderPage::withoutGlobalScopes()->findOrFail($root->id);
        $pages = collect([$root]);
        $pendingParentIds = [(int) $root->id];

        while ($pendingParentIds) {
            $children = BuilderPage::withoutGlobalScopes()
                ->whereIn('parent_id', $pendingParentIds)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            if ($children->isEmpty()) {
                break;
            }
            $pages = $pages->concat($children);
            $pendingParentIds = $children->pluck('id')->map(fn($id) => (int) $id)->all();
        }

        $changes = [];
        $errors = [];
        $desiredFullslugs = [];
        foreach ($pages as $page) {
            $isRoot = (int) $page->id === (int) $root->id;
            $desiredFullslug = $isRoot
                ? (string) $page->fullslug
                : $this->descendantFullslug($page, $desiredFullslugs);
            $desiredFullslugs[(int) $page->id] = $desiredFullslug;
            if (!$isRoot && !$this->changesPublishedStructure($page, $desiredFullslug)) {
                continue;
            }

            $context = $this->redirectContext($page);
            $oldPath = $page->published_revision_id === null
                ? null
                : $this->publicPath((string) $page->published_fullslug, $page->site_id);
            $newPath = $this->publicPath($desiredFullslug, $page->site_id);
            $deletionMode = $isRoot && $page->deletion_mode ? (string) $page->deletion_mode : null;
            $deletionTargetPath = null;
            if ($deletionMode !== null) {
                [$deletionTargetPath, $deletionErrors] = $this->deletionTarget($page, $deletionMode);
                $errors = array_merge($errors, $deletionErrors);
            }
            $change = new PublicationUrlChange(
                (int) $page->id,
                (string) $page->title,
                $oldPath,
                $newPath,
                $desiredFullslug,
                $context,
                $isRoot,
                $deletionMode,
                $deletionTargetPath,
                $page->published_revision_id !== null
                    && !$page->published_is_published
                    && (bool) $page->is_published
                    && $deletionMode === null,
            );
            $changes[] = $change;

            if (!$change->retiresUrl()) {
                if ($policyConflict = $this->urlPolicy->conflict($desiredFullslug, (bool) $page->is_home)) {
                    $errors[] = $policyConflict;
                }
                if ($collision = $this->publishedCollision($page, $desiredFullslug)) {
                    $errors[] = sprintf(
                        'Cílovou cestu %s stále veřejně používá stránka „%s“ (#%d).',
                        $newPath,
                        $collision->title,
                        $collision->id,
                    );
                }
                if ($targetConflict = $this->redirects->findConflict($newPath, $context)) {
                    if (!$replaceManualRedirects || $targetConflict->type !== RedirectConflict::MANUAL) {
                        $errors[] = $this->targetRedirectConflictMessage($newPath, $targetConflict);
                    }
                }
            }
            if (($change->needsRedirect() || $change->retiresUrl()) && $oldPath !== null
                && ($conflict = $this->redirects->findConflict($oldPath, $context))) {
                if (!$replaceManualRedirects || $conflict->type !== RedirectConflict::MANUAL) {
                    $errors[] = $this->redirectConflictMessage($change, $conflict);
                }
            }
        }

        $descendantChanges = array_filter($changes, fn(PublicationUrlChange $change) => !$change->isRoot);
        if ($descendantChanges && !$allowBranch) {
            $labels = array_map(
                fn(PublicationUrlChange $change) => sprintf('„%s“ (%s → %s)', $change->title, $change->oldPath ?? 'nepublikováno', $change->newPath),
                $descendantChanges,
            );
            $errors[] = 'Změna zasahuje publikované potomky: '.implode(', ', $labels).'. Publikujte celou větev atomicky.';
        }

        return new PublicationPreflightResult($changes, array_values(array_unique($errors)));
    }

    public function assertPublishable(BuilderPage $page): PublicationPreflightResult
    {
        $result = $this->inspect($page);
        $result->assertPasses();

        return $result;
    }

    public function assertBranchPublishable(
        BuilderPage $page,
        bool $replaceManualRedirects = false,
    ): PublicationPreflightResult
    {
        $result = $this->inspect($page, true, $replaceManualRedirects);
        $result->assertPasses();

        return $result;
    }

    private function changesPublishedStructure(BuilderPage $page, string $desiredFullslug): bool
    {
        if (!$page->published_revision_id) {
            return false;
        }

        return (string) $page->published_fullslug !== $desiredFullslug
            || (int) ($page->published_parent_id ?: 0) !== (int) ($page->parent_id ?: 0)
            || (int) $page->published_sort_order !== (int) $page->sort_order;
    }

    private function publishedCollision(BuilderPage $page, string $desiredFullslug): ?BuilderPage
    {
        $query = BuilderPage::withoutGlobalScopes()
            ->where('id', '<>', $page->id)
            ->whereNull('deleted_at')
            ->where('published_fullslug', $desiredFullslug)
            ->whereNotNull('published_revision_id')
            ->where('published_is_published', true);
        is_null($page->site_id) ? $query->whereNull('site_id') : $query->where('site_id', $page->site_id);

        return $query->first();
    }

    private function descendantFullslug(BuilderPage $page, array $desiredFullslugs): string
    {
        if ($page->is_home) {
            return '';
        }

        $parentPath = $desiredFullslugs[(int) $page->parent_id] ?? '';

        return trim($parentPath.'/'.trim((string) $page->slug, '/'), '/');
    }

    /** @return array{?string, array} */
    private function deletionTarget(BuilderPage $page, string $mode): array
    {
        $errors = [];
        if (!$page->published_revision_id) {
            $errors[] = 'Návrh řízeného odstranění lze publikovat jen u již publikované stránky.';
        }
        if ($page->is_home) {
            $errors[] = 'Úvodní stránku nelze odstranit, dokud není zvolena jiná úvodní stránka.';
        }
        if (BuilderPage::withoutGlobalScopes()
            ->where('published_parent_id', $page->id)
            ->whereNotNull('published_revision_id')
            ->where('published_is_published', true)
            ->exists()) {
            $errors[] = 'Stránka má publikované potomky. Nejdřív je přesuňte nebo odstraňte.';
        }
        if ($mode === 'gone') {
            return [null, $errors];
        }

        $targetId = $mode === 'parent' ? $page->parent_id : $page->deletion_target_page_id;
        if (!in_array($mode, ['parent', 'page'], true) || !$targetId) {
            $errors[] = 'Pro přesměrování po odstranění vyberte platnou cílovou stránku.';

            return [null, $errors];
        }
        $target = BuilderPage::withoutGlobalScopes()
            ->where('published_is_published', true)
            ->whereNotNull('published_revision_id')
            ->find($targetId);
        if (!$target || (int) $target->id === (int) $page->id) {
            $errors[] = 'Cíl odstranění musí být jiná publikovaná stránka.';

            return [null, $errors];
        }
        if ((int) ($target->site_id ?: 0) !== (int) ($page->site_id ?: 0)) {
            $errors[] = 'Cíl odstranění musí patřit ke stejnému webu a jazykové mutaci.';

            return [null, $errors];
        }

        return [$this->publicPath((string) $target->published_fullslug, $target->site_id), $errors];
    }

    private function publicPath(string $fullslug, mixed $siteId): string
    {
        $path = trim($fullslug, '/');
        if ($siteId !== null && ($site = SiteDefinition::find($siteId))) {
            $path = $site->attachRoutePrefix($path);
        }

        return '/'.trim($path, '/');
    }

    private function redirectContext(BuilderPage $page): RedirectContext
    {
        $siteId = $page->site_id === null ? null : (int) $page->site_id;
        $site = $siteId === null ? null : SiteDefinition::find($siteId);
        $host = null;
        if ($site?->is_custom_url) {
            $host = parse_url((string) $site->app_url, PHP_URL_HOST) ?: null;
        }

        return new RedirectContext($siteId, $host);
    }

    private function redirectConflictMessage(PublicationUrlChange $change, RedirectConflict $conflict): string
    {
        if ($conflict->type === RedirectConflict::MANUAL) {
            return sprintf(
                'Starou cestu %s už používá ruční redirect #%s. Publikace jej bez výslovného rozhodnutí nepřepíše.',
                $change->oldPath,
                implode(', #', $conflict->redirectIds),
            );
        }

        return sprintf(
            'Redirect pro starou cestu %s je nejednoznačný (pravidla #%s).',
            $change->oldPath,
            implode(', #', $conflict->redirectIds),
        );
    }

    private function targetRedirectConflictMessage(string $newPath, RedirectConflict $conflict): string
    {
        if ($conflict->type === RedirectConflict::MANUAL) {
            return sprintf(
                'Cílovou cestu %s už používá ruční redirect #%s. Publikace jej bez výslovného rozhodnutí nepřepíše.',
                $newPath,
                implode(', #', $conflict->redirectIds),
            );
        }

        return sprintf(
            'Redirect na cílové cestě %s je nejednoznačný (pravidla #%s).',
            $newPath,
            implode(', #', $conflict->redirectIds),
        );
    }
}
