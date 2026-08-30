<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<CallLike> */
final class DisallowStatusNegationRule implements Rule
{
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /** @return list<RuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $file = str_replace('\\', '/', $scope->getFile());
        if (! str_contains($file, '/app/Services/Billing/')
            && ! str_contains($file, '/app/Support/Billing/')) {
            return [];
        }

        if ((! $node instanceof MethodCall && ! $node instanceof StaticCall)
            || ! $node->name instanceof Identifier) {
            return [];
        }

        $method = strtolower($node->name->name);
        $column = $this->argument($node->getArgs(), 0, 'column');

        if (! $this->isConstantString($scope, $column, 'status')) {
            return [];
        }

        $isForbidden = $method === 'wherenotin'
            || ($method === 'where'
                && $this->isConstantString($scope, $this->argument($node->getArgs(), 1, 'operator'), '!='));

        if (! $isForbidden) {
            return [];
        }

        return [RuleErrorBuilder::message(
            "Calling {$node->name->name}() on a status column is forbidden; enumerate allowed statuses explicitly with whereIn() instead.",
        )->identifier('svc.statusNegation')->build()];
    }

    /**
     * @param  array<int, Arg>  $arguments
     */
    private function argument(array $arguments, int $position, string $name): ?Node\Expr
    {
        foreach ($arguments as $index => $argument) {
            if ($argument->name?->toString() === $name || ($argument->name === null && $index === $position)) {
                return $argument->value;
            }
        }

        return null;
    }

    private function isConstantString(Scope $scope, ?Node\Expr $expression, string $expected): bool
    {
        if ($expression === null) {
            return false;
        }

        foreach ($scope->getType($expression)->getConstantStrings() as $constant) {
            if ($constant->getValue() === $expected) {
                return true;
            }
        }

        return false;
    }
}
