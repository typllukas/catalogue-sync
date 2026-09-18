<?php

declare(strict_types=1);

namespace App\Tests\Tools\Sniffs;

use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\DummyFile;
use PHP_CodeSniffer\Ruleset;
use PHP_CodeSniffer\Runner;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * @see CodingStandard\Sniffs\Formatting\RequireShortChainOnOneLineSniff
 */
final class RequireShortChainOnOneLineSniffTest extends TestCase
{
    private Config $config;
    private Ruleset $ruleset;

    protected function setUp(): void
    {
        // Runner::init() defines the constants PHP_CodeSniffer reads while it builds the ruleset
        $runner = new Runner();
        $runner->config = new Config(['--standard=' . dirname(__DIR__, 3) . '/tools/CodingStandard', '-q']);
        $runner->init();

        $this->config = $runner->config;
        $this->ruleset = $runner->ruleset;
    }

    private function buildFile(string $code): DummyFile
    {
        $file = new DummyFile($code, $this->ruleset, $this->config);
        $file->process();

        return $file;
    }

    private function countErrorsIn(string $code): int
    {
        return $this->buildFile($code)->getErrorCount();
    }

    private function fixCode(string $code): string
    {
        $file = $this->buildFile($code);
        $file->fixer->fixFile();

        return $file->fixer->getContents();
    }

    public function testAChainOnTwoLinesThatFitsIsJoined(): void
    {
        $splitChain = <<<'PHP'
            <?php

            $value = $builder->first()
                ->second();
            PHP;

        $joinedChain = <<<'PHP'
            <?php

            $value = $builder->first()->second();
            PHP;

        self::assertSame(1, $this->countErrorsIn($splitChain));
        self::assertSame($joinedChain, $this->fixCode($splitChain));
    }

    public function testANullsafeChainOnTwoLinesThatFitsIsJoined(): void
    {
        $splitChain = <<<'PHP'
            <?php

            $value = $builder?->first()
                ?->second();
            PHP;

        $joinedChain = <<<'PHP'
            <?php

            $value = $builder?->first()?->second();
            PHP;

        self::assertSame(1, $this->countErrorsIn($splitChain));
        self::assertSame($joinedChain, $this->fixCode($splitChain));
    }

    public function testAChainTooLongForOneLineIsLeftSplit(): void
    {
        $code = <<<'PHP'
            <?php

            $valueReadFromTheQueryBuilder = $builderWithAConsiderablyLongName->firstCallOnTheBuilder('an argument')
                ->secondCallOnTheBuilder();
            PHP;

        self::assertSame(0, $this->countErrorsIn($code));
    }

    /**
     * The line break sits inside the comment token, so joining the two lines is not available.
     */
    public function testAChainAfterALineCommentIsLeftSplit(): void
    {
        $code = <<<'PHP'
            <?php

            $value = $builder->first() // the note
                ->second();
            PHP;

        self::assertSame(0, $this->countErrorsIn($code));
    }

    public function testAChainOnThreeLinesIsLeftSplit(): void
    {
        $code = <<<'PHP'
            <?php

            $value = $builder->first()
                ->second()
                ->third();
            PHP;

        self::assertSame(0, $this->countErrorsIn($code));
    }
}
