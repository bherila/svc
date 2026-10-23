<?php

namespace App\Console\Commands;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/** Keep lengthy diagnostic progress out of a machine-readable report. */
abstract class TextReportCommand extends SvcCommand
{
    /** @var array<string, mixed>|null */
    protected ?array $reportPayload = null;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->option('format') !== 'json') {
            return parent::execute($input, $output);
        }

        $this->reportPayload = null;
        $originalOutput = $this->output;
        $originalComponents = $this->components;
        $buffer = new BufferedOutput;
        $this->output = new OutputStyle($input, $buffer);
        $this->components = new Factory($this->output);
        try {
            $exit = parent::execute($input, $this->output);
        } finally {
            $this->output = $originalOutput;
            $this->components = $originalComponents;
        }
        $this->json($this->reportPayload ?? ['error' => trim($buffer->fetch())]);

        return $exit;
    }
}
