<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A pessimistic lock is taken through `Locks::forUpdate()` and nowhere else.
 *
 * `LockOrderConformanceTest` proves the recorded acquisition sequences are
 * ordered, and it is only worth anything if the recording is complete. One
 * inline `lockForUpdate()` is a lock that is taken, ordered against nothing,
 * and reported by no sequence - and the conformance test would stay green while
 * being wrong, which is worse than not having it. Completeness cannot be a
 * convention here; it has to be checked.
 *
 * The diagnostic names the replacement exactly, because the fix is mechanical:
 * the helper goes where the call was, in the same chain, and `tap` returns the
 * same builder.
 *
 * ## Scope
 *
 * Everything the analysis covers except the helper itself, which is the one
 * place the real call belongs. Scoped by file rather than by resolved class so
 * that a copy of the helper somewhere else does not inherit the exemption by
 * calling itself `Locks`.
 *
 * `sharedLock()` is not flagged. It takes a read lock rather than a write lock,
 * this application does not use it anywhere, and a rule that forbade a call
 * nobody makes would be enforcing a claim it has never tested. If a shared lock
 * is ever wanted, it needs its own ordering question answered first, and that
 * is the point at which to extend this.
 *
 * @implements Rule<CallLike>
 */
final class DisallowRawLockForUpdateRule implements Rule
{
    /** The one file allowed to make the call, by path. */
    private const HELPER = '/app/Support/Concurrency/Locks.php';

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /** @return list<RuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof MethodCall && ! $node instanceof NullsafeMethodCall && ! $node instanceof StaticCall) {
            return [];
        }

        if (! $node->name instanceof Identifier || strtolower($node->name->name) !== 'lockforupdate') {
            return [];
        }

        // The nullsafe operator on a builder chain is written by the analyser
        // itself in places, and a virtual node has no source to fix.
        if ($node instanceof MethodCall && $node->getAttribute('virtualNullsafeMethodCall') === true) {
            return [];
        }

        $file = str_replace('\\', '/', $scope->getFile());

        if (str_ends_with($file, self::HELPER)) {
            return [];
        }

        return [RuleErrorBuilder::message(
            'lockForUpdate() taken outside the lock-order registry; a lock nobody records is ordered against '
            .'nothing. Write ->tap(Locks::forUpdate()) in its place - it goes in the same chain and returns the '
            .'same builder - and add a LockResource case if this table has none.',
        )->identifier('svc.rawLockForUpdate')->build()];
    }
}
