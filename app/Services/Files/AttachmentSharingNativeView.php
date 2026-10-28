<?php

namespace App\Services\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentPageBudget;
use App\Support\AgentApi\CursorPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Initial bounded data is embedded in Inertia props; continuation is a finished URL. */
final class AttachmentSharingNativeView
{
    public function __construct(private readonly AttachmentSharingTransport $files, private readonly AttachmentReader $reader,
        private readonly AttachmentSharingPresenter $presenter) {}

    /** @return array<string,mixed> */
    public function collaboration(User $actor, Workspace $workspace, AttachmentParentReference $reference,
        AttachmentAudience $audience, ?AttachmentPageQuery $page = null): array
    {
        $result = $this->files->listing($actor, $workspace, $reference, $audience, $page);
        $view = $this->presenter->view($actor, $workspace, $reference, $audience);
        $ids = array_column($result['data'], 'id');
        $rows = $this->reader->forParent($actor, $workspace, $reference->type, $reference->publicId, $audience)
            ->whereIn('public_id', $ids)->get()->keyBy('public_id');
        $parameters = ['workspace' => $workspace, 'clientCompany' => $reference->companyId,
            'recordType' => $reference->type, 'recordPublicId' => $reference->publicId];
        $portal = in_array($audience, [AttachmentAudience::Portal, AttachmentAudience::PortalPreview], true);
        $readParameters = $portal ? ['clientCompany' => $reference->companyId, 'recordType' => $reference->type,
            'recordPublicId' => $reference->publicId] : $parameters;
        $files = [];
        foreach ($ids as $id) {
            $file = $rows->get($id);
            if (! $file instanceof ClientAttachment) {
                continue;
            }
            $sharing = $portal ? null : $this->presenter->nativeControl($file, $view,
                route('svc.files.collaboration.visibility.update', [...$parameters, 'attachment' => $id], absolute: false), $audience);
            $files[] = AttachmentMetadata::native($file,
                $portal ? route('portal.files.collaboration.download', [...$readParameters, 'attachment' => $id], absolute: false)
                    : route('svc.files.download', [$workspace, $id], absolute: false),
                $sharing === null ? null : route('svc.files.collaboration.destroy', [...$parameters, 'attachment' => $id], absolute: false), $sharing);
        }
        $canWrite = $audience === AttachmentAudience::Internal && $view->canPublish && AttachmentSharingGate::available();
        $cursor = $result['meta']['next_cursor'];

        return ['files' => $files, 'parent_version' => AgentApiVersion::for($view->parent),
            'upload' => $canWrite ? ['href' => route('svc.files.collaboration.upload', $parameters, absolute: false),
                'parent_version' => AgentApiVersion::for($view->parent)] : null,
            'next_href' => $cursor === null ? null : route($portal ? 'portal.files.collaboration.index' : 'svc.files.collaboration.index', [...$readParameters,
                'limit' => $page?->limit ?? 100, 'cursor' => $cursor], absolute: false)];
    }

    /** Current client-safe parent access precedes attachment rows or bytes. */
    public function portalParent(User $actor, ClientCompany $company, string $type, string $id): Model
    {
        abort_unless(in_array($type, ['proposal', 'invoice'], true), 404);
        $parent = app(AttachmentRecordResolver::class)->resolve($company->workspace, $type, $id);
        abort_unless(($parent instanceof ClientProposal || $parent instanceof ClientInvoice) && $parent->client_company_id === $company->id, 404);
        $this->reader->assertParentAccess($actor, $company->workspace, $type, $id, $this->reader->nativePortalAudience($actor, $company->workspace));

        return $parent;
    }

    /** Read-only native Portal files, with no internal sharing notes or writer capabilities.
     * @return array<string,mixed> */
    public function portalLegacy(User $actor, ClientCompany $company, string $type, string $id, ?AttachmentPageQuery $page = null): array
    {
        $parent = $this->portalParent($actor, $company, $type, $id);
        $workspace = $company->workspace;
        $audience = $this->reader->nativePortalAudience($actor, $workspace);
        $query = $this->reader->forParent($actor, $workspace, $type, $id, $audience);
        $page ??= AttachmentPageQuery::fromInput(['limit' => 25]);
        $parameters = ['clientCompany' => $company, 'recordType' => $type, 'recordPublicId' => $id];
        $result = CursorPage::run($workspace, implode(':', ['native-portal-files', $company->public_id, $type, $id, $actor->public_id, $audience->name, 'id-desc']), $page->limit, $page->cursor,
            fn (int $take, ?int $after) => (clone $query)->when($after !== null, fn (Builder $rows) => $rows->where('id', '<', $after))->orderByDesc('id')->limit($take)->get(),
            fn (ClientAttachment $file): array => AttachmentMetadata::native($file, route('portal.files.parent.download', [...$parameters, 'attachment' => $file->public_id], absolute: false), null, null),
            maxDataBytes: AgentPageBudget::dataBytes(), allowLegacyCursor: false);

        return ['files' => $result['data'], 'upload' => null, 'parent_version' => AgentApiVersion::for($parent),
            'next_href' => $result['meta']['next_cursor'] === null ? null : route('portal.files.parent.index', [...$parameters, 'limit' => $page->limit, 'cursor' => $result['meta']['next_cursor']], absolute: false)];
    }

    /** Commercial pages may remain readable for repairing malformed old project references.
     * @return array<string,mixed>|null */
    public function legacyIfReadable(User $actor, Workspace $workspace, AttachmentParentReference $reference): ?array
    {
        try {
            return $this->legacy($actor, $workspace, $reference);
        } catch (ModelNotFoundException) {
            return null;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 404) {
                throw $exception;
            }

            return null;
        }
    }

    /** Legacy native forms retain their existing upload/deletion contracts.
     * @return array<string,mixed> */
    public function legacy(User $actor, Workspace $workspace, AttachmentParentReference $reference, int $page = 1): array
    {
        abort_unless(in_array($reference->type, ['company', 'project', 'task', 'proposal', 'agreement', 'invoice'], true), 404);
        $view = $this->presenter->view($actor, $workspace, $reference, AttachmentAudience::Internal);
        $rows = $this->reader->forParent($actor, $workspace, $reference->type, $reference->publicId, AttachmentAudience::Internal)
            ->orderByDesc('id')->simplePaginate(100, ['*'], 'page', $page);
        $files = [];
        foreach ($rows->items() as $file) {
            $files[] = AttachmentMetadata::native($file, route('svc.files.download', [$workspace, $file->public_id], absolute: false),
                $view->canPublish ? route('svc.files.destroy', [$workspace, $file->public_id], absolute: false) : null,
                $this->presenter->nativeControl($file, $view, route('svc.files.visibility.update', [
                    'workspace' => $workspace, 'attachment' => $file->public_id], absolute: false), AttachmentAudience::Internal));
        }
        $parameters = ['workspace' => $workspace, 'recordType' => $reference->type, 'recordPublicId' => $reference->publicId];

        return ['files' => $files, 'upload' => null, 'parent_version' => AgentApiVersion::for($view->parent),
            'next_href' => $rows->hasMorePages() ? route('svc.files.parent.index', [...$parameters, 'page' => $page + 1], absolute: false) : null,
            'legacy_upload_href' => $view->canPublish ? route('svc.files.store', $parameters, absolute: false) : null];
    }
}
