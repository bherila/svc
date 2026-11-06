<?php

namespace Tests\Unit\Payments;

use App\Support\Payments\StripeAccountCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StripeAccountCheck::class)]
final class StripeAccountCheckTest extends TestCase
{
    private const string MISMATCH = 'This publishable key belongs to a different Stripe account than the restricted key.';

    public function test_reading_back_the_setup_intent_proves_the_account(): void
    {
        $this->assertNull(StripeAccountCheck::retrieveRefusal('seti_synthetic1', 200, null, 'seti_synthetic1'));
    }

    /** @return array<string, array{0: int|null, 1: string|null}> */
    public static function notFound(): array
    {
        return [
            'a 404' => [404, null],
            'a 404 with the code' => [404, 'resource_missing'],
            'the code on any other status' => [400, 'resource_missing'],
            'the code on a 200' => [200, 'resource_missing'],
        ];
    }

    #[DataProvider('notFound')]
    public function test_not_finding_it_is_a_different_account_on_the_publishable_key_field(?int $status, ?string $code): void
    {
        $this->assertSame(['field' => 'publishable_key', 'message' => self::MISMATCH], StripeAccountCheck::retrieveRefusal('seti_synthetic1', $status, $code, null));
    }

    /** @return array<string, array{0: int|null, 1: string|null, 2: string|null, 3: string}> */
    public static function unverifiable(): array
    {
        return [
            'success naming another intent' => [200, null, 'seti_other', StripeAccountCheck::UNVERIFIABLE],
            'success naming none' => [200, null, null, StripeAccountCheck::UNVERIFIABLE],
            'a refused publishable key' => [401, null, null, StripeAccountCheck::PUBLISHABLE_REJECTED],
            'no answer at all' => [null, null, null, StripeAccountCheck::UNREACHABLE],
            'a forbidden' => [403, null, null, StripeAccountCheck::UNVERIFIABLE],
            'a rate limit' => [429, 'rate_limit', null, StripeAccountCheck::UNVERIFIABLE],
            'a server error' => [500, null, null, StripeAccountCheck::UNVERIFIABLE],
            'a bad gateway' => [502, null, null, StripeAccountCheck::UNVERIFIABLE],
        ];
    }

    #[DataProvider('unverifiable')]
    public function test_anything_short_of_an_answer_refuses_the_save(?int $status, ?string $code, ?string $returned, string $message): void
    {
        $this->assertSame(['field' => 'publishable_key', 'message' => $message], StripeAccountCheck::retrieveRefusal('seti_synthetic1', $status, $code, $returned));
    }

    public function test_an_empty_created_id_is_never_a_match_even_when_the_reply_is_empty_too(): void
    {
        $this->assertSame(StripeAccountCheck::UNVERIFIABLE, StripeAccountCheck::retrieveRefusal('', 200, null, '')['message'] ?? null);
    }

    public function test_a_restricted_key_without_the_permission_is_told_what_to_change_on_the_secret_field(): void
    {
        $expected = ['field' => 'secret', 'message' => StripeAccountCheck::PERMISSION];

        $this->assertSame($expected, StripeAccountCheck::createRefusal(403, null));
        $this->assertSame($expected, StripeAccountCheck::createRefusal(400, 'permission_error'));
        $this->assertSame($expected, StripeAccountCheck::createRefusal(null, 'permission_error'));
        $this->assertStringContainsString('SetupIntents to Write', StripeAccountCheck::PERMISSION);
    }

    public function test_no_answer_on_create_is_unreachable_and_any_other_failure_is_unverifiable(): void
    {
        $this->assertSame(['field' => 'publishable_key', 'message' => StripeAccountCheck::UNREACHABLE], StripeAccountCheck::createRefusal(null, null));
        foreach ([[400, 'invalid_request_error'], [401, 'authentication_error'], [429, 'rate_limit_error'], [500, 'api_error'], [200, null]] as [$status, $type]) {
            $this->assertSame(['field' => 'publishable_key', 'message' => StripeAccountCheck::UNVERIFIABLE], StripeAccountCheck::createRefusal($status, $type), "{$status}");
        }
    }
}
