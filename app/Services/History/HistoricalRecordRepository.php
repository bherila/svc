<?php

namespace App\Services\History;

use App\Contracts\WorkspaceOwned;
use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\HistoricalRecord;
use App\Models\HistoricalSource;
use App\Models\Workspace;
use App\Support\Concurrency\Locks;
use App\Support\Fingerprint;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Original source facts are separate from editable native business records. */
final class HistoricalRecordRepository
{
    public function source(Workspace $workspace, string $system, string $identityHash, string $label): HistoricalSource
    {
        Validator::make(['system' => $system, 'hash' => $identityHash, 'label' => $label], [
            'system' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/D'],
            'hash' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'label' => ['required', 'string', 'max:160'],
        ])->validate();

        return HistoricalSource::query()->firstOrCreate([
            'workspace_id' => $workspace->id, 'system' => $system, 'identity_hash' => $identityHash,
        ], ['label' => $label]);
    }

    /**
     * Idempotent for the same original facts; changed facts or a different target
     * require an explicit migration decision rather than a destructive upsert.
     *
     * @param  array<string, mixed>  $snapshot  audited business fields only, never legacy credentials
     */
    public function remember(Workspace $workspace, HistoricalSource $source, string $table, string $key, array $snapshot, (Model&WorkspaceOwned)|null $target = null): HistoricalRecord
    {
        Validator::make(['table' => $table, 'key' => $key], [
            'table' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/D'],
            'key' => ['required', 'string', 'max:191'],
        ])->validate();

        return DB::transaction(function () use ($workspace, $source, $table, $key, $snapshot, $target): HistoricalRecord {
            $lockedSource = HistoricalSource::query()->where('workspace_id', $workspace->id)
                ->whereKey($source->id)->tap(Locks::forUpdate())->firstOrFail();
            $targetType = $target === null ? null : $this->targetType($target);
            $targetId = null;
            if ($target !== null) {
                $actual = $target->newQuery()->where('workspace_id', $workspace->id)->whereKey($target->getKey())->firstOrFail();
                $targetId = $actual->getAttribute('public_id');
            }

            $identity = ['workspace_id' => $workspace->id, 'historical_source_id' => $lockedSource->id, 'source_table' => $table, 'source_key' => $key];
            $fingerprint = Fingerprint::row($snapshot);
            $record = HistoricalRecord::query()->where($identity)->first();
            if ($record !== null) {
                if ($record->fingerprint !== $fingerprint) {
                    throw new DomainException('The original source record changed; review it before importing again.');
                }
                if ($targetId !== null && $record->target_public_id !== null
                    && ($record->target_public_id !== $targetId || $record->target_type !== $targetType)) {
                    throw new DomainException('The source record is already linked to a different destination.');
                }
                if ($targetId !== null && $record->target_public_id === null) {
                    $record->update(['target_type' => $targetType, 'target_public_id' => $targetId]);
                }

                return $record;
            }

            return HistoricalRecord::query()->create($identity + [
                'fingerprint' => $fingerprint, 'snapshot' => $snapshot,
                'target_type' => $targetType, 'target_public_id' => $targetId,
            ]);
        });
    }

    private function targetType(Model&WorkspaceOwned $target): string
    {
        return match ($target::class) {
            ClientCompany::class => 'company', ClientContact::class => 'contact',
            ClientProject::class => 'project', ClientTask::class => 'task',
            ClientInvoice::class => 'invoice', ClientInvoiceLine::class => 'invoice_line',
            ClientInvoicePayment::class => 'payment', ClientTimeEntry::class => 'time',
            ClientExpense::class => 'expense',
            default => throw new DomainException('This record type cannot be linked to source history.'),
        };
    }
}
