<?php

namespace App\Support\Search;

/**
 * The kinds of thing the palette can find, in the order it groups them.
 *
 * Ordered deliberately: a client is the coarsest destination and the one an
 * operator most often wants, and a task is the finest. Typing three letters
 * that match a client and a task should offer the client first.
 */
enum SearchResultKind: string
{
    case Client = 'client';
    case Project = 'project';
    case Invoice = 'invoice';
    case Task = 'task';

    /** The heading this kind appears under. */
    public function heading(): string
    {
        return match ($this) {
            self::Client => 'Clients',
            self::Project => 'Projects',
            self::Invoice => 'Invoices',
            self::Task => 'Tasks',
        };
    }
}
