<?php

namespace App\Support\Navigation;

/**
 * Where each module of one client actually lives, for one viewer.
 *
 * Generated on the server after authorization rather than assembled in the
 * browser from a workspace id and a company id. Two things make string
 * concatenation wrong here: an external portal user reaches the same company
 * through a different route family entirely, and one person can hold operator
 * access to one company and portal access to another - so "the invoices of this
 * client" is not a function of the ids, it is a function of the ids and the
 * viewer.
 *
 * Only `home` (the client's Overview) is guaranteed. A route family need not
 * offer every module, and a null hides that tab rather than linking to a page
 * that does not exist, so the tab strip is always exactly the set of screens
 * the viewer can actually open.
 *
 * The client tier's tabs are Overview, Agreements, Invoices, Time, Tasks and
 * Files. `settings`, `expenses`, `notes` and `discussions` are not tabs: they
 * live in the menu on the client's name, as does the portal's `billing`.
 */
final class ClientModuleDestinations
{
    public function __construct(
        public readonly string $home,
        public readonly ?string $agreements,
        public readonly ?string $invoices,
        public readonly ?string $time,
        public readonly ?string $tasks,
        public readonly ?string $files,
        public readonly ?string $settings,
        public readonly ?string $expenses,
        public readonly ?string $notes,
        public readonly ?string $discussions,
        /** The client's own billing page in the portal (#636): saved methods and autopay. Null for the operator family. */
        public readonly ?string $billing = null,
    ) {}

    /**
     * @return array{home: string, agreements: string|null, invoices: string|null, time: string|null, tasks: string|null, files: string|null, settings: string|null, expenses: string|null, notes: string|null, discussions: string|null, billing: string|null}
     */
    public function toArray(): array
    {
        return [
            'home' => $this->home,
            'agreements' => $this->agreements,
            'invoices' => $this->invoices,
            'time' => $this->time,
            'tasks' => $this->tasks,
            'files' => $this->files,
            'settings' => $this->settings,
            'expenses' => $this->expenses,
            'notes' => $this->notes,
            'discussions' => $this->discussions,
            'billing' => $this->billing,
        ];
    }
}
