<?php

namespace Tests\Feature\Square;

use App\Services\Square\SquareCollectionGuard;
use Illuminate\Support\Facades\File;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Tests\TestCase;

/**
 * Where SVC can collect money from a client, read off the source (#438).
 *
 * Collection reduces to four primitives: an invoice email leaving
 * (`new InvoiceMail`, sent only from `InvoiceEmailService::deliver()`), a
 * payment reminder leaving (`new InvoicePaymentReminderMail`, sent only from
 * `PaymentReminderService::deliver()`), a Stripe payment intent
 * (`->paymentIntents`), and an automatic delivery put on the schedule
 * (`'automatic_delivery_status' => 'scheduled'`). This walks
 * `app/` for every method that reaches one, and pins the set: a new caller of
 * a primitive fails here until it is either guarded and listed in
 * {@see SquareCollectionGuard::SITES}, or shown to be reached only through a
 * site that is. `SquareCollectionGuardTest` then drives each site from its
 * real entry points and proves it refuses.
 */
final class SquareCollectionSitesTest extends TestCase
{
    /**
     * Every method that reaches a collection primitive, and the guarded site
     * that stands in front of it.
     *
     * @var array<string, string>
     */
    private const array PRIMITIVES = [
        // The email itself. Reached only by the three callers below.
        'App\Services\Billing\InvoiceEmailService::deliver' => 'new InvoiceMail',
        'App\Services\Billing\InvoiceEmailService::send' => '->deliver() after record()/claimFailedManualDelivery(), both of which call assertSendable()',
        'App\Services\Billing\InvoiceEmailService::deliverRegistered' => '->deliver() after its own guard check under the invoice lock',
        'App\Services\Billing\InvoiceEmailService::sendAutomatic' => '->deliver() after its own guard check under the invoice lock',
        'App\Services\Billing\StripePaymentIntentService::createLocked' => '->paymentIntents, after the guard',
        'App\Services\Billing\InvoiceLifecycleService::issue' => 'schedules automatic delivery unless the guard says Square collects',
        'App\Services\Billing\InvoiceCorrectionService::setHold' => 'puts a held delivery back on the schedule, after the guard',
        // A reminder chooses its pay link through the guard (#438): the facts
        // it is sent on are re-read just before it leaves.
        'App\Services\Billing\Reminders\PaymentReminderService::deliver' => 'new InvoicePaymentReminderMail, after PaymentReminderRepository::facts() and the policy\'s last word',
    ];

    public function test_only_the_known_methods_reach_a_collection_primitive(): void
    {
        $found = [];
        foreach ($this->methods() as $name => $method) {
            if ($this->reachesPrimitive($method, str_starts_with($name, 'App\Services\Billing\InvoiceEmailService::'))) {
                $found[] = $name;
            }
        }
        sort($found);
        $known = array_keys(self::PRIMITIVES);
        sort($known);

        $this->assertSame($known, $found, 'A method reaches a client collection primitive without being accounted for. Guard it with SquareCollectionGuard and list it in SquareCollectionGuard::SITES.');
    }

    public function test_every_listed_site_asks_the_guard(): void
    {
        $methods = $this->methods();
        foreach (array_keys(SquareCollectionGuard::SITES) as $site) {
            $this->assertArrayHasKey($site, $methods, "{$site} no longer exists.");
            $asks = (new NodeFinder)->findFirst($methods[$site]->stmts ?? [], static fn (Node $node): bool => $node instanceof MethodCall
                && $node->name instanceof Identifier
                && in_array($node->name->toString(), ['refusal', 'assertSvcMayCollect', 'isSquareCollected'], true));
            $this->assertNotNull($asks, "{$site} is listed as guarded but never asks SquareCollectionGuard.");
        }
    }

    /**
     * The email callers are reached only through guarded sites: `send()` and
     * `sendAfterCommit()` reach `assertSendable()` before any delivery is
     * recorded, so a refusal leaves nothing behind.
     */
    public function test_every_email_caller_passes_a_guarded_site_first(): void
    {
        $methods = $this->methods();
        $guarded = static fn (string $method): bool => isset(SquareCollectionGuard::SITES['App\Services\Billing\InvoiceEmailService::'.$method]);
        $calls = static fn (string $caller, string $callee): bool => (new NodeFinder)->findFirst($methods['App\Services\Billing\InvoiceEmailService::'.$caller]->stmts ?? [], static fn (Node $node): bool => $node instanceof MethodCall
            && $node->name instanceof Identifier && $node->name->toString() === $callee) !== null;

        $this->assertTrue($guarded('assertSendable'));
        $this->assertTrue($calls('record', 'assertSendable'));
        $this->assertTrue($calls('claimFailedManualDelivery', 'assertSendable'));
        $this->assertTrue($calls('sendAfterCommit', 'assertSendable'));
        $this->assertTrue($calls('send', 'record'));
        $this->assertTrue($guarded('deliverRegistered'));
        $this->assertTrue($guarded('sendAutomatic'));
    }

    /**
     * A reminder leaves only on facts read again just before it is sent, and
     * those facts always ask the guard which pay link an invoice may carry.
     */
    public function test_a_payment_reminder_decides_its_pay_link_through_the_guard_before_it_leaves(): void
    {
        $methods = $this->methods();
        $calls = static fn (string $method, string $callee): bool => (new NodeFinder)->findFirst($methods[$method]->stmts ?? [], static fn (Node $node): bool => $node instanceof MethodCall
            && $node->name instanceof Identifier && $node->name->toString() === $callee) !== null;

        $this->assertTrue($calls('App\Services\Billing\Reminders\PaymentReminderService::deliver', 'facts'));
        $this->assertTrue($calls('App\Services\Billing\Reminders\PaymentReminderService::deliver', 'lastWord'));
        $this->assertTrue($calls('App\Services\Billing\Reminders\PaymentReminderRepository::facts', 'squareFacts'));
        $this->assertArrayHasKey('App\Services\Billing\Reminders\PaymentReminderRepository::squareFacts', SquareCollectionGuard::SITES);
    }

    /** `$this->deliver()` is the email primitive only inside InvoiceEmailService; other classes have their own `deliver`. */
    private function reachesPrimitive(Node\Stmt\ClassMethod $method, bool $emailService): bool
    {
        return (new NodeFinder)->findFirst($method->stmts ?? [], function (Node $node) use ($emailService): bool {
            if ($node instanceof New_ && $node->class instanceof Name) {
                return in_array($node->class->toString(), ['App\Mail\InvoiceMail', 'App\Mail\InvoicePaymentReminderMail'], true);
            }
            if ($node instanceof PropertyFetch && $node->name instanceof Identifier) {
                return $node->name->toString() === 'paymentIntents';
            }
            if ($emailService && $node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toString() === 'deliver'
                && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                return true;
            }
            if ($node instanceof ArrayItem && $node->key instanceof String_ && $node->key->value === 'automatic_delivery_status') {
                return (new NodeFinder)->findFirst([$node->value], static fn (Node $value): bool => $value instanceof String_ && $value->value === 'scheduled') !== null;
            }

            return false;
        }) !== null;
    }

    /** @return array<string, Node\Stmt\ClassMethod> */
    private function methods(): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $methods = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tree = (new NodeTraverser(new NameResolver))->traverse($parser->parse($file->getContents()) ?? []);
            foreach ($finder->findInstanceOf($tree, Class_::class) as $class) {
                if ($class->namespacedName === null) {
                    continue;
                }
                foreach ($class->getMethods() as $method) {
                    $methods[$class->namespacedName->toString().'::'.$method->name->toString()] = $method;
                }
            }
        }

        return $methods;
    }
}
