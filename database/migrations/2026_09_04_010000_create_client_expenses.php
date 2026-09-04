<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reimbursable client expenses.
 *
 * `docs/client-management/overview.md` has described these since the port
 * began and the schema has never had a table for them. The source system held
 * zero expense rows, so nothing is being migrated and nothing was lost: this is
 * a new table with a product decision behind it rather than a port of an old
 * one.
 *
 * ## What an expense is here
 *
 * An amount in minor units, a date, an owning company, and optionally a
 * project. It is a pass-through: it reaches an invoice at cost. There is no
 * markup column, deliberately - markup is real money arithmetic with its own
 * rounding rules, and adding one doubles the test surface of every generator
 * that touches expenses. If a client ever needs it, it arrives with its own
 * rounding decision and its own tests.
 *
 * ## Why the lifecycle columns exist before anything moves them
 *
 * `status`, `approved_by_user_id` and `approved_at` mirror `client_time_entries`
 * exactly, because an expense reaches an invoice through the same gate: draft
 * work is recorded, approved work may be billed, and issuing an invoice rewrites
 * the row to `invoiced`. The columns land with the table so the states are one
 * vocabulary from the start rather than a widening migration later.
 *
 * **Nothing transitions them yet.** Approval and the claim/release rules that go
 * with draft-invoice regeneration wait for the centralized lock discipline in
 * #117, so the transition adopts it rather than growing a second convention that
 * has to be unpicked.
 *
 * ## The nullable columns, and what each null means
 *
 * - `client_project_id` - the expense is the company's, not any project's.
 *   Optional association, and its absence is the ordinary case rather than
 *   missing data.
 * - `created_by_user_id` and `approved_by_user_id` - the person is gone, or (for
 *   approval) has not acted yet. The expense is a financial record and outlives
 *   any account, so both are `ON DELETE SET NULL`.
 * - `approved_at` - not approved yet. Paired with `status`, which is the column
 *   anything guarding on approval reads; the timestamp says when, never whether.
 *
 * Everything a reader branches on is NOT NULL: amount, currency, date,
 * description and status. #115's caveat is about `NOT NULL`-ing columns on
 * imported historical rows; this table has none, so it can simply start
 * without the load-bearing nulls that caveat exists to unwind carefully.
 *
 * ## Tenant keys
 *
 * `(workspace_id, client_company_id)` is a composite key to
 * `client_companies (workspace_id, id)`, so the database refuses an expense
 * whose company belongs to another tenant - the invariant #113 established for
 * every new child table. The delete rule mirrors the single-column key beside
 * it, as it must: both are evaluated when a company row is deleted, and two
 * rules that disagree either block a delete the schema allows or over-reach one
 * it does not.
 *
 * `client_project_id` gets no composite key, for the reason every other nullable
 * project reference here goes without one: the column's rule is
 * `ON DELETE SET NULL`, and InnoDB refuses `SET NULL` on a foreign key
 * containing the NOT NULL `workspace_id` (errno 1830). It is registered as an
 * exemption in `App\Support\Tenancy\TenantReferenceInventory`, which means the
 * audit command still counts violations on it, and
 * `App\Queries\Expenses\WorkspaceExpenses` is what refuses to write one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_expenses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('spent_on');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->text('description');
            $table->string('status')->default('draft');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'spent_on']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'deleted_at']);
            $table->index(['client_company_id', 'spent_on']);

            // Serves the composite key below. InnoDB will not accept a foreign
            // key it has no index for and creates one itself when nothing has
            // those columns as a leftmost prefix - named after the constraint,
            // and left behind when the constraint is dropped. Declaring it here
            // means the index is a reviewable part of the schema and this
            // table's teardown removes everything its creation made.
            $table->index(['workspace_id', 'client_company_id'], 'cex_ws_company_idx');

            $table->foreign(['workspace_id', 'client_company_id'], 'cex_ws_company_fk')
                ->references(['workspace_id', 'id'])
                ->on('client_companies')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_expenses');
    }
};
