<?php

namespace App\Services\History;

use App\Models\ClientCompany;
use App\Models\HistoricalEntry;
use App\Models\HistoricalRecord;
use App\Models\HistoricalSource;
use App\Models\Workspace;
use App\Support\Concurrency\Locks;
use App\Support\Fingerprint;
use App\Support\History\HistoryDocument;
use DomainException;
use Illuminate\Support\Facades\DB;

final class HistoricalLibraryRepository
{
    public function publish(Workspace $workspace, HistoricalRecord $record, HistoryDocument $document, ?ClientCompany $company = null, ?HistoricalRecord $parent = null): HistoricalEntry
    {
        return DB::transaction(function () use ($workspace, $record, $document, $company, $parent): HistoricalEntry {
            $actual = HistoricalRecord::query()->where('workspace_id', $workspace->id)->whereKey($record->id)->firstOrFail();
            HistoricalSource::query()->where('workspace_id', $workspace->id)->whereKey($actual->historical_source_id)->tap(Locks::forUpdate())->firstOrFail();
            $companyId = $company === null ? null : ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($company->id)->firstOrFail()->id;
            $parentId = $parent === null ? null : HistoricalRecord::query()->where('workspace_id', $workspace->id)
                ->where('historical_source_id', $actual->historical_source_id)->whereKey($parent->id)->firstOrFail()->id;
            if ($parentId === $actual->id) {
                throw new DomainException('A historical record cannot be its own parent.');
            }
            $attributes = $document->attributes() + ['client_company_id' => $companyId, 'parent_record_id' => $parentId];
            $fingerprint = Fingerprint::row($attributes);
            $entry = HistoricalEntry::query()->where('workspace_id', $workspace->id)->where('historical_record_id', $actual->id)->first();
            if ($entry !== null) {
                if ($entry->document_fingerprint !== $fingerprint) {
                    throw new DomainException('The preserved historical presentation changed; review before importing again.');
                }

                return $entry;
            }

            return HistoricalEntry::query()->create(['workspace_id' => $workspace->id, 'historical_record_id' => $actual->id,
                'document_fingerprint' => $fingerprint] + $attributes);
        });
    }
}
