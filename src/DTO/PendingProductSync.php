<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\ProductSyncOperation;
use App\Enum\ProductSyncScope;
use App\Helper\MixedToInteger;
use App\Helper\MixedToString;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Ulid;

use function is_string;
use function sprintf;

/**
 * One outbox row as the drain reads it.
 */
final readonly class PendingProductSync
{
    public const string MARKED_AT_FORMAT = 'Y-m-d H:i:s.v';

    /**
     * @param list<ProductSyncScope> $scopes
     */
    public function __construct(
        public Ulid $productId,
        public array $scopes,
        public ProductSyncOperation $operation,
        public DateTimeImmutable $markedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $productId = $row['product_id'] ?? null;
        if (!is_string($productId)) {
            throw new LogicException('An outbox row carries no product id.');
        }

        $markedAt = DateTimeImmutable::createFromFormat(
            self::MARKED_AT_FORMAT,
            MixedToString::transformStrict($row['marked_at'] ?? null),
        );
        if ($markedAt === false) {
            throw new LogicException(sprintf(
                'The outbox row of %s carries a marked_at this code cannot read.',
                Ulid::fromBinary($productId)->toBase32(),
            ));
        }

        $scopeMask = MixedToInteger::transformStrict($row['scope_mask'] ?? null);
        $scopes = [];
        foreach (ProductSyncScope::cases() as $scope) {
            if (($scopeMask & $scope->value) !== 0) {
                $scopes[] = $scope;
            }
        }

        return new self(
            Ulid::fromBinary($productId),
            $scopes,
            ProductSyncOperation::from(MixedToString::transformStrict($row['operation'] ?? null)),
            $markedAt,
        );
    }
}
