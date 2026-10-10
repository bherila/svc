<?php

namespace App\Services\AgentApi\Operations;

use App\Services\Mcp\AgentMcpAgreementResource;
use App\Services\Mcp\AgentMcpAgreementTools;
use App\Services\Mcp\AgentMcpBillingAuditTools;
use App\Services\Mcp\AgentMcpBillingScheduleTools;
use App\Services\Mcp\AgentMcpCapacityLedgerTools;
use App\Services\Mcp\AgentMcpClientTools;
use App\Services\Mcp\AgentMcpClientWriteTools;
use App\Services\Mcp\AgentMcpContextResource;
use App\Services\Mcp\AgentMcpPrompts;
use App\Services\Mcp\AgentMcpReadTools;
use App\Services\Mcp\AgentMcpWriteTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Bherila\McpLaravelBridge\Capabilities\McpBinding;
use Bherila\McpLaravelBridge\Capabilities\McpKind;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Bherila\McpLaravelBridge\Capabilities\WriteSafety;
use LogicException;

/**
 * Every agent operation, declared once (#408).
 *
 * REST routes, the MCP tools, resources and prompts, the route gate and the
 * withheld tools context.get reports are all derived from these declarations
 * by the shared operation registry. The OpenAPI document stays the contract:
 * documented operations take their scopes, request and response schemas from
 * it (spec-first SchemaRef), so a declaration here never restates them.
 *
 * Each declaration names its deployment switches: the write cutovers it sits
 * behind ({@see AgentDeploymentFlags}) and, for MCP, the transport, its group
 * and its own name. A tool behind a switched-off cutover is withheld from
 * agents everywhere at once - tools/list, prompts that need it, context.get -
 * and its REST route answers 404 for OAuth clients.
 */
final class AgentOperationCatalog
{
    private const string ROUTE_PREFIX = 'agent-api.v1.';

