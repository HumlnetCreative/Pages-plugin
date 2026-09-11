<?php namespace HumlnetCreative\Pages\Services;

/** Canonicalizes the deliberately small inline count-up annotation contract. */
final class CountUpMarkup
{
    private const MARKER_PATTERN = '~<span\b(?=[^>]*\bdata-hucr-count-up(?:\s*=|\s|>))([^>]*)>(.*?)</span\s*>~isu';

    public static function normalizeRichText(mixed $value): string
    {
        $html = (string) ($value ?? '');
        if (!str_contains($html, 'data-hucr-count-up')) {
            return $html;
        }

        return (string) preg_replace_callback(self::MARKER_PATTERN, fn(array $match): string => self::marker($match[1], $match[2]), $html);
    }

    public static function normalizeInline(mixed $value): string
    {
        $source = (string) ($value ?? '');
        $prefix = '__HUCR_COUNT_UP_MARKER_';
        while (str_contains($source, $prefix)) {
            $prefix .= '_';
        }
        $tokens = [];
        $annotated = (string) preg_replace_callback(self::MARKER_PATTERN, function(array $match) use (&$tokens, $prefix): string {
            $token = $prefix.count($tokens).'__';
            $tokens[$token] = self::marker($match[1], $match[2]);
            return $token;
        }, $source);

        $plain = html_entity_decode(strip_tags($annotated), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safe = htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return str_replace(array_keys($tokens), array_values($tokens), $safe);
    }

    public static function count(mixed $value): int
    {
        return count(self::values($value));
    }

    /** @return list<string> */
    public static function values(mixed $value): array
    {
        $values = [];
        preg_replace_callback(self::MARKER_PATTERN, function(array $match) use (&$values): string {
            $text = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (self::parseNumber($text) && strip_tags($match[2]) === $match[2]) {
                $values[] = $text;
            }

            return $match[0];
        }, (string) ($value ?? ''));

        return $values;
    }

    /** @return null|array{value:float, decimals:int, decimal_separator:string} */
    public static function parseNumber(string $text): ?array
    {
        $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $grouped = '[+\-−]?\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+(?:[,.]\d{1,3})?';
        $plain = '[+\-−]?\d+(?:[,.]\d{1,3})?';
        if (!preg_match('~^(?:'.$grouped.'|'.$plain.')$~u', $text)) {
            return null;
        }

        $decimalSeparator = str_contains($text, ',') ? ',' : (str_contains($text, '.') ? '.' : '');
        $decimals = $decimalSeparator === '' ? 0 : strlen(substr(strrchr($text, $decimalSeparator), 1));
        $canonical = str_replace([" ", "\u{00A0}", "\u{202F}", '−', ','], ['', '', '', '-', '.'], $text);
        $value = (float) $canonical;
        if (!is_finite($value) || abs($value) > 1_000_000_000_000) {
            return null;
        }

        return ['value' => $value, 'decimals' => $decimals, 'decimal_separator' => $decimalSeparator];
    }

    private static function marker(string $attributes, string $inner): string
    {
        $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parsed = self::parseNumber($text);
        if (!$parsed || strip_tags($inner) !== $inner) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        $safeAttributes = [];
        foreach (['start', 'end'] as $name) {
            $raw = self::attribute($attributes, 'data-hucr-count-up-'.$name);
            if ($raw === null) {
                continue;
            }
            $explicit = self::parseNumber($raw);
            if (!$explicit) {
                return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            }
            $safeAttributes[$name] = str_replace(',', '.', preg_replace('~[ \x{00A0}\x{202F}]~u', '', str_replace('−', '-', $raw)));
        }
        $rawDecimals = self::attribute($attributes, 'data-hucr-count-up-decimals');
        if ($rawDecimals !== null) {
            if (!ctype_digit($rawDecimals) || (int) $rawDecimals > 3) {
                return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            }
            $safeAttributes['decimals'] = (string) (int) $rawDecimals;
        }

        $output = '<span data-hucr-count-up';
        foreach ($safeAttributes as $name => $value) {
            $output .= ' data-hucr-count-up-'.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').'"';
        }

        return $output.'>'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').'</span>';
    }

    private static function attribute(string $attributes, string $name): ?string
    {
        if (!preg_match('~\b'.preg_quote($name, '~').'\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~iu', $attributes, $match)) {
            return null;
        }

        return html_entity_decode($match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : $match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
