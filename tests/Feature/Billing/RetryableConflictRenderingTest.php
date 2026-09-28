<?php

namespace Tests\Feature\Billing;

use App\Support\Billing\CreditPoolChanged;
use App\Support\Billing\InterimClaimRefused;
use App\Support\Billing\InterimLedgerChanged;
use DomainException;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * How a billing refusal reaches an API caller.
 *
 * Most are 422: the request cannot succeed until someone changes something.
 * Two are different - the state moved under the request, nothing was written,
 * and the same request made again will be checked against current state. Those
 * are 409, which is what the agent contract already means by "re-read and
 * retry"; a 422 tells an agent the request itself is wrong and not to repeat it.
 */
class RetryableConflictRenderingTest extends TestCase
{
    /** @return iterable<string, array{DomainException, int}> */
    public static function refusals(): iterable
    {
        yield 'credit pool changed' => [new CreditPoolChanged(1, 2), 409];
        yield 'interim ledger changed' => [new InterimLedgerChanged, 409];
        yield 'interim claim refused' => [new InterimClaimRefused('Regenerate this draft.', true), 422];
        yield 'any other billing refusal' => [new DomainException('Only draft invoices can be issued.'), 422];
    }

    #[DataProvider('refusals')]
    public function test_a_retryable_conflict_is_a_409_and_every_other_refusal_a_422(DomainException $refusal, int $status): void
    {
        Route::get('/_synthetic/refusal', fn () => throw $refusal);

        $this->getJson('/_synthetic/refusal')
            ->assertStatus($status)
            ->assertJsonPath('message', $refusal->getMessage());
    }
}