    /**
     * REST binding of each documented operation: route name (after the
     * agent-api.v1. prefix), path relative to /api/v1 with the controllers'
     * route parameters, and success statuses.
     *
     * @var array<string, array{0: string, 1: string, 2: list<int>}>
     */
    private const array ROUTES = [
        'agreements.activate' => ['agreements.activate', '/workspaces/{workspace}/agreements/{agreement}/activate', [200]],
        'agreements.create' => ['agreements.store', '/workspaces/{workspace}/clients/{client}/agreements', [201]],
        'agreements.get' => ['agreements.show', '/workspaces/{workspace}/agreements/{agreement}', [200]],
        'agreements.list' => ['agreements.index', '/workspaces/{workspace}/agreements', [200]],
        'agreements.terminate' => ['agreements.terminate', '/workspaces/{workspace}/agreements/{agreement}/terminate', [200]],
        'agreements.update' => ['agreements.update', '/workspaces/{workspace}/agreements/{agreement}', [200]],
        'attachments.delete' => ['attachments.destroy', '/workspaces/{workspace}/attachments/{attachment}', [202]],
        'attachments.download' => ['attachments.content', '/workspaces/{workspace}/attachments/{attachment}/content', [200]],
        'attachments.download_url' => ['attachments.download-url', '/workspaces/{workspace}/attachments/{attachment}/download-url', [200]],
        'attachments.get' => ['attachments.show', '/workspaces/{workspace}/attachments/{attachment}', [200]],
        'attachments.list' => ['attachments.index', '/workspaces/{workspace}/records/{recordType}/{recordPublicId}/attachments', [200]],
        'attachments.upload' => ['attachments.upload', '/workspaces/{workspace}/records/{recordType}/{recordPublicId}/attachments/upload', [201]],
        'attachments.upload_url' => ['attachments.upload-url', '/workspaces/{workspace}/records/{recordType}/{recordPublicId}/attachments/upload-url', [200]],
        'billing_audit.stale_and_missing' => ['billing_audit.stale_and_missing', '/workspaces/{workspace}/billing-audits/stale-and-missing', [200]],
        'billing_schedules.create' => ['billing-schedules.store', '/workspaces/{workspace}/billing-schedules', [201]],
        'billing_schedules.generate' => ['billing-schedules.generate', '/workspaces/{workspace}/billing-schedules/{schedule}/generate', [200]],
        'billing_schedules.get' => ['billing-schedules.show', '/workspaces/{workspace}/billing-schedules/{schedule}', [200]],
        'billing_schedules.list' => ['billing-schedules.index', '/workspaces/{workspace}/billing-schedules', [200]],
        'clients.archive' => ['clients.archive', '/workspaces/{workspace}/clients/{client}/archive', [200]],
        'clients.create' => ['clients.store', '/workspaces/{workspace}/clients', [201]],
        'clients.get' => ['clients.show', '/workspaces/{workspace}/clients/{client}', [200]],
        'clients.list' => ['clients.index', '/workspaces/{workspace}/clients', [200]],
        'clients.restore' => ['clients.restore', '/workspaces/{workspace}/clients/{client}/restore', [200]],
        'clients.update' => ['clients.update', '/workspaces/{workspace}/clients/{client}', [200]],
        'connections.revoke' => ['connections.destroy', '/connections/{token}', [204]],
        'context.get' => ['context', '/context', [200]],
        'expense_schedules.create' => ['expense-schedules.store', '/workspaces/{workspace}/expense-schedules', [201]],
        'expense_schedules.generate' => ['expense-schedules.generate', '/workspaces/{workspace}/expense-schedules/{schedule}/generate', [200]],
        'expense_schedules.list' => ['expense-schedules.index', '/workspaces/{workspace}/expense-schedules', [200]],
        'expense_schedules.update' => ['expense-schedules.update', '/workspaces/{workspace}/expense-schedules/{schedule}', [200]],
        'expenses.approve' => ['expenses.approve', '/workspaces/{workspace}/expenses/{expense}/approve', [200]],
        'expenses.delete' => ['expenses.destroy', '/workspaces/{workspace}/expenses/{expense}', [200]],
        'expenses.list' => ['expenses.index', '/workspaces/{workspace}/expenses', [200]],
        'expenses.log' => ['expenses.store', '/workspaces/{workspace}/expenses', [201]],
        'expenses.receipts.content' => ['expense-receipts.content', '/workspaces/{workspace}/expenses/{expense}/receipts/{receipt}/content', [200]],
        'expenses.receipts.download' => ['expense-receipts.download', '/workspaces/{workspace}/expenses/{expense}/receipts/{receipt}', [200]],
        'expenses.receipts.list' => ['expense-receipts.index', '/workspaces/{workspace}/expenses/{expense}/receipts', [200]],
        'expenses.receipts.upload' => ['expense-receipts.store', '/workspaces/{workspace}/expenses/{expense}/receipts', [201]],
        'expenses.receipts.upload_url' => ['expense-receipts.upload-url', '/workspaces/{workspace}/expenses/{expense}/receipts/upload-url', [200]],
        'expenses.unapprove' => ['expenses.unapprove', '/workspaces/{workspace}/expenses/{expense}/unapprove', [200]],
        'expenses.update' => ['expenses.update', '/workspaces/{workspace}/expenses/{expense}', [200]],
        'invoices.add_time' => ['invoices.add_time', '/workspaces/{workspace}/invoices/{invoice}/time', [200]],
        'invoices.correct' => ['invoices.correct', '/workspaces/{workspace}/invoices/{invoice}/correct', [200]],
        'invoices.create_draft' => ['invoices.store', '/workspaces/{workspace}/invoices', [201]],
        'invoices.discard_draft' => ['invoices.discard', '/workspaces/{workspace}/invoices/{invoice}/discard', [200]],
        'invoices.download_pdf' => ['invoices.download_pdf', '/workspaces/{workspace}/invoices/{invoice}/pdf', [200]],
        'invoices.generate_period' => ['invoices.generate_period', '/workspaces/{workspace}/agreements/{agreement}/invoices', [201]],
        'invoices.get' => ['invoices.show', '/workspaces/{workspace}/invoices/{invoice}', [200]],
        'invoices.hold_delivery' => ['invoices.hold_delivery', '/workspaces/{workspace}/invoices/{invoice}/automatic-delivery/hold', [200]],
        'invoices.issue' => ['invoices.issue', '/workspaces/{workspace}/invoices/{invoice}/issue', [200]],
        'invoices.list' => ['invoices.index', '/workspaces/{workspace}/invoices', [200]],
        'invoices.pdf' => ['invoices.pdf', '/workspaces/{workspace}/invoices/{invoice}/pdf-link', [200]],
        'invoices.release_delivery' => ['invoices.release_delivery', '/workspaces/{workspace}/invoices/{invoice}/automatic-delivery/release', [200]],
        'invoices.send' => ['invoices.send', '/workspaces/{workspace}/invoices/{invoice}/send', [200]],
        'invoices.update_details' => ['invoices.update_details', '/workspaces/{workspace}/invoices/{invoice}/details', [200]],
        'invoices.update_draft' => ['invoices.update', '/workspaces/{workspace}/invoices/{invoice}', [200]],
        'invoices.void' => ['invoices.void', '/workspaces/{workspace}/invoices/{invoice}/void', [200]],
        'operations.summary' => ['workspaces.summary', '/workspaces/{workspace}/summary', [200]],
        'payments.correct' => ['payments.update', '/workspaces/{workspace}/payments/{payment}', [200]],
        'payments.list' => ['payments.index', '/workspaces/{workspace}/payments', [200]],
        'payments.record' => ['payments.store', '/workspaces/{workspace}/payments', [201]],
        'projects.archive' => ['projects.archive', '/workspaces/{workspace}/projects/{project}/archive', [200]],
        'projects.create' => ['projects.store', '/workspaces/{workspace}/projects', [201]],
        'projects.get' => ['projects.show', '/workspaces/{workspace}/projects/{project}', [200]],
        'projects.list' => ['projects.index', '/workspaces/{workspace}/projects', [200]],
        'projects.members.list' => ['projects.members.index', '/workspaces/{workspace}/projects/{project}/members', [200]],
        'projects.members.update' => ['projects.members.update', '/workspaces/{workspace}/projects/{project}/members', [200]],
        'projects.update' => ['projects.update', '/workspaces/{workspace}/projects/{project}', [200]],
        'proposals.accept' => ['proposals.accept', '/workspaces/{workspace}/proposals/{proposal}/accept', [200]],
        'proposals.create' => ['proposals.store', '/workspaces/{workspace}/proposals', [201]],
        'proposals.get' => ['proposals.show', '/workspaces/{workspace}/proposals/{proposal}', [200]],
        'proposals.list' => ['proposals.index', '/workspaces/{workspace}/proposals', [200]],
        'proposals.send' => ['proposals.send', '/workspaces/{workspace}/proposals/{proposal}/send', [200]],
        'search' => ['search', '/workspaces/{workspace}/search', [200]],
        'tasks.create' => ['tasks.store', '/workspaces/{workspace}/projects/{project}/tasks', [201]],
        'tasks.get' => ['tasks.show', '/workspaces/{workspace}/tasks/{task}', [200]],
        'tasks.list' => ['tasks.index', '/workspaces/{workspace}/tasks', [200]],
        'tasks.update' => ['tasks.update', '/workspaces/{workspace}/tasks/{task}', [200]],
        'time_entries.approve' => ['time-entries.approve', '/workspaces/{workspace}/time-entries/approve', [200]],
        'time_entries.delete' => ['time-entries.destroy', '/workspaces/{workspace}/time-entries/{entry}', [200]],
        'time_entries.list' => ['time-entries.index', '/workspaces/{workspace}/time-entries', [200]],
        'time_entries.log' => ['time-entries.store', '/workspaces/{workspace}/time-entries', [201]],
        'time_entries.unapprove' => ['time-entries.unapprove', '/workspaces/{workspace}/time-entries/{entry}/unapprove', [200]],
        'time_entries.update' => ['time-entries.update', '/workspaces/{workspace}/time-entries/{entry}', [200]],
        'workspaces.create' => ['workspaces.store', '/workspaces', [201]],
    ];

    private ?OperationRegistry $registry = null;

    /** @var array<string, true> */
    private array $managerOnly = [];

    public function registry(): OperationRegistry
    {
        return $this->registry ??= $this->build();
    }

    /** Whether the operation is only ever offered to someone who manages a workspace. */
    public function isManagerOnly(string $operationId): bool
    {
        $this->registry();

        return isset($this->managerOnly[$operationId]);
    }

    private function build(): OperationRegistry
    {
        $registry = new OperationRegistry;
        $registry->register(...$this->tools(), ...$this->restOnly(), ...$this->resourcesAndPrompts());
        $documented = array_keys(AgentApiResponseSchemaCatalog::catalog()->operations());
        $bound = array_keys(self::ROUTES);
        sort($documented);
        sort($bound);
        if ($documented !== $bound) {
            throw new LogicException('Every documented operation needs a REST binding, and nothing else may have one.');
        }

        return $registry;
    }

