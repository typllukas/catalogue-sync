<?php

declare(strict_types=1);

namespace App\Elasticsearch\Client;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_TIMEOUT_MS;

final readonly class ElasticsearchClientFactory
{
    public function __construct(
        #[Autowire(env: 'ELASTICSEARCH_DSN')]
        private string $dsn,
        #[Autowire(service: 'monolog.logger.elasticsearch')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The request timeout is sized by a _bulk batch. A refused connection raises CurlException,
     * which the transport neither retries nor logs.
     */
    public function create(): Client
    {
        return ClientBuilder::create()
            ->setHosts([$this->dsn])
            ->setLogger($this->logger)
            ->setHttpClientOptions([
                CURLOPT_CONNECTTIMEOUT_MS => 1000,
                CURLOPT_TIMEOUT_MS => 30000,
            ])
            ->build();
    }
}
