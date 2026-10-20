<?php

namespace App\Console\Commands\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Shared by ordinary and finance mutation adapters. */
trait GuardsWrites
{
    protected function configureDefaults(): void
    {
        parent::configureDefaults();
        foreach (['apply' => 'Write the changes; otherwise preview only', 'dry-run' => 'Deprecated alias for the default preview', 'pretend' => 'Deprecated alias for the default preview'] as $name => $description) {
            if (! $this->getDefinition()->hasOption($name)) {
                $this->addOption($name, null, InputOption::VALUE_NONE, $description);
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $legacy = $this->option('dry-run') || $this->option('pretend');
        if ($this->applying() && $legacy) {
            $this->error('Give --apply or a preview flag, not both.');

            return self::INVALID;
        }
        if ($legacy) {
            $this->deprecation('Deprecated: --dry-run and --pretend; preview is now the default. Use --apply to write.');
        }

        return parent::execute($input, $output);
    }

    protected function applying(): bool
    {
        return (bool) $this->option('apply');
    }

    /**
     * Only for database operations with no immediate external or cache effects.
     * Roll back on every exit, including exceptions, to the caller's transaction.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    protected function preview(Closure $operation): mixed
    {
        if ($this->applying()) {
            return $operation();
        }

        DB::beginTransaction();
        try {
            return $operation();
        } finally {
            DB::rollBack();
        }
    }

    /** For irreversible effects, report intent without calling the operation. */
    protected function planned(): int
    {
        $payload = ['dry_run' => true, 'operation' => $this->getName(), 'executed' => false];
        if ($this->option('format') === 'json') {
            $this->json($payload);
        } else {
            $this->info('Dry run: operation not executed. Re-run with --apply to write.');
        }

        return self::SUCCESS;
    }
}
