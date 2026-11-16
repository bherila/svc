<?php

namespace Tests\Unit\Database;

use App\Support\Database\LocalStamps\LocalStampClassifier;
use App\Support\Database\LocalStamps\LocalStampSpec;
use App\Support\Database\LocalStamps\LocalStampVerdict;
use Carbon\CarbonImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which stored values were written in a workspace's wall clock before #712,
 * decided without a database. The zone is America/Los_Angeles: seven hours
 * behind UTC until 1 November 2026, eight after.
 */
final class LocalStampClassifierTest extends TestCase
{
    /** @return iterable<string, array{'exclusive'|'mixed'|'companionless'|'new column'|'expiry', string, ?string, string, ?string}> */
    public static function values(): iterable
    {
        yield 'written with its updated_at, local' => ['exclusive', '2026-10-01 10:00:00', '2026-10-01 17:00:03', LocalStampVerdict::CORRECTED, '2026-10-01 17:00:00'];
        yield 'written with its updated_at, already UTC' => ['mixed', '2026-10-01 17:00:00', '2026-10-01 17:00:20', LocalStampVerdict::ALREADY_UTC, null];
        yield 'a minute apart is still one statement' => ['mixed', '2026-10-01 10:00:00', '2026-10-01 17:01:00', LocalStampVerdict::CORRECTED, '2026-10-01 17:00:00'];
        yield 'a minute and a second apart is not' => ['mixed', '2026-10-01 10:00:00', '2026-10-01 17:01:01', LocalStampVerdict::AMBIGUOUS, null];
        yield 'an exclusive column updated since is local by its era' => ['exclusive', '2026-10-01 10:00:00', '2026-10-20 00:00:00', LocalStampVerdict::CORRECTED, '2026-10-01 17:00:00'];
        yield 'a mixed column updated since cannot be told' => ['mixed', '2026-10-01 10:00:00', '2026-10-20 00:00:00', LocalStampVerdict::AMBIGUOUS, null];
        yield 'an expiry is checked fourteen days back' => ['expiry', '2026-11-22 10:00:00', '2026-11-08 18:00:01', LocalStampVerdict::CORRECTED, '2026-11-22 18:00:00'];
        yield 'an expiry written in UTC' => ['expiry', '2026-11-22 18:00:00', '2026-11-08 18:00:01', LocalStampVerdict::ALREADY_UTC, null];
        yield 'the repeated hour, first time round' => ['exclusive', '2026-11-01 01:30:00', '2026-11-01 08:30:00', LocalStampVerdict::CORRECTED, '2026-11-01 08:30:00'];
        yield 'the repeated hour, second time round' => ['exclusive', '2026-11-01 01:30:00', '2026-11-01 09:30:00', LocalStampVerdict::CORRECTED, '2026-11-01 09:30:00'];
        yield 'the repeated hour with nothing to tell them apart' => ['companionless', '2026-11-01 01:30:00', null, LocalStampVerdict::AMBIGUOUS, null];
        yield 'a companionless column inside its era' => ['companionless', '2026-09-15 12:00:00', null, LocalStampVerdict::CORRECTED, '2026-09-15 19:00:00'];
        yield 'before the switch could have written local' => ['companionless', '2026-08-29 20:00:00', null, LocalStampVerdict::OUTSIDE, null];
        yield 'the band around the switch' => ['companionless', '2026-08-30 03:00:00', null, LocalStampVerdict::AMBIGUOUS, null];
        yield 'the first hour sure to be local' => ['companionless', '2026-08-30 08:30:00', null, LocalStampVerdict::CORRECTED, '2026-08-30 15:30:00'];
        yield 'a new column holds nothing but local values' => ['new column', '2026-11-07 10:00:00', '2026-11-12 00:00:00', LocalStampVerdict::CORRECTED, '2026-11-07 18:00:00'];
        yield 'the last local write the old release made' => ['companionless', '2026-11-13 14:28:50', null, LocalStampVerdict::CORRECTED, '2026-11-13 22:28:50'];
        yield 'a value meaning a moment after the old release stopped' => ['companionless', '2026-11-13 15:00:00', null, LocalStampVerdict::AMBIGUOUS, null];
        yield 'a second before the new release' => ['companionless', '2026-11-13 22:28:13', null, LocalStampVerdict::AMBIGUOUS, null];
        yield 'the new release writes UTC' => ['companionless', '2026-11-13 22:28:14', null, LocalStampVerdict::OUTSIDE, null];
        yield 'long after the release' => ['exclusive', '2026-11-14 09:00:00', '2026-11-14 09:00:00', LocalStampVerdict::OUTSIDE, null];
    }

    /** @param  'exclusive'|'mixed'|'companionless'|'new column'|'expiry'  $spec */
    #[DataProvider('values')]
    public function test_a_stored_value_is_decided(string $spec, string $stored, ?string $companion, string $kind, ?string $corrected): void
    {
        $verdict = (new LocalStampClassifier(new DateTimeZone('America/Los_Angeles')))->classify(self::spec($spec), $stored, $companion);

        $this->assertSame([$kind, $corrected], [$verdict->kind, $verdict->stored()]);
    }

    /**
     * Built in the test rather than the provider, which runs outside coverage.
     *
     * @param  'exclusive'|'mixed'|'companionless'|'new column'|'expiry'  $name
     */
    private static function spec(string $name): LocalStampSpec
    {
        $since = CarbonImmutable::parse('2026-08-30 06:29:02', 'UTC');

        return match ($name) {
            'exclusive' => new LocalStampSpec('client_agreements', 'workspace_id', ['signed_at'], 'updated_at', $since, true, true),
            'mixed' => new LocalStampSpec('client_time_entries', 'workspace_id', ['deleted_at'], 'updated_at', $since, false, true),
            'companionless' => new LocalStampSpec('client_company_activity', 'workspace_id', ['created_at'], null, $since, true, true),
            'new column' => new LocalStampSpec('reviewer_grants', 'granter_workspace_id', ['granted_at'], 'created_at', null, true, false),
            'expiry' => new LocalStampSpec('client_project_participation_invitations', 'host_workspace_id', ['expires_at'], 'created_at', null, true, false, companionShiftDays: 14),
        };
    }

    public function test_only_a_zone_off_utc_is_visited(): void
    {
        $this->assertTrue((new LocalStampClassifier(new DateTimeZone('America/Los_Angeles')))->isOffUtc());
        $this->assertTrue((new LocalStampClassifier(new DateTimeZone('Europe/London')))->isOffUtc(), 'London is on BST for part of the era.');
        $this->assertFalse((new LocalStampClassifier(new DateTimeZone('UTC')))->isOffUtc());
        $this->assertFalse((new LocalStampClassifier(new DateTimeZone('Etc/UTC')))->isOffUtc());
    }

    public function test_a_zone_east_of_utc_corrects_backwards(): void
    {
        $spec = new LocalStampSpec('client_invoices', 'workspace_id', ['voided_at'], 'updated_at', CarbonImmutable::parse('2026-08-30 06:29:02', 'UTC'), true, true);
        $verdict = (new LocalStampClassifier(new DateTimeZone('Pacific/Auckland')))->classify($spec, '2026-11-10 13:00:00', '2026-11-10 00:00:01');

        $this->assertSame([LocalStampVerdict::CORRECTED, '2026-11-10 00:00:00'], [$verdict->kind, $verdict->stored()]);
    }
}
