<?php

namespace App\Console\Commands;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Narrows a portal user to specific projects, or puts them back to seeing the
 * whole company.
 *
 * Without this the restriction is unreachable: `access_scope` defaults to
 * `company` and nothing in the application writes it, so the enforcement would
 * sit in the code with no way to switch it on short of editing production
 * tables by hand.
 */
final class PortalProjectAccessCommand extends Command
{
    protected $signature = 'svc:portal:project-access
        {company : Client company public id}
        {email : Portal user email}
        {--project=* : Project public ids the user may see; repeatable}
        {--company-wide : Return the user to seeing every client-visible project}
        {--show : Report the current scope without changing it}';

    protected $description = 'Limit a portal user to specific projects, or restore company-wide access';

    public function handle(): int
    {
        $company = ClientCompany::query()->where('public_id', $this->argument('company'))->first();
        if (! $company instanceof ClientCompany) {
            $this->components->error('No client company matches that public id.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();
        if (! $user instanceof User) {
            $this->components->error('No user matches that email.');

            return self::FAILURE;
        }

        $membership = ClientCompanyMembership::query()
            ->where('client_company_id', $company->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $membership instanceof ClientCompanyMembership) {
            $this->components->error('That user is not a portal member of that company.');

            return self::FAILURE;
        }

        if ($this->option('show')) {
            return $this->report($company, $membership);
        }

        if ($this->option('company-wide')) {
            DB::transaction(function () use ($membership): void {
                DB::table('client_portal_project_access')
                    ->where('client_company_membership_id', $membership->id)
                    ->delete();
                $membership->forceFill(['access_scope' => ClientCompanyMembership::SCOPE_COMPANY])->save();
            });

            $this->components->info('That user sees every client-visible project again.');

            return self::SUCCESS;
        }

        /** @var list<string> $publicIds */
        $publicIds = (array) $this->option('project');
        if ($publicIds === []) {
            $this->components->error('Give at least one --project, or --company-wide to remove the restriction.');

            return self::FAILURE;
        }

        $projects = ClientProject::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->whereIn('public_id', $publicIds)
            ->get();

        if ($projects->count() !== count(array_unique($publicIds))) {
            $this->components->error('One or more projects do not belong to that company.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($company, $membership, $projects): void {
            $membership->forceFill(['access_scope' => ClientCompanyMembership::SCOPE_PROJECTS])->save();

            // Replace rather than add: the granted set is what was asked for.
            DB::table('client_portal_project_access')
                ->where('client_company_membership_id', $membership->id)
                ->delete();

            foreach ($projects as $project) {
                DB::table('client_portal_project_access')->insert([
                    'workspace_id' => $company->workspace_id,
                    'client_company_membership_id' => $membership->id,
                    'client_project_id' => $project->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->components->info(sprintf('That user now sees %d project(s) and nothing else.', $projects->count()));

        return self::SUCCESS;
    }

    private function report(ClientCompany $company, ClientCompanyMembership $membership): int
    {
        $this->components->twoColumnDetail('scope', (string) $membership->access_scope);

        if ($membership->access_scope !== ClientCompanyMembership::SCOPE_PROJECTS) {
            $this->line('  Sees every client-visible project the company owns.');

            return self::SUCCESS;
        }

        $names = ClientProject::query()
            ->where('client_company_id', $company->id)
            ->whereIn('id', DB::table('client_portal_project_access')
                ->where('client_company_membership_id', $membership->id)
                ->select('client_project_id'))
            ->orderBy('name')
            ->pluck('name');

        if ($names->isEmpty()) {
            $this->line('  Granted nothing, so this user currently sees no projects.');

            return self::SUCCESS;
        }

        foreach ($names as $name) {
            $this->line("  {$name}");
        }

        return self::SUCCESS;
    }
}
