<?php

namespace App\Services\AgentApi\Operations;

use App\Http\Middleware\EnsureServicePackagesEnabled;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\Packages\CancellationMode;
use App\Support\Packages\IntakeQuestionKind;
use App\Support\Packages\PackageActivationMode;
use App\Support\Packages\PackageComponentKind;
use App\Support\Packages\PackageInvoiceIssuing;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageStatus;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\ServicePackageRules;
use App\Support\Billing\ChargeCadence;
use App\Support\Billing\FirstCycleProration;
use BackedEnum;
use InvalidArgumentException;
use LogicException;
use Stringable;

/**
 * The service package operations (docs/service-packages.md, "MCP and REST
 * surface"): list, read, create, edit, publish and retire a workspace's
 * packages as an owner or admin. Code-owned schemas, regenerated into the
 * public contract by scripts/generate-package-contract.php.
 *
 * Nothing here decides anything about a package: the routes call
 * {@see \App\Services\Packages\ServicePackageWorkflow} and
 * {@see \App\Services\Packages\ServicePackageReadService}, and the request
 * schemas are generated from {@see ServicePackageRules}, the rules that
 * workflow validates with, so the contract cannot accept what it refuses.
 *
 * While SVC_SERVICE_PACKAGES_ENABLED is off none of these operations is
 * registered (API-6, X-1): no route, no MCP tool or toolset, nothing in the
 * served OpenAPI document. Each also declares the product switch as an
 * availability precondition, which no session bypasses, so a process whose
 * configuration changed after its routes were built still answers 404.
 */
final class ServicePackageOperations
{
    public const array ROUTES = [
        'packages.list' => ['packages.index', '/workspaces/{workspace}/packages', [200]],
        'packages.get' => ['packages.show', '/workspaces/{workspace}/packages/{package}', [200]],
        'packages.create' => ['packages.store', '/workspaces/{workspace}/packages', [201]],
        'packages.update' => ['packages.update', '/workspaces/{workspace}/packages/{package}', [200]],
        'packages.publish' => ['packages.publish', '/workspaces/{workspace}/packages/{package}/publish', [200]],
        'packages.retire' => ['packages.retire', '/workspaces/{workspace}/packages/{package}/retire', [200]],
    ];

    /** Operation id => [MCP handler, title, method, response component, request component|null]. */
    public const array DEFINITIONS = [
        'packages.list' => ['list', 'List service packages', 'GET', 'ServicePackageListResponse', null],
        'packages.get' => ['get', 'Get service package', 'GET', 'ServicePackageResponse', null],
        'packages.create' => ['create', 'Create service package', 'POST', 'ServicePackageResponse', 'ServicePackageCreateRequest'],
        'packages.update' => ['update', 'Update service package draft', 'PATCH', 'ServicePackageResponse', 'ServicePackageUpdateRequest'],
        'packages.publish' => ['publish', 'Publish service package', 'POST', 'ServicePackageResponse', 'ServicePackageConfirmRequest'],
        'packages.retire' => ['retire', 'Retire service package', 'POST', 'ServicePackageResponse', 'ServicePackageConfirmRequest'],
    ];

    /** The page size bound of packages.list. */
    public const int MAX_LIMIT = 100;

    /**
     * Rule paths a caller may leave out although the stored value always has
     * them: a question's key is derived from its label when it is not sent
     * ({@see ServicePackageRules::withQuestionKeys()}).
     */
    private const array DERIVED = ['intake_questions.*.key'];

    public static function enabled(): bool
    {
        return EnsureServicePackagesEnabled::enabled();
    }

    public static function writes(string $id): bool
    {
        return self::DEFINITIONS[$id][4] !== null;
    }

    /** @return list<string> every component this family adds to the contract */
    public static function components(): array
    {
        return [
            'ServicePackage', 'ServicePackageVersion', 'ServicePackageTerms',
            'ServicePackageResponse', 'ServicePackageListResponse',
            'ServicePackageCreateRequest', 'ServicePackageUpdateRequest', 'ServicePackageConfirmRequest',
        ];
    }

