<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Elasticsearch\Exception\ResultWindowExceededException;
use App\Elasticsearch\Exception\SearchUnavailableException;
use BackedEnum;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ProblemNormalizer;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;

use function array_column;
use function array_map;
use function implode;
use function is_a;
use function is_array;
use function is_string;
use function preg_match;

#[AsDecorator('serializer.normalizer.problem')]
final readonly class ApiProblemNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    /**
     * URNs, not URLs: nothing serves a page per problem type, and these values are the client contract.
     */
    private const array PROBLEM_BY_EXCEPTION = [
        SearchUnavailableException::class => [
            'type' => 'urn:catalogue-sync:search-unavailable',
            'title' => 'Search unavailable',
            'detail' => 'Search is temporarily unavailable. Please try again shortly.',
        ],
        ResultWindowExceededException::class => [
            'type' => 'urn:catalogue-sync:result-window-exceeded',
            'title' => 'Result window exceeded',
            'detail' => 'The result window ends here. Narrow the search to see more.',
        ],
    ];

    public function __construct(
        private ProblemNormalizer $inner,
    ) {
    }

    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->inner->setSerializer($serializer);
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $error = $this->inner->normalize($object, $format, $context);

        // framework.exceptions wraps the app's exception in an HttpException, the original is previous
        $exception = $context['exception'] ?? null;
        $cause = $exception instanceof HttpExceptionInterface ? $exception->getPrevious() : null;
        $problem = $cause instanceof Throwable ? self::PROBLEM_BY_EXCEPTION[$cause::class] ?? null : null;
        if ($problem !== null) {
            $error = [...$error, ...$problem];
        }

        $violations = $error['violations'] ?? null;

        // RFC 7807 uses about:blank for a problem that adds nothing to the status code
        if ($problem === null && $violations === null) {
            $error['type'] = 'about:blank';
        }

        if (is_array($violations)) {
            $error['violations'] = array_map(
                static fn (mixed $violation): mixed => is_array($violation)
                    ? self::rewriteHintedViolation($violation)
                    : $violation,
                $violations,
            );
        }

        return $error;
    }

    /**
     * @param array<mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsNormalization($data, $format, $context);
    }

    /**
     * @return array<string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        return $this->inner->getSupportedTypes($format);
    }

    /**
     * @param array<mixed> $violation
     *
     * @return array<mixed>
     */
    private static function rewriteHintedViolation(array $violation): array
    {
        $parameters = $violation['parameters'] ?? null;
        if (!is_array($parameters) || !is_string($parameters['hint'] ?? null)) {
            return $violation;
        }

        $hint = $parameters['hint'];
        unset($parameters['hint']);
        $violation['parameters'] = $parameters;

        if (preg_match('/\w+(?:\\\\\w+)+/', $hint, $matches) !== 1) {
            return $violation;
        }

        $className = $matches[0];
        if (!is_a($className, BackedEnum::class, true)) {
            return $violation;
        }

        $allowedValues = implode(', ', array_column($className::cases(), 'value'));
        $violation['title'] = 'This value is not allowed. Allowed values: ' . $allowedValues . '.';

        return $violation;
    }
}
