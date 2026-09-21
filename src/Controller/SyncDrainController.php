<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ProductSyncDrain;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class SyncDrainController
{
    private const int BATCH_SIZE = 1000;

    public function __construct(
        private ProductSyncDrain $productSyncDrain,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route(
        '/api/sync/drain',
        name: 'api_sync_drain',
        methods: ['POST'],
    )]
    public function __invoke(): JsonResponse
    {
        return JsonResponse::fromJsonString(
            $this->serializer->serialize($this->productSyncDrain->drain(self::BATCH_SIZE), 'json'),
        );
    }
}
