<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ProductUpdateInput;
use App\Entity\Product;
use App\Service\ProductUpdater;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final readonly class ProductUpdateController
{
    public function __construct(
        private ProductUpdater $productUpdater,
    ) {
    }

    #[Route(
        '/api/products/{id}',
        name: 'api_products_update',
        requirements: ['id' => Requirement::ULID],
        methods: ['PATCH'],
    )]
    public function __invoke(
        #[MapEntity(message: 'Product not found.')]
        Product $product,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ProductUpdateInput $input,
    ): Response {
        $this->productUpdater->update($product, $input);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
