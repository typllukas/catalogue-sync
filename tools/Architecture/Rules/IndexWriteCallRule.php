<?php

declare(strict_types=1);

namespace Architecture\Rules;

use App\Elasticsearch\Reindexer;
use App\Service\ProductSyncDrain;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Endpoints\Indices;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function implode;
use function in_array;
use function sprintf;
use function str_starts_with;

/**
 * Any class outside INDEX_WRITERS can leave MariaDB and the index disagreeing with nothing failing.
 * Whether a predicate write matches its SQL counterpart is left to the drift check.
 *
 * @implements Rule<MethodCall>
 */
final class IndexWriteCallRule implements Rule
{
    private const array INDEX_WRITE_METHODS = [
        'index',
        'bulk',
        'update',
        'updateByQuery',
        'delete',
        'deleteByQuery',
        'create',
        'reindex',
    ];

    private const array INDEX_ADMINISTRATION_METHODS = [
        'create',
        'delete',
        'deleteAlias',
        'putAlias',
        'putMapping',
        'putSettings',
        'updateAliases',
    ];

    private const array INDEX_WRITERS = [
        ProductSyncDrain::class,
        Reindexer::class,
    ];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $methodName = $node->name->toString();
        $calledType = $scope->getType($node->var);
        $writesDocuments = in_array($methodName, self::INDEX_WRITE_METHODS, true)
            && new ObjectType(Client::class)->isSuperTypeOf($calledType)->yes();
        $administersIndices = in_array($methodName, self::INDEX_ADMINISTRATION_METHODS, true)
            && new ObjectType(Indices::class)->isSuperTypeOf($calledType)->yes();
        if (!$writesDocuments && !$administersIndices) {
            return [];
        }

        $callingClass = $scope->getClassReflection()?->getName() ?? '';
        if (in_array($callingClass, self::INDEX_WRITERS, true) || str_starts_with($callingClass, 'App\\Tests\\')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'The index is written by %s only.',
                implode(', ', self::INDEX_WRITERS),
            ))->identifier('catalogueSync.indexWrite')->build(),
        ];
    }
}
