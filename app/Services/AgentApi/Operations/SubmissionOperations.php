<?php

namespace App\Services\AgentApi\Operations;

use App\Queries\Submission\ApprovedTimesheet;
use App\Queries\Submission\SubmissionsOverview;
use App\Services\Submission\SubmissionService;
use App\Support\AgentApi\AgentApiScopes;

/**
 * Submission operations (docs/ux-overhaul.md sections 7b and 7e, slice 11):
 * list the month's own hours and expenses with where each stands, submit them
 * for review, take a submission back, and read the approved timesheet. Code-owned
 * schemas, regenerated into the public contract by
 * scripts/generate-submission-contract.php.
 *
 * Submitting is not approving. Nothing here approves anything, and no operation
 * lets a person decide their own rows; the reviewer's decision is made in the
 * reviewer's own queue, in the browser.
 */
final class SubmissionOperations
{
    public const array ROUTES = [
        'submissions.list' => ['submissions.index', '/workspaces/{workspace}/submissions', [200]],
        'submissions.submit' => ['submissions.submit', '/workspaces/{workspace}/submissions', [200]],
        'submissions.withdraw' => ['submissions.withdraw', '/workspaces/{workspace}/submissions/withdraw', [200]],
        'timesheets.approved' => ['timesheets.approved', '/workspaces/{workspace}/timesheets/approved', [200]],
    ];

    /** @var array<string, string> */
    private const array METHODS = [
        'submissions.list' => 'GET',
        'submissions.submit' => 'POST',
        'submissions.withdraw' => 'POST',
        'timesheets.approved' => 'GET',
    ];

    /** @var array<string, list<string>> */
    private const array SCOPES = [
        'submissions.list' => [AgentApiScopes::TIME_READ],
        'submissions.submit' => [AgentApiScopes::TIME_WRITE],
        'submissions.withdraw' => [AgentApiScopes::TIME_WRITE],
        'timesheets.approved' => [AgentApiScopes::TIME_READ],
    ];

    /** Operation id => [MCP handler, title, response component, request component|null]. */
    public const array DEFINITIONS = [
        'submissions.list' => ['list', 'List a month\'s submissions', 'SubmissionListResponse', null],
        'submissions.submit' => ['submit', 'Submit hours and expenses for review', 'SubmissionResultResponse', 'SubmissionRequest'],
        'submissions.withdraw' => ['withdraw', 'Withdraw a submission', 'SubmissionResultResponse', 'SubmissionRequest'],
        'timesheets.approved' => ['approved', 'Read the approved timesheet', 'ApprovedTimesheetResponse', null],
    ];

