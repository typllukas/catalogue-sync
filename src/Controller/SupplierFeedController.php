<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SupplierFeedGenerator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class SupplierFeedController
{
    private const int FEED_ROWS = 20000;

    public function __construct(
        private SupplierFeedGenerator $supplierFeedGenerator,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route(
        '/api/dev/supplier-feed',
        name: 'api_dev_supplier_feed',
        methods: ['POST'],
    )]
    public function __invoke(): JsonResponse
    {
        $feedResult = $this->supplierFeedGenerator->generate(
            self::FEED_ROWS,
            static function (string $message): void {
            },
        );

        return JsonResponse::fromJsonString($this->serializer->serialize($feedResult, 'json'));
    }
}
