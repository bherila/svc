<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * Deciding what Stripe's answers to the publishable-key check mean. Pure and
 * database-free: the adapter does the calling and hands over only the facts.
 *
 * The check: the restricted key creates a SetupIntent, the publishable key
 * reads it back with its client secret, and the restricted key cancels it.
 * A publishable key can read a SetupIntent only in the account that owns it,
 * so Stripe answering "no such setupintent" is the mismatch. Anything that
 * stops the check from reaching an answer - a key without the permission,
 * Stripe unreachable, an unexpected reply - refuses the save as well: a key
 * that could not be checked is never accepted silently.
 *
 * A refusal is `['field' => the form field it belongs to, 'message' => safe to show]`.
 *
 * @phpstan-type Refusal array{field: string, message: string}
 */
final class StripeAccountCheck
{
    public const string DIFFERENT_ACCOUNT = 'This publishable key belongs to a different Stripe account than the restricted key.';

    public const string PERMISSION = 'This restricted key cannot create SetupIntents, which SVC uses to confirm the publishable key belongs to the same account. In Stripe, edit the key, set SetupIntents to Write, and save again.';

    public const string UNREACHABLE = 'SVC could not reach Stripe to check the publishable key against the account. Nothing was saved; try again.';

    public const string UNVERIFIABLE = 'Stripe gave no usable answer when SVC checked the publishable key against the account. Nothing was saved; try again, or leave the publishable key blank.';

    public const string PUBLISHABLE_REJECTED = 'Stripe did not accept this publishable key. Check that it is correct and has not been rolled.';

    /**
     * Why creating the check SetupIntent with the restricted key failed.
     *
     * @param  int|null  $status  Stripe's HTTP status; null when it never answered
     * @param  string|null  $errorType  Stripe's error type, when it gave one
     * @return Refusal
     */
    public static function createRefusal(?int $status, ?string $errorType): array
    {
        if ($status === 403 || $errorType === 'permission_error') {
            return ['field' => 'secret', 'message' => self::PERMISSION];
        }
        if ($status === null) {
            return ['field' => 'publishable_key', 'message' => self::UNREACHABLE];
        }

        return ['field' => 'publishable_key', 'message' => self::UNVERIFIABLE];
    }

    /**
     * What the publishable key's read of the SetupIntent says; null when it
     * proves the key belongs to the account.
     *
     * @param  string  $createdId  the SetupIntent the restricted key created
     * @param  int|null  $status  Stripe's HTTP status; null when it never answered
     * @param  string|null  $errorCode  Stripe's error code, when it gave one
     * @param  string|null  $returnedId  the id of the SetupIntent a success returned
     * @return Refusal|null
     */
    public static function retrieveRefusal(string $createdId, ?int $status, ?string $errorCode, ?string $returnedId): ?array
    {
        if ($status === 404 || $errorCode === 'resource_missing') {
            return ['field' => 'publishable_key', 'message' => self::DIFFERENT_ACCOUNT];
        }
        if ($status === 200) {
            return $createdId !== '' && $returnedId === $createdId
                ? null
                : ['field' => 'publishable_key', 'message' => self::UNVERIFIABLE];
        }
        if ($status === 401) {
            return ['field' => 'publishable_key', 'message' => self::PUBLISHABLE_REJECTED];
        }
        if ($status === null) {
            return ['field' => 'publishable_key', 'message' => self::UNREACHABLE];
        }

        return ['field' => 'publishable_key', 'message' => self::UNVERIFIABLE];
    }
}
