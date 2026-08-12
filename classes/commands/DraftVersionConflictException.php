<?php namespace HumlnetCreative\Pages\Classes\Commands;

final class DraftVersionConflictException extends \ApplicationException
{
    public function __construct(public readonly int $expected, public readonly int $actual)
    {
        parent::__construct("Koncept byl mezitím změněn. Očekávaná verze {$expected}, aktuální verze {$actual}. Obnovte editor.");
    }
}
