<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Match_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * A `match` over a billing enum must name every case.
 *
 * A `default` arm absorbs whatever it was not told about. Add a case to
 * `InvoiceStatus` or `BillingCadence` and every such match keeps compiling,
 * keeps passing, and quietly routes the new case to whatever the default
 * happened to be - which on these enums is a decision about money.
 *
 * That is the same shape as the null defects this registry-and-audit work keeps
 * finding: SQL answers false for a null rather than unknown, and a `default`
 * answers *something* for an unrecognised case rather than refusing. In both,
 * the failure is silent and the code reads as though it considered the case.
 *
 * The codebase already argues this for statuses - `DisallowStatusNegationRule`
 * refuses `!=` on a status column for the same reason, that an unrecognised
 * value should not be swept into the safe-looking side by omission. This is
 * that rule's typed counterpart.
 *
 * ## Why exhaustiveness is not enough on its own
 *
 * PHPStan already reports an unhandled case in a `match` with no `default`, so
 * removing the default is what turns a new enum case into a build failure. With
 * one present there is nothing to report: the match is total by construction and
 * the analyser is right to say so. The rule has to forbid the arm rather than
 * check the arms.
 *
 * ## Scope
 *
 * Only the billing enums, and only where the subject's type is one of them.
 * A `default` over a string status is untouched - not because it is safe, but
 * because a string has no case list to enumerate, so the rule could not name a
 * mechanical replacement and #123 requires that it can. Those sites want the
 * column typed first; that is #114's work, not this rule's.
 *
 * @implements Rule<Match_>
 */
final class DisallowBillingEnumDefaultArmRule implements Rule
{
    /**
     * The enums whose cases are billing decisions.
     *
     * Named rather than discovered by namespace. A rule that swept
     * `App\Support\Billing\*` would silently take in every enum added there
     * later, including ones where a default is the right answer - and the point
     * of this rule is that absorbing an unnamed case should be a decision
     * somebody made on purpose.
     *
     * @var list<string>
     */
    private const BILLING_ENUMS = [
        'App\Support\Billing\BillingCadence',
        'App\Support\Billing\ChargeCadence',
        'App\Support\Billing\FirstCycleProration',
        'App\Support\Billing\InvoiceKind',
        'App\Support\Billing\InvoiceLineType',
        'App\Support\Billing\InvoiceStatus',
        'App\Support\Billing\SubcontractorBillingMode',
    ];

    public function getNodeType(): string
    {
        return Match_::class;
    }

    /** @return list<RuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $enum = $this->billingEnumOf($scope->getType($node->cond));

        if ($enum === null) {
            return [];
        }

        foreach ($node->arms as $arm) {
            // A default arm is the one with no conditions. Null rather than an
            // empty list, so this is an identity check and not a count.
            if ($arm->conds !== null) {
                continue;
            }

            $short = substr($enum, (int) strrpos($enum, '\\') + 1);

            return [RuleErrorBuilder::message(
                "A default arm on a match over {$short} silently absorbs any case added later; "
                .'name every case explicitly so a new one fails to compile instead.',
            )->identifier('svc.billingEnumDefaultArm')->line($arm->getStartLine())->build()];
        }

        return [];
    }

    /**
     * The billing enum this type is, if it is one.
     *
     * Read through the type rather than the expression, so it holds for a
     * property, a parameter, a return value or a cast alike. A nullable subject
     * still counts: `InvoiceStatus|null` is a case list plus null, and a default
     * arm over it absorbs both the null and every case added later - which is
     * strictly worse than the non-nullable version, not an exemption from it.
     */
    private function billingEnumOf(Type $type): ?string
    {
        // Null stripped first. A nullable subject arrives as `InvoiceKind|null`,
        // and asking a union for its enum cases or object classes answers about
        // the union rather than about the enum inside it - so without this the
        // nullable form, which is the *worse* one, was the one that passed.
        $type = TypeCombinator::removeNull($type);

        foreach ($type->getEnumCases() as $case) {
            $name = $case->getClassName();

            if (in_array($name, self::BILLING_ENUMS, true)) {
                return $name;
            }
        }

        foreach ($type->getObjectClassNames() as $name) {
            if (in_array($name, self::BILLING_ENUMS, true)) {
                return $name;
            }
        }

        return null;
    }
}
