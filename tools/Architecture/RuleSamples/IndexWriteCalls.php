<?php

declare(strict_types=1);

namespace Architecture\RuleSamples;

use Elastic\Elasticsearch\Client;

final readonly class IndexWriteCalls
{
    public function __construct(
        private Client $client,
    ) {
    }

    public function writeStraightIntoTheIndex(): void
    {
        $this->client->index(['index' => 'products', 'id' => 'a product id', 'body' => []]);
    }

    public function readTheIndex(): mixed
    {
        return $this->client->search(['index' => 'products']);
    }

    public function dropTheIndex(): void
    {
        $this->client->indices()->delete(['index' => 'products']);
    }

    public function refreshTheIndex(): void
    {
        $this->client->indices()->refresh(['index' => 'products']);
    }

    public function createOneDocument(): void
    {
        $this->client->create(['index' => 'products', 'id' => 'a product id', 'body' => []]);
    }
}
