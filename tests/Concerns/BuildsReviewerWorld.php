<?php

namespace Tests\Concerns;

use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\ReviewerGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Review\ReviewerGrants;
use App\Support\Review\GranteeKind;
use Illuminate\Support\Str;

/**
 * Two providers who each bill a client called "Synthetic Vet" in their own
 * workspace, with nothing linking the two rows, and the person who is the link.
 *
 * Every string beginning SECRET- belongs to something a reviewer must never be
 * told; every string beginning VISIBLE- is a control that proves a surface was
 * not empty for the wrong reason. Today is 2026-11-06.
 */
trait BuildsReviewerWorld
{
    protected Workspace $herila;

    protected Workspace $otherEng;

    protected ClientCompany $herilaVet;

    protected ClientCompany $otherVet;

    protected ClientProject $herilaProject;

    protected ClientProject $otherProject;

    protected User $herilaOwner;

    protected User $herilaWorker;

    protected User $otherOwner;

    protected User $otherWorker;

    /** The vet's person, who belongs to neither workspace. */
    protected User $ben;

    protected function buildReviewerWorld(): void
    {
        $this->herila = $this->newWorkspace('Synthetic Herila WS');
        $this->otherEng = $this->newWorkspace('Synthetic Other Eng WS');
        $this->herilaVet = $this->newCompany($this->herila, 'Synthetic Vet');
        $this->otherVet = $this->newCompany($this->otherEng, 'Synthetic Vet');
        $this->herilaProject = $this->newProject($this->herilaVet, 'Synthetic Herila Project');
        $this->otherProject = $this->newProject($this->otherVet, 'Synthetic Other Project');
        $this->herilaOwner = $this->staff($this->herila, 'owner');
        $this->herilaWorker = $this->staff($this->herila, 'member');
        $this->otherOwner = $this->staff($this->otherEng, 'owner');
        $this->otherWorker = $this->staff($this->otherEng, 'member');
        $this->ben = User::factory()->create(['name' => 'Ben Synthetic', 'email' => 'ben@vet.example.test']);
    }

    protected function newWorkspace(string $name): Workspace
    {
        return Workspace::query()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'default_currency' => 'USD']);
    }

    protected function newCompany(Workspace $workspace, string $name, ?string $billing = null): ClientCompany
    {
        return ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'billing_email' => $billing]);
    }

    protected function newProject(ClientCompany $company, string $name): ClientProject
    {
        return ClientProject::query()->create(['workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'name' => $name, 'is_visible_to_client' => true]);
    }

    protected function staff(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create(['email' => Str::lower(Str::random(8)).'@staff.example.test']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    protected function hours(ClientProject $project, User $author, string $day, int $minutes = 60, array $overrides = []): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create($overrides + [
            'workspace_id' => $project->workspace_id, 'client_company_id' => $project->client_company_id, 'client_project_id' => $project->id,
            'user_id' => $author->id, 'worked_on' => $day, 'minutes' => $minutes,
            'description' => 'SECRET-INTERNAL-NOTE', 'client_visible_description' => 'VISIBLE-HOURS',
            'is_visible_to_client' => true, 'is_billable' => true, 'status' => 'approved',
            'billing_rate_amount' => 987654, 'currency' => 'USD',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function expense(ClientProject $project, User $author, string $day, int $amount = 5000, array $overrides = []): ClientExpense
    {
        return ClientExpense::query()->create($overrides + [
            'workspace_id' => $project->workspace_id, 'client_company_id' => $project->client_company_id, 'client_project_id' => $project->id,
            'created_by_user_id' => $author->id, 'spent_on' => $day, 'amount' => $amount, 'currency' => 'USD',
            'description' => 'VISIBLE-EXPENSE', 'status' => 'approved',
        ]);
    }

    protected function invoiceFor(ClientTimeEntry $entry, string $number = 'SECRET-INV-1', int $total = 10000): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $entry->workspace_id, 'client_company_id' => $entry->client_company_id, 'invoice_number' => $number, 'status' => 'issued',
            'currency' => 'USD', 'issue_date' => '2026-11-01', 'due_date' => '2026-11-30', 'subtotal_amount' => $total, 'tax_amount' => 0,
            'total_amount' => $total, 'paid_amount' => 0, 'balance_amount' => $total, 'is_visible_to_client' => true,
        ]);
        $line = ClientInvoiceLine::query()->create(['workspace_id' => $entry->workspace_id, 'client_invoice_id' => $invoice->id, 'type' => 'service', 'description' => 'Synthetic line', 'quantity' => 1, 'unit_amount' => $total, 'tax_amount' => 0, 'total_amount' => $total, 'sort_order' => 0]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $entry->workspace_id]);
        $entry->forceFill(['status' => 'invoiced'])->save();

        return $invoice;
    }

    protected function contactOf(ClientCompany $company, string $email, string $first = 'Ben', string $last = 'Synthetic'): ClientContact
    {
        return ClientContact::query()->create(['workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'first_name' => $first, 'last_name' => $last, 'email' => $email]);
    }

    /** Give a grant the way the screen does, as the workspace's owner. */
    protected function giveGrant(Workspace $workspace, User $owner, ClientCompany $company, string $email = 'ben@vet.example.test', ?ClientProject $project = null): ReviewerGrant
    {
        $contact = $this->contactOf($company, $email);

        return app(ReviewerGrants::class)->grant($owner, $workspace, $company->public_id, $project?->public_id, GranteeKind::Contact, $contact->public_id);
    }
}
