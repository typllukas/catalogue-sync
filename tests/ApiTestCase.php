<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function json_decode;

abstract class ApiTestCase extends WebTestCase
{
    /**
     * @return array<mixed>
     */
    protected function getResponseBody(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        $body = json_decode($content, true);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * @return array<int, string>
     */
    protected function getViolatedFields(KernelBrowser $client): array
    {
        $violations = $this->getResponseBody($client)['violations'] ?? null;
        self::assertIsArray($violations);

        $fields = [];
        foreach ($violations as $violation) {
            self::assertIsArray($violation);
            self::assertIsString($violation['propertyPath']);
            $fields[] = $violation['propertyPath'];
        }

        return $fields;
    }
}
