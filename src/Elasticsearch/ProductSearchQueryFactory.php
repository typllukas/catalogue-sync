<?php

declare(strict_types=1);

namespace App\Elasticsearch;

use App\DTO\ProductSearchInput;
use App\Elasticsearch\Exception\ResultWindowExceededException;
use App\Elasticsearch\Mapping\ProductIndexDefinition;
use App\Enum\ProductSort;
use stdClass;

use function array_filter;
use function array_last;
use function array_values;
use function explode;
use function mb_strlen;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function trim;

final readonly class ProductSearchQueryFactory
{
    private const array HIGHLIGHT_FIELDS = ['name', 'description', 'sku', 'ean'];

    public const int EXACT_CODE_BOOST = 10;

    private const array MULTI_MATCH_FIELDS = ['name^3', 'description'];

    private const string NAME_PREFIX_FIELD = 'name.prefix';

    /**
     * A half typed word matches only name.prefix, and without matched_fields its fragment comes back unmarked.
     */
    private const array HIGHLIGHT_OPTIONS = [
        'name' => ['matched_fields' => ['name', self::NAME_PREFIX_FIELD]],
    ];

    /**
     * Below name^3, so a finished word outranks a fragment.
     */
    private const float NAME_PREFIX_BOOST = 1.5;

    private const int MINIMUM_PREFIX_LENGTH = 2;

    private const string SKU_PREFIX_PATTERN = '/^[A-Za-z]{3}-[A-Za-z0-9]*$/';

    private const int SKU_PREFIX_BOOST = 20;

    private const array EXACT_CODE_PATTERNS = [
        'sku' => '/(?<![A-Za-z])[A-Za-z]{3}-[A-Za-z0-9]+/',
        'ean' => '/(?<!\d)\d{13}(?!\d)/',
    ];

    public function __construct(
        private ProductFacetQueryFactory $productFacetQueryFactory,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ResultWindowExceededException
     */
    public function build(ProductSearchInput $input): array
    {
        $firstRowIndex = ($input->page - 1) * $input->size;
        if ($firstRowIndex + $input->size > ProductIndexDefinition::MAX_RESULT_WINDOW) {
            throw new ResultWindowExceededException(sprintf(
                'Paging stops at %d rows.',
                ProductIndexDefinition::MAX_RESULT_WINDOW,
            ));
        }

        $text = $this->normaliseText($input->text);
        $highlightFields = [];
        foreach (self::HIGHLIGHT_FIELDS as $highlightFieldName) {
            $highlightFields[$highlightFieldName] = self::HIGHLIGHT_OPTIONS[$highlightFieldName] ?? new stdClass();
        }

        $body = [
            'from' => $firstRowIndex,
            'size' => $input->size,
            'track_total_hits' => true,
            // a price range sits in the query, so it narrows the facet counts too
            'query' => [
                'bool' => [
                    'should' => $this->buildTextClauses($text),
                    'minimum_should_match' => $text === null ? 0 : 1,
                    'filter' => $this->buildFilterClauses($input),
                ],
            ],
            'sort' => $this->buildSortClauses($input->sort),
            'highlight' => [
                'encoder' => 'html',
                'pre_tags' => ['<mark>'],
                'post_tags' => ['</mark>'],
                'fields' => $highlightFields,
            ],
            'aggs' => $this->productFacetQueryFactory->buildAggregations($input),
        ];

        $selectionClauses = $this->productFacetQueryFactory->buildSelectionClauses($input);
        if ($selectionClauses !== []) {
            $body['post_filter'] = ['bool' => ['filter' => array_values($selectionClauses)]];
        }

        return $body;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTextClauses(?string $text): array
    {
        if ($text === null) {
            return [];
        }

        $clauses = [
            [
                'multi_match' => [
                    'query' => $text,
                    'fields' => self::MULTI_MATCH_FIELDS,
                    'fuzziness' => 'AUTO',
                    'operator' => 'and',
                ],
            ],
        ];

        // match_bool_prefix makes a prefix of the last word only
        $lastWord = array_last(explode(' ', $text));
        if (mb_strlen($lastWord) >= self::MINIMUM_PREFIX_LENGTH) {
            $clauses[] = [
                'match_bool_prefix' => [
                    self::NAME_PREFIX_FIELD => [
                        'query' => $text,
                        'boost' => self::NAME_PREFIX_BOOST,
                        'operator' => 'and',
                    ],
                ],
            ];
        }

        if (preg_match(self::SKU_PREFIX_PATTERN, $text) === 1) {
            $clauses[] = [
                'prefix' => [
                    'sku' => [
                        'value' => $text,
                        'boost' => self::SKU_PREFIX_BOOST,
                        'case_insensitive' => true,
                    ],
                ],
            ];
        }

        return [...$clauses, ...$this->buildExactCodeClauses($text)];
    }

    private function normaliseText(?string $text): ?string
    {
        $trimmed = trim($text ?? '');

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildExactCodeClauses(string $text): array
    {
        $clauses = [];
        foreach (self::EXACT_CODE_PATTERNS as $fieldName => $codePattern) {
            preg_match_all($codePattern, $text, $codeMatches);
            foreach ($codeMatches[0] as $code) {
                $clauses[] = [
                    'term' => [
                        $fieldName => ['value' => $code, 'boost' => self::EXACT_CODE_BOOST, 'case_insensitive' => true],
                    ],
                ];
            }
        }

        return $clauses;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFilterClauses(ProductSearchInput $input): array
    {
        $clauses = [['term' => ['visible' => true]]];

        $priceBounds = array_filter(
            ['gte' => $input->priceFromCrowns, 'lte' => $input->priceToCrowns],
            static fn (?int $bound): bool => $bound !== null,
        );
        if ($priceBounds !== []) {
            $clauses[] = ['range' => ['price' => $priceBounds]];
        }

        return $clauses;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSortClauses(ProductSort $sort): array
    {
        // sku breaks ties, or products with one score swap places between pages
        return match ($sort) {
            ProductSort::RELEVANCE => [['_score' => 'desc'], ['sku' => 'asc']],
            ProductSort::PRICE_ASC => [['price' => 'asc'], ['sku' => 'asc']],
            ProductSort::PRICE_DESC => [['price' => 'desc'], ['sku' => 'asc']],
        };
    }
}
