<?php

namespace App\Http\Controllers\Packages;

use App\Exceptions\ServicePackageRefused;
use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use App\Models\User;
use App\Queries\Packages\PublicPackageCatalog;
use App\Services\Packages\PackageRequestWorkflow;
use App\Support\Packages\IntakeQuestion;
use App\Support\Packages\PackagePublicUrls;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\PublicPackagePresenter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public catalog and package pages (docs/service-packages.md, PUB-1..3).
 *
 * No sign-in. Every miss is the same 404 (PUB-1). The pages carry the
 * workspace's name and the package's offer, never an id.
 */
final class PublicPackageController extends Controller
{
    /** The hidden field a person never sees and a form-filling bot fills (PUB-5). */
    public const HONEYPOT = 'website';

    /** Seconds a person needs at least to read the page and fill the form. */
    public const MIN_FILL_SECONDS = 3;

    /** How long a rendered form stays good to submit. */
    public const MAX_FORM_AGE_SECONDS = 86400;

    public const PER_ADDRESS_PER_MINUTE = 5;

    public const PER_PACKAGE_PER_HOUR = 30;

    public function __construct(
        private readonly PublicPackageCatalog $catalog,
        private readonly PackageRequestWorkflow $requests,
    ) {}

    public function catalog(string $token): Response
    {
        $workspace = $this->catalog->workspace($token) ?? abort(404);

        return Inertia::render('packages/public/catalog', [
            'workspace' => ['name' => $workspace->name],
            'packages' => $this->catalog->listed($workspace)->map(
                fn ($package): array => PublicPackagePresenter::summary($workspace, $package, PackageTerms::fromVersion(PublicPackageCatalog::versionOf($package))),
            )->values()->all(),
            'sign_in_url' => route('login', absolute: false),
        ]);
    }

    public function show(string $token, string $linkName): Response
    {
        $workspace = $this->catalog->workspace($token) ?? abort(404);
        $package = $this->catalog->package($workspace, $linkName) ?? abort(404);
        $terms = PackageTerms::fromVersion(PublicPackageCatalog::versionOf($package));
        $viewer = request()->user();

        return Inertia::render('packages/public/show', [
            'workspace' => ['name' => $workspace->name],
            'package' => PublicPackagePresenter::details($workspace, $package, $terms),
            'catalog_url' => PackagePublicUrls::catalog($workspace),
            'sign_in_url' => route('login', absolute: false),
            'request' => [
                'submit_url' => route('packages.public.request', ['token' => $token, 'linkName' => $linkName], absolute: false),
                'form_token' => self::formToken($package),
                'honeypot_field' => self::HONEYPOT,
                'questions' => array_map(static fn (IntakeQuestion $question): array => $question->toArray() + ['max_length' => $question->kind->maxLength()], $terms->intakeQuestions),
                // A signed-in visitor's own name and address, to start from.
                'prefill' => $viewer instanceof User ? ['contact_name' => $viewer->name, 'email' => $viewer->email] : null,
            ],
        ]);
    }

    /**
     * Record a request for this package (PUB-4, PUB-5).
     *
     * Every refusal - a filled honeypot, a form submitted too fast or too
     * late, a throttle, the per-address cap - is the same 422 with the same
     * sentence, so nobody learns which guard stopped them. Validation errors
     * on the fields themselves are ordinary 422s a person can correct.
     */
    public function submit(Request $request, string $token): JsonResponse
    {
        $workspace = $this->catalog->workspace($token) ?? abort(404);
        $package = $this->catalog->package($workspace, (string) $request->route('linkName')) ?? abort(404);
        $address = 'ip:'.($request->ip() ?? 'unknown');

        $refused = $this->filledHoneypot($request)
            || ! $this->formTokenAccepts($package, $request->input('form_token'))
            || ! $this->withinRate("package-requests|{$address}", self::PER_ADDRESS_PER_MINUTE, 60)
            || ! $this->withinRate("package-requests|package:{$package->id}", self::PER_PACKAGE_PER_HOUR, 3600);
        if ($refused) {
            throw ServicePackageRefused::because(PackageRefusal::RequestRefused);
        }

        $viewer = $request->user();
        $this->requests->submit(
            $workspace,
            $package,
            $request->only(['company_name', 'contact_name', 'email', 'phone', 'answers']),
            $viewer instanceof User ? $viewer : null,
            hash_hmac('sha256', $workspace->id.'|'.$address, (string) config('app.key')),
        );

        return response()->json(['status' => 'sent'], 201);
    }

    /** An encrypted note of which form this is and when it was rendered. */
    public static function formToken(ServicePackage $package): string
    {
        return Crypt::encryptString(json_encode(['p' => $package->public_id, 't' => now()->getTimestamp()], JSON_THROW_ON_ERROR));
    }

    private function formTokenAccepts(ServicePackage $package, mixed $token): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }
        try {
            $note = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return false;
        }
        if (! is_array($note) || ($note['p'] ?? null) !== $package->public_id || ! is_int($note['t'] ?? null)) {
            return false;
        }
        $age = now()->getTimestamp() - $note['t'];

        return $age >= self::MIN_FILL_SECONDS && $age <= self::MAX_FORM_AGE_SECONDS;
    }

    private function filledHoneypot(Request $request): bool
    {
        $value = $request->input(self::HONEYPOT);

        return $value !== null && $value !== '';
    }

    private function withinRate(string $key, int $attempts, int $decaySeconds): bool
    {
        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            return false;
        }
        RateLimiter::hit($key, $decaySeconds);

        return true;
    }
}
