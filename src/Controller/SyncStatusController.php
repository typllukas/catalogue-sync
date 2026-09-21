<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SyncStatusReader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class SyncStatusController
{
    public function __construct(
        private SyncStatusReader $syncStatusReader,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route(
        '/api/sync/status',
        name: 'api_sync_status',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        return JsonResponse::fromJsonString(
            $this->serializer->serialize($this->syncStatusReader->read(), 'json'),
        );
    }
}
