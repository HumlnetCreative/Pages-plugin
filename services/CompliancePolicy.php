<?php namespace HumlnetCreative\Pages\Services;

use Cms\Classes\Theme;

class CompliancePolicy
{
    public static function mode(): string
    {
        $theme = Theme::getActiveTheme();
        $path = $theme ? $theme->getPath().'/config/page-builder-compliance.php' : null;
        $config = $path && is_file($path) ? require $path : [];
        return in_array($config['mode'] ?? 'warn', ['off', 'warn', 'strict'], true) ? $config['mode'] : 'warn';
    }

    public static function shouldBlock(): bool
    {
        return self::mode() === 'strict';
    }

    public static function sectionIssues(string $type, array $content): array
    {
        $issues = [];
        if (in_array($type, ['hero', 'image_text', 'cta'], true)) {
            $issues = array_merge($issues, self::ctaIssues($content));
        }
        if ($type === 'embed' && !empty($content['embed'])) {
            if (preg_match('/<iframe\b/i', $content['embed']) && !preg_match('/<iframe\b[^>]*\btitle\s*=\s*["\'][^"\']+["\']/i', $content['embed'])) {
                $issues[] = 'Vložený iframe musí mít výstižný atribut title.';
            }
        }
        return $issues;
    }

    public static function itemIssues(string $sectionType, array $content): array
    {
        $issues = in_array($sectionType, ['cards', 'carousel'], true) ? self::ctaIssues($content) : [];
        if ($sectionType === 'accordion' && empty(trim((string) ($content['heading'] ?? '')))) {
            $issues[] = 'Položka akordeonu musí mít nadpis.';
        }
        return $issues;
    }

    protected static function ctaIssues(array $content): array
    {
        $hasLabel = !empty(trim((string) ($content['cta_label'] ?? '')));
        $hasUrl = !empty(trim((string) ($content['cta_url'] ?? '')));
        return $hasLabel === $hasUrl ? [] : ['CTA musí mít současně text i cíl.'];
    }
}
