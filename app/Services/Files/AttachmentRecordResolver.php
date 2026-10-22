<?php

namespace App\Services\Files;

use App\Contracts\WorkspaceOwned;
use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class AttachmentRecordResolver
{
    /** @var array<string, class-string<Model>> */
    private const RECORD_CLASSES = [
        'company' => ClientCompany::class,
        'expense' => ClientExpense::class,
        'project' => ClientProject::class,
        'task' => ClientTask::class,
        'proposal' => 'App\\Models\\ClientProposal',
        'agreement' => 'App\\Models\\ClientAgreement',
        'invoice' => 'App\\Models\\ClientInvoice',
        'topic' => ClientCollaborationTopic::class,
        'comment' => ClientComment::class,
    ];

    /** @return list<string> */
    public static function allowedTypes(): array
    {
        return self::uploadableTypes();
    }

    /** Reader support does not authorize creation of new parent types.
     * @return list<string>
     */
    public static function readableTypes(): array
    {
        return array_keys(self::RECORD_CLASSES);
    }

    /** @return list<string> */
    public static function uploadableTypes(): array
    {
        $types = array_diff(self::readableTypes(), ['topic', 'comment']);

        // @infection-ignore-all Equivalent reindex: the closed readable list removes only its final two entries, so array_diff already has consecutive keys; array_values states the list type for static analysis.
        return array_values($types);
    }

    public function resolve(Workspace $workspace, string $recordType, string $recordPublicId): Model&WorkspaceOwned
    {
        $recordClass = self::RECORD_CLASSES[$recordType] ?? null;

        if ($recordClass === null || ! class_exists($recordClass)) {
            throw (new ModelNotFoundException)->setModel($recordClass ?? Model::class, [$recordPublicId]);
        }

        $record = $recordClass::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $recordPublicId)
            ->firstOrFail();

        if ($record instanceof ClientCollaborationTopic || $record instanceof ClientComment) {
            $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($record->client_company_id)->firstOrFail();
            $repository = app(CollaborationRepository::class);
            if ($record instanceof ClientComment) {
                $repository->commentParent($workspace, $company, $record);
            } else {
                $repository->topicProject($workspace, $company, $record);
            }
        } elseif ($record instanceof ClientTask) {
            $record->setRelation('project', $this->projectIn($workspace, $record->client_project_id));
        } elseif ($record instanceof ClientProject || $record instanceof ClientExpense
            || $record instanceof ClientProposal || $record instanceof ClientAgreement || $record instanceof ClientInvoice) {
            $company = ClientCompany::query()->where('workspace_id', $workspace->id)
                ->whereKey($record->client_company_id)->firstOrFail();
            $record->setRelation('clientCompany', $company);
            if (($record instanceof ClientProposal || $record instanceof ClientAgreement) && $record->client_project_id !== null) {
                $record->setRelation('project', $this->projectIn($workspace, $record->client_project_id, $company->id));
            }
        }

        return $record;
    }

    private function projectIn(Workspace $workspace, int $projectId, ?int $companyId = null): ClientProject
    {
        return ClientProject::query()->where('workspace_id', $workspace->id)->whereKey($projectId)
            ->when($companyId !== null, fn (Builder $projects): Builder => $projects->where('client_company_id', $companyId))
            ->whereHas('clientCompany', fn (Builder $companies): Builder => $companies->where('workspace_id', $workspace->id))
            ->with('clientCompany')->firstOrFail();
    }

    public function portalUserCanView(User $user, Workspace $workspace, ClientAttachment $attachment): bool
    {
        $this->resolve($workspace, $attachment->record_type, $attachment->record_public_id);

        return app(AttachmentReader::class)->canView($user, $workspace, $attachment, AttachmentAudience::Portal);
    }

    /** A held file URL carries no authority beyond its current parent and flag. */
    public function userCanView(User $user, Workspace $workspace, ClientAttachment $attachment): bool
    {
        $this->resolve($workspace, $attachment->record_type, $attachment->record_public_id);

        return app(AttachmentReader::class)->canView($user, $workspace, $attachment, AttachmentAudience::Native);
    }
}
