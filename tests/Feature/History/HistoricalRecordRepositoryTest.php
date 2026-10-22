<?php

namespace Tests\Feature\History;

use App\Models\ClientCompany;
use App\Models\HistoricalRecord;
use App\Models\Workspace;
use App\Services\History\HistoricalRecordRepository;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class HistoricalRecordRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reimport_is_idempotent_preserves_decimal_strings_and_allows_company_merges(): void
    {
        $workspace = $this->workspace('first');
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Merged synthetic company', 'slug' => 'merged']);
        $repo = app(HistoricalRecordRepository::class);
        $source = $repo->source($workspace, 'synthetic', hash('sha256', 'synthetic-source'), 'Synthetic source');
        $again = $repo->source($workspace, 'synthetic', hash('sha256', 'synthetic-source'), 'Ignored replacement label');
        $this->assertSame($source->id, $again->id);
        $snapshot = ['name' => 'Original synthetic name', 'discount' => '0.00400'];
        $record = $repo->remember($workspace, $source, 'companies', '11', $snapshot, $company);
        $replay = $repo->remember($workspace, $source, 'companies', '11', array_reverse($snapshot, true), $company);
        $other = $repo->remember($workspace, $source, 'companies', '22', ['name' => 'Second original name'], $company);
        $this->assertSame($record->id, $replay->id);
        $this->assertSame($record->target_public_id, $other->target_public_id);
        $this->assertSame('0.00400', $record->snapshot['discount']);
        $company->update(['name' => 'Current canonical name']);
        $this->assertSame('Original synthetic name', $record->fresh()->snapshot['name']);
        $this->assertArrayNotHasKey('snapshot', $record->toArray());
        $this->assertDatabaseCount('historical_records', 2);
    }

    public function test_changed_source_record_refuses_to_overwrite_the_original(): void
    {
        $workspace = $this->workspace('first');
        $repo = app(HistoricalRecordRepository::class);
        $source = $repo->source($workspace, 'synthetic', hash('sha256', 'source'), 'Source');
        $repo->remember($workspace, $source, 'invoices', '31', ['number' => 'SYN-OLD']);
        try {
            $repo->remember($workspace, $source, 'invoices', '31', ['number' => 'SYN-CHANGED']);
            $this->fail('Changed original must be refused.');
        } catch (DomainException) {
            $this->assertSame('SYN-OLD', HistoricalRecord::query()->where('workspace_id', $workspace->id)->firstOrFail()->snapshot['number']);
        }
    }

    public function test_source_namespace_and_target_resolution_are_workspace_scoped(): void
    {
        $workspace = $this->workspace('first');
        $other = $this->workspace('second');
        $repo = app(HistoricalRecordRepository::class);
        $source = $repo->source($workspace, 'synthetic', hash('sha256', 'source'), 'Source');
        $foreignSource = $repo->source($other, 'synthetic', hash('sha256', 'source'), 'Source');
        $this->assertNotSame($source->id, $foreignSource->id);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Other company', 'slug' => 'other']);
        foreach ([[$foreignSource, null], [$source, $foreignCompany]] as [$attemptedSource, $target]) {
            try {
                $repo->remember($workspace, $attemptedSource, 'companies', '11', ['name' => 'Unreachable'], $target);
                $this->fail('Cross-workspace source or target must be concealed.');
            } catch (ModelNotFoundException) {
                $this->assertDatabaseCount('historical_records', 0);
            }
        }
    }

    public function test_linking_a_preserved_record_is_explicit_and_cannot_retarget_it(): void
    {
        $workspace = $this->workspace('first');
        $repo = app(HistoricalRecordRepository::class);
        $source = $repo->source($workspace, 'synthetic', hash('sha256', 'source'), 'Source');
        $first = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'First', 'slug' => 'first']);
        $second = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Second', 'slug' => 'second']);
        $record = $repo->remember($workspace, $source, 'companies', '11', ['name' => 'Original']);
        $this->assertNull($record->target_public_id);
        $linked = $repo->remember($workspace, $source, 'companies', '11', ['name' => 'Original'], $first);
        $this->assertSame($first->public_id, $linked->target_public_id);
        $this->expectException(DomainException::class);
        $repo->remember($workspace, $source, 'companies', '11', ['name' => 'Original'], $second);
    }

    public function test_model_updates_cannot_replace_a_preserved_snapshot(): void
    {
        $workspace = $this->workspace('first');
        $repo = app(HistoricalRecordRepository::class);
        $source = $repo->source($workspace, 'synthetic', hash('sha256', 'source'), 'Source');
        $record = $repo->remember($workspace, $source, 'companies', '11', ['name' => 'Original']);
        $this->expectException(LogicException::class);
        $record->update(['snapshot' => ['name' => 'Erased original']]);
    }

    private function workspace(string $slug): Workspace
    {
        return Workspace::query()->create(['name' => 'Synthetic '.$slug, 'slug' => $slug]);
    }

    public function test_a_source_namespace_cannot_be_changed_after_creation(): void
    {
        $workspace = $this->workspace('first');
        $source = app(HistoricalRecordRepository::class)->source($workspace, 'synthetic', hash('sha256', 'source'), 'Source');
        $this->expectException(LogicException::class);
        $source->update(['identity_hash' => hash('sha256', 'different-source')]);
    }
}
