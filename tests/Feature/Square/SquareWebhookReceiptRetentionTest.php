<?php

namespace Tests\Feature\Square;

use App\Models\Workspace;
use App\Services\Square\SquareWebhookReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesSquare;
use Tests\TestCase;

final class SquareWebhookReceiptRetentionTest extends TestCase
{
    use FakesSquare;
    use RefreshDatabase;

    public function test_retention_covers_failed_and_completed_events_and_keeps_recent_active_and_foreign_claims(): void
    {
        Date::setTestNow('2030-02-02 12:00:00');
        $workspace = Workspace::query()->create(['name' => 'Synthetic receipt retention', 'slug' => 'synthetic-receipt-retention']);
        $other = Workspace::query()->create(['name' => 'Synthetic receipt other', 'slug' => 'synthetic-receipt-other']);
        $this->connectSquare($workspace);
        $receipts = app(SquareWebhookReceipts::class);
        foreach (['completed', 'failed', 'active', 'boundary', 'recent'] as $id) {
            $claim = $receipts->claim($workspace, 'evt_fixture_'.$id, 'invoice.updated');
            $this->assertIsString($claim);
            if ($id !== 'active') {
                $receipts->finish($workspace, 'evt_fixture_'.$id, $claim, $id === 'completed');
            }
        }
        $this->assertIsString($receipts->claim($other, 'evt_fixture_foreign', 'invoice.updated'));
        DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->whereIn('event_hash', array_map(fn ($id) => hash('sha256', 'evt_fixture_'.$id), ['completed', 'failed', 'active']))->update(['received_at' => '2030-01-01 12:00:00']);
        DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->where('event_hash', hash('sha256', 'evt_fixture_boundary'))->update(['received_at' => '2030-01-03 12:00:00']);
        DB::table('square_webhook_receipts')->where('workspace_id', $other->id)->update(['received_at' => '2030-01-01 12:00:00', 'lease_expires_at' => null]);
        $this->artisan('svc:square:prune-webhook-receipts')->assertSuccessful();
        $this->assertSame(3, DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->count());
        foreach (['active', 'boundary', 'recent'] as $id) {
            $this->assertTrue(DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->where('event_hash', hash('sha256', 'evt_fixture_'.$id))->exists());
        }
        $this->assertSame(1, DB::table('square_webhook_receipts')->where('workspace_id', $other->id)->count());
    }
}
