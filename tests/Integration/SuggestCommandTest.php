<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\Replenishment\SuggestCommand;
use App\DataFixtures\ProductFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Ulid;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * @see SuggestCommand
 */
final class SuggestCommandTest extends KernelTestCase
{
    private const string PREVIOUS_SUGGESTIONS =
        "store,sku,average_daily_demand,safety_stock,on_hand,on_order,order_quantity\n"
        . "1,TEST-SKU,1.00,1.00,0,0,2\n";

    private Command $suggestCommand;

    private CommandTester $commandTester;

    private string $outputPath;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->suggestCommand = $application->find('catalogue-sync:replenishment:suggest');
        $this->commandTester = new CommandTester($this->suggestCommand);
        $this->outputPath = sys_get_temp_dir() . '/replenishment-test.csv';
    }

    protected function tearDown(): void
    {
        if (is_file($this->outputPath)) {
            unlink($this->outputPath);
        }

        if (is_dir($this->outputPath)) {
            rmdir($this->outputPath);
        }

        parent::tearDown();
    }

    private function insertSale(string $salesDate, int $quantity): void
    {
        self::getContainer()->get(Connection::class)->insert(
            'daily_store_sales',
            [
                'store_number' => 1,
                'product_id' => Ulid::fromString(ProductFixtures::PRODUCT_MAKITA_DRILL_ULID)->toBinary(),
                'sales_date' => $salesDate,
                'quantity' => $quantity,
            ],
            ['product_id' => ParameterType::BINARY],
        );
    }

    public function testWritesOneLinePerPairBelowItsTarget(): void
    {
        // before the window, so it counts as history and not as demand
        $this->insertSale('2026-09-02', 1);
        $this->insertSale('2026-09-30', 28);

        self::assertSame(Command::SUCCESS, $this->commandTester->execute([
            '--as-of' => '2026-09-30',
            '--output' => $this->outputPath,
        ]));
        self::assertSame(
            "store,sku,average_daily_demand,safety_stock,on_hand,on_order,order_quantity\n"
            . "1,MAK-DHP484Z,1.00,32.67,0,0,47\n",
            file_get_contents($this->outputPath),
        );
        self::assertSame([$this->outputPath], glob($this->outputPath . '*'));
        self::assertStringContainsString('1 suggestions as of 2026-09-30', $this->commandTester->getDisplay());
    }

    public function testAHistoryShorterThanTheWindowFailsAndLeavesThePreviousFileAsItWas(): void
    {
        file_put_contents($this->outputPath, self::PREVIOUS_SUGGESTIONS);
        $this->insertSale('2026-09-04', 1);
        $this->insertSale('2026-09-30', 28);

        self::assertSame(Command::FAILURE, $this->commandTester->execute([
            '--as-of' => '2026-09-30',
            '--output' => $this->outputPath,
        ]));
        self::assertSame(self::PREVIOUS_SUGGESTIONS, file_get_contents($this->outputPath));
        self::assertStringContainsString('does not reach back to 2026-09-03', $this->commandTester->getDisplay());
    }

    public function testNoSalesOnTheAsOfDateFailsAndLeavesThePreviousFileAsItWas(): void
    {
        file_put_contents($this->outputPath, self::PREVIOUS_SUGGESTIONS);
        $this->insertSale('2026-09-29', 28);

        self::assertSame(Command::FAILURE, $this->commandTester->execute([
            '--as-of' => '2026-09-30',
            '--output' => $this->outputPath,
        ]));
        self::assertSame(self::PREVIOUS_SUGGESTIONS, file_get_contents($this->outputPath));
        self::assertSame([$this->outputPath], glob($this->outputPath . '*'));
        self::assertStringContainsString('No sales on 2026-09-30', $this->commandTester->getDisplay());
    }

    public function testAnAsOfDateThatDoesNotExistIsRefused(): void
    {
        self::assertSame(Command::INVALID, $this->commandTester->execute([
            '--as-of' => '2026-02-30',
            '--output' => $this->outputPath,
        ]));
        self::assertFileDoesNotExist($this->outputPath);
    }

    public function testAMissingDirectoryFailsWithoutCreatingIt(): void
    {
        $missingDirectory = sys_get_temp_dir() . '/missing-directory';
        $this->outputPath = $missingDirectory . '/replenishment.csv';
        self::assertDirectoryDoesNotExist($missingDirectory);

        self::assertSame(Command::FAILURE, $this->commandTester->execute(['--output' => $this->outputPath]));
        self::assertDirectoryDoesNotExist($missingDirectory);
        self::assertStringContainsString('is not a writable directory', $this->commandTester->getDisplay());
    }

    public function testARefusedRenameFailsAndRemovesTheTemporaryFile(): void
    {
        $this->outputPath = sys_get_temp_dir() . '/replenishment-test-directory';
        mkdir($this->outputPath);
        $this->insertSale('2026-09-02', 1);
        $this->insertSale('2026-09-30', 28);

        self::assertSame(Command::FAILURE, $this->commandTester->execute([
            '--as-of' => '2026-09-30',
            '--output' => $this->outputPath,
        ]));
        self::assertDirectoryExists($this->outputPath);
        self::assertSame([$this->outputPath], glob($this->outputPath . '*'));
        self::assertStringContainsString('Cannot rename', $this->commandTester->getDisplay());
    }

    public function testTheAsOfDateDefaultsToYesterday(): void
    {
        self::assertSame(
            new DateTimeImmutable('yesterday')->format('Y-m-d'),
            $this->suggestCommand->getDefinition()->getOption('as-of')->getDefault(),
        );
    }

    public function testTheDefaultOutputPathIsInsideTheProject(): void
    {
        self::assertSame(
            self::getContainer()->getParameter('kernel.project_dir') . '/var/replenishment.csv',
            $this->suggestCommand->getDefinition()->getOption('output')->getDefault(),
        );
    }
}
