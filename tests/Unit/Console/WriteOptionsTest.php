<?php

namespace Tests\Unit\Console;

use App\Console\Commands\MutationCommand;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class WriteOptionsTest extends TestCase
{
    public function test_preview_rolls_back_and_apply_runs_the_same_operation_without_an_extra_transaction(): void
    {
        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollBack')->once();
        foreach ([false, true] as $apply) {
            $command = $this->command();
            $output = new BufferedOutput;
            $this->assertSame(0, $command->run(new ArrayInput(['--format' => 'json', '--apply' => $apply]), $output));
            $this->assertSame(['dry_run' => ! $apply, 'result' => 7.0], json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR));
            $this->assertSame(1, $command->calls);
        }
    }

    public function test_preview_rolls_back_even_when_the_operation_throws(): void
    {
        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('rollBack')->once();
        $command = $this->command();
        $command->fail = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Synthetic refusal');
        $command->run(new ArrayInput([]), new BufferedOutput);
    }

    public function test_conflicting_flags_and_invalid_formats_never_reach_the_operation(): void
    {
        foreach ([['--apply' => true, '--dry-run' => true], ['--apply' => true, '--pretend' => true], ['--format' => 'yaml']] as $options) {
            $command = $this->command();
            $output = new BufferedOutput;
            $this->assertSame(2, $command->run(new ArrayInput($options), $output));
            $this->assertSame(0, $command->calls);
            $this->assertNotEmpty($output->fetch());
        }
    }

    public function test_legacy_preview_flags_keep_json_clean_and_do_not_apply(): void
    {
        DB::shouldReceive('beginTransaction')->twice();
        DB::shouldReceive('rollBack')->twice();
        foreach (['dry-run', 'pretend'] as $flag) {
            $command = $this->command();
            $output = new BufferedOutput;
            $this->assertSame(0, $command->run(new ArrayInput(['--'.$flag => true, '--format' => 'json']), $output));
            $this->assertSame(['dry_run' => true, 'result' => 7.0], json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    private function command(): MutationCommand
    {
        $command = new class extends MutationCommand
        {
            protected $signature = 'svc:synthetic:write';

            public int $calls = 0;

            public bool $fail = false;

            public function handle(): int
            {
                $result = $this->preview(function (): float {
                    $this->calls++;
                    if ($this->fail) {
                        throw new RuntimeException('Synthetic refusal');
                    }

                    return 7.0;
                });
                $this->json(['dry_run' => ! $this->applying(), 'result' => $result]);

                return self::SUCCESS;
            }
        };
        $command->setLaravel($this->app);

        return $command;
    }
}
