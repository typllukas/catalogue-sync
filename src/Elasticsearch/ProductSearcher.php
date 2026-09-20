<?php

declare(strict_types=1);

namespace App\Elasticsearch;

use App\DTO\ProductSearchInput;
use App\DTO\ProductSearchResult;
use App\Elasticsearch\Client\ResponseBody;
use App\Elasticsearch\DataTransformer\ArrayToProductSearchResult;
use App\Elasticsearch\Exception\ResultWindowExceededException;
use App\Elasticsearch\Exception\SearchUnavailableException;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Transport\Exception\TransportException;

final readonly class ProductSearcher
{
    public function __construct(
        private Client $client,
        private ProductSearchQueryFactory $productSearchQueryFactory,
        private IndexNameFactory $indexNameFactory,
    ) {
    }

    /**
     * @throws SearchUnavailableException|ResultWindowExceededException
     */
    public function search(ProductSearchInput $input): ProductSearchResult
    {
        try {
            $response = ResponseBody::read($this->client->search([
                'index' => $this->indexNameFactory->build(),
                'body' => $this->productSearchQueryFactory->build($input),
            ]));
        } catch (ElasticsearchException | TransportException $exception) {
            // a 404 is the missing alias before the first reindex; any other 4xx is a query bug, not an outage
            if ($exception instanceof ClientResponseException && $exception->getCode() !== 404) {
                throw $exception;
            }

            throw new SearchUnavailableException('Search is unavailable.', previous: $exception);
        }

        return ArrayToProductSearchResult::transform($response);
    }
}
