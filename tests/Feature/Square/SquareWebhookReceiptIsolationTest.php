<?php

namespace Tests\Feature\Square;

use App\Models\Workspace;
use App\Services\Square\SquareWebhookReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SquareWebhookReceiptIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_workspace_cannot_observe_another_workspaces_completed_event(): void
    {
        [$first, $second] = $this->workspaces();
        $receipts = app(SquareWebhookReceipts::class);
        $claim = $receipts->claim($first, 'evt_fixture_shared', 'invoice.updated');
        $this->assertIsString($claim);
        $receipts->finish($first, 'evt_fixture_shared', $claim, true);
        $secondClaim = $receipts->claim($second, 'evt_fixture_shared', 'invoice.updated');
        $this->assertIsString($secondClaim);
        $this->assertNotSame($claim, $secondClaim);
        $this->assertNull(DB::table('square_webhook_receipts')->where('workspace_id', $second->id)->value('completed_at'));
        $this->assertNull($receipts->claim($first, 'evt_fixture_shared', 'invoice.updated'));
    }

    public function test_completion_is_scoped_even_when_event_and_claim_tokens_are_identical(): void
    {
        [$first, $second] = $this->workspaces();
        $receipts = app(SquareWebhookReceipts::class);
        $claim = $receipts->claim($first, 'evt_fixture_shared', 'invoice.updated');
        $this->assertIsString($claim);
        $this->assertIsString($receipts->claim($second, 'evt_fixture_shared', 'invoice.updated'));
        // Deliberately collide opaque claim tokens to prove scope, not randomness.
        DB::table('square_webhook_receipts')->where('workspace_id', $second->id)->update(['claim_token' => $claim]);
        $receipts->finish($second, 'evt_fixture_shared', $claim, true);
        $this->assertNull(DB::table('square_webhook_receipts')->where('workspace_id', $first->id)->value('completed_at'));
        $this->assertNotNull(DB::table('square_webhook_receipts')->where('workspace_id', $second->id)->value('completed_at'));
        $this->assertSame($claim, DB::table('square_webhook_receipts')->where('workspace_id', $first->id)->value('claim_token'));
        $receipts->finish($first, 'evt_fixture_shared', $claim, false);
        $this->assertNotNull(DB::table('square_webhook_receipts')->where('workspace_id', $second->id)->value('completed_at'));
    }

    /** @return array{Workspace, Workspace} */
    private function workspaces(): array
    {
        return [Workspace::query()->create(['name' => 'Synthetic receipt first', 'slug' => 'synthetic-receipt-first']), Workspace::query()->create(['name' => 'Synthetic receipt second', 'slug' => 'synthetic-receipt-second'])];
    }
}
