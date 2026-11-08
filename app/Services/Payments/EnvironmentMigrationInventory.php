<?php

namespace App\Services\Payments;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripeEvent;
use App\Models\ClientStripePaymentMethod;
use App\Models\CompanySquareCustomer;
use App\Models\InvoiceAutopayAttempt;
use App\Models\InvoiceSquareLink;
use App\Models\SquareLocationSnapshot;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\InvoiceStatus;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\Inventory\ExistingConnection;
use App\Support\Payments\Inventory\ImpactCounts;
use App\Support\Payments\Inventory\ImpactRole;
use App\Support\Payments\Inventory\MigrationInventory;
use App\Support\Payments\Inventory\MigrationOutcome;
use App\Support\Payments\Inventory\OwnershipStatus;
use App\Support\Payments\Inventory\ProcessorInventory;
use App\Support\Payments\Inventory\SquareLocationFacts;
use App\Support\Payments\Inventory\WorkspaceImpact;
use App\Support\Payments\Inventory\WorkspaceRef;
use App\Support\Payments\PaymentProcessor;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What `svc:payments:migrate-environment` would change, worked out without
 * changing or calling anything: only SELECTs, never Stripe or Square.
 *
 * The questions are asked once for every workspace (grouped counts), never once
 * per workspace, so the number of queries depends on which processors are
 * configured and not on how many workspaces exist.
 */
final class EnvironmentMigrationInventory
{
    /** How far back webhook traffic is counted. */
    private const int WEBHOOK_WINDOW_DAYS = 90;

    /** The mirrored location names listed before the rest are only counted. */
    private const int LOCATION_NAMES = 10;

    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    /** The environment's credentials for a processor, or null when it has none. */
    public function environmentCredentials(PaymentProcessor $processor): ?PaymentCredentials
    {
        $string = static fn (string $key): ?string => is_string($value = config($key)) && trim($value) !== '' ? trim($value) : null;

        return match ($processor) {
            PaymentProcessor::Stripe => ($secret = $string('services.stripe.secret_key')) === null
                ? null
                : new PaymentCredentials($secret, $string('services.stripe.webhook_secret')),
            PaymentProcessor::Square => ($token = $string('services.square.access_token')) === null
                ? null
                : new PaymentCredentials($token, $string('services.square.webhook_signature_key'), null, $string('services.square.environment') ?? 'production'),
        };
    }

    /**
     * @param  Workspace|null  $named  the workspace --workspace named
     * @param  Workspace|null  $configured  the workspace SQUARE_WORKSPACE names
     */
    public function build(?Workspace $named, ?Workspace $configured): MigrationInventory
    {
        $credentials = [];
        foreach (PaymentProcessor::cases() as $processor) {
            $credentials[$processor->value] = $this->environmentCredentials($processor);
        }

        // Every connection, revoked or not: "ever connected" is what decides who the environment serves.
        // The secrets are not selected, so nothing here is decrypted.
        $connections = WorkspacePaymentConnection::query()
            ->select(['id', 'workspace_id', 'processor', 'origin', 'revoked_at'])
            ->orderBy('workspace_id')->orderBy('id')
            ->get();

        $pending = $this->pendingPayments();
        $stripe = $credentials[PaymentProcessor::Stripe->value] === null || $this->migrated($connections, PaymentProcessor::Stripe)
            ? []
            : $this->stripeCounts($pending);
        $squareConfigured = $configured !== null && $credentials[PaymentProcessor::Square->value] !== null
            && ! $this->migrated($connections, PaymentProcessor::Square);
        $square = $squareConfigured ? $this->squareCounts($configured, $pending) : [];

        $mirror = $this->mirror($named ?? $configured, $credentials[PaymentProcessor::Square->value] !== null);

        $wanted = [...array_keys($stripe), ...array_keys($square), ...$connections->pluck('workspace_id')->map(static fn (mixed $id): int => (int) $id)->all()];
        $refs = $this->references(array_values(array_unique($wanted)), [$named, $configured]);

        $processors = [];
        foreach (PaymentProcessor::cases() as $processor) {
            $processors[$processor->value] = $this->processor(
                $processor, $credentials[$processor->value], $named, $configured, $refs,
                $connections->filter(fn (WorkspacePaymentConnection $row): bool => $row->processor === $processor->value),
                match ($processor) {
                    PaymentProcessor::Stripe => $stripe,
                    PaymentProcessor::Square => $square,
                },
                $mirror,
            );
        }

        return new MigrationInventory($named === null ? null : $refs[$named->id], $processors);
    }

