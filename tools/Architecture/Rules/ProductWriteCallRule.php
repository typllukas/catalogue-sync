<?php

declare(strict_types=1);

namespace Architecture\Rules;

use App\Entity\Product;
use App\Service\ProductUpdater;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function in_array;
use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * A write outside the updater marks nothing dirty, so the index keeps the old document until the
 * next full rebuild.
 *
 * @implements Rule<MethodCall>
 */
final class ProductWriteCallRule implements Rule
{
    private const string READ_METHOD_PATTERN = '/^(get|is|has)[A-Z]/';

    private const array WRITE_PATH_OWNERS = [ProductUpdater::class];

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

        $calledMethod = $node->name->toString();
        if (preg_match(self::READ_METHOD_PATTERN, $calledMethod) === 1) {
            return [];
        }

        if (!new ObjectType(Product::class)->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        $callingClass = $scope->getClassReflection()?->getName() ?? '';
        if (
            in_array($callingClass, self::WRITE_PATH_OWNERS, true)
            || str_starts_with($callingClass, 'App\\Tests\\')
            || str_starts_with($callingClass, 'App\\DataFixtures\\')
        ) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s() changes a product; that belongs in %s, which marks it for the index.',
                $calledMethod,
                ProductUpdater::class,
            ))->identifier('catalogueSync.writePath')->build(),
        ];
    }
}
