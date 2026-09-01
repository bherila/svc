<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A model that belongs to a workspace may not be looked up by key alone.
 *
 * `Model::find($id)` starts a fresh query, so there is no workspace in it and
 * none can be added afterwards - whatever comes back is whatever row carries
 * that id, in any tenant. That is the defect this epic has now found three
 * times by hand and once by runtime sweep (#157, #167): a list is scoped to
 * what the reader may reach, and the detail route beside it reads the record
 * straight off its id.
 *
 * #167 made that a runtime test over the router. This is the same rule one step
 * earlier, and it catches what a route sweep cannot: a lookup in a service, a
 * job or a console command, where there is no request to drive.
 *
 * ## Only the static forms, deliberately
 *
 * `Model::find()`, `::findOrFail()`, `::findOr()` and `::findMany()` are the
 * forms that are *always* unscoped and execute immediately, because a static
 * call has nothing before it to have been constrained. That makes every
 * diagnostic a true positive and every fix mechanical: start from a scoped
 * query instead.
 *
 * `Model::whereKey($id)` is also not flagged: it returns a deferred builder, so
 * `Model::whereKey($id)->where('workspace_id', $workspaceId)->first()` executes
 * one correctly scoped query. The same is true of `Model::query()->find($id)`
 * and `$builder->find($id)`. Telling a scoped chain from an unscoped one means
 * following the receiver back through arbitrary intermediate calls and knowing
 * which of them constrain a tenant. #123 anticipates exactly that ("the stretch
 * rules need deeper PHPStan reflection and type inference to avoid false
 * positives"), and a rule that cried wolf on valid builder chains would be
 * switched off rather than obeyed. Those forms are left to review and to
 * #167's runtime sweep.
 *
 * @implements Rule<StaticCall>
 */
final class DisallowUnscopedTenantLookupRule implements Rule
{
    /**
     * Lookups that immediately resolve rows by primary key and nothing else.
     *
     * @var list<string>
     */
    private const KEY_LOOKUPS = ['find', 'findorfail', 'findor', 'findmany'];

    /**
     * Key operations that write rather than read.
     *
     * `destroy()` is the same unscoped claim as `find()` and a strictly worse
     * outcome: it starts its own query, so it selects and deletes whatever row
     * carries that id in any tenant, and unlike a mis-read there is nothing left
     * afterwards to notice. Held apart from the lookups only so the diagnostic
     * can say "deletes" rather than "looks up" - a message that describes a read
     * would be read as a lesser problem than it is.
     *
     * @var list<string>
     */
    private const KEY_WRITES = ['destroy'];

    /**
     * The contract that marks a model as a tenant's.
     */
    private const TENANT_CONTRACT = 'App\Contracts\WorkspaceOwned';

    /**
     * The column every tenant-owned model is scoped by.
     */
    private const TENANT_KEY = 'workspace_id';

    public function __construct(private readonly ReflectionProvider $reflection) {}

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /** @return list<RuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier || ! $node->class instanceof Name) {
            return [];
        }

        $method = strtolower($node->name->name);
        $isWrite = in_array($method, self::KEY_WRITES, true);

        if (! $isWrite && ! in_array($method, self::KEY_LOOKUPS, true)) {
            return [];
        }

        $class = $scope->resolveName($node->class);

        if (! $this->belongsToAWorkspace($class)) {
            return [];
        }

        // A model whose primary key *is* the tenant column is already scoped by
        // the key it was given: `WorkspaceInvoiceCounter::find($workspace->id)`
        // names a workspace and can reach no other. Flagging it would make the
        // rule wrong on the one shape where a bare key lookup is provably safe,
        // and a rule that is wrong about a safe call gets suppressed rather than
        // obeyed.
        if ($this->keyedByTenant($class)) {
            return [];
        }

        $short = str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;
        $verb = $isWrite
            ? 'deletes rows by key alone, so it can delete from any workspace'
            : 'looks a row up by key alone, so it reaches every workspace';

        // The replacement names a shape rather than a method. Guessing the
        // relation - `$workspace->clientInvoices()` - would be wrong as often
        // as right, and a diagnostic that names something that does not exist
        // teaches the reader to stop reading them.
        return [RuleErrorBuilder::message(
            "{$short}::{$node->name->name}() {$verb}; "
            .'start from a query that already names the workspace - the workspace relation, or '
            ."where('workspace_id', ...) - and resolve the key inside it.",
        )->identifier('svc.unscopedTenantLookup')->build()];
    }

    /**
     * Whether the class carries the tenant ownership contract.
     *
     * The contract covers workspace-owned models that do not use the common
     * trait, including pivot and import-ledger models. Interfaces are inherited,
     * so a tenant model subclass cannot silently fall out of the rule either.
     */
    private function belongsToAWorkspace(string $class): bool
    {
        if (! $this->reflection->hasClass($class)) {
            return false;
        }

        return $this->reflection->getClass($class)->implementsInterface(self::TENANT_CONTRACT);
    }

    /**
     * Whether the model's primary key is the tenant column itself.
     *
     * Read from the declared default of `$primaryKey` rather than assumed, so a
     * model that stops being workspace-keyed loses the exemption by editing the
     * property. Eloquent's own default is `id`, which is what a model that never
     * declares one gets - and that is exactly the unscoped case the rule exists
     * for, so the fallback here has to be the flagging one.
     */
    private function keyedByTenant(string $class): bool
    {
        $defaults = $this->reflection->getClass($class)->getNativeReflection()->getDefaultProperties();

        return ($defaults['primaryKey'] ?? 'id') === self::TENANT_KEY;
    }
}