    /**
     * @param  array<int, WorkspaceRef>  $refs
     * @param  Collection<int, WorkspacePaymentConnection>  $rows
     * @param  array<int, ImpactCounts>  $counts
     */
    private function processor(PaymentProcessor $processor, ?PaymentCredentials $credentials, ?Workspace $named, ?Workspace $configured, array $refs, Collection $rows, array $counts, ?SquareLocationFacts $mirror): ProcessorInventory
    {
        $migrated = $rows->contains(fn (WorkspacePaymentConnection $row): bool => $row->origin === ConnectionOrigin::Environment->value);
        $everConnected = $rows->pluck('workspace_id')->flip()->all();
        $activeOf = $rows->filter(fn (WorkspacePaymentConnection $row): bool => $row->revoked_at === null)->pluck('workspace_id')->flip()->all();

        $square = $processor === PaymentProcessor::Square && $credentials !== null ? $mirror : null;
        $hint = $credentials === null ? null : WorkspacePaymentConnection::hint($credentials->secret());
        $mode = $credentials === null ? null : $this->mode($processor, $credentials);

        if ($credentials === null || $migrated) {
            return new ProcessorInventory(
                $processor, $credentials !== null, $hint, $mode, OwnershipStatus::NotApplicable, null,
                $credentials === null ? 'There is no environment credential to hand over.' : 'The environment credential was already handed to a workspace.',
                $credentials === null ? MigrationOutcome::NoEnvironmentCredentials : MigrationOutcome::AlreadyMigrated,
                [], $this->connectionsOf($rows, $refs, null), null,
            );
        }

        // Who relies on the environment today: workspaces that have never connected this processor.
        $dependents = array_values(array_filter(
            array_keys($counts),
            static fn (int $id): bool => ! isset($everConnected[$id]) && ! $counts[$id]->isEmpty(),
        ));

        [$status, $owner, $rule] = $this->ownership($processor, $named, $configured, $dependents);
        $ownerId = $owner?->id;

        $ids = $dependents;
        if ($ownerId !== null) {
            $ids[] = $ownerId;
        }
        if ($processor === PaymentProcessor::Square && $configured !== null && ! isset($everConnected[$configured->id])) {
            $ids[] = $configured->id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        $impacts = [];
        foreach ($ids as $id) {
            $role = match (true) {
                $id === $ownerId => ImpactRole::WillOwn,
                $status->needsExplicitChoice() => ImpactRole::Candidate,
                default => ImpactRole::LosesAccess,
            };
            $impacts[] = new WorkspaceImpact($refs[$id], $role, $counts[$id] ?? new ImpactCounts);
        }

        $outcome = match (true) {
            $status === OwnershipStatus::NotChosen => MigrationOutcome::NeedsWorkspace,
            $status === OwnershipStatus::Ambiguous => MigrationOutcome::OwnershipAmbiguous,
            $ownerId !== null && isset($activeOf[$ownerId]) => MigrationOutcome::WorkspaceAlreadyConnected,
            default => MigrationOutcome::WouldMigrate,
        };

        return new ProcessorInventory(
            $processor, true, $hint, $mode, $status, $owner === null ? null : $refs[$owner->id], $rule, $outcome,
            $impacts, $this->connectionsOf($rows, $refs, $ownerId), $square,
        );
    }

    /**
     * Who would own the connection, and the rule that says so.
     *
     * Square's environment token has only ever served the one workspace
     * SQUARE_WORKSPACE names. Stripe's environment key serves every workspace
     * that has never connected its own, and SQUARE_WORKSPACE says nothing about
     * Stripe: borrowing it is a guess whenever another workspace has Stripe
     * activity.
     *
     * @param  list<int>  $dependents  workspace ids with Stripe/Square activity riding on the environment
     * @return array{0: OwnershipStatus, 1: ?Workspace, 2: string}
     */
    private function ownership(PaymentProcessor $processor, ?Workspace $named, ?Workspace $configured, array $dependents): array
    {
        if ($named !== null) {
            return [OwnershipStatus::Explicit, $named, 'You named it with --workspace.'];
        }
        if ($configured === null) {
            return [OwnershipStatus::NotChosen, null, 'No --workspace was given and SQUARE_WORKSPACE is empty or names no workspace, so nothing says who should own it.'];
        }

        return match ($processor) {
            PaymentProcessor::Square => [OwnershipStatus::Configured, $configured, 'SQUARE_WORKSPACE names it, and it is the only workspace the environment\'s Square token has ever served.'],
            PaymentProcessor::Stripe => ($others = array_values(array_diff($dependents, [$configured->id]))) === []
                ? [OwnershipStatus::Configured, $configured, 'SQUARE_WORKSPACE names it (there is no Stripe-specific setting), and no other workspace has Stripe activity.']
                : [OwnershipStatus::Ambiguous, null, 'The environment\'s Stripe key serves every workspace that has not connected its own, and '.count($others).' other workspace'.(count($others) === 1 ? ' has' : 's have').' Stripe activity; SQUARE_WORKSPACE only names the Square workspace. Choose one with --workspace.'],
        };
    }

    /**
     * @param  Collection<int, WorkspacePaymentConnection>  $rows
     * @param  array<int, WorkspaceRef>  $refs
     * @return list<ExistingConnection>
     */
    private function connectionsOf(Collection $rows, array $refs, ?int $ownerId): array
    {
        $out = [];
        foreach ($rows as $row) {
            $active = $row->revoked_at === null;
            $workspaceId = (int) $row->workspace_id;
            $effect = match (true) {
                $row->origin === ConnectionOrigin::Environment->value => ExistingConnection::ENVIRONMENT_MIGRATED,
                $workspaceId === $ownerId && $active => ExistingConnection::BLOCKS_OWNER,
                $workspaceId === $ownerId => ExistingConnection::REVOKED_EARLIER,
                default => ExistingConnection::UNAFFECTED,
            };
            $out[] = new ExistingConnection($refs[$workspaceId], $row->origin, $active, $effect);
        }

        return $out;
    }

    /** @param Collection<int, WorkspacePaymentConnection> $connections */
    private function migrated(Collection $connections, PaymentProcessor $processor): bool
    {
        return $connections->contains(fn (WorkspacePaymentConnection $row): bool => $row->processor === $processor->value
            && $row->origin === ConnectionOrigin::Environment->value);
    }

    private function mode(PaymentProcessor $processor, PaymentCredentials $credentials): string
    {
        return match ($processor) {
            PaymentProcessor::Stripe => str_contains($credentials->secret(), '_live_') ? 'live' : (str_contains($credentials->secret(), '_test_') ? 'test' : 'unknown'),
            PaymentProcessor::Square => (string) $credentials->environment,
        };
    }

    /**
     * Open invoices a client can pay online: issued or part paid, visible to the
     * client, with a balance. One grouped query for all workspaces.
     *
     * @return Builder<ClientInvoice>
     */
    private function openInvoices(): Builder
    {
        return ClientInvoice::query()
            ->whereIn('status', InvoiceStatus::collectible())
            ->where('is_visible_to_client', true)
            ->where('balance_amount', '>', 0);
    }

    /**
     * Pending payments per workspace and processor, in one query.
     *
     * @return array<string, array<int, int>> processor value => workspace id => count
     */
    private function pendingPayments(): array
    {
        $out = [];
        $rows = ClientInvoicePayment::query()
            ->where('status', InvoicePaymentStatus::Pending->value)
            ->whereIn('provider', array_map(static fn (PaymentProcessor $p): string => $p->value, PaymentProcessor::cases()))
            ->groupBy('workspace_id', 'provider')
            ->selectRaw('workspace_id, provider, count(*) as n')
            ->get();
        foreach ($rows as $row) {
            $out[(string) $row->getAttribute('provider')][(int) $row->workspace_id] = (int) $row->getAttribute('n');
        }

        return $out;
    }

    /**
     * @param  array<string, array<int, int>>  $pending
     * @return array<int, ImpactCounts>
     */
    private function stripeCounts(array $pending): array
    {
        $grouped = static fn (Builder $query): array => $query
            ->groupBy('workspace_id')->selectRaw('workspace_id, count(*) as n')
            ->pluck('n', 'workspace_id')->map(static fn (mixed $n): int => (int) $n)->all();

        $invoices = $grouped($this->openInvoices());
        $autopay = $grouped(ClientCompany::query()->whereNotNull('autopay_enabled_at'));
        $methods = $grouped(ClientStripePaymentMethod::query());
        $held = $grouped(InvoiceAutopayAttempt::query()->where('state', AutopayAttemptState::Claimed->value));
        $events = $grouped(ClientStripeEvent::query()->whereNotNull('workspace_id')->where('created_at', '>=', $this->since()));
        $payments = $pending[PaymentProcessor::Stripe->value] ?? [];

        $ids = array_unique([...array_keys($invoices), ...array_keys($autopay), ...array_keys($methods), ...array_keys($held), ...array_keys($events), ...array_keys($payments)]);
        $counts = [];
        foreach ($ids as $id) {
            $counts[(int) $id] = new ImpactCounts(
                payableInvoices: $invoices[$id] ?? 0,
                autopayCompanies: $autopay[$id] ?? 0,
                savedPaymentMethods: $methods[$id] ?? 0,
                pendingPayments: $payments[$id] ?? 0,
                heldAutopayAttempts: $held[$id] ?? 0,
                recentWebhookEvents: $events[$id] ?? 0,
            );
        }

        return $counts;
    }

    /**
     * Only the workspace SQUARE_WORKSPACE names has ever been served by the
     * environment's Square token, so only it is counted.
     *
     * @param  array<string, array<int, int>>  $pending
     * @return array<int, ImpactCounts>
     */
    private function squareCounts(Workspace $workspace, array $pending): array
    {
        $id = (int) $workspace->id;
        $invoices = $this->openInvoices()->where('workspace_id', $id)
            ->whereExists(fn ($link) => $link->selectRaw('1')->from((new InvoiceSquareLink)->getTable())
                ->whereColumn('invoice_square_links.client_invoice_id', 'client_invoices.id')
                ->whereColumn('invoice_square_links.workspace_id', 'client_invoices.workspace_id')
                ->whereNull('invoice_square_links.unlinked_at'))
            ->count();
        $customers = CompanySquareCustomer::query()->where('workspace_id', $id)->whereNull('cleared_at')->count();
        $receipts = DB::table('square_webhook_receipts')->where('workspace_id', $id)->where('received_at', '>=', $this->since())->count();

        return [$id => new ImpactCounts(
            payableInvoices: $invoices,
            linkedCustomers: $customers,
            pendingPayments: $pending[PaymentProcessor::Square->value][$id] ?? 0,
            recentWebhookEvents: $receipts,
        )];
    }

    private function since(): CarbonImmutable
    {
        return $this->clock->now()->subDays(self::WEBHOOK_WINDOW_DAYS);
    }

    /** What the local Square mirror knows about the workspace's locations; null when Square is not configured. */
    private function mirror(?Workspace $workspace, bool $squareConfigured): ?SquareLocationFacts
    {
        if (! $squareConfigured) {
            return null;
        }
        if ($workspace === null) {
            return new SquareLocationFacts(0, []);
        }
        $names = SquareLocationSnapshot::query()->where('workspace_id', $workspace->id)->orderBy('name')->limit(self::LOCATION_NAMES + 1)->pluck('name');

        return new SquareLocationFacts($names->count(), array_values($names->take(self::LOCATION_NAMES)->map(static fn (mixed $name): string => (string) $name)->all()));
    }

    /**
     * Names for the workspaces the report mentions, in one query.
     *
     * @param  list<int>  $ids
     * @param  list<Workspace|null>  $known
     * @return array<int, WorkspaceRef>
     */
    private function references(array $ids, array $known): array
    {
        $refs = [];
        foreach ($known as $workspace) {
            if ($workspace instanceof Workspace) {
                $refs[$workspace->id] = new WorkspaceRef((string) $workspace->public_id, (string) $workspace->name);
            }
        }
        $missing = array_values(array_diff($ids, array_keys($refs)));
        if ($missing !== []) {
            foreach (Workspace::query()->whereIn('id', $missing)->get(['id', 'public_id', 'name']) as $workspace) {
                $refs[$workspace->id] = new WorkspaceRef((string) $workspace->public_id, (string) $workspace->name);
            }
        }

        return $refs;
    }
}
