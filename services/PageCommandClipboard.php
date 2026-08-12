<?php namespace HumlnetCreative\Pages\Services;

final class PageCommandClipboard
{
    public const SCHEMA_VERSION = 1;

    public function put(array $section): void
    {
        session()->put('humlnetcreative.pages.clipboard', [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => 'section',
            'section' => $section,
        ]);
    }

    public function get(): ?array
    {
        $payload = session()->get('humlnetcreative.pages.clipboard');
        if (!is_array($payload) || ($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION || ($payload['kind'] ?? null) !== 'section') {
            return null;
        }

        return $payload;
    }
}
