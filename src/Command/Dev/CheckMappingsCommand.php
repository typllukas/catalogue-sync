<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Elasticsearch\Mapping\IndexDefinitionInterface;
use App\Elasticsearch\Mapping\ProductIndexDefinition;
use App\Elasticsearch\ProductFacetQueryFactory;
use App\Elasticsearch\ProductSearchQueryFactory;
use Generator;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_key_exists;
use function file_get_contents;
use function is_array;
use function is_string;
use function preg_replace;
use function sprintf;
use function str_contains;

#[AsCommand(
    name: 'catalogue-sync:dev:check-mappings',
    description: 'Report indexed fields no query reads.',
)]
final class CheckMappingsCommand extends Command
{
    public function __construct(
        #[Autowire(service: ProductIndexDefinition::class)]
        private readonly IndexDefinitionInterface $productIndexDefinition,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $unreadFieldCount = 0;

        $querySource = $this->readQuerySideSource();
        foreach ($this->iterateIndexedFieldPaths($this->productIndexDefinition->getMapping(), '') as $fieldPath) {
            if (str_contains($querySource, sprintf("'%s'", $fieldPath))) {
                continue;
            }

            $io->writeln(sprintf('%s is indexed and no query reads it', $fieldPath));
            ++$unreadFieldCount;
        }

        if ($unreadFieldCount > 0) {
            return Command::FAILURE;
        }

        $io->writeln('Mappings: every indexed field has a reader');

        return Command::SUCCESS;
    }

    /**
     * The match is a literal string, so a field name passed as some other parameter counts as used too.
     */
    private function readQuerySideSource(): string
    {
        $classNames = [
            ProductSearchQueryFactory::class,
            ProductFacetQueryFactory::class,
        ];

        $source = '';
        foreach ($classNames as $className) {
            $fileName = new ReflectionClass($className)->getFileName();
            if ($fileName === false) {
                continue;
            }

            $source .= file_get_contents($fileName);
        }

        // a boost is appended to the field name in the query, so the literal is not the field path
        return preg_replace('/\^\d+(\.\d+)?\'/', "'", $source) ?? $source;
    }

    /**
     * @param array<mixed> $mapping a mapping, a property or a multi-field entry
     *
     * @return Generator<string>
     */
    private function iterateIndexedFieldPaths(array $mapping, string $prefix): Generator
    {
        foreach (['properties', 'fields'] as $childrenKey) {
            $children = $mapping[$childrenKey] ?? null;
            if (!is_array($children)) {
                continue;
            }

            foreach ($children as $fieldName => $field) {
                if (!is_string($fieldName) || !is_array($field)) {
                    continue;
                }

                $path = $prefix === '' ? $fieldName : $prefix . '.' . $fieldName;
                if (array_key_exists('type', $field) && ($field['index'] ?? true) !== false) {
                    yield $path;
                }

                yield from $this->iterateIndexedFieldPaths($field, $path);
            }
        }
    }
}
