<?php namespace HumlnetCreative\Pages\Services;

use HumlnetCreative\Pages\Classes\Snapshot\PageSnapshot;
use JsonException;
use UnexpectedValueException;

/** Rehydrates a validated in-memory DTO; DB restoration belongs to the revision layer. */
final class PageSnapshotHydrator
{
    /** @throws JsonException */
    public function fromJson(string $json): PageSnapshot
    {
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new UnexpectedValueException('Snapshot musí být JSON objekt.');
        }

        return PageSnapshot::fromArray($payload);
    }

    public function fromArray(array $payload): PageSnapshot
    {
        return PageSnapshot::fromArray($payload);
    }
}