    /** @return array<string, array{method: string, scopes: list<string>, summary: string, description: string, parameters: list<string|array<string, mixed>>, response: string, response_description: string, errors: array<int, string>, request?: string}> */
    public static function documentation(): array
    {
        $who = 'Workspace owners and admins only: anyone else, and any other workspace\'s package, is answered as not found.';
        $flag = 'Requires SVC_SERVICE_PACKAGES_ENABLED; while it is off the operation does not exist.';
        $writeFlags = $flag.' Agent writes also need AGENT_API_WRITES_ENABLED and AGENT_API_PACKAGE_WRITES_ENABLED.';
        $descriptions = [
            'packages.list' => 'List the workspace\'s service packages, oldest first, optionally by status (draft, published or retired). Each has its published version and its draft, both with every term, its public URL once published, and publish_refusals: the coded reasons publishing the draft would be refused now (for example a payment at sign-up without a Stripe connection). '.$who.' '.$flag,
            'packages.get' => 'Read one service package with its published version, its draft, its public URL and publish_refusals, and the version to send with a change. '.$who.' '.$flag,
            'packages.create' => 'Create a service package with a draft version. Terms not sent take the defaults (the workspace currency, monthly, draft invoices, owner review, request-mode cancellation with 30 days\' notice); intake questions without a key get one from their label. Creating publishes nothing. '.$who.' '.$writeFlags,
            'packages.update' => 'Change package fields (link name, listed, portal-addable) and/or the draft\'s terms, using the package\'s current version. Only what is sent changes; sending components or intake_questions replaces that whole list. Terms are always written to the draft: a published version is never changed, and editing a published package starts a new draft from it. '.$who.' '.$writeFlags,
            'packages.publish' => 'Only after explicit user confirmation, publish the draft as the package\'s new version and open its public page. Refused with a coded reason (package_payment_needs_stripe, package_auto_issue_needs_fixed_price and the other publish_refusals codes) when the draft could not be honoured. Current version and confirm: true required. '.$who.' '.$writeFlags,
            'packages.retire' => 'Only after explicit user confirmation, close a published package to new requests and portal additions. Agreements already made from it are untouched. Current version and confirm: true required. '.$who.' '.$writeFlags,
        ];
        $errors = [
            401 => 'Not signed in, or the token is missing, expired or revoked.',
            403 => 'The token lacks the operation\'s scopes.',
            404 => 'The workspace or package is not visible to the caller, or the caller is not a workspace owner or admin.',
            422 => 'status or limit is out of range, or the cursor is malformed or belongs to another query.',
            429 => 'Too many requests (retry after Retry-After).',
        ];
        $writeErrors = array_replace($errors, [
            403 => 'The token lacks the operation\'s scopes, or the caller is not a workspace owner or admin (checked again on a replay).',
            404 => 'The workspace or package is not visible to the caller, the caller is not a workspace owner or admin, or an agent write cutover is switched off.',
            409 => 'expected_version is no longer current (read the package again), or the Idempotency-Key was already used with a different request, or the first request with it is still being processed.',
            422 => 'The Idempotency-Key header is missing, the body has an unknown field, confirm is not true, or a field is invalid (`errors` names it). A coded refusal names its reason in `code`: package_link_name_taken, package_nothing_to_publish, package_not_published, or a publish refusal.',
        ]);
        $package = ['name' => 'package', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
        $status = ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => self::values(PackageStatus::cases())]];

        $operations = [];
        foreach (self::DEFINITIONS as $id => [, $title, $method, $response, $request]) {
            $parameters = match ($id) {
                'packages.list' => ['WorkspaceId', $status, 'Limit', 'Cursor'],
                'packages.create' => ['WorkspaceId', 'IdempotencyKey'],
                'packages.get' => ['WorkspaceId', $package],
                default => ['WorkspaceId', $package, 'IdempotencyKey'],
            };
            $operations[$id] = [
                'method' => $method,
                'scopes' => $request === null ? [AgentApiScopes::PACKAGES_READ] : [AgentApiScopes::PACKAGES_WRITE, AgentApiScopes::PACKAGES_READ],
                'summary' => $title,
                'description' => $descriptions[$id],
                'parameters' => $parameters,
                'response' => $response,
                'response_description' => $id === 'packages.list' ? 'Service packages' : 'Service package',
                'errors' => $request === null ? $errors : $writeErrors,
            ] + ($request === null ? [] : ['request' => $request]);
        }

