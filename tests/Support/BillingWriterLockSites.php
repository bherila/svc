<?php

namespace Tests\Support;

use App\Support\Concurrency\LockResource;
use Illuminate\Support\Facades\File;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

/** Source inventory checked against locks reached by the conformance fixture. */
final class BillingWriterLockSites
{
    /** @return array<string, string> source file:line => enclosing writer method */
    public static function all(): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $sites = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tree = $parser->parse($file->getContents()) ?? [];
            $tree = (new NodeTraverser(new NameResolver, new ParentConnectingVisitor))->traverse($tree);
            foreach ($finder->findInstanceOf($tree, Class_::class) as $class) {
                foreach ($class->getMethods() as $method) {
                    foreach ($finder->findInstanceOf($method->stmts ?? [], StaticCall::class) as $call) {
                        if (! $call->class instanceof Name || $call->class->toString() !== 'App\\Support\\Concurrency\\Locks' || $call->name->toString() !== 'forUpdate') {
                            continue;
                        }
                        $parent = $call->getAttribute('parent');
                        while ($parent instanceof Node && ! $parent instanceof MethodCall) {
                            $parent = $parent->getAttribute('parent');
                        }
                        if (! $parent instanceof MethodCall || self::resource($parent->var, $method, $class) === null) {
                            continue;
                        }
                        $sites[$file->getPathname().':'.$call->getStartLine()] = $class->namespacedName->toString().'::'.$method->name->toString();
                    }
                }
            }
        }
        ksort($sites);

        return $sites;
    }

    private static function resource(Expr $query, ClassMethod $method, Class_ $class): ?LockResource
    {
        while ($query instanceof MethodCall) {
            if ($class->name?->toString() === 'WorkspaceTaskRepository' && $query->name->toString() === 'query') {
                return LockResource::ClientTask;
            }
            // The polymorphic attachment parent can be a task. Its revision
            // lock is a billing writer site even though it has no task query.
            if ($class->name?->toString() === 'AttachmentAction' && $method->name->toString() === 'lockedRecord') {
                return LockResource::ClientTask;
            }
            $query = $query->var;
        }
        if ($query instanceof Variable && is_string($query->name)) {
            foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Assign::class) as $assignment) {
                if ($assignment->var instanceof Variable && $assignment->var->name === $query->name && ! $assignment->expr instanceof Variable) {
                    return self::resource($assignment->expr, $method, $class);
                }
            }
        }
        if ($query instanceof StaticCall && $query->class instanceof Name) {
            if ($query->class->toString() === 'Illuminate\\Support\\Facades\\DB' && $query->name->toString() === 'table' && $query->args[0]->value instanceof String_) {
                return match ($query->args[0]->value->value) {
                    'client_time_entries' => LockResource::ClientTimeEntry,
                    'client_tasks' => LockResource::ClientTask,
                    default => null,
                };
            }

            return match ($query->class->toString()) {
                'App\\Models\\ClientTimeEntry' => LockResource::ClientTimeEntry,
                'App\\Models\\ClientTask' => LockResource::ClientTask,
                default => null,
            };
        }

        return null;
    }
}
