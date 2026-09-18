<?php

declare(strict_types=1);

namespace Architecture\Rules;

use Doctrine\ORM\EntityManagerInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * phpstan-doctrine checks a DQL string only while it is constant, and one concatenated filter turns it
 * off without a warning; a query builder keeps entity and field names checked as the query grows.
 *
 * @implements Rule<MethodCall>
 */
final class StringDqlRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== 'createQuery') {
            return [];
        }

        if (!new ObjectType(EntityManagerInterface::class)->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'createQuery() takes a DQL string; build the query with createQueryBuilder() instead.',
            )->identifier('catalogueSync.stringDql')->build(),
        ];
    }
}
