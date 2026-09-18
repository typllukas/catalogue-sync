<?php

declare(strict_types=1);

namespace App\Elasticsearch\Client;

use App\Helper\MixedToFloat;
use App\Helper\MixedToInteger;
use App\Helper\MixedToString;
use Elastic\Elasticsearch\Response\Elasticsearch;
use LogicException;

use function get_debug_type;
use function is_array;
use function json_encode;
use function sprintf;

use const JSON_UNESCAPED_UNICODE;

final class ResponseBody
{
    private const int VERSION_CONFLICT_STATUS = 409;

    /**
     * @return array<array-key, mixed>
     */
    public static function read(mixed $response): array
    {
        if (!$response instanceof Elasticsearch) {
            throw new LogicException(
                'The Elasticsearch client returned an asynchronous response this code does not expect.',
            );
        }

        return $response->asArray();
    }

    /**
     * Elasticsearch leaves a key out rather than sending null: e.g. a hit that matched outside
     * ProductSearchQueryFactory::HIGHLIGHT_FIELDS carries no 'highlight' key.
     *
     * @param array<array-key, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    public static function readArray(array $body, string $key): array
    {
        return self::narrowToArray($body[$key] ?? []);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function narrowToArray(mixed $value): array
    {
        if (!is_array($value)) {
            throw new LogicException(sprintf('Expected an array in the response, got %s.', get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function readStringStrict(array $body, string $key): string
    {
        try {
            return MixedToString::transformStrict($body[$key] ?? null);
        } catch (LogicException $exception) {
            throw self::describeKey($key, $exception);
        }
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function readIntegerStrict(array $body, string $key): int
    {
        try {
            return MixedToInteger::transformStrict($body[$key] ?? null);
        } catch (LogicException $exception) {
            throw self::describeKey($key, $exception);
        }
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function readFloatStrict(array $body, string $key): float
    {
        try {
            return MixedToFloat::transformStrict($body[$key] ?? null);
        } catch (LogicException $exception) {
            throw self::describeKey($key, $exception);
        }
    }

    private static function describeError(mixed $error): string
    {
        $encodedError = json_encode($error, JSON_UNESCAPED_UNICODE);

        return $encodedError === false ? 'the error could not be read' : $encodedError;
    }

    private static function describeKey(string $key, LogicException $exception): LogicException
    {
        return new LogicException(
            sprintf('The response key %s did not read: %s', $key, $exception->getMessage()),
            previous: $exception,
        );
    }

    /**
     * _bulk hides item errors under items[].<operation>.error and still answers 200.
     *
     * @param array<array-key, mixed> $response
     *
     * @return array<int, string> error descriptions
     */
    public static function findBulkFailures(array $response): array
    {
        if (($response['errors'] ?? false) !== true) {
            return [];
        }

        $failures = [];
        foreach (self::readArray($response, 'items') as $item) {
            foreach (self::narrowToArray($item) as $operationResult) {
                $error = self::narrowToArray($operationResult)['error'] ?? null;
                if ($error === null) {
                    continue;
                }

                $failures[] = self::describeError($error);
            }
        }

        return $failures;
    }

    /**
     * A 409 after retry_on_conflict is a conflict, not a failure: a newer write won and the row
     * stays marked.
     *
     * @param array<array-key, mixed> $response
     */
    public static function countBulkResults(array $response): BulkResultCounts
    {
        $countsByResult = ['created' => 0, 'updated' => 0, 'noop' => 0, 'deleted' => 0, 'not_found' => 0];
        $conflictedIds = [];
        $failures = [];

        foreach (self::readArray($response, 'items') as $item) {
            foreach (self::narrowToArray($item) as $operationResult) {
                $narrowedResult = self::narrowToArray($operationResult);

                if (self::readIntegerStrict($narrowedResult, 'status') === self::VERSION_CONFLICT_STATUS) {
                    $conflictedIds[] = self::readStringStrict($narrowedResult, '_id');
                    continue;
                }

                $error = $narrowedResult['error'] ?? null;
                if ($error !== null) {
                    $failures[self::readStringStrict($narrowedResult, '_id')] = self::describeError($error);
                    continue;
                }

                ++$countsByResult[self::readStringStrict($narrowedResult, 'result')];
            }
        }

        return new BulkResultCounts(
            $countsByResult['created'],
            $countsByResult['updated'],
            $countsByResult['noop'],
            $countsByResult['deleted'],
            $conflictedIds,
            $failures,
        );
    }
}
