<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\ReplenishmentSuggestion;
use App\DTO\SalesWindowTotals;
use App\Service\PeriodicReviewPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @see PeriodicReviewPolicy
 */
final class PeriodicReviewPolicyTest extends TestCase
{
    /**
     * A pair that sold 7 a day on 7 of the 28 days, so skipping the zero days would give mean 7 and no deviation
     */
    private function suggestOrder(int $quantityOnHand, int $quantityOnOrder): ?ReplenishmentSuggestion
    {
        return new PeriodicReviewPolicy()->suggestOrder(
            new SalesWindowTotals(
                storeNumber: 1,
                productSku: 'TEST-SKU',
                quantitySum: 49,
                quantitySquareSum: 343,
                quantityOnHand: $quantityOnHand,
                quantityOnOrder: $quantityOnOrder,
            ),
        );
    }

    public function testDemandCountsDaysWithoutASale(): void
    {
        $suggestion = $this->suggestOrder(5, 0);

        self::assertNotNull($suggestion);
        self::assertSame('TEST-SKU', $suggestion->productSku);
        self::assertEqualsWithDelta(1.75, $suggestion->averageDailyDemand, 0.0001);
        self::assertEqualsWithDelta(19.057, $suggestion->safetyStock, 0.001);
        self::assertSame(39, $suggestion->suggestedOrderQuantity);
    }

    public function testStockOnOrderIsSubtractedFromTheOrder(): void
    {
        self::assertSame(29, $this->suggestOrder(5, 10)?->suggestedOrderQuantity);
    }

    public function testAPairStockedUpToItsTargetGetsNoSuggestionAndOneUnitShortGetsOne(): void
    {
        // target is 43.557
        self::assertNull($this->suggestOrder(44, 0));
        self::assertSame(1, $this->suggestOrder(43, 0)?->suggestedOrderQuantity);
    }
}
