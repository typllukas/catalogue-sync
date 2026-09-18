<?php

declare(strict_types=1);

namespace App\Elasticsearch\Mapping;

final class CzechAnalysis
{
    public const array SETTINGS = [
        'analysis' => [
            'filter' => [
                'czech_stopwords' => [
                    'type' => 'stop',
                    'stopwords' => '_czech_',
                ],
                'czech_stemmer' => [
                    'type' => 'stemmer',
                    'language' => 'czech',
                ],
            ],
            'analyzer' => [
                'folded_words' => [
                    'tokenizer' => 'standard',
                    'filter' => ['lowercase', 'asciifolding'],
                ],
                'czech_folded_stems' => [
                    'tokenizer' => 'standard',
                    /**
                     * Order matters: lowercase first, the stop list is lowercase; stopwords before the stemmer,
                     * a stemmed stopword no longer matches; asciifolding last, both need the diacritics.
                     */
                    'filter' => ['lowercase', 'czech_stopwords', 'czech_stemmer', 'asciifolding'],
                ],
            ],
        ],
    ];
}
