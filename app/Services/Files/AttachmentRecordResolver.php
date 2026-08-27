<?php

namespace App\Services\Files;

use App\Contracts\WorkspaceOwned;
use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\PortalAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class AttachmentRecordResolver
{
    public function __construct(private readonly PortalAccess $portalAccess) {}

    /** @var array<string, class-string<Model>> */
    private const RECORD_CLASSES = [
        'company' => ClientCompany::class,
        'project' => ClientProject::class,
        'task' => ClientTask::class,
        'proposal' => 'App\\Models\\ClientProposal',
        'agreement' => 'App\\Models\\ClientAgreement',
        'invoice' => 'App\\Models\\ClientInvoice',
    ];

    /** @return list<string> */
    public static function allowedTypes(): array
    {
        return array_keys(self::RECORD_CLASSES);
    }

    public function resolve(Workspace $workspace, string $recordType, string $recordPublicId): Model&WorkspaceOwned
    {
        $recordClass = self::RECORD_CLASSES[$recordType] ?? null;

        if ($recordClass === null || ! class_exists($recordClass)) {
            throw (new ModelNotFoundException)->setModel($recordClass ?? Model::class, [$recordPublicId]);
        }

        return $recordClass::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $recordPublicId)
            ->firstOrFail();
    }

    public function portalUserCanView(User $user, Workspace $workspace, ClientAttachment $attachment): bool
    {
        $record = $this->resolve($workspace, $attachment->record_type, $attachment->record_public_id);
        $company = match (true) {
            $record instanceof ClientCompany => $record,
            $record instanceof ClientProject => $record->clientCompany,
            $record instanceof ClientTask => $record->project->clientCompany,
            $record instanceof ClientProposal => $record->clientCompany,
            $record instanceof ClientAgreement => $record->clientCompany,
            $record instanceof ClientInvoice => $record->clientCompany,
            default => null,
        };

        if ($company === null || ! $company->portalUsers()->whereKey($user->id)->exists()) {
            return false;
        }

        return match (true) {
            $record instanceof ClientCompany => false,
            // Visibility is not enough: a project-scoped user must also have been
            // granted this project, or a held attachment URL still resolves.
            $record instanceof ClientProject => $record->is_visible_to_client
                && $this->portalAccess->canViewProject($user, $record),
            $record instanceof ClientTask => $record->is_visible_to_client
                && $record->project->is_visible_to_client
                && $this->portalAccess->canViewProject($user, $record->project),
            // A proposal or agreement scoped to a project is reachable only by
            // someone granted that project; one with no project belongs to the
            // company and stays company-wide.
            $record instanceof ClientProposal => $record->is_visible_to_client
                && in_array($record->status, ['sent', 'accepted'], true)
                && $this->portalCanReachProjectOf($user, $record->clientCompany, $record->client_project_id),
            $record instanceof ClientAgreement => $record->is_visible_to_client
                && in_array($record->status, ['active', 'paused', 'terminated', 'expired'], true)
                && $this->portalCanReachProjectOf($user, $record->clientCompany, $record->client_project_id),
            $record instanceof ClientInvoice => $record->is_visible_to_client
                && in_array($record->status, ['issued', 'partially_paid', 'paid'], true),
            default => false,
        };
    }

    /**
     * Can this portal user reach a record tied to this project?
     *
     * Null project means the record belongs to the company rather than to any
     * one project, and stays visible to every portal user of that company.
     */
    private function portalCanReachProjectOf(?User $user, ?ClientCompany $company, ?int $projectId): bool
    {
        if ($projectId === null) {
            return true;
        }

        if (! $company instanceof ClientCompany) {
            return false;
        }

        $allowed = $this->portalAccess->visibleProjectIds($company, $user);

        return $allowed === null || in_array($projectId, $allowed, true);
    }
}
