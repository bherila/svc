<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The refunds of a payment recorded by hand, one immutable row each (#472).
 *
 * `client_invoice_payments.refunded_amount` stays the total every balance is
 * derived from; these rows are what it is the total of. A refund entered in
 * error is never edited or deleted: it is answered by a `reversal` row naming
 * it in `reverses_refund_id`, which is unique, so a refund is reversed at most
 * once. For a hand-recorded payment the refunds less their reversals always
 * equal `refunded_amount`. A processor's payment has no rows here - its
 * refunds are the processor's report of a total.
 *
 * A hand-recorded payment refunded before this table existed is given one
 * `refund` row for its whole refunded amount, so the invariant holds from the
 * start and that amount can be reversed like any other.
 */
return new class extends Migration
{
    public const string BACKFILL_REASON = 'Refunded before refunds were recorded one by one; the total of those refunds.';

    public function up(): void
    {
        Schema::create('client_invoice_payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('cipr_public_id_unique');
            $table->foreignId('workspace_id')->constrained('workspaces', 'id', 'cipr_workspace_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('client_invoice_payment_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount');
            $table->string('reason', 500);
            $table->unsignedBigInteger('reverses_refund_id')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users', 'id', 'cipr_recorded_by_fk')->nullOnDelete();
            // A dateTime, not a timestamp: on MariaDB without explicit
            // timestamp defaults the first required timestamp silently gains
            // ON UPDATE CURRENT_TIMESTAMP. The row is never updated anyway.
            $table->dateTime('created_at');

            $table->unique(['workspace_id', 'id'], 'cipr_ws_id_unique');
            $table->unique('reverses_refund_id', 'cipr_reverses_unique');
            $table->index(['workspace_id', 'client_invoice_payment_id', 'id'], 'cipr_ws_payment_idx');
            $table->foreign(['workspace_id', 'client_invoice_payment_id'], 'cipr_ws_payment_fk')
                ->references(['workspace_id', 'id'])->on('client_invoice_payments')->cascadeOnDelete();
            // CASCADE, not RESTRICT: a refund and its reversal leave together
            // when their payment, invoice or workspace is deleted. Nothing
            // deletes one row on its own - the model refuses it.
            $table->foreign(['workspace_id', 'reverses_refund_id'], 'cipr_ws_reverses_fk')
                ->references(['workspace_id', 'id'])->on('client_invoice_payment_refunds')->cascadeOnDelete();
        });

        // When the money was refunded: the payment's latest refund activity.
        // Not the payment row's updated_at, which any later correction of its
        // method, reference, note or date also moves. A payment with no such
        // activity (refunded by an import or a hand repair) is dated when it
        // was backfilled, which is the one moment this row can vouch for.
        $backfilledAt = now('UTC')->format('Y-m-d H:i:s');
        DB::table('client_invoice_payments')
            ->whereNull('provider')
            ->where('refunded_amount', '>', 0)
            ->orderBy('id')
            ->select(['id', 'public_id', 'workspace_id', 'refunded_amount'])
            ->chunkById(500, function ($payments) use ($backfilledAt): void {
                $refundedAt = [];
                // Activity times are the workspace's wall clock
                // (ClientActivityRecorder writes WorkspaceClock::now() into a
                // timezone-less column); entries are UTC, as every new one is.
                $timezones = DB::table('workspaces')->whereIn('id', $payments->pluck('workspace_id')->unique()->all())->pluck('timezone', 'id');
                foreach ($payments->groupBy('workspace_id') as $workspaceId => $inWorkspace) {
                    $timezone = $timezones[$workspaceId] ?? null;
                    $timezone = is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
                    $latest = DB::table('client_company_activity')
                        ->where('workspace_id', $workspaceId)
                        ->where('subject_type', 'client_invoice_payment')
                        ->where('action', 'invoice.payment_refunded')
                        ->whereIn('subject_public_id', $inWorkspace->pluck('public_id')->all())
                        ->groupBy('subject_public_id')
                        ->selectRaw('subject_public_id, max(created_at) as refunded_at')
                        ->pluck('refunded_at', 'subject_public_id');
                    foreach ($latest as $publicId => $at) {
                        $refundedAt[$workspaceId.'|'.$publicId] = CarbonImmutable::parse((string) $at, $timezone)->utc()->format('Y-m-d H:i:s');
                    }
                }
                DB::table('client_invoice_payment_refunds')->insert($payments->map(static fn (object $payment): array => [
                    'public_id' => (string) Str::uuid(),
                    'workspace_id' => $payment->workspace_id,
                    'client_invoice_payment_id' => $payment->id,
                    'kind' => 'refund',
                    'amount' => $payment->refunded_amount,
                    'reason' => self::BACKFILL_REASON,
                    'reverses_refund_id' => null,
                    'recorded_by_user_id' => null,
                    'created_at' => $refundedAt[$payment->workspace_id.'|'.$payment->public_id] ?? $backfilledAt,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_invoice_payment_refunds');
    }
};
