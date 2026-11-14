<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\RecordsSnapshotReads;

/**
 * The snapshot-read ratchet's key (#661): one statement's values never move
 * it, and a second, different read in a method already pinned is a new key -
 * which the ratchet then refuses until it is reviewed.
 */
final class SnapshotReadKeyTest extends TestCase
{
    use RecordsSnapshotReads;

    public function test_values_are_not_shape(): void
    {
        $key = static fn (string $sql): string => self::snapshotReadKey('A::open', 'B::read', 'client_invoices', $sql);

        $this->assertSame(
            $key('select * from `client_invoices` where `workspace_id` = ? and `id` in (?, ?, ?) limit 1'),
            $key('select * from `client_invoices` where `workspace_id` = 7 and `id` in (?) limit 25'),
        );
        $this->assertSame(
            'select * from `client_invoices_2` where `name` = ? and `id` in (?) and `total` > ?',
            self::sqlShape("SELECT *  FROM `client_invoices_2` WHERE `name` = 'it''s' AND `id` IN (4, 5) AND `total` > 1.5"),
        );
    }

    public function test_a_second_read_in_a_pinned_method_is_a_new_key_the_ratchet_refuses(): void
    {
        $fixture = dirname(__DIR__, 2).'/Fixtures/Concurrency/snapshot-reads.txt';
        $pinned = array_values(array_filter(
            array_map(static fn (string $line): string => trim(explode('  # ', $line, 2)[0]), file($fixture, FILE_IGNORE_NEW_LINES) ?: []),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));
        $this->assertNotSame([], $pinned);
        $this->assertMatchesRegularExpression('/^(\S+) > (\S+) ([a-z0-9_]+) #[0-9a-f]{8}$/', $pinned[0]);
        preg_match('/^(\S+) > (\S+) ([a-z0-9_]+) #/', $pinned[0], $place);

        // The same opener, reader and table - a method already pinned - with
        // a statement nobody reviewed.
        $added = self::snapshotReadKey($place[1], $place[2], $place[3], 'select `id` from `'.$place[3].'` where `synthetic_unreviewed_read` = ?');

        $this->assertStringStartsWith($place[1].' > '.$place[2].' '.$place[3].' #', $added);
        $this->assertNotContains($added, $pinned, 'A second ordinary read in a pinned method must not pass as the first.');
    }
}
