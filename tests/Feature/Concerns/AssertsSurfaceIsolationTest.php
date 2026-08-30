<?php

namespace Tests\Feature\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\TestCase;

final class AssertsSurfaceIsolationTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;

    public function test_query_count_guard_rejects_two_zero_query_renders(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The first render issued no queries');

        $this->assertQueryCountIndependentOfRows(
            static function (): void {},
            static function (): void {},
        );
    }

    public function test_query_counter_restores_logging_after_a_render_throws(): void
    {
        $connection = DB::connection();
        $this->assertFalse($connection->logging());

        try {
            $this->queriesDuring(static function (): void {
                throw new RuntimeException('Synthetic render failure.');
            });

            $this->fail('The render exception should escape the query counter.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic render failure.', $exception->getMessage());
        }

        $this->assertFalse($connection->logging());
    }

    public function test_identifier_guard_rejects_a_column_from_an_unreferenced_table(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('MariaDB rejects the out-of-scope identifier before the harness needs to.');
        }

        Schema::create('identifier_guard_source', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('identifier_guard_unreferenced', function (Blueprint $table): void {
            $table->string('unrelated_column');
        });

        try {
            $this->expectException(AssertionFailedError::class);
            $this->expectExceptionMessage('`unrelated_column` is not a column, table or alias');

            $this->assertQueriesNameOnlyRealIdentifiers(
                static function (): void {
                    DB::select('select "unrelated_column" from "identifier_guard_source"');
                },
            );
        } finally {
            Schema::dropIfExists('identifier_guard_unreferenced');
            Schema::dropIfExists('identifier_guard_source');
        }
    }
}
