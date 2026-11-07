<?php

namespace Tests\Unit\Billing\Autopay;

use App\Support\Billing\Autopay\AutopayConnectionRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;

/** Which Stripe refusals are about the provider's connection and which are about the charge (#641). */
final class AutopayConnectionRefusalTest extends TestCase
{
    private static function refusal(string $class, ?string $code): ApiErrorException
    {
        return $class::factory('Synthetic refusal.', 400, '{}', null, null, $code);
    }

    /** @return iterable<string, array{class-string<ApiErrorException>, string|null, bool}> */
    public static function refusals(): iterable
    {
        yield 'a bad key' => [AuthenticationException::class, null, true];
        yield 'a key without the permission' => [PermissionException::class, 'secret_key_required', true];
        yield 'an expired key' => [InvalidRequestException::class, 'api_key_expired', true];
        yield 'an invalid account' => [InvalidRequestException::class, 'account_invalid', true];
        yield 'a platform account required' => [InvalidRequestException::class, 'platform_account_required', true];
        yield 'a key that is not a secret key' => [InvalidRequestException::class, 'secret_key_required', true];
        yield 'a livemode mismatch' => [InvalidRequestException::class, 'livemode_mismatch', true];
        yield 'an account that only takes test charges' => [InvalidRequestException::class, 'testmode_charges_only', true];
        yield 'a missing customer is about this charge' => [InvalidRequestException::class, 'resource_missing', false];
        yield 'a bad parameter is about this charge' => [InvalidRequestException::class, 'parameter_invalid_integer', false];
        yield 'an invalid request with no code' => [InvalidRequestException::class, null, false];
        yield 'a declined card' => [CardException::class, 'card_declined', false];
        yield 'a card that needs the client' => [CardException::class, 'authentication_required', false];
        yield 'a card error that names an account code' => [CardException::class, 'account_invalid', false];
        yield 'a rate limit' => [RateLimitException::class, null, false];
        yield 'a network failure' => [ApiConnectionException::class, null, false];
    }

    /**
     * @param  class-string<ApiErrorException>  $class
     */
    #[DataProvider('refusals')]
    public function test_only_a_refusal_of_the_key_or_account_is_a_connection_problem(string $class, ?string $code, bool $connection): void
    {
        $this->assertSame($connection, AutopayConnectionRefusal::refuses(self::refusal($class, $code)));
    }
}
