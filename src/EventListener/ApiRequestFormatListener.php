<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

use function str_starts_with;

/**
 * Before RouterListener at 32, so a 404 without a route is JSON too.
 */
#[AsEventListener(event: RequestEvent::class, priority: 100)]
final readonly class ApiRequestFormatListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $request->setRequestFormat('json');
    }
}
