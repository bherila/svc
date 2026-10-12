<?php

namespace App\Services\Square;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\CompanySquareCustomer;
use App\Models\InvoiceSquareLink;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use App\Support\Square\SquareInvoiceReference;
use App\Support\WorkspaceClock;
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

    /** Soft-unlink: the link stays as history and either side may be linked again. */
    public function unlink(Workspace $workspace, ClientInvoice $invoice, User $actor, string $expectedVersion): InvoiceSquareLink
    {
        return DB::transaction(function () use ($workspace, $invoice, $actor, $expectedVersion): InvoiceSquareLink {
            $locked = $this->lockInvoice($workspace, $invoice);
            abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The invoice has changed; read it and retry.');
            $current = $this->links->activeLink($locked);
            abort_if($current === null, 409, sprintf('Invoice %s is not linked to a Square invoice.', (string) $locked->invoice_number));

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
     * Mark the agreement as billed in Square by its series.
     *
     * From then on SVC never collects the agreement's invoices - see
     * {@see SquareCollectionGuard}. A series already marking another agreement
     * is refused with 409; the same series again is a no-op.
     */
    public function markAgreement(Workspace $workspace, ClientAgreement $agreement, User $actor, string $expectedVersion, string $seriesKey): ClientAgreement
    {
        try {
            return DB::transaction(function () use ($workspace, $agreement, $actor, $expectedVersion, $seriesKey): ClientAgreement {
                $locked = $this->lockAgreement($workspace, $agreement);
                abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The agreement has changed; read it and retry.');
                $previous = $locked->square_series_key;
                if ($previous === $seriesKey) {
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
                $holder = $this->links->agreementForSeries($workspace->id, $seriesKey);
                if ($holder !== null) {
                    abort(409, sprintf('Square series %s already marks agreement "%s". Clear it there first.', $seriesKey, (string) $holder->title));
                }

                $locked->forceFill(['square_series_key' => $seriesKey])->save();
                $this->activities->record($workspace, $this->companyOfAgreement($workspace, $locked), 'agreement.square_series_set', $locked, [
                    'series_key' => $seriesKey,
                    'replaced_series_key' => $previous,
                ], $actor, (string) Str::uuid());

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, sprintf('Square series %s already marks another agreement.', $seriesKey));
        }
    }

    public function clearAgreement(Workspace $workspace, ClientAgreement $agreement, User $actor, string $expectedVersion): ClientAgreement
    {
        return DB::transaction(function () use ($workspace, $agreement, $actor, $expectedVersion): ClientAgreement {
            $locked = $this->lockAgreement($workspace, $agreement);
            abort_unless(AgentApiVersion::matches($locked, $expectedVersion), 409, 'The agreement has changed; read it and retry.');
            $previous = $locked->square_series_key;
            abort_if($previous === null, 409, 'This agreement is not marked as billed in Square.');

            $locked->forceFill(['square_series_key' => null])->save();
            $this->activities->record($workspace, $this->companyOfAgreement($workspace, $locked), 'agreement.square_series_cleared', $locked, [
                'series_key' => $previous,
            ], $actor, (string) Str::uuid());

            return $locked;
        });
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

    private function companyOf(Workspace $workspace, ClientInvoice $invoice): ClientCompany
    {
        return ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($invoice->client_company_id)->firstOrFail();
    }

    private function companyOfAgreement(Workspace $workspace, ClientAgreement $agreement): ClientCompany
    {
        return ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($agreement->client_company_id)->firstOrFail();
    }
}
