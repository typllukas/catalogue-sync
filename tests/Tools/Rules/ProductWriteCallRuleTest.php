<?php

declare(strict_types=1);

namespace App\Tests\Tools\Rules;

use Architecture\Rules\ProductWriteCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @see ProductWriteCallRule
 *
 * @extends RuleTestCase<ProductWriteCallRule>
 */
final class ProductWriteCallRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ProductWriteCallRule();
    }

    public function testAWriteOutsideTheUpdaterIsReported(): void
    {
        $this->analyse([__DIR__ . '/../../../tools/Architecture/RuleSamples/ProductWriteCalls.php'], [
            [
                'setPriceInMinorUnits() changes a product; that belongs in App\Service\ProductUpdater, '
                    . 'which marks it for the index.',
                13,
            ],
        ]);
    }

    public function testTheUpdaterItselfMayWrite(): void
    {
        $this->analyse([__DIR__ . '/../../../src/Service/ProductUpdater.php'], []);
    }
}