        return $operations;
    }

    /** @return array<string, mixed> the JSON schema of a component this family adds */
    public static function schema(string $component): array
    {
        $uuid = ['$ref' => '#/components/schemas/Uuid'];
        $version = ['$ref' => '#/components/schemas/Version'];
        $text = ['type' => 'string'];
        $nullableText = ['type' => ['string', 'null']];
        $int = ['type' => 'integer'];
        $nullableInt = ['type' => ['integer', 'null']];
        $bool = ['type' => 'boolean'];
        $instant = ['type' => 'string', 'format' => 'date-time'];
        $nullableInstant = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $expected = ['expected_version' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]];
        $termKeys = ServicePackageRules::termKeys();
        $packageRules = ServicePackageRules::package(creating: true);
        $requiredAtCreate = array_values(array_filter(
            [...array_keys($packageRules), ...$termKeys],
            static fn (string $key): bool => in_array('required', ServicePackageRules::terms()[$key] ?? $packageRules[$key], true)
                && ! array_key_exists($key, PackageTerms::defaults('USD')),
        ));

        return match ($component) {
            'ServicePackage' => self::object([
                'id' => $uuid,
                'link_name' => $text,
                'status' => ['type' => 'string', 'enum' => self::values(PackageStatus::cases())],
                'is_listed' => $bool,
                'portal_addable' => $bool,
                'public_url' => ['type' => ['string', 'null'], 'format' => 'uri'],
                'published' => ['oneOf' => [['$ref' => '#/components/schemas/ServicePackageVersion'], ['type' => 'null']]],
                'draft' => ['oneOf' => [['$ref' => '#/components/schemas/ServicePackageVersion'], ['type' => 'null']]],
                'publish_refusals' => ['type' => 'array', 'maxItems' => count(PackageRefusal::cases()), 'items' => self::object([
                    'code' => ['type' => 'string', 'enum' => self::values(PackageRefusal::cases())],
                    'message' => $text,
                ])],
                'version' => $version,
                'created_at' => $nullableInstant,
                'updated_at' => $nullableInstant,
            ]),
            'ServicePackageVersion' => self::object([
                'id' => $uuid,
                'number' => ['type' => 'integer', 'minimum' => 1],
                'published_at' => $nullableInstant,
                'terms' => ['$ref' => '#/components/schemas/ServicePackageTerms'],
                'setup_fee_total' => ['type' => 'integer', 'minimum' => 0],
            ]),
            'ServicePackageTerms' => self::object([
                'name' => $text,
                'summary' => $nullableText,
                'body_markdown' => $nullableText,
                'agreement_text' => $nullableText,
                'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                'billing_cadence' => ['type' => 'string', 'enum' => self::values(ChargeCadence::cases())],
                'hourly_rate_amount' => $nullableInt,
                'retainer_amount' => $nullableInt,
                'retainer_minutes' => $nullableInt,
                'rollover_months' => $nullableInt,
                'first_cycle_proration' => ['type' => ['string', 'null'], 'enum' => [...self::values(FirstCycleProration::cases()), null]],
                'charge_setup_fee_at_signup' => $bool,
                'charge_first_period' => $bool,
                'require_autopay' => $bool,
                'autopay_limit_amount' => $nullableInt,
                'pay_before_activation' => $bool,
                'payment_lapse_days' => $int,
                'activation_mode' => ['type' => 'string', 'enum' => self::values(PackageActivationMode::cases())],
                'invoice_issuing' => ['type' => 'string', 'enum' => self::values(PackageInvoiceIssuing::cases())],
                'notice_days' => $int,
                'minimum_term_months' => $nullableInt,
                'cancellation_mode' => ['type' => 'string', 'enum' => self::values(CancellationMode::cases())],
                'intake_questions' => ['type' => 'array', 'maxItems' => ServicePackageRules::MAX_QUESTIONS, 'items' => self::object([
                    'key' => $text,
                    'label' => $text,
                    'kind' => ['type' => 'string', 'enum' => self::values(IntakeQuestionKind::cases())],
                    'required' => $bool,
                ])],
                'components' => ['type' => 'array', 'maxItems' => ServicePackageRules::MAX_COMPONENTS, 'items' => self::object([
                    'kind' => ['type' => 'string', 'enum' => self::values(PackageComponentKind::cases())],
                    'description' => $text,
                    'cadence' => ['type' => 'string', 'enum' => self::values(ChargeCadence::cases())],
                    'amount' => $int,
                    // A decimal string with three places, as stored.
                    'quantity' => ['type' => 'string', 'pattern' => '^\d+\.\d{3}$'],
                    'is_taxable' => $bool,
                ])],
            ]),
            'ServicePackageResponse' => self::object(['data' => ['$ref' => '#/components/schemas/ServicePackage']]),
            'ServicePackageListResponse' => self::object([
                'data' => ['type' => 'array', 'maxItems' => self::MAX_LIMIT, 'items' => ['$ref' => '#/components/schemas/ServicePackage']],
                'meta' => self::object(['next_cursor' => $nullableText]),
            ]),
            'ServicePackageCreateRequest' => self::request(
                self::fromRules([...$packageRules, ...ServicePackageRules::terms()], [...array_keys($packageRules), ...$termKeys]),
                $requiredAtCreate,
            ),
            'ServicePackageUpdateRequest' => self::request(
                [...$expected, ...self::fromRules([...ServicePackageRules::package(creating: false), ...ServicePackageRules::terms()], [...array_keys($packageRules), ...$termKeys])],
                ['expected_version'],
            ),
            'ServicePackageConfirmRequest' => self::request([...$expected, 'confirm' => ['type' => 'boolean', 'const' => true]], ['expected_version', 'confirm']),
            default => throw new InvalidArgumentException("Unknown service package component {$component}."),
        };
    }

    /**
     * The fields a write's body may carry: its request schema's properties,
     * so the route refuses exactly what the contract does not name.
     *
     * @return list<string>
     */
    public static function bodyFields(string $id): array
    {
        $request = self::DEFINITIONS[$id][4] ?? throw new LogicException("Operation [{$id}] takes no body.");

        return array_keys(self::schema($request)['properties']);
    }

    /**
     * JSON schemas for the top-level fields named, generated from Laravel
     * rules. Presence (`required`, `sometimes`) is the caller's to say at the
     * top level, because an update merges onto the stored draft and a create
     * onto the defaults; inside a list item `required` means the item needs it.
     *
     * @param  array<string, list<mixed>>  $rules
     * @param  list<string>  $fields
     * @return array<string, array<string, mixed>>
     */
    private static function fromRules(array $rules, array $fields): array
    {
        $schemas = [];
        foreach ($fields as $field) {
            $schemas[$field] = self::field($rules, $field);
        }

        return $schemas;
    }

    /**
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private static function field(array $rules, string $path): array
    {
        $schema = [];
        $nullable = false;
        $bounds = [];
        foreach ($rules[$path] ?? [] as $rule) {
            $rule = $rule instanceof Stringable ? (string) $rule : $rule;
            if (! is_string($rule)) {
                throw new LogicException("Rule for [{$path}] has no schema translation.");
            }
            [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
            match ($name) {
                'required', 'sometimes', 'distinct' => null,
                'nullable' => $nullable = true,
                'string' => $schema['type'] = 'string',
                'integer' => $schema['type'] = 'integer',
                'numeric' => $schema['type'] = 'number',
                'boolean' => $schema['type'] = 'boolean',
                'array' => $schema += self::container($rules, $path, $argument),
                'in' => $schema['enum'] = str_getcsv((string) $argument, ',', '"', ''),
                'regex' => $schema['pattern'] = substr((string) $argument, 1, (int) strrpos((string) $argument, '/') - 1),
                'min', 'max', 'size', 'gt' => $bounds[] = [$name, (int) $argument],
                default => throw new LogicException("Rule [{$rule}] for [{$path}] has no schema translation."),
            };
        }
        foreach ($bounds as [$name, $value]) {
            $keys = match ($schema['type'] ?? null) {
                'string' => ['min' => ['minLength'], 'max' => ['maxLength'], 'size' => ['minLength', 'maxLength']],
                'array' => ['min' => ['minItems'], 'max' => ['maxItems'], 'size' => ['minItems', 'maxItems']],
                default => ['min' => ['minimum'], 'max' => ['maximum'], 'size' => ['minimum', 'maximum'], 'gt' => ['exclusiveMinimum']],
            };
            foreach ($keys[$name] ?? throw new LogicException("Bound [{$name}] for [{$path}] has no schema translation.") as $key) {
                $schema[$key] = $value;
            }
        }
        if (isset($schema['enum']) && ! isset($schema['type'])) {
            $schema['type'] = 'string';
        }
        if ($nullable) {
            $schema['type'] = [$schema['type'], 'null'];
            if (isset($schema['enum'])) {
                $schema['enum'][] = null;
            }
        }

        return $schema;
    }

    /**
     * A list (`field.*` has rules) or a closed object (`array:a,b`).
     *
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private static function container(array $rules, string $path, ?string $keys): array
    {
        if ($keys === null) {
            return ['type' => 'array', 'items' => self::field($rules, $path.'.*')];
        }
        $properties = [];
        $required = [];
        foreach (explode(',', $keys) as $key) {
            $properties[$key] = self::field($rules, $path.'.'.$key);
            if (in_array('required', $rules[$path.'.'.$key] ?? [], true) && ! in_array($path.'.'.$key, self::DERIVED, true)) {
                $required[] = $key;
            }
        }

        return ['type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function request(array $properties, array $required): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function object(array $fields): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($fields), 'properties' => $fields];
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return list<string>
     */
    private static function values(array $cases): array
    {
        return array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
    }
}
