<?php

namespace Tests\Unit\Tasks\Planning;

use App\Services\Tasks\Planning\TaskContactFact;
use App\Services\Tasks\Planning\TaskContactResponsibilityFacts;
use App\Services\Tasks\Planning\TaskContactSelection;
use DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskContactResponsibilityFacts::class)]
#[CoversClass(TaskContactFact::class)]
#[CoversClass(TaskContactSelection::class)]
final class TaskContactResponsibilityFactsTest extends TestCase
{
    public function test_replacement_keeps_recorded_archived_contacts_and_computes_only_association_changes(): void
    {
        $facts = new TaskContactResponsibilityFacts('company', ['archived', 'removed'], [
            new TaskContactFact('active', 'company', false), new TaskContactFact('archived', 'company', true),
        ]);
        $selection = $facts->replace(['archived', 'active']);
        $this->assertSame(['active', 'archived'], $selection->ids);
        $this->assertSame(['active'], $selection->addedIds);
        $this->assertSame(['removed'], $selection->removedIds);
        $this->assertTrue($selection->changed());
        $this->assertSame(['archived'], $facts->replace(['archived'])->ids);
    }

    public function test_unchanged_set_is_a_noop_but_clearing_retained_responsibility_is_valid(): void
    {
        $facts = new TaskContactResponsibilityFacts('company', ['a', 'b'], [new TaskContactFact('a', 'company', true), new TaskContactFact('b', 'company', false)]);
        $same = $facts->replace(['b', 'a']);
        $this->assertSame(['a', 'b'], $same->ids);
        $this->assertSame([], $same->addedIds);
        $this->assertSame([], $same->removedIds);
        $this->assertFalse($same->changed());
        $clear = $facts->replace([]);
        $this->assertSame([], $clear->ids);
        $this->assertSame([], $clear->addedIds);
        $this->assertSame(['a', 'b'], $clear->removedIds);
        $this->assertTrue($clear->changed());
    }

    public function test_removing_an_archived_contact_does_not_make_it_eligible_for_readdition(): void
    {
        $contact = new TaskContactFact('archived', 'company', true);
        $cleared = (new TaskContactResponsibilityFacts('company', ['archived'], [$contact]))->replace([]);
        $this->assertSame(['archived'], $cleared->removedIds);
        $this->expectException(DomainException::class);
        (new TaskContactResponsibilityFacts('company', $cleared->ids, [$contact]))->replace(['archived']);
    }

    public function test_exactly_ten_active_contacts_are_eligible_without_account_facts(): void
    {
        $ids = array_map(static fn (int $id): string => 'contact'.$id, range(1, 10));
        $contacts = array_map(static fn (string $id): TaskContactFact => new TaskContactFact($id, 'company', false), $ids);
        $selection = (new TaskContactResponsibilityFacts('company', [], $contacts))->replace($ids);
        $this->assertCount(10, $selection->ids);
        $this->assertCount(10, $selection->addedIds);
        $this->assertSame([], $selection->removedIds);
        $this->assertSame(10, TaskContactResponsibilityFacts::MAX_CONTACTS);
        $this->assertSame(['id' => 'contact1', 'companyId' => 'company', 'archived' => false], get_object_vars($contacts[0]));
    }

    public static function invalidSelections(): iterable
    {
        yield 'new archived contact' => [[], [new TaskContactFact('foreign-contact', 'company', true)], ['foreign-contact']];
        yield 'wrong company' => [[], [new TaskContactFact('foreign-contact', 'other-company', false)], ['foreign-contact']];
        yield 'malformed retained company' => [['foreign-contact'], [new TaskContactFact('foreign-contact', 'other-company', false)], ['foreign-contact']];
        yield 'missing contact' => [[], [], ['foreign-contact']];
        yield 'duplicate facts' => [[], [new TaskContactFact('a', 'company', false), new TaskContactFact('a', 'company', false)], ['a']];
        yield 'duplicate request' => [[], [new TaskContactFact('a', 'company', false)], ['a', 'a']];
        yield 'sparse list' => [[], [new TaskContactFact('a', 'company', false)], [1 => 'a']];
        yield 'too many contacts' => [[], [], array_map(static fn (int $id): string => 'contact'.$id, range(1, 11))];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_selections_fail_with_generic_eligibility_errors(array $recorded, array $contacts, array $requested): void
    {
        $facts = new TaskContactResponsibilityFacts('company', $recorded, $contacts);
        try {
            $facts->replace($requested);
            $this->fail('Invalid responsibility was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString('foreign-contact', $exception->getMessage());
            $this->assertStringNotContainsString('other-company', $exception->getMessage());
        }
    }
}
