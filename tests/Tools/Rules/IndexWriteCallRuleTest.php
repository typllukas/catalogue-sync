<?php

declare(strict_types=1);

namespace App\Tests\Tools\Rules;

use Architecture\Rules\IndexWriteCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @see IndexWriteCallRule
 *
 * @extends RuleTestCase<IndexWriteCallRule>
 */
final class IndexWriteCallRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new IndexWriteCallRule();
    }

    public function testAWriteFromAThirdPlaceIsReported(): void
    {
        $this->analyse([__DIR__ . '/../../../tools/Architecture/RuleSamples/IndexWriteCalls.php'], [
            [
                'The index is written by App\Service\ProductSyncDrain, App\Elasticsearch\Reindexer only.',
                18,
            ],
            [
                'The index is written by App\Service\ProductSyncDrain, App\Elasticsearch\Reindexer only.',
                28,
            ],
            [
                'The index is written by App\Service\ProductSyncDrain, App\Elasticsearch\Reindexer only.',
                38,
            ],
        ]);
    }

    public function testTheDrainMayWrite(): void
    {
        $this->analyse([__DIR__ . '/../../../src/Service/ProductSyncDrain.php'], []);
    }
}
