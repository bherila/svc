<?php

namespace App\Support\ClientHome;

/**
 * One client, at a glance.
 *
 * The screen answers four questions and then gets out of the way: what is the
 * most recent billing event, what work happened recently, what needs attention,
 * and where is the full history. Everything here is therefore bounded - the
 * limits are constants on this class rather than decisions each adapter makes -
 * because the two screens this replaced were unbounded in opposite directions.
 * The internal one sent every project, every agreement and twenty invoices; the
 * portal sent the client's entire visible record, including every attachment,
 * and rendered it as a wall of cards.
 *
 * Both operator and portal adapters produce this same shape, and the page
 * cannot tell them apart. That is the point: the experience is one experience,
 * while the queries behind it stay two, because merging those is how an
 * external user ends up reading an unapproved time entry.
 */
final class ClientHomeViewModel
{
    /** How many recent time entries a glance can hold. */
    public const RECENT_TIME = 5;

    /** How many open tasks, likewise. */
    public const OPEN_TASKS = 5;

    /**
     * @param  list<TimeEntryRow>  $recentTime
     * @param  list<TaskRow>  $openTasks
     * @param  array{invoices: string|null, time: string|null, tasks: string|null}  $links
     *                                                                                      where each section's full history lives, or null when this
     *                                                                                      viewer's route family has no such screen
     */
    public function __construct(
        public readonly string $companyId,
        public readonly string $companyName,
        public readonly ?InvoiceSummary $latestInvoice,
        public readonly array $recentTime,
        public readonly array $openTasks,
        public readonly EngagementSummary $engagement,
        public readonly array $links,
        /** Whether this viewer may edit the client record itself. */
        public readonly ?string $settingsHref,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'company' => [
                'id' => $this->companyId,
                'name' => $this->companyName,
            ],
            'latest_invoice' => $this->latestInvoice?->toArray(),
            'recent_time' => array_map(
                static fn (TimeEntryRow $row): array => $row->toArray(),
                $this->recentTime,
            ),
            'open_tasks' => array_map(
                static fn (TaskRow $row): array => $row->toArray(),
                $this->openTasks,
            ),
            'engagement' => $this->engagement->isEmpty() ? null : $this->engagement->toArray(),
            'links' => $this->links,
            'settings_href' => $this->settingsHref,
        ];
    }
}
