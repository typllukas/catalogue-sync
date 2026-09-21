<?php

declare(strict_types=1);

namespace App\Tests\Tools\Rules;

use Architecture\Rules\ProductSyncOutboxDeleteRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @see ProductSyncOutboxDeleteRule
 *
 * @extends RuleTestCase<ProductSyncOutboxDeleteRule>
 */
final class ProductSyncOutboxDeleteRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ProductSyncOutboxDeleteRule();
    }

    public function testADeleteWithoutTheMarkedAtPredicateIsReported(): void
    {
        $this->analyse([__DIR__ . '/../../../tools/Architecture/RuleSamples/ProductSyncOutboxDeletes.php'], [
            [
                'A row of product_sync_outbox is released only by DELETE with AND marked_at = ?, '
                    . 'otherwise an update that arrived during the batch is lost.',
                18,
            ],
            [
                'A row of product_sync_outbox is released only by DELETE with AND marked_at = ?, '
                    . 'otherwise an update that arrived during the batch is lost.',
                26,
            ],
        ]);
    }

    public function testTheOptimisticReleaseInTheRepositoryPasses(): void
    {
        $this->analyse([__DIR__ . '/../../../src/Repository/ProductSyncOutboxRepository.php'], []);
    }
}
