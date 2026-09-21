<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\SyncStatus;
use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Elasticsearch\IndexNameFactory;
use App\Repository\ProductSyncOutboxRepository;
use App\Repository\ProductSyncRunRepository;
use DateTimeInterface;

final readonly class SyncStatusReader
{
    private const int DRIFT_SAMPLE_SIZE = 1000;

    public function __construct(
        private ProductSyncOutboxRepository $productSyncOutboxRepository,
        private ProductSyncRunRepository $productSyncRunRepository,
        private ProductDriftChecker $productDriftChecker,
        private IndexNameFactory $indexNameFactory,
    ) {
    }

    /**
     * @throws SearchUnavailableException
     */
    public function read(): SyncStatus
    {
        $lastRun = $this->productSyncRunRepository->findLatest();

        return new SyncStatus(
            $this->productSyncOutboxRepository->countPending($this->indexNameFactory->build()),
            $lastRun?->getRanAt()->format(DateTimeInterface::ATOM),
            $lastRun?->getWrittenProductCount() ?? 0,
            $lastRun?->getUnchangedProductCount() ?? 0,
            $lastRun?->getElapsedMilliseconds() ?? 0,
            $this->productDriftChecker->countDriftedInSample(self::DRIFT_SAMPLE_SIZE),
        );
    }
}
