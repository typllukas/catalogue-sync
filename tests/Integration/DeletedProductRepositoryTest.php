<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\DeletedProductRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

use function array_map;
use function iterator_to_array;

final class DeletedProductRepositoryTest extends KernelTestCase
{
    private DeletedProductRepository $deletedProductRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->deletedProductRepository = self::getContainer()->get(DeletedProductRepository::class);
    }

    /**
     * @return array<int, string>
     */
    private function findIdsDeletedSince(DateTimeImmutable $since): array
    {
        return array_map(
            static fn (Ulid $id): string => $id->toBase32(),
            iterator_to_array($this->deletedProductRepository->iterateIdsOfProductsDeletedSince($since), false),
        );
    }

    public function testARetriedRunWritesTheSameIdsAgainWithoutFailing(): void
    {
        $productId = new Ulid();
        $deletedAt = new DateTimeImmutable('2026-09-18 10:00:00');

        $this->deletedProductRepository->insertTombstones([$productId], $deletedAt);
        $this->deletedProductRepository->insertTombstones([$productId], $deletedAt->modify('+1 minute'));

        self::assertSame([$productId->toBase32()], $this->findIdsDeletedSince($deletedAt));
    }

    public function testTheCatchUpSeesOnlyTombstonesNewerThanTheReindexStart(): void
    {
        $productDeletedBeforeTheRebuild = new Ulid();
        $productDeletedDuringTheRebuild = new Ulid();
        $rebuildStartedAt = new DateTimeImmutable('2026-09-18 10:00:00');

        $this->deletedProductRepository->insertTombstones(
            [$productDeletedBeforeTheRebuild],
            $rebuildStartedAt->modify('-1 hour'),
        );
        $this->deletedProductRepository->insertTombstones(
            [$productDeletedDuringTheRebuild],
            $rebuildStartedAt->modify('+1 minute'),
        );

        self::assertSame(
            [$productDeletedDuringTheRebuild->toBase32()],
            $this->findIdsDeletedSince($rebuildStartedAt),
        );
    }
}
