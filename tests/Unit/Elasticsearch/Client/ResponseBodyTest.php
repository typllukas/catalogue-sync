<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch\Client;

use App\Elasticsearch\Client\ResponseBody;
use LogicException;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * @see ResponseBody
 */
final class ResponseBodyTest extends TestCase
{
    /**
     * Elasticsearch answers 'not_found' without an 'error' key, so the batch must report only the
     * item that carries one.
     */
    public function testDeletingSomethingThatIsNotThereIsNotAFailure(): void
    {
        $failures = ResponseBody::findBulkFailures([
            'errors' => true,
            'items' => [
                [
                    'delete' => [
                        '_id' => 'A',
                        'result' => 'not_found',
                    ],
                ],
                [
                    'delete' => [
                        '_id' => 'B',
                        'error' => ['type' => 'index_not_found_exception'],
                    ],
                ],
            ],
        ]);

        self::assertCount(1, $failures);
        self::assertStringContainsString('index_not_found_exception', $failures[0]);
    }

    public function testFailedWritesAreReported(): void
    {
        $failures = ResponseBody::findBulkFailures([
            'errors' => true,
            'items' => [
                [
                    'index' => [
                        '_id' => 'A',
                        'error' => ['type' => 'strict_dynamic_mapping_exception'],
                    ],
                ],
            ],
        ]);

        self::assertCount(1, $failures);
        self::assertStringContainsString('strict_dynamic_mapping_exception', $failures[0]);
    }

    public function testAMissingKeyReadsAsAnEmptyArray(): void
    {
        self::assertSame([], ResponseBody::readArray(['hits' => []], 'aggregations'));
    }

    /**
     * rest_total_hits_as_int turns hits.total into a number, which readArray() must not read as empty.
     */
    public function testAKeyHoldingSomethingOtherThanAnArrayIsAFailure(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Expected an array in the response, got int.');

        ResponseBody::readArray(['total' => 7], 'total');
    }

    /**
     * The noop count is only in the per-item result.
     */
    public function testEachItemResultIsCountedAndAVersionConflictIsNotAFailure(): void
    {
        $counts = ResponseBody::countBulkResults([
            'errors' => true,
            'items' => [
                ['update' => ['_id' => 'A', 'result' => 'noop', 'status' => 200]],
                ['update' => ['_id' => 'B', 'result' => 'updated', 'status' => 200]],
                ['update' => ['_id' => 'C', 'result' => 'created', 'status' => 201]],
                ['delete' => ['_id' => 'D', 'result' => 'deleted', 'status' => 200]],
                [
                    'update' => [
                        '_id' => 'E',
                        'status' => 409,
                        'error' => ['type' => 'version_conflict_engine_exception'],
                    ],
                ],
                ['update' => ['_id' => 'F', 'status' => 400, 'error' => ['type' => 'mapper_parsing_exception']]],
            ],
        ]);

        self::assertSame(1, $counts->noop);
        self::assertSame(1, $counts->updated);
        self::assertSame(1, $counts->created);
        self::assertSame(1, $counts->deleted);
        self::assertSame(['E'], $counts->conflictedIds);
        self::assertSame(['F'], array_keys($counts->failures));
        self::assertStringContainsString('mapper_parsing_exception', $counts->failures['F']);
    }
}
