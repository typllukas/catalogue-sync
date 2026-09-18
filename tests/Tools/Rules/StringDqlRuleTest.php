<?php

declare(strict_types=1);

namespace App\Tests\Tools\Rules;

use Architecture\Rules\StringDqlRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @see StringDqlRule
 *
 * @extends RuleTestCase<StringDqlRule>
 */
final class StringDqlRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new StringDqlRule();
    }

    public function testOnlyAQueryWrittenAsADqlStringIsReported(): void
    {
        $this->analyse([__DIR__ . '/../../../tools/Architecture/RuleSamples/StringDqlQueries.php'], [
            [
                'createQuery() takes a DQL string; build the query with createQueryBuilder() instead.',
                14,
            ],
        ]);
    }
}
