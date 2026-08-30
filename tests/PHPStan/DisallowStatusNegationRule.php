<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

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

        if ((! $node instanceof MethodCall && ! $node instanceof NullsafeMethodCall && ! $node instanceof StaticCall)
            || ! $node->name instanceof Identifier) {
            return [];
        }
        if ($node instanceof MethodCall && $node->getAttribute('virtualNullsafeMethodCall') === true) {
            return [];
        }

        $method = strtolower($node->name->name);
        $column = $this->argument($node->getArgs(), 0, 'column');

        $isForbidden = match (true) {
            in_array($method, ['wherenotin', 'orwherenotin'], true) => $this->isStatusColumn($scope, $column),
            in_array($method, ['where', 'orwhere'], true) => $this->isDirectNegation($scope, $node->getArgs())
                || $this->containsArrayNegation($scope, $column),
            in_array($method, ['whereraw', 'orwhereraw'], true) => $this->containsRawStatusNegation($scope, $this->argument($node->getArgs(), 0, 'sql')),
            default => false,
        };

        if (! $isForbidden) {
            return [];
        }

        return [RuleErrorBuilder::message(
            "Calling {$node->name->name}() on a status column is forbidden; enumerate allowed statuses explicitly with whereIn() instead.",
        )->identifier('svc.statusNegation')->build()];
    }

    /** @param array<int, Arg> $arguments */
    private function isDirectNegation(Scope $scope, array $arguments): bool
    {
        return $this->isStatusColumn($scope, $this->argument($arguments, 0, 'column'))
            && $this->isNegationOperator($scope, $this->argument($arguments, 1, 'operator'));
    }

    private function containsArrayNegation(Scope $scope, ?Node\Expr $expression): bool
    {
        if (! $expression instanceof Array_) {
            return $expression !== null && $this->typeContainsArrayNegation($scope->getType($expression));
        }

        $values = array_values(array_filter(array_map(
            static fn (?Node\ArrayItem $item): ?Node\Expr => $item?->value,
            $expression->items,
        )));

        if (isset($values[0], $values[1])
            && $this->isStatusColumn($scope, $values[0])
            && $this->isNegationOperator($scope, $values[1])) {
            return true;
        }

        foreach ($values as $value) {
            if ($this->containsArrayNegation($scope, $value)) {
                return true;
            }
        }

        return false;
    }

    private function typeContainsArrayNegation(Type $type): bool
    {
        foreach ($type->getConstantArrays() as $array) {
            $values = array_values($array->getValueTypes());
            if (isset($values[0], $values[1])
                && $this->isStatusColumnType($values[0])
                && $this->isNegationOperatorType($values[1])) {
                return true;
            }

            foreach ($values as $value) {
                if ($this->typeContainsArrayNegation($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function containsRawStatusNegation(Scope $scope, ?Node\Expr $expression): bool
    {
        if ($expression === null) {
            return false;
        }

        foreach ($scope->getType($expression)->getConstantStrings() as $constant) {
            if (preg_match('/(?:^|[^a-z0-9_])(?:`?[a-z_][a-z0-9_]*`?\.)?`?status`?\s*(?:!=|<>)/i', $constant->getValue()) === 1) {
                return true;
            }
        }

        // Runtime-built SQL cannot be classified without executing application
        // code. Constant strings are enforced here; dynamic raw SQL remains a
        // deliberate review concern rather than being guessed at unsafely.
        return false;
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

    private function isStatusColumn(Scope $scope, ?Node\Expr $expression): bool
    {
        if ($expression === null) {
            return false;
        }

        return $this->isStatusColumnType($scope->getType($expression));
    }

    private function isStatusColumnType(Type $type): bool
    {
        foreach ($type->getConstantStrings() as $constant) {
            $column = strtolower($constant->getValue());
            if ($column === 'status' || str_ends_with($column, '.status')) {
                return true;
            }
        }

        return false;
    }

    private function isNegationOperator(Scope $scope, ?Node\Expr $expression): bool
    {
        if ($expression === null) {
            return false;
        }

        return $this->isNegationOperatorType($scope->getType($expression));
    }

    private function isNegationOperatorType(Type $type): bool
    {
        foreach ($type->getConstantStrings() as $constant) {
            if (in_array($constant->getValue(), ['!=', '<>'], true)) {
                return true;
            }
        }

        return false;
    }
}
