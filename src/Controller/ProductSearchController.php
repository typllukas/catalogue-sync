<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ProductSearchInput;
use App\Elasticsearch\ProductSearcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class ProductSearchController
{
    public function __construct(
        private ProductSearcher $productSearcher,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route(
        '/api/products',
        name: 'api_products_search',
        methods: ['GET'],
    )]
    public function __invoke(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ProductSearchInput $input,
    ): JsonResponse {
        return JsonResponse::fromJsonString(
            $this->serializer->serialize($this->productSearcher->search($input), 'json'),
        );
    }
}
