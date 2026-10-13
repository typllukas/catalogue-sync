<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ReplenishmentSuggestion;
use App\DTO\SalesWindowTotals;

use function ceil;
use function intval;
use function max;
use function sqrt;

/**
 * The (R, S) order-up-to model from Silver, Pyke and Thomas, Inventory and Production Management in Supply Chains.
 */
final readonly class PeriodicReviewPolicy
{
    public const int DEMAND_WINDOW_DAYS = 28;

    /** Seven days of lead time plus a weekly review. */
    private const int COVERED_DAYS = 14;

    /** About a 95 % chance of not running out before the next delivery, for normally distributed demand. */
    private const float SERVICE_LEVEL_FACTOR = 1.65;

    /**
     * The totals are divided by the whole window, so days without a sale count as zero.
     */
    public function suggestOrder(SalesWindowTotals $salesWindowTotals): ?ReplenishmentSuggestion
    {
        $averageDailyDemand = $salesWindowTotals->quantitySum / self::DEMAND_WINDOW_DAYS;
        $safetyStock = self::SERVICE_LEVEL_FACTOR * sqrt(self::COVERED_DAYS) * sqrt(max(
            0.0,
            ($salesWindowTotals->quantitySquareSum - $salesWindowTotals->quantitySum * $averageDailyDemand)
            / (self::DEMAND_WINDOW_DAYS - 1),
        ));
        $suggestedOrderQuantity = intval(ceil(
            $averageDailyDemand * self::COVERED_DAYS + $safetyStock
            - $salesWindowTotals->quantityOnHand - $salesWindowTotals->quantityOnOrder,
        ));
        if ($suggestedOrderQuantity <= 0) {
            return null;
        }

        return new ReplenishmentSuggestion(
            $salesWindowTotals->storeNumber,
            $salesWindowTotals->productSku,
            $averageDailyDemand,
            $safetyStock,
            $salesWindowTotals->quantityOnHand,
            $salesWindowTotals->quantityOnOrder,
            $suggestedOrderQuantity,
        );
    }
}
