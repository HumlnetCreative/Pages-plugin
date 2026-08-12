<?php namespace HumlnetCreative\Pages\Classes\Redirect;

use RuntimeException;

final class RedirectConflictException extends RuntimeException
{
    public function __construct(public readonly RedirectConflict $conflict)
    {
        parent::__construct(sprintf(
            'Redirect pro %s koliduje s existujícím pravidlem (%s).',
            $conflict->source,
            $conflict->type,
        ));
    }
}
