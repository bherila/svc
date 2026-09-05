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
 * Only `home` is guaranteed. A route family need not offer every module - a
 * module arrives on the portal in a different commit from the one that adds it
 * for operators, which is where expenses stand now (#75): operators have the
 * screen, the portal does not. A null hides that tab rather than linking to a
 * page that does not exist, so the tab strip is always exactly the set of
 * screens the viewer can actually open.
 */
final class ClientModuleDestinations
{
    public function __construct(
        public readonly string $home,
        public readonly ?string $invoices,
        public readonly ?string $time,
        public readonly ?string $expenses,
        public readonly ?string $tasks,
    ) {}

    /**
     * @return array{home: string, invoices: string|null, time: string|null, expenses: string|null, tasks: string|null}
     */
    public function toArray(): array
    {
        return [
            'home' => $this->home,
            'invoices' => $this->invoices,
            'time' => $this->time,
            'expenses' => $this->expenses,
            'tasks' => $this->tasks,
        ];
    }
}
