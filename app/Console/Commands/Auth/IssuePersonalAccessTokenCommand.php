<?php

namespace App\Console\Commands\Auth;

use App\Console\Commands\MutationCommand;
use App\Models\User;
use App\Support\Auth\PersonalAccessTokenIdentity;
use BWH\Auth\OAuth\Session\ProviderStatusUnavailable;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

class IssuePersonalAccessTokenCommand extends MutationCommand
{
    protected $signature = 'svc:auth:issue-token
        {user_public_id : Exact user public UUID}
        {name : Name for the personal access token}
        {abilities* : Explicit token abilities}
        {--expires-at= : ISO-8601 expiration timestamp (required)}';

    protected $description = 'Issue a named, expiring Sanctum personal access token for one exact user';

    public function handle(): int
    {
        $publicId = trim((string) $this->argument('user_public_id'));

        if (! Str::isUuid($publicId)) {
            $this->error('The user_public_id must be an exact public UUID.');

            return self::INVALID;
        }

        $name = trim((string) $this->argument('name'));

        if ($name === '' || $this->containsControlCharacter($name)) {
            $this->error('The token name must be non-empty and must not contain control characters.');

            return self::INVALID;
        }

        $abilities = $this->normalizedAbilities((array) $this->argument('abilities'));

        if ($abilities === []) {
            $this->error('At least one explicit token ability is required.');

            return self::INVALID;
        }

        $expiresAt = $this->expirationTimestamp((string) ($this->option('expires-at') ?? ''));

        if ($expiresAt === null) {
            $this->error('A future ISO-8601 --expires-at timestamp is required.');

            return self::INVALID;
        }

        $user = User::query()->where('public_id', $publicId)->first();

        if ($user === null) {
            $this->error('No user exists for that public UUID.');

            return self::FAILURE;
        }

        // Stamped with the provider identity it is issued under, so it stops
        // working once that identity ends (see PersonalAccessTokenIdentity).
        try {
            $stamp = app(PersonalAccessTokenIdentity::class)->stampForIssue($user);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ProviderStatusUnavailable) {
            $this->error('Provider identity verification is unavailable. Retry later.');

            return self::FAILURE;
        }

        if (! $this->applying()) {
            return $this->planned();
        }

        /** @var NewAccessToken $issuedToken */
        $issuedToken = DB::transaction(static function () use ($user, $name, $abilities, $expiresAt, $stamp): NewAccessToken {
            $issued = $user->createToken($name, $abilities, $expiresAt);
            if ($stamp !== []) {
                $issued->accessToken->forceFill($stamp)->save();
            }

            return $issued;
        });

        if ($this->option('format') === 'json') {
            $this->json(['user_public_id' => $publicId, 'name' => $name, 'abilities' => $abilities, 'expires_at' => $expiresAt->toIso8601String(), 'token' => $issuedToken->plainTextToken]);

            return self::SUCCESS;
        }

        $this->components->info('Personal access token issued.');
        $this->components->twoColumnDetail('User public UUID', $publicId);
        $this->components->twoColumnDetail('Name', $name);
        $this->components->twoColumnDetail('Abilities', implode(', ', $abilities));
        $this->components->twoColumnDetail('Expires at', $expiresAt->toIso8601String());
        $this->line('Token (shown once): '.$issuedToken->plainTextToken);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $abilities
     * @return list<string>
     */
    private function normalizedAbilities(array $abilities): array
    {
        $normalized = [];

        foreach ($abilities as $ability) {
            $ability = trim((string) $ability);

            if ($ability === '' || $this->containsControlCharacter($ability)) {
                continue;
            }

            if (! in_array($ability, $normalized, true)) {
                $normalized[] = $ability;
            }
        }

        return $normalized;
    }

    private function expirationTimestamp(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        $expiresAt = CarbonImmutable::instance($parsed);

        return $expiresAt->isFuture() ? $expiresAt : null;
    }

    private function containsControlCharacter(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