    /** @return list<Operation> */
    private function tools(): array
    {
        return [
            $this->tool(
                'context.get',
                'Get context',
                'Get the authorized identity, workspaces, roles, and capabilities. Call this before selecting a workspace.',
                [AgentMcpReadTools::class, 'context'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'operations.summary',
                'Get workspace summary',
                'Get the role- and scope-filtered operational summary for one workspace.',
                [AgentMcpReadTools::class, 'summary'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'projects.list',
                'List projects',
                'List authorized projects with bounded cursor pagination.',
                [AgentMcpReadTools::class, 'projects'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'projects.members.list',
                'List project access',
                'List eligible workspace members and their current project roles as a workspace owner/admin, with bounded pagination. Includes none for members without project access; returns the current project version.',
                [AgentMcpReadTools::class, 'projectMembers'],
                Effect::Read,
                true,
                'mcp.read',
                manager: true,
            ),
            $this->tool(
                'projects.get',
                'Get project',
                'Get one authorized project and its visible tasks.',
                [AgentMcpReadTools::class, 'project'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'tasks.list',
                'List tasks',
                'List authorized tasks with bounded cursor pagination.',
                [AgentMcpReadTools::class, 'tasks'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'tasks.get',
                'Get task',
                'Get one authorized task.',
                [AgentMcpReadTools::class, 'task'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'time_entries.list',
                'List time entries',
                'List authorized time entries and their tenant-owned invoice allocation with bounded cursor pagination. Use unallocated: true for exactly the approved, priced, billable, non-deferred work counted in operations.summary; allocation_state filters all rows by allocation, including draft and deferred work.',
                [AgentMcpReadTools::class, 'timeEntries'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'proposals.list',
                'List proposals',
                'List authorized proposals with their current version and commercial terms. Portal members see only sent or accepted proposals within their company and project grants.',
                [AgentMcpReadTools::class, 'proposalsList'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'proposals.get',
                'Get proposal',
                'Read an authorized proposal and current version before sending or accepting.',
                [AgentMcpReadTools::class, 'proposalsGet'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'expenses.list',
                'List expenses',
                'List authorized expenses with bounded cursor pagination. Unattributed expenses are visible only to workspace managers.',
                [AgentMcpReadTools::class, 'expensesList'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'payments.list',
                'List received payments',
                'List payments for an explicit invoice or client company, following invoice visibility with bounded pagination. No private reconciliation or processor identifiers are returned.',
                [AgentMcpReadTools::class, 'paymentsList'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'invoices.list',
                'List invoices',
                'List authorized invoices with bounded cursor pagination.',
                [AgentMcpReadTools::class, 'invoices'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'invoices.pdf',
                'Get invoice PDF link',
                'Return a signed invoice PDF download URL valid for five minutes. The download requires the current authenticated connection or browser session and rechecks invoice visibility.',
                [AgentMcpReadTools::class, 'invoicesPdf'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'billing_audit.stale_and_missing',
                'Audit stale drafts and missing periods',
                'Workspace managers only. Count past-due drafts and active recurring agreements lacking a non-void invoice for their current retainer period, with balances per currency and up to 100 identifiers per category.',
                [AgentMcpReadTools::class, 'billingAuditStaleAndMissing'],
                Effect::Read,
                true,
                'mcp.read',
                manager: true,
            ),
            $this->tool(
                'invoices.get',
                'Get invoice',
                'Get one authorized invoice. The response includes a browser URL for paying. See context.get withheld_tools for payment-recording availability; payment recording never initiates a charge.',
                [AgentMcpReadTools::class, 'invoice'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'search',
                'Search workspace',
                'Search up to five authorized results per kind in one workspace. Each kind also requires its read scope; missing scopes produce no results for that kind.',
                [AgentMcpWriteTools::class, 'search'],
                Effect::Read,
                true,
                'mcp.read',
            ),
            $this->tool(
                'attachments.list',
                'List attachments',
                'List up to 100 generic files on a tenant-owned record, with its parent version. Workspace managers only; expense receipts use separate tools.',
                [AgentMcpWriteTools::class, 'attachmentsList'],
                Effect::Read,
                true,
                'mcp.read',
                manager: true,
            ),
            $this->tool(
                'attachments.get',
                'Get attachment',
                'Get generic file metadata and current version. Workspace managers only.',
                [AgentMcpWriteTools::class, 'attachmentsGet'],
                Effect::Read,
                true,
                'mcp.read',
                manager: true,
            ),
            $this->tool(
                'attachments.download_url',
                'Get attachment download URL',
                'Prepare a ten-minute signed download URL. Use the same bearer credential; current files:read and manager permission remain required.',
                [AgentMcpWriteTools::class, 'attachmentsDownloadUrl'],
                Effect::Read,
                true,
                'mcp.read',
                manager: true,
            ),
            $this->tool(
                'expense_schedules.list',
                'List expense schedules',
                'List expense schedules as a workspace manager with bounded cursor pagination.',
                [AgentMcpWriteTools::class, 'expenseSchedulesList'],
                Effect::Read,
                true,
                'mcp.read',
                manager: true,
            ),
            $this->tool(
                'expenses.receipts.list',
                'List expense receipts',
                'List available receipts for an expense as a workspace manager; at most 100 newest receipts.',
                [AgentMcpWriteTools::class, 'expenseReceiptsList'],
                Effect::Read,
                true,
                'mcp.read',
                manager: true,
            ),
            $this->tool(
                'expenses.receipts.download',
                'Download expense receipt',
                'Get a ten-minute receipt download URL. The URL still requires the same expenses:read bearer credential and manager access.',
                [AgentMcpWriteTools::class, 'expenseReceiptsDownload'],
                Effect::Read,
                true,
                'mcp.read',
                manager: true,
            ),
            $this->tool(
                'projects.create',
                'Create project',
                'Create a project for a client as a workspace owner/admin only after explicit user confirmation. Read the client company first and supply its current version.',
                [AgentMcpWriteTools::class, 'projectsCreate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::PROJECTS],
                manager: true,
            ),
            $this->tool(
                'projects.update',
                'Update project',
                'Update project facts or client visibility as a workspace owner/admin only after explicit user confirmation, using the current project version. Omitted fields remain unchanged; null clears description or repository.',
                [AgentMcpWriteTools::class, 'projectsUpdate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::PROJECTS],
                manager: true,
            ),
            $this->tool(
                'projects.archive',
                'Archive project',
                'Archive a project as a workspace owner/admin only after explicit user confirmation, using its current version. No tasks, time, or financial history are deleted.',
                [AgentMcpWriteTools::class, 'projectsArchive'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::PROJECTS],
                manager: true,
            ),
            $this->tool(
                'projects.members.update',
                'Set project access',
                'Set an existing workspace member project role, or remove it with none, as a workspace owner/admin only after explicit user confirmation. Read projects.members.list for public member IDs and the project version. Workspace owners/admins cannot receive explicit project grants.',
                [AgentMcpWriteTools::class, 'projectMembersUpdate'],
                Effect::Destructive,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::PROJECTS],
                manager: true,
            ),
            $this->tool(
                'workspaces.create',
                'Create workspace',
                'Create a workspace owned by the authenticated user. A new tenant has no expected version; an actor/client/idempotency key reservation prevents duplicate creation.',
                [AgentMcpWriteTools::class, 'workspacesCreate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::WORKSPACES],
            ),
            $this->tool(
                'attachments.upload_url',
                'Prepare attachment upload',
                'Prepare a ten-minute signed multipart REST upload URL without uploading bytes. POST file and expected_version there with the same bearer credential and a fresh Idempotency-Key. Upload retries with the same key and identical bytes return the first attachment. Current files:write and manager permission remain required.',
                [AgentMcpWriteTools::class, 'attachmentsUploadUrl'],
                Effect::Read,
                true,
                'mcp.read',
                cutovers: [AgentDeploymentFlags::FILES],
                manager: true,
            ),
            $this->tool(
                'attachments.delete',
                'Delete attachment',
                'Delete a generic file using its current version, only after explicit user confirmation. The file becomes unavailable immediately and is purged after retention.',
                [AgentMcpWriteTools::class, 'attachmentsDelete'],
                Effect::Destructive,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::FILES],
                manager: true,
            ),
            $this->tool(
                'expenses.approve',
                'Approve expense',
                'Approve one draft expense using its current version as a workspace manager.',
                [AgentMcpWriteTools::class, 'expensesApprove'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::EXPENSES],
                manager: true,
            ),
            $this->tool(
                'expenses.unapprove',
                'Unapprove expense',
                'Return one approved uninvoiced expense to draft using its current version.',
                [AgentMcpWriteTools::class, 'expensesUnapprove'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::EXPENSES],
                manager: true,
            ),
            $this->tool(
                'expense_schedules.create',
                'Create expense schedule',
                'Create a recurring draft expense schedule using the current client version.',
                [AgentMcpWriteTools::class, 'expenseSchedulesCreate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::EXPENSES],
                manager: true,
            ),
            $this->tool(
                'expense_schedules.update',
                'Update expense schedule',
                'Update or pause a schedule using its current version. Anchor and cadence are immutable.',
                [AgentMcpWriteTools::class, 'expenseSchedulesUpdate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::EXPENSES],
                manager: true,
            ),
            $this->tool(
                'expense_schedules.generate',
                'Generate scheduled expenses',
                'Materialize due draft expenses only after explicit user confirmation. Bounded to 24 occurrences; never approves or invoices them.',
                [AgentMcpWriteTools::class, 'expenseSchedulesGenerate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::EXPENSES],
                manager: true,
            ),
            $this->tool(
                'expenses.receipts.upload_url',
                'Prepare expense receipt upload',
                'Get a ten-minute multipart upload URL and current expense version. Upload with the same expenses:read and expenses:write bearer credential, Idempotency-Key header, file and expected_version form fields. Preparing a URL does not upload a receipt.',
                [AgentMcpWriteTools::class, 'expenseReceiptsUploadUrl'],
                Effect::Read,
                true,
                'mcp.read',
                cutovers: [AgentDeploymentFlags::EXPENSES],
                manager: true,
            ),
            $this->tool(
                'expenses.log',
                'Record expenses',
                'Idempotently record up to 20 draft expenses as a workspace manager. Amounts use minor units; no receipt attachment or approval.',
                [AgentMcpWriteTools::class, 'expensesLog'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::EXPENSES],
                manager: true,
            ),
            $this->tool(
                'expenses.update',
                'Update draft expense',
                'Replace draft expense facts using the current version. Only workspace managers may write.',
                [AgentMcpWriteTools::class, 'expensesUpdate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::EXPENSES],
                manager: true,
            ),
            $this->tool(
                'expenses.delete',
                'Delete draft expense',
                'Soft-delete a draft expense using its current version. Approved, invoiced and unknown statuses are refused.',
                [AgentMcpWriteTools::class, 'expensesDelete'],
                Effect::Destructive,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::EXPENSES],
                manager: true,
            ),
            $this->tool(
                'proposals.create',
                'Create proposal',
                'Create a draft proposal as a workspace manager, using the current client company version. Items use minor-unit amounts. Creating does not send it.',
                [AgentMcpWriteTools::class, 'proposalsCreate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::PROPOSALS],
                manager: true,
            ),
            $this->tool(
                'proposals.send',
                'Send proposal',
                'Only after explicit user confirmation, mark a draft proposal sent and make it available in the client portal. Does not email a recipient. Workspace managers only; current proposal version required.',
                [AgentMcpWriteTools::class, 'proposalsSend'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::PROPOSALS],
                manager: true,
            ),
            $this->tool(
                'proposals.accept',
                'Accept proposal',
                'Only after explicit user confirmation, accept a sent proposal using the current version and explicit signer identity. Signs and activates its agreement. Authorized portal recipients within their project grants, or workspace managers recording offline acceptance, only.',
                [AgentMcpWriteTools::class, 'proposalsAccept'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::PROPOSALS],
            ),
            $this->tool(
                'time_entries.log',
                'Log time',
                'Idempotently log up to 20 completed time entries. When time:read is held and time_entries.list remains enabled, the response matches time_entries.list, including its role rule for rates; if that list capability is disabled, the response uses the plain write shape. If client_visible_description is supplied and is_visible_to_client is omitted, the entry defaults to client-visible; send false to stage client text privately. Pass approve: true to approve them in the same call, only when the user asked for approval; approving and explicit billing rates require time:approve and a project approver role.',
                [AgentMcpWriteTools::class, 'timeEntriesLog'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::TIME_ENTRIES],
            ),
            $this->tool(
                'time_entries.update',
                'Update editable time',
                'Update authorized draft time, or approved time on a regenerable draft invoice, using its current version.',
                [AgentMcpWriteTools::class, 'timeEntriesUpdate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::TIME_ENTRIES],
            ),
            $this->tool(
                'time_entries.delete',
                'Delete editable time',
                'Soft-delete authorized draft time, or approved time on a regenerable draft invoice, using its current version.',
                [AgentMcpWriteTools::class, 'timeEntriesDelete'],
                Effect::Destructive,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::TIME_ENTRIES],
            ),
            $this->tool(
                'time_entries.unapprove',
                'Withdraw time approval',
                'Return approved time to draft as a workspace owner or admin using its current version. Time on a draft invoice is released and that invoice regenerated in the same transaction. Billed time and time on issued, paid or void invoices are refused.',
                [AgentMcpWriteTools::class, 'timeEntriesUnapprove'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::WRITES, AgentDeploymentFlags::TIME_ENTRIES],
                manager: true,
            ),
            $this->tool(
                'time_entries.approve',
                'Approve time',
                'Approve a bounded batch of draft time entries after version checks.',
                [AgentMcpWriteTools::class, 'timeEntriesApprove'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::WRITES],
            ),
            $this->tool(
                'tasks.create',
                'Create task',
                'Create a task in an authorized project.',
                [AgentMcpWriteTools::class, 'tasksCreate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::WRITES],
            ),
            $this->tool(
                'tasks.update',
                'Update task',
                'Update an authorized task using its current version.',
                [AgentMcpWriteTools::class, 'tasksUpdate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::WRITES],
            ),
            $this->tool(
                'invoices.hold_delivery',
                'Hold invoice delivery',
                'Hold scheduled or failed automatic delivery using the current invoice version.',
                [AgentMcpWriteTools::class, 'invoicesHoldDelivery'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
                manager: true,
            ),
            $this->tool(
                'invoices.release_delivery',
                'Release invoice delivery',
                'Release held automatic delivery only after explicit user confirmation, using the current invoice version.',
                [AgentMcpWriteTools::class, 'invoicesReleaseDelivery'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
                manager: true,
            ),
            $this->tool(
                'invoices.add_time',
                'Add time to draft',
                'Add explicit approved unallocated time to an ad-hoc draft, or regenerate an eligible generated draft with selected work in its service period. Generated drafts recalculate their whole period through the canonical billing engine.',
                [AgentMcpWriteTools::class, 'invoicesAddTime'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
                manager: true,
            ),
            $this->tool(
                'invoices.generate_period',
                'Generate cadence period draft',
                'Create a draft selling an explicit started retainer cycle on an active recurring agreement. period_start is the retainer cycle start; service dates cover the preceding work cycle. No automatic cadence generation job is deployed. A different key for an existing non-void invoice is refused. Explicit confirmation and current agreement version required.',
                [AgentMcpWriteTools::class, 'invoicesGeneratePeriod'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
                manager: true,
            ),
            $this->tool(
                'billing_schedules.create',
                'Create billing schedule',
                'Create a recurring billing schedule for an explicit company and agreement. Use the agreement version as expected_version. Does not issue invoices.',
                [AgentMcpWriteTools::class, 'billingSchedulesCreate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
                manager: true,
            ),
            $this->tool(
                'billing_schedules.generate',
                'Generate scheduled invoices',
                'Generate and issue all due invoices through today, only after explicit confirmation. Requires the current schedule version. Never emails directly; normal automatic delivery settings apply.',
                [AgentMcpWriteTools::class, 'billingSchedulesGenerate'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
                manager: true,
            ),
            $this->tool(
                'invoices.create_draft',
                'Create invoice draft',
                'Create a draft from explicit manual lines and/or explicit approved time.',
                [AgentMcpWriteTools::class, 'invoicesCreateDraft'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
            ),
            $this->tool(
                'invoices.update_draft',
                'Update invoice draft',
                'Replace an ad-hoc draft invoice\'s selection and lines using its current version. Only a draft that invoices.get reports as editable is accepted; a generated draft (any other invoice_kind) is regenerated in SVC, never edited here.',
                [AgentMcpWriteTools::class, 'invoicesUpdateDraft'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
            ),
            $this->tool(
                'invoices.update_details',
                'Update draft invoice details',
                'Change the due date and/or notes of any draft invoice, including a generated draft that invoices.update_draft refuses, using its current version. Lines and linked time are unchanged. Use this before issuing a draft whose due date has passed: a due date may not precede the issue date, and a draft without one is issued today.',
                [AgentMcpWriteTools::class, 'invoicesUpdateDetails'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
            ),
            $this->tool(
                'invoices.correct',
                'Correct issued invoice',
                'Correct an unpaid issued invoice that has not been emailed to the client, only after the user explicitly confirms: its due date and/or line wording, and amounts only on operator-authored lines. Omit lines to keep them; supplied lines must name every existing line. A reason is required and kept in the client history; any automatic client delivery is held for explicit release after review. Paid, sent and void invoices are refused.',
                [AgentMcpWriteTools::class, 'invoicesCorrect'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
            ),
            $this->tool(
                'invoices.discard_draft',
                'Discard invoice draft',
                'Discard a draft and release its selected time only after explicit confirmation.',
                [AgentMcpWriteTools::class, 'invoicesDiscardDraft'],
                Effect::Destructive,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
            ),
            $this->tool(
                'invoices.issue',
                'Issue invoice',
                'Issue a draft invoice using its current version, only after the user explicitly confirms. Issuing never emails the client itself: if the client company has automatic invoice email enabled, a delivery is scheduled for after its configured delay, otherwise none is. SVC also queues its usual internal issued-invoice notice to the workspace administrator. For money already collected elsewhere, such as a card autopay, pass payment (owner/admin, payments:record) to record it in the same transaction: the invoice ends paid or partially paid, the scheduled client delivery is cancelled before it can send, and if any part is refused nothing is written. A payment never initiates a charge and overpayment is refused. With billing:read, and while invoices.get remains enabled, the response matches invoices.get; otherwise it is the plain mutation shape.',
                [AgentMcpWriteTools::class, 'invoicesIssue'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
            ),
            $this->tool(
                'invoices.send',
                'Send invoice',
                'Queue delivery to explicit recipients only after confirmation.',
                [AgentMcpWriteTools::class, 'invoicesSend'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
            ),
            $this->tool(
                'invoices.void',
                'Void invoice',
                'Void an invoice only after explicit confirmation and reason.',
                [AgentMcpWriteTools::class, 'invoicesVoid'],
                Effect::Destructive,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::INVOICES],
            ),
            $this->tool(
                'payments.record',
                'Record received payment',
                'Record money already received using an explicit invoice, amount in minor units, currency, payment date and method. Idempotency key required. Owner/admin only; overpayments refused. Never charges a customer or issues a refund.',
                [AgentMcpWriteTools::class, 'paymentsRecord'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::PAYMENTS],
                manager: true,
            ),
            $this->tool(
                'payments.correct',
                'Correct received payment',
                'Correct the method, reference or received date of a payment already recorded, using the version from payments.list and a reason that is kept in the client history. Omitted fields are unchanged; a null reference clears it. Amount, currency, status and refunds cannot be changed here. Idempotency key required. Owner/admin only. Never charges a customer or issues a refund.',
                [AgentMcpWriteTools::class, 'paymentsCorrect'],
                Effect::LocalWrite,
                true,
                'mcp.write',
                cutovers: [AgentDeploymentFlags::PAYMENTS],
                manager: true,
            ),
            $this->tool(
                'agreements.list',
                'List agreements',
                'List active or historical agreement terms visible to a workspace manager.',
                [AgentMcpAgreementTools::class, 'list'],
                Effect::Read,
                true,
                'mcp.read.agreements',
                manager: true,
                input: AgentInlineSchemas::agreementListInput(),
                output: AgentInlineSchemas::agreementListOutput(),
            ),
            $this->tool(
                'agreements.get',
                'Get agreement',
                'Get one agreement and its derived recurring billing terms.',
                [AgentMcpAgreementTools::class, 'get'],
                Effect::Read,
                true,
                'mcp.read.agreements',
                manager: true,
                input: AgentInlineSchemas::agreementGetInput(),
                output: AgentInlineSchemas::agreementOutput(),
            ),
            $this->tool(
                'clients.list',
                'List clients',
                'List client companies visible to a workspace manager, including archived clients unless filtered.',
                [AgentMcpClientTools::class, 'list'],
                Effect::Read,
                true,
                'mcp.read.clients',
                manager: true,
                input: AgentInlineSchemas::clientInput('clients.list', [
                    'status' => ['type' => ['string', 'null'], 'enum' => ['active', 'archived', null]],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                    'cursor' => ['type' => ['string', 'null'], 'maxLength' => 2048],
                ], []),
            ),
            $this->tool(
                'clients.get',
                'Get client',
                'Get one client company, its current version and invoice-delivery settings.',
                [AgentMcpClientTools::class, 'get'],
                Effect::Read,
                true,
                'mcp.read.clients',
                manager: true,
                input: AgentInlineSchemas::clientInput('clients.get', ['client_id' => ['type' => 'string', 'format' => 'uuid']], ['client_id']),
            ),
            $this->tool(
                'clients.create',
                'Create client',
                'Create a client company. Retry only an identical request with the same key.',
                [AgentMcpClientWriteTools::class, 'clientsCreate'],
                Effect::LocalWrite,
                false,
                'mcp.write.clients',
                cutovers: [AgentDeploymentFlags::CLIENTS],
                manager: true,
                input: AgentInlineSchemas::clientInput('clients.create', ['idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]], ['idempotency_key'], write: true),
            ),
            $this->tool(
                'clients.update',
                'Update client',
                'Change only the fields sent using the current version. Omitted fields are unchanged and nullable fields can be cleared. Enabling automatic delivery requires a non-null delay and configured invoice recipients. Disabling automatic delivery cancels pending deliveries.',
                [AgentMcpClientWriteTools::class, 'clientsUpdate'],
                Effect::LocalWrite,
                false,
                'mcp.write.clients',
                cutovers: [AgentDeploymentFlags::CLIENTS],
                manager: true,
                input: AgentInlineSchemas::clientInput('clients.update', ['client_id' => ['type' => 'string', 'format' => 'uuid'], 'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]], ['client_id', 'idempotency_key'], write: true),
            ),
            $this->tool(
                'clients.archive',
                'Archive client',
                'Deactivate a client using the current version. Invoices, time, payments and agreements are retained; restore reverses it.',
                [AgentMcpClientWriteTools::class, 'clientsArchive'],
                Effect::Destructive,
                false,
                'mcp.write.clients',
                cutovers: [AgentDeploymentFlags::CLIENTS],
                manager: true,
                input: AgentInlineSchemas::clientInput('clients.archive', ['client_id' => ['type' => 'string', 'format' => 'uuid'], 'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]], ['client_id', 'idempotency_key'], write: true),
            ),
            $this->tool(
                'clients.restore',
                'Restore client',
                'Reactivate an archived client using the current version.',
                [AgentMcpClientWriteTools::class, 'clientsRestore'],
                Effect::LocalWrite,
                false,
                'mcp.write.clients',
                cutovers: [AgentDeploymentFlags::CLIENTS],
                manager: true,
                input: AgentInlineSchemas::clientInput('clients.restore', ['client_id' => ['type' => 'string', 'format' => 'uuid'], 'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]], ['client_id', 'idempotency_key'], write: true),
            ),
            $this->tool(
                'agreements.create',
                'Create agreement',
                'Create a client-wide draft agreement using the parent client version. Amounts are minor units and retainer_minutes are whole minutes. It bills nothing until activated.',
                [AgentMcpClientWriteTools::class, 'agreementsCreate'],
                Effect::LocalWrite,
                false,
                'mcp.write.clients',
                cutovers: [AgentDeploymentFlags::CLIENTS],
                manager: true,
                input: AgentInlineSchemas::clientInput('agreements.create', ['client_id' => ['type' => 'string', 'format' => 'uuid'], 'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]], ['client_id', 'idempotency_key'], write: true),
            ),
            $this->tool(
                'agreements.update',
                'Update agreement',
                'Correct only the terms sent using the current version. Omitted fields are unchanged; nullable fields can be cleared. Status and signature cannot be edited here.',
                [AgentMcpClientWriteTools::class, 'agreementsUpdate'],
                Effect::LocalWrite,
                false,
                'mcp.write.clients',
                cutovers: [AgentDeploymentFlags::CLIENTS],
                manager: true,
                input: AgentInlineSchemas::clientInput('agreements.update', ['agreement_id' => ['type' => 'string', 'format' => 'uuid'], 'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]], ['agreement_id', 'idempotency_key'], write: true),
            ),
            $this->tool(
                'agreements.activate',
                'Activate agreement',
                'Activate a draft or paused agreement so it governs billing. Requires explicit user confirmation and the current version. Overlapping active agreements are refused.',
                [AgentMcpClientWriteTools::class, 'agreementsActivate'],
                Effect::LocalWrite,
                false,
                'mcp.write.clients',
                cutovers: [AgentDeploymentFlags::CLIENTS],
                manager: true,
                input: AgentInlineSchemas::clientInput('agreements.activate', ['agreement_id' => ['type' => 'string', 'format' => 'uuid'], 'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]], ['agreement_id', 'idempotency_key'], write: true),
            ),
            $this->tool(
                'agreements.terminate',
                'Terminate agreement',
                'End an agreement using the current version after explicit user confirmation. Termination is irreversible. ends_on defaults to today in the workspace timezone, never extending an earlier end or ending before the start.',
                [AgentMcpClientWriteTools::class, 'agreementsTerminate'],
                Effect::Destructive,
                false,
                'mcp.write.clients',
                cutovers: [AgentDeploymentFlags::CLIENTS],
                manager: true,
                input: AgentInlineSchemas::clientInput('agreements.terminate', ['agreement_id' => ['type' => 'string', 'format' => 'uuid'], 'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]], ['agreement_id', 'idempotency_key'], write: true),
            ),
            $this->tool(
                'billing_schedules.list',
                'List billing schedules',
                'List bounded recurring billing schedules visible to a workspace manager.',
                [AgentMcpBillingScheduleTools::class, 'list'],
                Effect::Read,
                true,
                'mcp.read.billing_schedules',
                manager: true,
                input: AgentInlineSchemas::billingScheduleListInput(),
                output: AgentInlineSchemas::billingScheduleListOutput(),
            ),
            $this->tool(
                'billing_schedules.get',
                'Get billing schedule',
                'Get one bounded recurring billing schedule.',
                [AgentMcpBillingScheduleTools::class, 'get'],
                Effect::Read,
                true,
                'mcp.read.billing_schedules',
                manager: true,
                input: AgentInlineSchemas::billingScheduleGetInput(),
                output: AgentInlineSchemas::billingScheduleOutput(),
            ),
            $this->tool(
                'capacity_ledger.get',
                'Get capacity ledger',
                'Get a bounded trailing window of the computed agreement capacity ledger.',
                [AgentMcpCapacityLedgerTools::class, 'get'],
                Effect::Read,
                true,
                'mcp.read.capacity_ledger',
                manager: true,
                input: AgentInlineSchemas::capacityLedgerInput(),
                output: AgentInlineSchemas::capacityLedgerOutput(),
                scopes: ['billing:read'],
            ),
            $this->tool(
                'billing.audit_unplaceable_invoices',
                'Audit unplaceable invoices',
                'Get aggregate counts of invoices whose billing period or cycle cannot be placed safely.',
                [AgentMcpBillingAuditTools::class, 'unplaceableInvoices'],
                Effect::Read,
                true,
                'mcp.read.billing.audit_unplaceable_invoices',
                manager: true,
                input: AgentInlineSchemas::billingAuditInput(),
                output: AgentInlineSchemas::billingAuditOutput(AgentInlineSchemas::billingAuditProperties('billing.audit_unplaceable_invoices')),
                scopes: ['billing:read'],
            ),
            $this->tool(
                'billing.audit_undated_collectible_invoices',
                'Audit undated collectible invoices',
                'Get aggregate counts and per-currency balances for collectible invoices without due dates.',
                [AgentMcpBillingAuditTools::class, 'undatedCollectibleInvoices'],
                Effect::Read,
                true,
                'mcp.read.billing.audit_undated_collectible_invoices',
                manager: true,
                input: AgentInlineSchemas::billingAuditInput(),
                output: AgentInlineSchemas::billingAuditOutput(AgentInlineSchemas::billingAuditProperties('billing.audit_undated_collectible_invoices')),
                scopes: ['billing:read'],
            ),
            $this->tool(
                'billing.audit_missing_billed_overage',
                'Audit missing billed overage',
                'Get aggregate counts of charged invoices missing billed-overage data.',
                [AgentMcpBillingAuditTools::class, 'missingBilledOverage'],
                Effect::Read,
                true,
                'mcp.read.billing.audit_missing_billed_overage',
                manager: true,
                input: AgentInlineSchemas::billingAuditInput(),
                output: AgentInlineSchemas::billingAuditOutput(AgentInlineSchemas::billingAuditProperties('billing.audit_missing_billed_overage')),
                scopes: ['billing:read'],
            ),
            $this->tool(
                'billing.audit_opening_rollover',
                'Audit opening rollover',
                'Get aggregate counts of agreements whose opening rollover changes their capacity ledger.',
                [AgentMcpBillingAuditTools::class, 'openingRollover'],
                Effect::Read,
                true,
                'mcp.read.billing.audit_opening_rollover',
                manager: true,
                input: AgentInlineSchemas::billingAuditInput(),
                output: AgentInlineSchemas::billingAuditOutput(AgentInlineSchemas::billingAuditProperties('billing.audit_opening_rollover')),
                scopes: ['billing:read'],
            ),
        ];
    }

    /** @return list<Operation> */
    private function restOnly(): array
    {
        return [
            $this->restOperation('attachments.download', 'Download attachment', Effect::Download),
            $this->restOperation('attachments.upload', 'Upload attachment', Effect::Upload, [AgentDeploymentFlags::FILES], new WriteSafety(IdempotencyKey::Header, expectedVersion: true)),
            $this->restOperation('expenses.receipts.content', 'Download expense receipt content', Effect::Download),
            $this->restOperation('expenses.receipts.upload', 'Upload expense receipt', Effect::Upload, [AgentDeploymentFlags::EXPENSES], new WriteSafety(IdempotencyKey::Header, expectedVersion: true)),
            $this->restOperation('invoices.download_pdf', 'Download invoice PDF', Effect::Download),
            $this->restOperation('connections.revoke', 'Revoke this connection', Effect::Destructive, safety: new WriteSafety(note: 'Revokes only the calling connection; repeating it is harmless.'), idempotent: true),
        ];
    }

    /** @return list<Operation> */
    private function resourcesAndPrompts(): array
    {
        $closed = ['type' => 'object', 'additionalProperties' => false];

        return [
            $this->mcpOnly('resources.current_context', 'Current SVC context', 'The authenticated SVC identity and authorized workspaces.',
                new McpBinding('current-context', [AgentMcpContextResource::class, 'read'], McpKind::Resource, 'svc://context'),
                ['identity:read'], 'mcp.read.context', $closed, SchemaRef::responseOf('context.get')),
            $this->mcpOnly('resources.agreement', 'Agreement', 'Read one canonical agreement representation visible to a workspace manager.',
                new McpBinding('agreement', [AgentMcpAgreementResource::class, 'read'], McpKind::ResourceTemplate, 'svc://workspaces/{workspace_id}/agreements/{agreement_id}'),
                ['billing:read'], 'mcp.read.agreements', AgentInlineSchemas::agreementGetInput(), AgentInlineSchemas::agreementOutput(), manager: true),
            $this->mcpOnly('prompts.log_time_across_projects', 'Log time across projects', 'Guide an authorized client through bounded, retry-safe time logging.',
                new McpBinding('log-time-across-projects', [AgentMcpPrompts::class, 'logTimeAcrossProjects'], McpKind::Prompt),
                ['identity:read', 'projects:read', 'time:write'], 'mcp.prompt.log_time_across_projects', $closed, AgentInlineSchemas::promptOutput(),
                requires: ['context.get', 'projects.list', 'time_entries.log']),
            $this->mcpOnly('prompts.prepare_invoice_safely', 'Prepare an invoice safely', 'Guide an authorized client through reviewing and preparing an invoice draft.',
                new McpBinding('prepare-invoice-safely', [AgentMcpPrompts::class, 'prepareInvoiceSafely'], McpKind::Prompt),
                ['identity:read', 'projects:read', 'time:read', 'billing:read', 'billing:write'], 'mcp.prompt.prepare_invoice_safely', $closed, AgentInlineSchemas::promptOutput(),
                requires: ['context.get', 'projects.get', 'time_entries.list', 'invoices.get', 'invoices.create_draft', 'invoices.update_draft']),
        ];
    }

    /**
     * An MCP tool. A documented one takes its scopes, REST binding and schemas
     * from the document; a write's input is its REST request body, merged over
     * the handler's own parameters.
     *
     * @param  array{0: class-string, 1: string}  $handler
     * @param  list<string>  $cutovers
     * @param  array<string, mixed>|SchemaRef|null  $input
     * @param  array<string, mixed>|SchemaRef|null  $output
     * @param  list<string>|null  $scopes  only for a tool the document does not describe
     */
    private function tool(
        string $id,
        string $title,
        string $description,
        array $handler,
        Effect $effect,
        bool $idempotent,
        string $group,
        array $cutovers = [],
        bool $manager = false,
        array|SchemaRef|null $input = null,
        array|SchemaRef|null $output = null,
        ?array $scopes = null,
    ): Operation {
        $documented = isset(self::ROUTES[$id]);
        if ($documented === ($scopes !== null)) {
            throw new LogicException("Operation [{$id}]: declare scopes exactly when the document does not.");
        }
        if ($manager) {
            $this->managerOnly[$id] = true;
        }
        $write = ! $effect->readOnly();

        return new Operation(
            id: $id,
            title: $title,
            description: $description,
            effect: $effect,
            requirement: new Requirement(
                scopes: $scopes ?? AgentApiResponseSchemaCatalog::scopesForOperation($id),
                flags: [...$cutovers, ...self::mcpSwitches($group, $id)],
            ),
            idempotent: $idempotent,
            safety: $write ? new WriteSafety(IdempotencyKey::HeaderAndArgument, expectedVersion: $this->takesVersion($id)) : new WriteSafety,
            rest: $documented ? $this->rest($id) : null,
            mcp: new McpBinding(handler: $handler),
            input: $input ?? ($write && $documented ? SchemaRef::requestOf($id) : null),
            output: $output ?? ($documented ? SchemaRef::responseOf($id) : null),
        );
    }

    /**
     * @param  list<string>  $cutovers
     */
    private function restOperation(string $id, string $title, Effect $effect, array $cutovers = [], ?WriteSafety $safety = null, ?bool $idempotent = null): Operation
    {
        $documented = AgentApiResponseSchemaCatalog::catalog()->operations()[$id] ?? throw new LogicException("Operation [{$id}] is not documented.");

        return new Operation(
            id: $id,
            title: $title,
            description: $documented['description'],
            effect: $effect,
            requirement: new Requirement(scopes: AgentApiResponseSchemaCatalog::scopesForOperation($id), flags: $cutovers),
            idempotent: $idempotent,
            safety: $safety ?? new WriteSafety,
            rest: $this->rest($id),
            // A download answers with the file itself and 204 with nothing.
            output: $effect === Effect::Download || self::ROUTES[$id][2] === [204] ? null : SchemaRef::responseOf($id),
        );
    }

    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|SchemaRef  $output
     * @param  list<string>  $requires
     */
    private function mcpOnly(string $id, string $title, string $description, McpBinding $binding, array $scopes, string $group, array $input, array|SchemaRef $output, bool $manager = false, array $requires = []): Operation
    {
        if ($manager) {
            $this->managerOnly[$id] = true;
        }

        return new Operation(
            id: $id,
            title: $title,
            description: $description,
            effect: Effect::Read,
            requirement: new Requirement(scopes: $scopes, flags: self::mcpSwitches($group, (string) $binding->name)),
            idempotent: true,
            mcp: $binding,
            input: $input,
            output: $output,
            requiresOperations: $requires,
        );
    }

    /** @return list<string> */
    private static function mcpSwitches(string $group, string $name): array
    {
        return [AgentDeploymentFlags::MCP, AgentDeploymentFlags::MCP_SWITCH.$group, AgentDeploymentFlags::MCP_SWITCH.$name];
    }

    private function rest(string $id): RestBinding
    {
        [$name, $path, $statuses] = self::ROUTES[$id];
        preg_match_all('/\{([^}]+)\}/', $path, $parameters);

        return new RestBinding(
            method: AgentApiResponseSchemaCatalog::catalog()->operations()[$id]['method'],
            path: $path,
            routeName: self::ROUTE_PREFIX.$name,
            pathParameters: $parameters[1],
            successStatuses: $statuses,
        );
    }

    private function takesVersion(string $id): bool
    {
        if (! isset(self::ROUTES[$id])) {
            return false;
        }
        $body = AgentApiResponseSchemaCatalog::requestForOperation($id);

        return isset($body['properties']['expected_version']);
    }
}
