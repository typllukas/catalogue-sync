<?php

declare(strict_types=1);

namespace App\Elasticsearch\Mapping;

use DateTimeImmutable;
use Generator;
use Symfony\Component\Uid\Ulid;

interface IndexDefinitionInterface
{
    /** @return array<string, mixed> */
    public function getSettings(): array;

    /** @return array<string, mixed> */
    public function getMapping(): array;

    /** @return Generator<string, array<string, mixed>> */
    public function iterateDocuments(): Generator;

    /** @return Generator<Ulid> */
    public function iterateIdsOfDocumentsDeletedSince(DateTimeImmutable $since): Generator;

    /** @return Generator<string, array<string, mixed>> */
    public function iterateDocumentsChangedSince(DateTimeImmutable $since): Generator;
}
