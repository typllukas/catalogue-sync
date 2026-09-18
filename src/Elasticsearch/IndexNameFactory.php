<?php

declare(strict_types=1);

namespace App\Elasticsearch;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class IndexNameFactory
{
    private const string ALIAS = 'products';

    public function __construct(
        #[Autowire(param: 'elasticsearch_index_suffix')]
        private string $indexSuffix,
    ) {
    }

    public function build(): string
    {
        return self::ALIAS . $this->indexSuffix;
    }
}
