<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceDeferredWorkSnapshot;
use App\Support\Billing\InvoiceLineDetail;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsGoldenInvoices;
use Tests\Support\Billing\BladeInvoiceDocumentFacts;
use Tests\Support\Billing\InvoiceDocumentFacts;
use Tests\TestCase;

/**
 * Golden tests for the "Still deferred" section of the invoice document.
 *
 * ## What the document does today, which is not what the brief assumed
 *
 * The section is read from the snapshot stored with the invoice
 * (`deferred_work_statement`) and is printed whenever one is stored and
 * readable, for the operator copy and the client copy alike. The document
 * itself never consults `show_deferred_backlog_to_client`: that agreement flag
 * gates the portal's live backlog panel (`ClientPortalController`), not this
 * section. These tests pin the document as it is, so a rewrite that begins to
 * gate the section on the flag has to say so by changing them.
 *
 * Both stored versions are covered: version 2 (project lineage frozen per
 * entry, #610) and the legacy version 1 reading, which still prints.
 */
class InvoiceDocumentDeferredWorkGoldenTest extends TestCase
{
    use BuildsGoldenInvoices;
    use RefreshDatabase;

    private const SUMMARY = '2 entries · 3.5000 hours · oldest worked date 2026-06-01';

    private const ENTRIES = [
        ['date' => '2026-06-01', 'work' => 'Reviewed the roadmap', 'hours' => '1.5000'],
        ['date' => '2026-06-15', 'work' => '', 'hours' => '2.0000'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGoldenTenant();
    }

    protected function documentFacts(): InvoiceDocumentFacts
    {
        return new BladeInvoiceDocumentFacts(app(InvoiceDocumentService::class));
    }

    /** @return iterable<string, array{string}> */
    public static function audiences(): iterable
    {
        yield 'operator' => [InvoiceLineDetail::OPERATOR];
        yield 'client' => [InvoiceLineDetail::CLIENT];
    }

    /** @return iterable<string, array{string, bool}> */
    public static function audiencesAndFlag(): iterable
    {
        foreach ([InvoiceLineDetail::OPERATOR, InvoiceLineDetail::CLIENT] as $audience) {
            foreach ([true, false] as $flag) {
                yield $audience.' / show_deferred_backlog_to_client='.($flag ? 'true' : 'false') => [$audience, $flag];
            }
        }
    }

    /** @return array<string, mixed> */
    private function version2(): array
    {
        return ['version' => 2, 'entries' => [
            // Stored out of order: the document prints oldest first.
            ['id' => 12, 'workedOn' => '2026-06-15', 'minutes' => 120, 'description' => null, 'projectId' => $this->project->id + 1000],
            ['id' => 11, 'workedOn' => '2026-06-01', 'minutes' => 90, 'description' => 'Reviewed the roadmap', 'projectId' => $this->project->id],
        ]];
    }

    /** @return array<string, mixed> */
    private function version1(): array
    {
        return ['version' => 1, 'entries' => [
            ['id' => 12, 'workedOn' => '2026-06-15', 'minutes' => 120, 'description' => null],
            ['id' => 11, 'workedOn' => '2026-06-01', 'minutes' => 90, 'description' => 'Reviewed the roadmap'],
        ]];
    }

    #[DataProvider('audiences')]
    public function test_a_version_2_snapshot_with_project_lineage_prints_the_section(string $audience): void
    {
        $invoice = $this->goldenInvoice(['deferred_work_statement' => $this->version2()]);

        $snapshot = $invoice->deferredWorkSnapshot();
        $this->assertSame(InvoiceDeferredWorkSnapshot::VERSION, $snapshot?->version);
        $this->assertFalse($snapshot->hasUnverifiableLineage());
        $this->assertSame([$this->project->id, $this->project->id + 1000], $snapshot->projectIds());

        $section = $this->documentFacts()->extract($invoice, $audience)['still_deferred'];

        // The lineage gates who may read the invoice; it is never printed.
        $this->assertSame(['summary' => self::SUMMARY, 'entries' => self::ENTRIES], $section);
    }

    #[DataProvider('audiences')]
    public function test_a_version_1_legacy_snapshot_still_prints_the_same_section(string $audience): void
    {
        $invoice = $this->goldenInvoice(['deferred_work_statement' => $this->version1()]);

        $snapshot = $invoice->deferredWorkSnapshot();
        $this->assertSame(InvoiceDeferredWorkSnapshot::LEGACY_VERSION, $snapshot?->version);
        $this->assertTrue($snapshot->hasUnverifiableLineage(), 'A legacy entry never recorded its project.');
        $this->assertSame([], $snapshot->projectIds());
        // Written back as version 1: unknown lineage is never laundered into "no project".
        $this->assertArrayNotHasKey('projectId', $snapshot->toArray()['entries'][0]);

        $section = $this->documentFacts()->extract($invoice, $audience)['still_deferred'];

        $this->assertSame(['summary' => self::SUMMARY, 'entries' => self::ENTRIES], $section);
    }

    #[DataProvider('audiencesAndFlag')]
    public function test_the_section_prints_for_both_audiences_whatever_the_agreement_flag_says(string $audience, bool $flag): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Synthetic flag retainer', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 100000, 'hourly_rate_amount' => 10000,
            'show_deferred_backlog_to_client' => $flag,
        ]);
        $invoice = $this->goldenInvoice(['client_agreement_id' => $agreement->id, 'deferred_work_statement' => $this->version2()]);

        $this->assertSame($flag, $invoice->agreement?->show_deferred_backlog_to_client);
        $this->assertSame(
            ['summary' => self::SUMMARY, 'entries' => self::ENTRIES],
            $this->documentFacts()->extract($invoice, $audience)['still_deferred'],
        );
    }

    #[DataProvider('audiences')]
    public function test_one_entry_reads_singular(string $audience): void
    {
        $invoice = $this->goldenInvoice(['deferred_work_statement' => ['version' => 2, 'entries' => [
            ['id' => 5, 'workedOn' => '2026-07-02', 'minutes' => 30, 'description' => 'Drafted the memo', 'projectId' => $this->project->id],
        ]]]);

        $this->assertSame([
            'summary' => '1 entry · 0.5000 hours · oldest worked date 2026-07-02',
            'entries' => [['date' => '2026-07-02', 'work' => 'Drafted the memo', 'hours' => '0.5000']],
        ], $this->documentFacts()->extract($invoice, $audience)['still_deferred']);
    }

    #[DataProvider('audiences')]
    public function test_no_stored_snapshot_prints_no_section(string $audience): void
    {
        $this->assertNull($this->documentFacts()->extract($this->goldenInvoice(), $audience)['still_deferred']);
    }

    #[DataProvider('audiences')]
    public function test_a_snapshot_the_document_cannot_read_prints_no_section_but_the_invoice_still_prints(string $audience): void
    {
        foreach ([
            ['version' => 7, 'entries' => [['id' => 1, 'workedOn' => '2026-06-01', 'minutes' => 60, 'description' => null, 'projectId' => 1]]],
            ['version' => 2, 'entries' => [['id' => 1, 'workedOn' => '2026-06-01', 'minutes' => 60, 'description' => null]]],
            ['version' => 2, 'entries' => []],
        ] as $stored) {
            $invoice = $this->goldenInvoice(['deferred_work_statement' => $stored]);
            $this->goldenLine($invoice, ['description' => 'Synthetic retainer']);
            $facts = $this->documentFacts()->extract($invoice, $audience);
            $this->assertNull($facts['still_deferred'], json_encode($stored) ?: '');
            $this->assertSame('Issued', $facts['status']);
        }
    }

    #[DataProvider('audiences')]
    public function test_the_section_comes_from_the_stored_snapshot_not_the_live_entries(string $audience): void
    {
        $invoice = $this->goldenInvoice(['deferred_work_statement' => $this->version2()]);
        // Newer deferred work, and an internal note, after the snapshot was taken.
        $this->goldenEntry('2026-06-20', 600, ['is_deferred' => true, 'description' => 'Synthetic later internal note']);

        $facts = $this->documentFacts()->extract($invoice, $audience);

        $this->assertSame(['summary' => self::SUMMARY, 'entries' => self::ENTRIES], $facts['still_deferred']);
        $html = app(InvoiceDocumentService::class)->html($invoice, $audience)->render();
        $this->assertStringNotContainsString('Synthetic later internal note', $html);
    }

    public function test_a_stored_snapshot_has_only_client_wording_so_the_operator_copy_prints_no_internal_note(): void
    {
        $entry = $this->goldenEntry('2026-06-01', 90, [
            'is_deferred' => true, 'description' => 'Synthetic internal-only note', 'is_visible_to_client' => false,
            'client_visible_description' => 'Synthetic unreleased wording',
        ]);
        $visible = $this->goldenEntry('2026-06-02', 60, [
            'is_deferred' => true, 'is_visible_to_client' => true, 'client_visible_description' => '  Reviewed the roadmap  ',
        ]);
        $snapshot = InvoiceDeferredWorkSnapshot::fromEntries([$entry, $visible]);
        $invoice = $this->goldenInvoice(['deferred_work_statement' => $snapshot?->toArray()]);

        foreach ([InvoiceLineDetail::OPERATOR, InvoiceLineDetail::CLIENT] as $audience) {
            $section = $this->documentFacts()->extract($invoice, $audience)['still_deferred'];
            $this->assertSame([
                ['date' => '2026-06-01', 'work' => '', 'hours' => '1.5000'],
                ['date' => '2026-06-02', 'work' => 'Reviewed the roadmap', 'hours' => '1.0000'],
            ], $section['entries'] ?? null, $audience);
            $html = app(InvoiceDocumentService::class)->html($invoice, $audience)->render();
            $this->assertStringNotContainsString('Synthetic internal-only note', $html);
            $this->assertStringNotContainsString('Synthetic unreleased wording', $html);
        }
    }

    /**
     * The same facts as the stored fixtures, but written by the real
     * generator and issue path: a version 2 snapshot freezing each entry's
     * project at capture, printed unchanged after the live entry moves.
     */
    public function test_a_generated_and_issued_invoice_stores_version_2_lineage_and_prints_it_for_both_audiences(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 12:00:00'));
        $other = $this->goldenProject('Synthetic Other Project');
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Synthetic lineage retainer', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-04-01',
            'billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 100000, 'hourly_rate_amount' => 10000,
            'show_deferred_backlog_to_client' => false,
        ]);
        // A full pool of ordinary work, so the older deferred work has to wait.
        $this->goldenEntry('2026-05-10', 600);
        $deferred = $this->goldenEntry('2026-04-01', 60, [
            'client_project_id' => $other->id, 'is_deferred' => true,
            'is_visible_to_client' => true, 'client_visible_description' => 'Synthetic project B deferred work',
        ]);
        $start = Carbon::parse('2026-05-01');
        $draft = app(ClientInvoicingService::class)->generateInvoice($this->company, $start, $start->copy()->endOfMonth(), $agreement);
        $issued = app(InvoiceLifecycleService::class)->issue($draft, $this->workspace);

        $snapshot = $issued->deferredWorkSnapshot();
        $this->assertSame(2, $snapshot?->version);
        $this->assertSame([$other->id], $snapshot->projectIds());

        $expected = [
            'summary' => '1 entry · 1.0000 hours · oldest worked date 2026-04-01',
            'entries' => [['date' => '2026-04-01', 'work' => 'Synthetic project B deferred work', 'hours' => '1.0000']],
        ];
        foreach ([InvoiceLineDetail::OPERATOR, InvoiceLineDetail::CLIENT] as $audience) {
            $this->assertSame($expected, $this->documentFacts()->extract($issued, $audience)['still_deferred'], $audience);
        }

        // Frozen at issue: moving the live entry later changes neither the lineage nor the text.
        $deferred->forceFill(['client_project_id' => $this->project->id, 'client_visible_description' => 'Synthetic rewritten wording'])->save();
        $reread = ClientInvoice::query()->whereKey($issued->id)->firstOrFail();
        $this->assertSame([$other->id], $reread->deferredWorkSnapshot()?->projectIds());
        foreach ([InvoiceLineDetail::OPERATOR, InvoiceLineDetail::CLIENT] as $audience) {
            $this->assertSame($expected, $this->documentFacts()->extract($reread, $audience)['still_deferred'], $audience);
        }
    }
}
