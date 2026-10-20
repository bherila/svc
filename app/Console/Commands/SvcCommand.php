<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Tenancy\WorkspacePublicIdResolver;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Shared console presentation and workspace lookup; operations own the rules. */
abstract class SvcCommand extends Command
{
    protected function configureDefaults(): void
    {
        parent::configureDefaults();
        if (! $this->getDefinition()->hasOption('format')) {
            $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output text or json', 'text');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! in_array($this->option('format'), ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        return parent::execute($input, $output);
    }

    protected function workspaceByPublicId(string $value, bool $legacySlug = false): ?Workspace
    {
        $resolver = app(WorkspacePublicIdResolver::class);
        $workspace = $resolver->find($value);
        if ($workspace === null && $legacySlug) {
            $workspace = Workspace::query()->where('slug', $value)->first();
            if ($workspace !== null) {
                $this->deprecation('Deprecated: workspace slugs; use the workspace public UUID.');
            }
        }

        return $workspace;
    }

    protected function deprecation(string $message): void
    {
        // Artisan::call uses a single buffer: never mix notices into JSON.
        if ($this->option('format') !== 'json' || $this->output->getOutput() instanceof ConsoleOutputInterface) {
            $this->output->getErrorStyle()->writeln($message);
        }
    }

    /** @param array<string, mixed> $payload */
    protected function json(array $payload): void
    {
        $this->output->writeln((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), OutputInterface::OUTPUT_RAW);
    }
}