    /** @return list<string> every component this family adds to the contract */
    public static function components(): array
    {
        $names = [];
        foreach (self::DEFINITIONS as [, , $response, $request]) {
            foreach (array_filter([$response, $request]) as $name) {
                if (! in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /** @return array<string, array{method: string, scopes: list<string>, summary: string, description: string, parameters: list<string|array<string, mixed>>, response: string, errors: array<int, string>, request?: string}> */
    public static function documentation(): array
    {
        $rows = 'Only the caller\'s own hours and expenses are read or changed, in projects the caller can see; another person\'s row, or another workspace\'s, is simply not found. Nothing about money crosses to a reviewer: no rate, invoice, agreement or internal note is returned.';
        $errors = [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The token lacks the scope, the caller is not a member of the workspace, or an expense is named without expenses:write and its cutover.',
            404 => 'The workspace, project or a named row is not visible to the caller.',
            422 => 'period is not a month written YYYY-MM, or a field is invalid (`errors` names it).',
            429 => 'Too many requests (retry after Retry-After).',
        ];
        $writeErrors = array_replace($errors, [
            404 => $errors[404].' Agent writes also answer 404 while AGENT_API_TIME_ENTRY_WRITES_ENABLED is off.',
            409 => 'A named row cannot be moved (it is not a draft, is already submitted, is not billable or shown to the client, or a decision landed first), or the Idempotency-Key was already used with a different request, or the first request with it is still being processed.',
            422 => 'The Idempotency-Key header is missing, nothing was named (give a period or the rows), more than 500 rows match, or a field is invalid (`errors` names it).',
        ]);
        $period = ['name' => 'period', 'in' => 'query', 'schema' => ['type' => 'string', 'pattern' => '^\d{4}-(0[1-9]|1[0-2])$']];
        $project = ['name' => 'project_id', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'uuid']];

        $descriptions = [
            'submissions.list' => 'The caller\'s own hours and expenses for one calendar month (period, default the current month), each with its state: draft, submitted, approved or disputed, and whether it can be submitted or withdrawn now. Expenses are listed only to a token that also has expenses:read. Bounded to 500 rows of each kind; truncated says when there are more.',
            'submissions.submit' => 'Mark the caller\'s own draft hours and expenses as submitted for review, so a reviewer or the client sees them without anyone in the caller\'s workspace approving them. Name a calendar month (period) and every eligible row in it is submitted and the rest are left alone, or name rows (time_entry_ids, expense_ids) and each must be eligible or nothing changes. Submitting approves nothing and changes no status; a submitted row cannot be edited until it is withdrawn. Expenses are included only for a token that also has expenses:write while AGENT_API_EXPENSE_WRITES_ENABLED is on; naming an expense without it is a 403. Requires AGENT_API_TIME_ENTRY_WRITES_ENABLED.',
            'submissions.withdraw' => 'Take back the caller\'s own submissions that nobody has approved yet, removing them from the reviewer\'s list. Named like submit: a month, or rows that must each be withdrawable. A row already approved, or already marked ready to bill, is refused. Requires AGENT_API_TIME_ENTRY_WRITES_ENABLED.',
            'timesheets.approved' => 'The approved timesheet for one calendar month (period, default the current month): the hours a reviewer or the client\'s contact approved, with who worked each, who approved it and how, and the month\'s total. Draft, merely submitted, disputed and payment-implied rows are never included. An owner or admin reads the workspace\'s, any other member their own. Bounded to 1000 entries.',
        ];

        $operations = [];
        foreach (self::DEFINITIONS as $id => [, $title, $response, $request]) {
            $write = $request !== null;
            $parameters = ['WorkspaceId'];
            if (! $write) {
                array_push($parameters, $period, $project);
            }
            if ($write) {
                $parameters[] = 'IdempotencyKey';
            }
            $operations[$id] = [
                'method' => self::METHODS[$id],
                'scopes' => self::SCOPES[$id],
                'summary' => $title,
                'description' => $descriptions[$id].' '.$rows,
                'parameters' => $parameters,
                'response' => $response,
                'errors' => $write ? $writeErrors : $errors,
            ] + ($write ? ['request' => $request] : []);
        }

        return $operations;
    }

    /** @return array<string, mixed> the JSON schema of a component this family adds */
    public static function schema(string $component): array
    {
        $text = ['type' => 'string'];
        $nullableText = ['type' => ['string', 'null']];
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $date = ['type' => 'string', 'format' => 'date'];
        $month = ['type' => 'string', 'pattern' => '^\d{4}-(0[1-9]|1[0-2])$'];
        $state = ['type' => 'string', 'enum' => ['draft', 'submitted', 'approved', 'disputed']];
        $minutes = ['type' => 'integer', 'minimum' => 0];
        $row = static fn (array $fields): array => self::object([
            'id' => $uuid, 'state' => $state, 'project' => $nullableText, 'dispute_note' => $nullableText,
            'can_submit' => ['type' => 'boolean'], 'why_not' => $nullableText, 'can_withdraw' => ['type' => 'boolean'],
        ] + $fields);
        $ids = ['type' => 'array', 'maxItems' => SubmissionService::LIMIT, 'items' => $uuid];

        return [
            'SubmissionListResponse' => self::object(['data' => self::object([
                'period' => $month, 'period_label' => $text, 'previous_period' => $month, 'next_period' => $month,
                'time' => ['type' => 'array', 'maxItems' => SubmissionsOverview::LIMIT, 'items' => $row([
                    'worked_on' => $date, 'minutes' => $minutes, 'description' => $text, 'client_description' => $nullableText,
                ])],
                'expenses' => ['type' => 'array', 'maxItems' => SubmissionsOverview::LIMIT, 'items' => $row([
                    'spent_on' => $date, 'description' => $text, 'amount' => $minutes, 'currency' => $text,
                ])],
                'totals' => self::object(['draft' => $minutes, 'submitted' => $minutes, 'approved' => $minutes, 'disputed' => $minutes]),
                'truncated' => ['type' => 'boolean'],
            ])]),
            'SubmissionResultResponse' => self::object(['data' => self::object([
                'items' => ['type' => 'array', 'maxItems' => 2 * SubmissionService::LIMIT, 'items' => self::object([
                    'kind' => ['type' => 'string', 'enum' => ['time', 'expense']], 'id' => $uuid, 'state' => $state,
                ])],
            ])]),
            'SubmissionRequest' => [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => ['period' => $month, 'project_id' => $uuid, 'time_entry_ids' => $ids, 'expense_ids' => $ids],
            ],
            'ApprovedTimesheetResponse' => self::object(['data' => self::object([
                'period' => $month, 'period_label' => $text, 'previous_period' => $month, 'next_period' => $month,
                'entries' => ['type' => 'array', 'maxItems' => ApprovedTimesheet::LIMIT, 'items' => self::object([
                    'id' => $uuid, 'worked_on' => $date, 'minutes' => $minutes, 'description' => $text, 'project' => $nullableText, 'task' => $nullableText,
                    'worked_by' => $nullableText, 'approved_by' => $nullableText,
                    'approved_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'approved_via' => ['type' => 'string', 'enum' => ['Reviewer', 'Client', 'Payment', 'Unknown']],
                ])],
                'total_minutes' => $minutes,
                'truncated' => ['type' => 'boolean'],
            ])]),
        ][$component] ?? throw new \InvalidArgumentException("Unknown submission component {$component}.");
    }

    /** @param array<string, mixed> $fields
     * @return array<string, mixed> */
    private static function object(array $fields): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($fields), 'properties' => $fields];
    }
}
