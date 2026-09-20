<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Any URL under /api/ that matches no route.
 */
final class UnknownUrlApiTest extends ApiTestCase
{
    public function testAnUnknownUrlUnderApiAnswersJsonNotHtml(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/nothing/here');

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        self::assertSame(404, $this->getResponseBody($client)['status']);
    }
}
