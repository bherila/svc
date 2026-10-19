<?php

namespace App\Console\Commands;

use App\Exceptions\AccessMutationRefused;
use App\Models\ClientCompany;
use App\Models\User;
use App\Services\Access\PortalAccessOperation;
use App\Services\AgentApi\AgentPortalContactReadService;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Narrows a portal user to specific projects, or puts them back to seeing the
 * whole company.
 *
 * The operator's way to make the change the client's settings page, REST and
 * MCP make as `clients.portal_access.update`: the same
 * {@see PortalAccessOperation}, with the same fields and validation - the
 * contact's current version (printed by `--show`) and explicit confirmation -
 * run as the system actor, still bound by the access boundary's tenant
 * scoping, locks and audit trail.
 */
final class PortalProjectAccessCommand extends Command
{
    protected $signature = 'svc:portal:project-access
        {company : Client company public id}
        {email : Portal user email}
        {--project=* : Project public ids the user may see; repeatable}
        {--company-wide : Return the user to seeing every client-visible project}
        {--expected-version= : The contact\'s current version, as --show prints it}
        {--confirm : Confirm the change}
        {--show : Report the current scope and version without changing it}';

    protected $description = 'Limit a portal user to specific projects, or restore company-wide access';

    public function handle(PortalAccessOperation $operation, AgentPortalContactReadService $contacts): int
    {
        $company = ClientCompany::query()->where('public_id', $this->argument('company'))->first();
        if (! $company instanceof ClientCompany) {
            $this->components->error('No client company matches that public id.');

            return self::FAILURE;
        }

        // Scoped on the workspace as well as the company, as every caller of
        // the operation resolves the person.
        $user = $operation->memberByEmail($company, (string) $this->argument('email'));
        if (! $user instanceof User) {
            $this->components->error('That user is not a portal member of that company.');

            return self::FAILURE;
        }

        if ($this->option('show')) {
            return $this->report($contacts->contact($company->workspace()->firstOrFail(), $company, $user->public_id));
        }

        /** @var list<string> $projects */
        $projects = (array) $this->option('project');
        if ($projects === [] && ! $this->option('company-wide')) {
            $this->components->error('Give at least one --project, or --company-wide to remove the restriction.');

            return self::FAILURE;
        }

        $companyWide = (bool) $this->option('company-wide');
        $payload = [
            'scope' => ($companyWide ? PortalAccessScope::Company : PortalAccessScope::Projects)->value,
            'projects' => $projects,
            'expected_version' => $this->option('expected-version'),
            'confirm' => (bool) $this->option('confirm'),
        ];

        try {
            $operation->perform(AccessMutationContext::system(), $company, PortalAccessOperation::UPDATE, $user->public_id, $payload);
        } catch (ValidationException $invalid) {
            foreach ($invalid->errors() as $field => $messages) {
                $this->components->error(self::flag($field).': '.implode(' ', $messages));
            }

            return self::FAILURE;
        } catch (AccessMutationRefused $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        } catch (HttpException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info($companyWide
            ? 'That user sees every client-visible project again.'
            : sprintf('That user now sees %d project(s) and nothing else.', count($projects)));

        return self::SUCCESS;
    }

    /** The command-line spelling of an operation field. */
    private static function flag(string $field): string
    {
        return match (true) {
            $field === 'expected_version' => '--expected-version',
            $field === 'confirm' => '--confirm',
            str_starts_with($field, 'projects') => '--project',
            default => $field,
        };
    }

    /** @param array<string, mixed> $contact the contact as the API presents it */
    private function report(array $contact): int
    {
        $this->components->twoColumnDetail('scope', (string) $contact['scope']);
        $this->components->twoColumnDetail('version', (string) $contact['version']);

        /** @var list<array{name: string}> $projects */
        $projects = $contact['projects'];
        if ($contact['scope'] !== PortalAccessScope::Projects->value) {
            $this->line('  Sees every client-visible project the company owns.');

            return self::SUCCESS;
        }

        if ($projects === []) {
            $this->line('  Granted nothing, so this user currently sees no projects.');

            return self::SUCCESS;
        }

        foreach (collect($projects)->pluck('name')->sort()->values() as $name) {
            $this->line('  '.$name);
        }

        return self::SUCCESS;
    }
}
