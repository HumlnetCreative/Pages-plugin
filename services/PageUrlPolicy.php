<?php namespace HumlnetCreative\Pages\Services;

use Cms\Classes\Page as CmsPage;
use Cms\Classes\Router as CmsRouter;
use Cms\Classes\Theme;

/** Evaluates paths owned by the active theme independently from draft-time validation. */
final class PageUrlPolicy
{
    private const INFRASTRUCTURE_ROUTES = ['api', 'backend', 'storage'];

    public function conflict(string $fullslug, bool $isHome = false): ?string
    {
        $fullslug = trim($fullslug, '/');
        if ($isHome || $fullslug === '') {
            return null;
        }

        $reserved = $this->reservedRoutes();
        $rootSegment = explode('/', $fullslug)[0] ?? '';
        if (in_array($fullslug, $reserved, true) || in_array($rootSegment, $reserved, true)) {
            return 'Cesta /'.$fullslug.' je rezervovaná konfigurací aktivního theme.';
        }

        $theme = $this->theme();
        if (!$theme) {
            return null;
        }

        $matched = (new CmsRouter($theme))->findByUrl('/'.$fullslug);
        if ($matched instanceof CmsPage && !in_array($matched->getFileName(), ['page.htm', 'homepage.htm'], true)) {
            return 'Cesta /'.$fullslug.' koliduje s explicitní CMS routou „'.$matched->getFileName().'“.';
        }

        return null;
    }

    public function assertAvailable(string $fullslug, bool $isHome = false): void
    {
        if ($conflict = $this->conflict($fullslug, $isHome)) {
            throw new \ValidationException(['slug' => $conflict]);
        }
    }

    private function reservedRoutes(): array
    {
        $theme = $this->theme();
        $path = $theme ? $theme->getPath().'/config/reserved-routes.php' : null;

        $configured = $path && is_file($path) ? (array) require $path : [];

        return array_values(array_unique(array_merge(self::INFRASTRUCTURE_ROUTES, $configured)));
    }

    private function theme(): ?Theme
    {
        return Theme::getActiveTheme() ?: Theme::getEditTheme();
    }
}
