<?php

declare(strict_types=1);

namespace App\Tests\PhpUnit;

use App\Helper\MixedToString;
use App\Kernel;
use Override;
use PHPUnit\Event\Application\Started;
use PHPUnit\Event\Application\StartedSubscriber;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function filter_var;

use const FILTER_VALIDATE_BOOL;

/**
 * Once per run and before DAMA wraps the first test, so every run starts from the same data even after a
 * run that died halfway.
 */
final class LoadFixturesSubscriber implements StartedSubscriber
{
    #[Override]
    public function notify(Started $event): void
    {
        $kernel = new Kernel(
            MixedToString::transformStrict($_SERVER['APP_ENV']),
            filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
        );
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(
            new ArrayInput([
                'command' => 'doctrine:fixtures:load',
                '--no-interaction' => true,
                // phpunit.dist.xml sets SHELL_VERBOSITY=-1, which would leave the failure message empty
                '--verbose' => true,
            ]),
            $output,
        );

        $kernel->shutdown();
        if ($exitCode !== 0) {
            throw new RuntimeException('Loading the test fixtures failed: ' . $output->fetch());
        }
    }
}
