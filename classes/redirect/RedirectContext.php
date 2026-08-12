<?php namespace HumlnetCreative\Pages\Classes\Redirect;

use InvalidArgumentException;

final class RedirectContext
{
    public readonly ?string $host;

    public function __construct(
        public readonly ?int $siteId = null,
        ?string $host = null,
    ) {
        $host = $host === null ? null : strtolower(trim($host));
        if ($host === '') {
            $host = null;
        }
        if ($host !== null && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('Neplatný host kontext redirectu.');
        }
        $this->host = $host;
    }

    public function key(): string
    {
        return sprintf('site=%s;host=%s', $this->siteId ?? '*', $this->host ?? '*');
    }
}
