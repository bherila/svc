<?php

namespace App\Services\Square;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\CompanySquareCustomer;
use App\Models\InvoiceSquareLink;
use App\Models\SquareInvoiceCoverage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\Square\SquareInvoiceReference;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The SVC side of linking records to Square (#438): invoice links, the
 * company's Square customer, and an agreement's billed-in-Square marker.
 *
 * Nothing here calls Square. Each write locks the one SVC row it changes,
 * checks the caller's opaque version against it, writes, advances that row's
 * version and records client activity - so every change is audited and a
 * stale screen or agent is refused with 409 rather than overwriting a change
 * it never saw.
 *
 * "One active link per side" is held twice. The locked row serialises writers
 * of the same SVC record, and the `active_*` slot columns' unique indexes are
 * what stop two writers on *different* SVC records claiming the same Square
 * record at once: the loser's insert fails and is answered 409, exactly as a
 * sequential second link would be.
 */
final class SquareLinkService
{
    public function __construct(
        private readonly SquareLinkRepository $links,
        private readonly ClientActivityRecorder $activities,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Link an SVC invoice to a Square invoice.
     *
     * Refused with 409 when the Square invoice is already actively linked
     * anywhere, or when this invoice is already linked and `$replace` is not
     * set: re-pointing an invoice at a different Square invoice is an explicit
     * act, and the link it replaces is kept as history.
     */
    public function link(Workspace $workspace, ClientInvoice $invoice, User $actor, string $expectedVersion, SquareInvoiceReference $square, bool $replace): InvoiceSquareLink
    {
        try {
            return DB::transaction(function () use ($workspace, $invoice, $actor, $expectedVersion, $square, $replace): InvoiceSquareLink {
                $locked = $this->lockInvoice($workspace, $invoice);
                // Next in lock order, before the hand-off checks lock anything else.
                $this->lockSquareClaims($workspace);
                abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The invoice has changed; read it and retry.');
                if ($locked->status === InvoiceStatus::Void->value) {
                    throw new DomainException('A void invoice cannot be linked to Square.');
                }
                // Handing collection to Square while SVC's own is in flight
                // would leave a live Stripe intent beside Square's request for
                // the same money. The intent locks this invoice and advances
                // its version when it reserves the balance, so a caller who read
                // the invoice before it fails the version check above, and one
                // who read it after sees the payment here.
                if ($this->links->hasStripePaymentInFlight($locked)) {
                    abort(409, sprintf('A Stripe payment on invoice %s can still complete (pending, or failed and retryable). Let it succeed or cancel it in Stripe before Square collects this invoice.', (string) $locked->invoice_number));
                }
                // Nor while an email to the client may be leaving: a claim
                // commits before the message is sent, outside its lock.
                if ($this->links->hasEmailDeliveryInFlight($locked, $this->emailWindow($workspace))) {
                    abort(409, sprintf('An email to the client for invoice %s is being sent. Retry once it has gone.', (string) $locked->invoice_number));
                }
                if ($square->currency !== null && $square->currency !== strtoupper((string) $locked->currency)) {
                    throw new DomainException(sprintf(
                        'Square invoice %s is in %s and invoice %s is in %s; a link must be in one currency.',
                        $square->number, $square->currency, (string) $locked->invoice_number, strtoupper((string) $locked->currency),
                    ));
                }

                $current = $this->links->activeLink($locked);
                if ($current !== null && $current->square_invoice_id === $square->id) {
                    abort(409, sprintf('Invoice %s is already linked to Square invoice %s.', (string) $locked->invoice_number, $current->square_invoice_number));
                }
                $elsewhere = $this->links->activeLinkForSquareInvoice($workspace->id, $square->id);
                if ($elsewhere !== null) {
                    $other = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($elsewhere->client_invoice_id)->value('invoice_number');
                    abort(409, sprintf('Square invoice %s is already linked to invoice %s. Unlink it there first.', $square->number, is_string($other) ? $other : 'another invoice'));
                }
                $covered = $this->links->activeCoverage($workspace->id, $square->id);
                if ($covered !== null) {
                    abort(409, sprintf('Square invoice %s is recorded as covered by invoice %s. Remove that first.', $square->number, $this->invoiceNumber($workspace, $covered->client_invoice_id)));
                }
                if ($current !== null && ! $replace) {
                    abort(409, sprintf('Invoice %s is already linked to Square invoice %s. Pass replace: true to re-point it.', (string) $locked->invoice_number, $current->square_invoice_number));
                }

                $now = $this->clock->now($workspace)->utc();
                if ($current !== null) {
                    $this->retire($current, $actor, $now);
                }
                $link = InvoiceSquareLink::query()->create([
                    'workspace_id' => $workspace->id,
                    'client_invoice_id' => $locked->id,
                    'square_invoice_id' => $square->id,
                    'square_invoice_number' => $square->number,
                    'series_key' => $square->seriesKey(),
                    'currency' => strtoupper((string) $locked->currency),
                    'match_method' => $square->matchMethod,
                    'score' => $square->score,
                    'reasons' => $square->reasons,
                    'active_invoice_slot' => $locked->id,
                    'active_square_invoice_slot' => $square->id,
                    'linked_by_user_id' => $actor->id,
                    'linked_at' => $now,
                ]);
                $locked->advanceAgentRevision();
                $this->activities->record($workspace, $this->companyOf($workspace, $locked), 'invoice.square_linked', $locked, [
                    'square_invoice_id' => $square->id,
                    'square_invoice_number' => $square->number,
                    'series_key' => $square->seriesKey(),
                    'match_method' => $square->matchMethod->value,
                    'score' => $square->score,
                    'replaced_square_invoice_number' => $current?->square_invoice_number,
                ], $actor, (string) Str::uuid());

                return $link;
            });
        } catch (UniqueConstraintViolationException) {
            // Another invoice claimed this Square invoice between the check
            // above and this insert: the same answer a sequential call gets.
            abort(409, sprintf('Square invoice %s is already linked to another invoice.', $square->number));
        }
    }

    /**
     * Record a Square invoice as covered by this invoice (#487): one svc
     * invoice covers what Square split into several, its primary Square
     * invoice is linked, and each other one is covered. The billing audit
     * then does not report the covered invoice as an unmirrored period.
     *
     * Not a link: SVC collects, reconciles and records nothing through it.
     * Refused with 409 when the Square invoice is linked or covered anywhere
     * (the same one again included), and with 422 when this invoice has no
     * linked Square invoice to be the primary, is void, or is in another
     * currency than a stated one.
     */
    public function cover(Workspace $workspace, ClientInvoice $invoice, User $actor, string $expectedVersion, SquareInvoiceReference $square): SquareInvoiceCoverage
    {
        try {
            return DB::transaction(function () use ($workspace, $invoice, $actor, $expectedVersion, $square): SquareInvoiceCoverage {
                $locked = $this->lockInvoice($workspace, $invoice);
                abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The invoice has changed; read it and retry.');
                if ($locked->status === InvoiceStatus::Void->value) {
                    throw new DomainException('A void invoice covers nothing.');
                }
                $this->lockSquareClaims($workspace);
                $primary = $this->links->activeLink($locked);
                if ($primary === null) {
                    throw new DomainException(sprintf('Invoice %s is not linked to a Square invoice. Link its primary Square invoice first; the others it covers are recorded besides.', (string) $locked->invoice_number));
                }
                if ($square->currency !== null && $square->currency !== strtoupper((string) $locked->currency)) {
                    throw new DomainException(sprintf(
                        'Square invoice %s is in %s and invoice %s is in %s; an invoice covers only its own currency.',
                        $square->number, $square->currency, (string) $locked->invoice_number, strtoupper((string) $locked->currency),
                    ));
                }
                $linked = $this->links->activeLinkForSquareInvoice($workspace->id, $square->id);
                if ($linked !== null) {
                    abort(409, sprintf('Square invoice %s is linked to invoice %s, so it is not covered by another.', $square->number, $this->invoiceNumber($workspace, $linked->client_invoice_id)));
                }
                $covered = $this->links->activeCoverage($workspace->id, $square->id);
                if ($covered !== null) {
                    abort(409, sprintf('Square invoice %s is already recorded as covered by invoice %s.', $square->number, $this->invoiceNumber($workspace, $covered->client_invoice_id)));
                }
                if ($this->links->activeCoveragesOf($locked)->count() >= SquareLinkRepository::MAX_COVERAGES) {
                    throw new DomainException(sprintf('Invoice %s already covers %d Square invoices besides its linked one.', (string) $locked->invoice_number, SquareLinkRepository::MAX_COVERAGES));
                }

                $coverage = SquareInvoiceCoverage::query()->create([
                    'workspace_id' => $workspace->id,
                    'client_invoice_id' => $locked->id,
                    'square_invoice_id' => $square->id,
                    'square_invoice_number' => $square->number,
                    'active_square_invoice_slot' => $square->id,
                    'covered_by_user_id' => $actor->id,
                    'covered_at' => $this->clock->now($workspace)->utc(),
                ]);
                $locked->advanceAgentRevision();
                $this->activities->record($workspace, $this->companyOf($workspace, $locked), 'invoice.square_coverage_added', $locked, [
                    'square_invoice_id' => $square->id,
                    'square_invoice_number' => $square->number,
                    'primary_square_invoice_number' => $primary->square_invoice_number,
                ], $actor, (string) Str::uuid());

                return $coverage;
            });
        } catch (UniqueConstraintViolationException) {
            // Another invoice recorded this Square invoice as covered between
            // the check above and this insert.
            abort(409, sprintf('Square invoice %s is already recorded as covered by another invoice.', $square->number));
        }
    }

    /** Clear a coverage of this invoice; the row stays as history and the Square invoice may be covered or linked again. */
    public function uncover(Workspace $workspace, ClientInvoice $invoice, User $actor, string $expectedVersion, string $squareInvoiceId): SquareInvoiceCoverage
    {
        return DB::transaction(function () use ($workspace, $invoice, $actor, $expectedVersion, $squareInvoiceId): SquareInvoiceCoverage {
            $locked = $this->lockInvoice($workspace, $invoice);
            abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The invoice has changed; read it and retry.');
            $coverage = $this->links->activeCoverage($workspace->id, $squareInvoiceId);
            abort_if($coverage === null || $coverage->client_invoice_id !== $locked->id, 409, sprintf('Invoice %s does not cover that Square invoice.', (string) $locked->invoice_number));

            $coverage->forceFill([
                'active_square_invoice_slot' => null,
                'cleared_by_user_id' => $actor->id,
                'cleared_at' => $this->clock->now($workspace)->utc(),
            ])->save();
            $locked->advanceAgentRevision();
            $this->activities->record($workspace, $this->companyOf($workspace, $locked), 'invoice.square_coverage_removed', $locked, [
                'square_invoice_id' => $coverage->square_invoice_id,
                'square_invoice_number' => $coverage->square_invoice_number,
            ], $actor, (string) Str::uuid());

            return $coverage;
        });
    }

    /** Soft-unlink: the link stays as history and either side may be linked again. */
    public function unlink(Workspace $workspace, ClientInvoice $invoice, User $actor, string $expectedVersion): InvoiceSquareLink
    {
        return DB::transaction(function () use ($workspace, $invoice, $actor, $expectedVersion): InvoiceSquareLink {
            $locked = $this->lockInvoice($workspace, $invoice);
            abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The invoice has changed; read it and retry.');
            $current = $this->links->activeLink($locked);
            abort_if($current === null, 409, sprintf('Invoice %s is not linked to a Square invoice.', (string) $locked->invoice_number));
            // A coverage stands beside its invoice's primary link: unlinked,
            // the invoice could be collected by SVC while the Square invoices
            // it covers still read as accounted for.
            $covers = $this->links->activeCoveragesOf($locked)->count();
            abort_if($covers > 0, 409, sprintf('Invoice %s covers %d other Square invoice(s). Remove those coverages before unlinking it.', (string) $locked->invoice_number, $covers));

            $this->retire($current, $actor, $this->clock->now($workspace)->utc());
            $locked->advanceAgentRevision();
            $this->activities->record($workspace, $this->companyOf($workspace, $locked), 'invoice.square_unlinked', $locked, [
                'square_invoice_id' => $current->square_invoice_id,
                'square_invoice_number' => $current->square_invoice_number,
            ], $actor, (string) Str::uuid());

            return $current;
        });
    }

    /**
     * Confirm the company's Square customer, replacing any earlier mapping.
     *
     * A customer already mapped to another company is refused with 409; the
     * same customer again is a no-op.
     */
    public function setCustomer(Workspace $workspace, ClientCompany $company, User $actor, string $expectedVersion, string $customerId): ClientCompany
    {
        try {
            return DB::transaction(function () use ($workspace, $company, $actor, $expectedVersion, $customerId): ClientCompany {
                $locked = $this->lockCompany($workspace, $company);
                abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The client has changed; read it and retry.');
                $current = $this->links->activeCustomer($locked);
                if ($current !== null && $current->square_customer_id === $customerId) {
                    return $locked;
                }
                $elsewhere = $this->links->activeCustomerByCustomerId($workspace->id, $customerId);
                if ($elsewhere !== null) {
                    $other = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($elsewhere->client_company_id)->value('name');
                    abort(409, sprintf('That Square customer is already mapped to %s. Clear it there first.', is_string($other) ? $other : 'another client'));
                }

                $now = $this->clock->now($workspace)->utc();
                if ($current !== null) {
                    $this->clearMapping($current, $actor, $now);
                }
                CompanySquareCustomer::query()->create([
                    'workspace_id' => $workspace->id,
                    'client_company_id' => $locked->id,
                    'square_customer_id' => $customerId,
                    'active_company_slot' => $locked->id,
                    'active_customer_slot' => $customerId,
                    'confirmed_by_user_id' => $actor->id,
                    'confirmed_at' => $now,
                ]);
                $locked->advanceAgentRevision();
                $this->activities->record($workspace, $locked, 'company.square_customer_set', $locked, [
                    'square_customer_id' => $customerId,
                    'replaced_square_customer_id' => $current?->square_customer_id,
                ], $actor, (string) Str::uuid());

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'That Square customer is already mapped to another client.');
        }
    }

    public function clearCustomer(Workspace $workspace, ClientCompany $company, User $actor, string $expectedVersion): ClientCompany
    {
        return DB::transaction(function () use ($workspace, $company, $actor, $expectedVersion): ClientCompany {
            $locked = $this->lockCompany($workspace, $company);
            abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The client has changed; read it and retry.');
            $current = $this->links->activeCustomer($locked);
            abort_if($current === null, 409, 'This client has no Square customer to clear.');

            $this->clearMapping($current, $actor, $this->clock->now($workspace)->utc());
            $locked->advanceAgentRevision();
            $this->activities->record($workspace, $locked, 'company.square_customer_cleared', $locked, [
                'square_customer_id' => $current->square_customer_id,
            ], $actor, (string) Str::uuid());

            return $locked;
        });
    }

    /**
     * Mark the agreement as billed in Square by its series, in one Square
     * location (#488): Square numbers invoices uniquely per location only, so
     * the same prefix in another location is another series.
     *
     * Without `$locationId` the location is the one the mirror holds the
     * series in; a series it holds in no location, in more than one, or with
     * an invoice naming none, is refused until the caller names it.
     *
     * From then on SVC never collects the agreement's invoices - see
     * {@see SquareCollectionGuard}. A series already marking another agreement
     * in that location is refused with 409; the same series and location
     * again is a no-op.
     */
    public function markAgreement(Workspace $workspace, ClientAgreement $agreement, User $actor, string $expectedVersion, string $seriesKey, ?string $locationId = null): ClientAgreement
    {
        $locationId ??= $this->seriesLocation($workspace, $seriesKey);
        try {
            return DB::transaction(function () use ($workspace, $agreement, $actor, $expectedVersion, $seriesKey, $locationId): ClientAgreement {
                $locked = $this->lockAgreement($workspace, $agreement);
                abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The agreement has changed; read it and retry.');
                $previous = $locked->square_series_key;
                $previousLocation = $locked->square_location_id;
                if ($previous === $seriesKey && $previousLocation === $locationId) {
                    return $locked;
                }
                // As for a link: no hand-off while a Stripe payment the client
                // started is still in flight on one of these invoices. A
                // locking read, after the agreement it is ordered behind, so it
                // sees an intent committed while this waited - and starting an
                // intent takes this agreement first, so none can begin after.
                $pending = $this->links->stripePaymentInFlightForAgreement($locked);
                if ($pending !== null) {
                    abort(409, sprintf('A Stripe payment on invoice %s of this agreement can still complete (pending, or failed and retryable). Let it succeed or cancel it in Stripe before Square collects the agreement\'s invoices.', $pending));
                }
                $emailing = $this->links->emailDeliveryInFlightForAgreement($locked, $this->emailWindow($workspace));
                if ($emailing !== null) {
                    abort(409, sprintf('An email to the client for invoice %s of this agreement is being sent. Retry once it has gone.', $emailing));
                }
                $holder = $this->links->agreementForSeries($workspace->id, $seriesKey, $locationId, $locked->id);
                if ($holder !== null) {
                    abort(409, sprintf('Square series %s in location %s already marks agreement "%s". Clear it there first.', $seriesKey, $locationId, (string) $holder->title));
                }

                $locked->forceFill(['square_series_key' => $seriesKey, 'square_location_id' => $locationId])->save();
                $this->activities->record($workspace, $this->companyOfAgreement($workspace, $locked), 'agreement.square_series_set', $locked, [
                    'series_key' => $seriesKey,
                    'location_id' => $locationId,
                    'replaced_series_key' => $previous,
                    'replaced_location_id' => $previousLocation,
                ], $actor, (string) Str::uuid());

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, sprintf('Square series %s in location %s already marks another agreement.', $seriesKey, $locationId));
        }
    }

    /**
     * The one Square location the mirror holds this series in.
     *
     * @throws DomainException when that is not exactly one location
     */
    private function seriesLocation(Workspace $workspace, string $seriesKey): string
    {
        $locations = $this->links->seriesLocations($workspace->id, $seriesKey);
        if ($locations === []) {
            throw new DomainException(sprintf('The Square mirror holds no invoice of series %s, so its location is not known. Pass location_id, or sync first.', $seriesKey));
        }
        if (count($locations) > 1 || $locations[0] === null) {
            throw new DomainException(sprintf('Square series %s is used in more than one Square location, or by an invoice naming none. Pass location_id to say which location bills this agreement.', $seriesKey));
        }

        return $locations[0];
    }

    public function clearAgreement(Workspace $workspace, ClientAgreement $agreement, User $actor, string $expectedVersion): ClientAgreement
    {
        return DB::transaction(function () use ($workspace, $agreement, $actor, $expectedVersion): ClientAgreement {
            $locked = $this->lockAgreement($workspace, $agreement);
            abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The agreement has changed; read it and retry.');
            $previous = $locked->square_series_key;
            abort_if($previous === null, 409, 'This agreement is not marked as billed in Square.');

            $previousLocation = $locked->square_location_id;
            $locked->forceFill(['square_series_key' => null, 'square_location_id' => null])->save();
            $this->activities->record($workspace, $this->companyOfAgreement($workspace, $locked), 'agreement.square_series_cleared', $locked, [
                'series_key' => $previous,
                'location_id' => $previousLocation,
            ], $actor, (string) Str::uuid());

            return $locked;
        });
    }

    /**
     * How far back a registered or claimed client email still counts as
     * leaving: the same hour after which a payment treats an unresolved claim
     * as ambiguous rather than in progress.
     */
    private function emailWindow(Workspace $workspace): CarbonImmutable
    {
        return $this->clock->now($workspace)->utc()->subMinutes(60);
    }

    private function retire(InvoiceSquareLink $link, User $actor, \DateTimeInterface $now): void
    {
        $link->forceFill([
            'active_invoice_slot' => null,
            'active_square_invoice_slot' => null,
            'unlinked_by_user_id' => $actor->id,
            'unlinked_at' => $now,
        ])->save();
    }

    private function clearMapping(CompanySquareCustomer $mapping, User $actor, \DateTimeInterface $now): void
    {
        $mapping->forceFill([
            'active_company_slot' => null,
            'active_customer_slot' => null,
            'cleared_by_user_id' => $actor->id,
            'cleared_at' => $now,
        ])->save();
    }

    private function lockInvoice(Workspace $workspace, ClientInvoice $invoice): ClientInvoice
    {
        return ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($invoice->id)
            ->tap(Locks::forUpdate())->firstOrFail();
    }

    private function lockCompany(Workspace $workspace, ClientCompany $company): ClientCompany
    {
        return ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($company->id)
            ->tap(Locks::forUpdate())->firstOrFail();
    }

    private function lockAgreement(Workspace $workspace, ClientAgreement $agreement): ClientAgreement
    {
        return ClientAgreement::query()->where('workspace_id', $workspace->id)->whereKey($agreement->id)
            ->tap(Locks::forUpdate())->firstOrFail();
    }

    /**
     * Links and coverages each claim a Square invoice, in two tables whose
     * unique indexes cannot see each other. Two claims on different invoices
     * lock different rows, so both take the workspace row next (after the
     * invoice, in lock order) and read the other table only then: a link and a
     * coverage of one Square invoice can no longer both commit.
     */
    private function lockSquareClaims(Workspace $workspace): void
    {
        Workspace::query()->whereKey($workspace->id)->tap(Locks::forUpdate())->firstOrFail();
    }

    private function invoiceNumber(Workspace $workspace, int $invoiceId): string
    {
        $number = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($invoiceId)->value('invoice_number');

        return is_string($number) ? $number : 'another invoice';
    }

    private function companyOf(Workspace $workspace, ClientInvoice $invoice): ClientCompany
    {
        return ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($invoice->client_company_id)->firstOrFail();
    }

    private function companyOfAgreement(Workspace $workspace, ClientAgreement $agreement): ClientCompany
    {
        return ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($agreement->client_company_id)->firstOrFail();
    }
}
