<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use LogicException;
use Symfony\Component\Uid\Ulid;

use function array_fill;
use function array_keys;
use function array_sum;
use function count;
use function explode;
use function implode;
use function intval;
use function mt_rand;
use function mt_srand;
use function sprintf;
use function str_ends_with;
use function str_split;
use function strtoupper;
use function substr;

/**
 * Written with DBAL, persist() is too slow for a million products. No lifecycle callbacks, cascades
 * or type conversion run, so the timestamps and the binary ids are set here.
 */
final readonly class CatalogueDataGenerator
{
    private const int INSERT_BATCH_SIZE = 1000;

    private const int RANDOM_SEED = 20260918;

    /** Weighted, so a facet over ten brands is not a flat list. */
    private const array BRAND_NAME_WEIGHTS = [
        'Makita' => 90,
        'Bosch' => 85,
        'DeWalt' => 60,
        'Einhell' => 45,
        'Narex' => 40,
        'Fiskars' => 30,
        'Gardena' => 28,
        'Stanley' => 25,
        'Extol' => 20,
        'Hecht' => 18,
    ];

    /** Three levels, so the category facet has something to drill into. */
    private const array CATEGORY_TREE = [
        'naradi' => [
            'elektro' => ['vrtacky', 'brusky', 'pily', 'sroubovaky'],
            'rucni' => ['kladiva', 'kliste', 'pilniky'],
        ],
        'zahrada' => [
            'sekacky' => ['benzinove', 'akumulatorove'],
            'zavlaha' => ['hadice', 'postrikovace'],
        ],
        'dum' => [
            'kuchyne' => ['noze', 'nadobi'],
            'koupelna' => ['baterie', 'doplnky'],
        ],
    ];

    private const array CATEGORY_NAMES = [
        'naradi' => 'Nářadí',
        'elektro' => 'Elektrické nářadí',
        'vrtacky' => 'Vrtačky',
        'brusky' => 'Brusky',
        'pily' => 'Pily',
        'sroubovaky' => 'Šroubováky',
        'rucni' => 'Ruční nářadí',
        'kladiva' => 'Kladiva',
        'kliste' => 'Kleště',
        'pilniky' => 'Pilníky',
        'zahrada' => 'Zahrada',
        'sekacky' => 'Sekačky',
        'benzinove' => 'Benzinové',
        'akumulatorove' => 'Akumulátorové',
        'zavlaha' => 'Závlaha',
        'hadice' => 'Hadice',
        'postrikovace' => 'Postřikovače',
        'dum' => 'Dům',
        'kuchyne' => 'Kuchyně',
        'noze' => 'Nože',
        'nadobi' => 'Nádobí',
        'koupelna' => 'Koupelna',
        'baterie' => 'Baterie',
        'doplnky' => 'Doplňky',
    ];

    private const array GUARANTEED_PRODUCTS = [
        [
            'sku' => 'MAK-DHP484Z',
            'name' => 'Makita DHP484Z příklepový šroubovák',
            'brandName' => 'Makita',
            'leafSlug' => 'sroubovaky',
            'priceInMinorUnits' => 289900,
            'stockQuantity' => 12,
        ],
        [
            'sku' => 'BOS-GSB18V55',
            'name' => 'Bosch GSB 18V-55 vrtačka',
            'brandName' => 'Bosch',
            'leafSlug' => 'vrtacky',
            'priceInMinorUnits' => 349900,
            'stockQuantity' => 0,
        ],
        [
            'sku' => 'NAR-EBU125',
            'name' => 'Narex EBU 125-11 úhlová bruska',
            'brandName' => 'Narex',
            'leafSlug' => 'brusky',
            'priceInMinorUnits' => 219900,
            'stockQuantity' => 43,
        ],
    ];

    private const array NOUN_BY_LEAF_SLUG = [
        'vrtacky' => 'vrtačka',
        'brusky' => 'bruska',
        'pily' => 'pila',
        'sroubovaky' => 'šroubovák',
        'kladiva' => 'kladivo',
        'kliste' => 'kleště',
        'pilniky' => 'pilník',
        'benzinove' => 'benzinová sekačka',
        'akumulatorove' => 'akumulátorová sekačka',
        'hadice' => 'hadice',
        'postrikovace' => 'postřikovač',
        'noze' => 'nůž',
        'nadobi' => 'hrnec',
        'baterie' => 'baterie',
        'doplnky' => 'držák',
    ];

    /** Minor units, weighted so a price facet is not uniform. */
    private const array PRICE_BAND_WEIGHTS = [
        '9900:49900' => 40,
        '49900:199900' => 35,
        '199900:499900' => 20,
        '499900:1999900' => 5,
    ];

    private const int OUT_OF_STOCK_IN = 8;

    private const int HIDDEN_IN = 50;

    private const array BRAND_COLUMNS = ['id', 'name', 'created_at', 'updated_at'];

    private const array CATEGORY_COLUMNS = ['id', 'name', 'slug_path', 'created_at', 'updated_at'];

    private const array PRODUCT_COLUMNS = [
        'id',
        'sku',
        'name',
        'description',
        'ean',
        'price_in_minor_units',
        'stock_quantity',
        'visible',
        'brand_id',
        'category_id',
        'created_at',
        'updated_at',
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param callable(string): void $reportProgress
     */
    public function generate(int $productCount, callable $reportProgress): void
    {
        mt_srand(self::RANDOM_SEED);

        $brandIdsByName = $this->generateBrands();
        $reportProgress(sprintf('brands: %d', count($brandIdsByName)));

        $leafCategoryIdsBySlug = $this->generateCategories();
        $reportProgress(sprintf('categories: %d leaves', count($leafCategoryIdsBySlug)));

        $this->generateProducts($productCount, $brandIdsByName, $leafCategoryIdsBySlug, $reportProgress);
    }

    /**
     * @return array<string, string> brand name => binary ULID
     */
    private function generateBrands(): array
    {
        $now = $this->formatCurrentTimestamp();
        $brandIdsByName = [];
        $brandRows = [];

        foreach (array_keys(self::BRAND_NAME_WEIGHTS) as $brandName) {
            $id = new Ulid()->toBinary();
            $brandIdsByName[$brandName] = $id;
            $brandRows[] = [$id, $brandName, $now, $now];
        }

        $this->insert('brand', self::BRAND_COLUMNS, $brandRows);

        return $brandIdsByName;
    }

    /**
     * @return array<string, string> leaf slug => binary ULID
     */
    private function generateCategories(): array
    {
        $now = $this->formatCurrentTimestamp();
        $categoryRows = [];
        $leafIds = [];

        foreach (self::CATEGORY_TREE as $rootSlug => $children) {
            $rootId = new Ulid()->toBinary();
            $categoryRows[] = [$rootId, $this->readCategoryName($rootSlug), $rootSlug, $now, $now];

            foreach ($children as $childSlug => $leafSlugs) {
                $childPath = $rootSlug . '/' . $childSlug;
                $childId = new Ulid()->toBinary();
                $categoryRows[] = [$childId, $this->readCategoryName($childSlug), $childPath, $now, $now];

                foreach ($leafSlugs as $leafSlug) {
                    $leafId = new Ulid()->toBinary();
                    $leafIds[$leafSlug] = $leafId;
                    $categoryRows[] = [
                        $leafId,
                        $this->readCategoryName($leafSlug),
                        $childPath . '/' . $leafSlug,
                        $now,
                        $now,
                    ];
                }
            }
        }

        $this->insert('category', self::CATEGORY_COLUMNS, $categoryRows);

        return $leafIds;
    }

    /**
     * @param array<string, string> $brandIdsByName
     * @param array<string, string> $leafCategoryIdsBySlug
     * @param callable(string): void $reportProgress
     */
    private function generateProducts(
        int $productCount,
        array $brandIdsByName,
        array $leafCategoryIdsBySlug,
        callable $reportProgress,
    ): void {
        $now = $this->formatCurrentTimestamp();
        $leafSlugs = array_keys($leafCategoryIdsBySlug);
        $productRows = [];
        $writtenCount = 0;

        foreach (self::GUARANTEED_PRODUCTS as $guaranteedIndex => $guaranteedProduct) {
            $productRows[] = [
                new Ulid()->toBinary(),
                $guaranteedProduct['sku'],
                $guaranteedProduct['name'],
                $this->buildDescription(
                    $guaranteedProduct['brandName'],
                    $this->readProductNoun($guaranteedProduct['leafSlug']),
                ),
                $this->buildEan($guaranteedIndex),
                $guaranteedProduct['priceInMinorUnits'],
                $guaranteedProduct['stockQuantity'],
                1,
                $brandIdsByName[$guaranteedProduct['brandName']],
                $leafCategoryIdsBySlug[$guaranteedProduct['leafSlug']],
                $now,
                $now,
            ];
        }

        for ($productIndex = count(self::GUARANTEED_PRODUCTS); $productIndex < $productCount; ++$productIndex) {
            $brandName = $this->pickWeightedKey(self::BRAND_NAME_WEIGHTS);
            $leafSlug = $leafSlugs[$productIndex % count($leafSlugs)];
            $noun = $this->readProductNoun($leafSlug);

            $productRows[] = [
                new Ulid()->toBinary(),
                sprintf('%s-%07d', $this->buildSkuPrefix($brandName), $productIndex),
                sprintf('%s %s %04d', $brandName, $noun, $productIndex % 9999),
                $this->buildDescription($brandName, $noun),
                $this->buildEan($productIndex),
                $this->pickPrice(),
                $productIndex % self::OUT_OF_STOCK_IN === 0 ? 0 : mt_rand(1, 400),
                $productIndex % self::HIDDEN_IN === 0 ? 0 : 1,
                $brandIdsByName[$brandName],
                $leafCategoryIdsBySlug[$leafSlug],
                $now,
                $now,
            ];

            if (count($productRows) < self::INSERT_BATCH_SIZE) {
                continue;
            }

            $this->insert('product', self::PRODUCT_COLUMNS, $productRows);
            $writtenCount += count($productRows);
            $productRows = [];
            $reportProgress(sprintf('products: %d', $writtenCount));
        }

        $this->insert('product', self::PRODUCT_COLUMNS, $productRows);
        $reportProgress(sprintf('products: %d', $writtenCount + count($productRows)));
    }

    private function readCategoryName(string $slug): string
    {
        return self::CATEGORY_NAMES[$slug]
            ?? throw new LogicException(sprintf('The category tree holds %s with no name.', $slug));
    }

    private function readProductNoun(string $leafSlug): string
    {
        return self::NOUN_BY_LEAF_SLUG[$leafSlug]
            ?? throw new LogicException(sprintf('The category tree holds a leaf %s with no noun.', $leafSlug));
    }

    private function buildDescription(string $brandName, string $noun): string
    {
        return sprintf('%s %s pro každodenní práci.', $brandName, $noun);
    }

    private function buildSkuPrefix(string $brandName): string
    {
        return strtoupper(substr($brandName, 0, 3));
    }

    private function buildEan(int $productIndex): string
    {
        $eanWithoutCheckDigit = sprintf('859%09d', $productIndex);
        $weightedDigitSum = 0;
        foreach (str_split($eanWithoutCheckDigit) as $digitPosition => $digit) {
            $weightedDigitSum += intval($digit) * ($digitPosition % 2 === 0 ? 1 : 3);
        }

        return $eanWithoutCheckDigit . (10 - $weightedDigitSum % 10) % 10;
    }

    private function pickPrice(): int
    {
        [$lowest, $highest] = explode(':', $this->pickWeightedKey(self::PRICE_BAND_WEIGHTS));

        return mt_rand(intval($lowest), intval($highest));
    }

    /**
     * @param array<string, int> $weights
     */
    private function pickWeightedKey(array $weights): string
    {
        $remainingWeight = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $remainingWeight -= $weight;
            if ($remainingWeight <= 0) {
                return $key;
            }
        }

        throw new LogicException('Weights must sum to a positive number.');
    }

    private function formatCurrentTimestamp(): string
    {
        return new DateTimeImmutable()->format('Y-m-d H:i:s');
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<int, string|int|null>> $rows
     */
    private function insert(string $table, array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($rows), $rowPlaceholder)),
        );

        $parameters = [];
        $types = [];
        foreach ($rows as $row) {
            foreach ($row as $columnIndex => $value) {
                $parameters[] = $value;
                $types[] = $columns[$columnIndex] === 'id' || str_ends_with($columns[$columnIndex], '_id')
                    ? ParameterType::BINARY
                    : ParameterType::STRING;
            }
        }

        $this->connection->executeStatement($sql, $parameters, $types);
    }
}
