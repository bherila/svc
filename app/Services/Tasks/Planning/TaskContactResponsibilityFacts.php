<?php

declare(strict_types=1);

namespace App\Services\Tasks\Planning;

use DomainException;

/** Eligibility only; callers separately authorize the current workspace manager. */
final readonly class TaskContactResponsibilityFacts
{
    public const int MAX_CONTACTS = 10;

    /** @param list<string> $recordedIds
     * @param  list<TaskContactFact>  $contacts
     */
    public function __construct(private string $companyId, private array $recordedIds, private array $contacts) {}

    /** @param array<array-key, string> $requestedIds */
    public function replace(array $requestedIds): TaskContactSelection
    {
        if (! array_is_list($requestedIds) || count($requestedIds) > self::MAX_CONTACTS
            || count(array_unique($requestedIds)) !== count($requestedIds)) {
            throw new DomainException('Choose a list of at most 10 distinct business contacts.');
        }
        foreach ($requestedIds as $id) {
            $matching = array_values(array_filter($this->contacts, static fn (TaskContactFact $contact): bool => $contact->id === $id));
            if (count($matching) !== 1 || $matching[0]->companyId !== $this->companyId
                || ($matching[0]->archived && ! in_array($id, $this->recordedIds, true))) {
                throw new DomainException('Choose active business contacts from this task\'s client company.');
            }
        }
        sort($requestedIds, SORT_STRING);

        return new TaskContactSelection($requestedIds,
            array_values(array_diff($requestedIds, $this->recordedIds)),
            array_values(array_diff($this->recordedIds, $requestedIds)));
    }
}
