<?php

declare(strict_types=1);

namespace App\Tests\Tools\Rules;

use PHPUnit\Framework\TestCase;

use function basename;
use function file_get_contents;
use function glob;
use function preg_quote;
use function sprintf;

/**
 * A rule test builds the rule itself, so a rule dropped from phpstan.neon enforces nothing.
 */
final class RuleRegistrationTest extends TestCase
{
    private const string RULE_DIRECTORY = __DIR__ . '/../../../tools/Architecture/Rules';
    private const string CONFIGURATION_FILE = __DIR__ . '/../../../phpstan.neon';

    public function testEveryRuleInTheDirectoryIsRegisteredWithPhpstan(): void
    {
        $configuration = file_get_contents(self::CONFIGURATION_FILE);
        self::assertIsString($configuration, 'phpstan.neon could not be read.');

        $ruleFiles = glob(self::RULE_DIRECTORY . '/*.php');
        self::assertIsArray($ruleFiles, 'The rule directory could not be read.');
        self::assertNotSame([], $ruleFiles, 'No rules were found; this test is reading the wrong directory.');

        foreach ($ruleFiles as $ruleFile) {
            $ruleClass = 'Architecture\\Rules\\' . basename($ruleFile, '.php');
            self::assertMatchesRegularExpression(
                sprintf('/- class: %s\s*\n\s*tags: \[phpstan\.rules\.rule\]/', preg_quote($ruleClass, '/')),
                $configuration,
                sprintf('%s exists and is tested, but phpstan.neon never runs it.', $ruleClass),
            );
        }
    }
}
