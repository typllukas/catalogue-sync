<?php

declare(strict_types=1);

namespace Architecture\RuleSamples;

use App\Entity\Product;

final class ProductWriteCalls
{
    public function writeWithoutMarkingItDirty(Product $product): void
    {
        $product->setPriceInMinorUnits(12900);
    }

    public function readWithoutWriting(Product $product): int
    {
        return $product->getPriceInMinorUnits();
    }
}
