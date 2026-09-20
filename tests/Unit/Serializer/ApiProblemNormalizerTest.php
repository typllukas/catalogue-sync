<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\Elasticsearch\Exception\SearchUnavailableException;
use App\Serializer\ApiProblemNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Serializer\Normalizer\ConstraintViolationListNormalizer;
use Symfony\Component\Serializer\Normalizer\ProblemNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * @see ApiProblemNormalizer
 */
final class ApiProblemNormalizerTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private function normalize(Throwable $throwable): array
    {
        $serializer = new Serializer([
            new ApiProblemNormalizer(new ProblemNormalizer()),
            new ConstraintViolationListNormalizer(),
        ]);

        $body = $serializer->normalize(
            FlattenException::createFromThrowable($throwable),
            'json',
            ['exception' => $throwable],
        );
        self::assertIsArray($body);

        return $body;
    }

    /**
     * @return array<mixed>
     */
    private function normalizeOneViolation(ConstraintViolation $constraintViolation): array
    {
        $body = $this->normalize(new UnprocessableEntityHttpException(
            'glued message',
            new ValidationFailedException(null, new ConstraintViolationList([$constraintViolation])),
        ));

        self::assertIsArray($body['violations']);
        self::assertIsArray($body['violations'][0]);

        return $body['violations'][0];
    }

    public function testAnAppExceptionGetsItsOwnTypeTitleAndDetail(): void
    {
        $body = $this->normalize(HttpException::fromStatusCode(
            503,
            previous: new SearchUnavailableException('Search is unavailable.'),
        ));

        self::assertSame('urn:catalogue-sync:search-unavailable', $body['type']);
        self::assertSame('Search unavailable', $body['title']);
        self::assertSame('Search is temporarily unavailable. Please try again shortly.', $body['detail']);
        self::assertSame(503, $body['status']);
    }

    public function testAnExceptionWithNoProblemOfItsOwnIsTypedAboutBlank(): void
    {
        $body = $this->normalize(HttpException::fromStatusCode(404));

        self::assertSame('about:blank', $body['type']);
        self::assertSame(404, $body['status']);
    }

    public function testAValidationFailureKeepsTheTypeThatDescribesIt(): void
    {
        $body = $this->normalize(new UnprocessableEntityHttpException(
            'glued message',
            new ValidationFailedException(null, new ConstraintViolationList([
                new ConstraintViolation('This value should be between 1 and 100.', null, [], '', 'size', '999'),
            ])),
        ));

        self::assertSame('https://symfony.com/errors/validation', $body['type']);
    }

    public function testAHintWithoutAClassNameLeavesTheTitleAloneAndStillDropsTheHint(): void
    {
        $violation = $this->normalizeOneViolation(
            new ConstraintViolation(
                'This value should be of type int.',
                null,
                ['hint' => 'wording that changed'],
                null,
                'size',
                'x',
            ),
        );

        self::assertSame('This value should be of type int.', $violation['title']);
        self::assertIsArray($violation['parameters']);
        self::assertArrayNotHasKey('hint', $violation['parameters']);
    }
}
