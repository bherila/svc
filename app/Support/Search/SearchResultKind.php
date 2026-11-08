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
    case Note = 'note';
    case Discussion = 'discussion';
    case Comment = 'comment';
    case History = 'history';
    // Palette-only kinds. The agent API's `search` tool never asks for them
    // (see AgentWorkspaceMiscController), so its contract is unchanged.
    case Expense = 'expense';
    case Agreement = 'agreement';
    case Proposal = 'proposal';
    case File = 'file';
    case Time = 'time';
    case Contact = 'contact';
    case PortalUser = 'portal_user';

    /**
     * Sort weight, so results from different workspaces still group by kind.
     *
     * Declared rather than relying on `cases()` order, because that order is
     * also the enum's declaration order and a reorder there would silently
     * change the ranking.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Client => 0,
            self::Project => 1,
            self::Invoice => 2,
            self::Task => 3,
            self::Note => 4,
            self::Discussion => 5,
            self::Comment => 6,
            self::History => 7,
            self::Expense => 8,
            self::Agreement => 9,
            self::Proposal => 10,
            self::File => 11,
            self::Time => 12,
            self::Contact => 13,
            self::PortalUser => 14,
        };
    }

    /**
     * Whether rows of this kind are listed alphabetically.
     *
     * The original kinds are; the palette-only ones keep the order their query
     * gave them, which is the point of those queries - time entries come back
     * most recent first, and a file or expense you touched last is the one you
     * most likely want.
     */
    public function listedByTitle(): bool
    {
        return match ($this) {
            self::Client, self::Project, self::Invoice, self::Task, self::Note, self::Discussion, self::Comment, self::History => true,
            self::Expense, self::Agreement, self::Proposal, self::File, self::Time, self::Contact, self::PortalUser => false,
        };
    }

    /** The heading this kind appears under. */
    public function heading(): string
    {
        return match ($this) {
            self::Client => 'Clients',
            self::Project => 'Projects',
            self::Invoice => 'Invoices',
            self::Task => 'Tasks',
            self::Note => 'Notes',
            self::Discussion => 'Discussions',
            self::Comment => 'Comments',
            self::History => 'History',
            self::Expense => 'Expenses',
            self::Agreement => 'Agreements',
            self::Proposal => 'Proposals',
            self::File => 'Files',
            self::Time => 'Time',
            self::Contact => 'Contacts',
            self::PortalUser => 'Portal users',
        };
    }

    /**
     * Kinds the palette can reopen from "recent items".
     *
     * A recent is resolved again through the same authorized query that search
     * uses, so a kind belongs here only if that query can be asked for a set of
     * public ids. Time entries, contacts and portal users have no page of their
     * own, and notes, comments and history are resolved by a different service.
     *
     * @return list<self>
     */
    public static function recentable(): array
    {
        return [self::Client, self::Project, self::Invoice, self::Task, self::Expense, self::Agreement, self::Proposal, self::File];
    }
}
