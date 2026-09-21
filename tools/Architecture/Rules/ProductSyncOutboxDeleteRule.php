<?php

declare(strict_types=1);

namespace Architecture\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function preg_match;

/**
 * A plain delete after a successful index loses an update that arrived between the read and the delete.
 *
 * @implements Rule<MethodCall>
 */
final class ProductSyncOutboxDeleteRule implements Rule
{
    private const string OUTBOX_DELETE_PATTERN = '/DELETE\s+FROM\s+product_sync_outbox/i';

    private const string RELEASE_PREDICATE_PATTERN = '/marked_at\s*=\s*\?/i';

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== 'executeStatement') {
            return [];
        }

        $firstArgument = $node->getArgs()[0] ?? null;
        if ($firstArgument === null) {
            return [];
        }

        foreach ($scope->getType($firstArgument->value)->getConstantStrings() as $statement) {
            if (
                preg_match(self::OUTBOX_DELETE_PATTERN, $statement->getValue()) === 1
                && preg_match(self::RELEASE_PREDICATE_PATTERN, $statement->getValue()) !== 1
            ) {
                return [
                    RuleErrorBuilder::message(
                        'A row of product_sync_outbox is released only by DELETE with AND marked_at = ?, '
                            . 'otherwise an update that arrived during the batch is lost.',
                    )->identifier('catalogueSync.outboxRelease')->build(),
                ];
            }
        }

        return [];
    }
}
