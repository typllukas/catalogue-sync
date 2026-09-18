<?php

declare(strict_types=1);

namespace App\Tests\Unit\Elasticsearch;

use App\Elasticsearch\IndexNameFactory;
use PHPUnit\Framework\TestCase;

/**
 * @see IndexNameFactory
 */
final class IndexNameFactoryTest extends TestCase
{
    public function testAnEmptySuffixLeavesTheAliasAlone(): void
    {
        self::assertSame('products', new IndexNameFactory('')->build());
    }

    public function testTheSuffixIsAppendedToTheAlias(): void
    {
        self::assertSame('products_test', new IndexNameFactory('_test')->build());
    }
}
