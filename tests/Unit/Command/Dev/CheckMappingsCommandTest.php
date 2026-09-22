<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\Dev;

use App\Command\Dev\CheckMappingsCommand;
use App\Elasticsearch\Mapping\IndexDefinitionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @see CheckMappingsCommand
 */
final class CheckMappingsCommandTest extends TestCase
{
    /**
     * @param array<mixed> $productMapping
     */
    private function buildCommandTester(array $productMapping): CommandTester
    {
        $productIndexDefinition = self::createStub(IndexDefinitionInterface::class);
        $productIndexDefinition->method('getMapping')->willReturn($productMapping);

        return new CommandTester(new CheckMappingsCommand($productIndexDefinition));
    }

    public function testAnIndexedFieldNoQueryReadsFailsTheCommand(): void
    {
        $commandTester = $this->buildCommandTester([
            'properties' => [
                'unread_field' => ['type' => 'keyword'],
            ],
        ]);

        self::assertSame(Command::FAILURE, $commandTester->execute([]));
        self::assertStringContainsString('unread_field is indexed and no query reads it', $commandTester->getDisplay());
    }

    public function testAnIndexedFieldAQueryNamesPassesTheCommand(): void
    {
        $commandTester = $this->buildCommandTester([
            'properties' => [
                'sku' => ['type' => 'keyword'],
            ],
        ]);

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        self::assertStringContainsString('every indexed field has a reader', $commandTester->getDisplay());
    }
}
