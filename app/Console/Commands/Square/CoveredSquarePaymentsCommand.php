<?php

namespace App\Console\Commands\Square;

use App\Console\Commands\MutationCommand;
use App\Models\User;
use App\Services\Square\SquareCoveredPaymentService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Review by default; the actor, exact reviewed facts and --apply --confirm authorize one record. */
final class CoveredSquarePaymentsCommand extends MutationCommand
{
    protected $signature = 'svc:square:covered-payments {workspace} {invoice} {square_invoice}
        {--actor= : Current workspace manager public id}
        {--payment= : Reviewed Square payment id to record}
        {--expected-version= : Invoice version from review}
        {--reviewed-version= : Selected payment version from review}
        {--idempotency-key= : Stable key for this confirmed recording}
        {--confirm : Explicitly confirm recording the selected payment}';

    protected $description = 'Review covered Square payments, or explicitly record one safe payment';

    public function handle(SquareCoveredPaymentService $payments): int
    {
        $workspace = $this->workspaceByPublicId((string) $this->argument('workspace'));
        $actorId = $this->option('actor');
        $actor = is_string($actorId) && Str::isUuid($actorId) ? User::query()->where('public_id', $actorId)->first() : null;
        if ($workspace === null || $actor === null) {
            $this->error('Give a workspace public id and --actor with a current manager public id.');

            return self::INVALID;
        }
        try {
            if (! $this->applying()) {
                $review = $payments->review($actor, $workspace, (string) $this->argument('invoice'), (string) $this->argument('square_invoice'), true);
                if ($this->option('format') === 'json') {
                    $this->json($review);
                } else {
                    $this->line(json_encode($review, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                }

                return self::SUCCESS;
            }
            foreach (['payment', 'expected-version', 'reviewed-version', 'idempotency-key'] as $required) {
                if (! is_string($this->option($required)) || trim($this->option($required)) === '') {
                    $this->error("Recording requires --{$required} from a fresh review.");

                    return self::INVALID;
                }
            }
            $recorded = $payments->record($actor, $workspace, (string) $this->argument('invoice'), (string) $this->argument('square_invoice'),
                (string) $this->option('payment'), 'svc-cli:square-covered-payments', (string) $this->option('idempotency-key'), [
                    'expected_version' => $this->option('expected-version'), 'reviewed_version' => $this->option('reviewed-version'),
                    'confirm' => $this->option('confirm'),
                ], native: true);
            if ($this->option('format') === 'json') {
                $this->json(['data' => ['payment_id' => $recorded->public_id, 'invoice_id' => $this->argument('invoice')]]);
            } else {
                $this->info('The reviewed Square payment was recorded.');
            }

            return self::SUCCESS;
        } catch (DomainException|ModelNotFoundException|ValidationException|HttpExceptionInterface $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
