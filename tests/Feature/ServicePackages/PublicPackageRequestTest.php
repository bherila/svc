<?php

namespace Tests\Feature\ServicePackages;

use App\Http\Controllers\Packages\PublicPackageController;
use App\Models\ServicePackage;
use App\Models\ServicePackageRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\Packages\PackageRefusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

final class PublicPackageRequestTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;

    private string $url;

    private string $page;

    private Workspace $workspace;

    private User $owner;

    private ServicePackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        [$workspace, $owner] = $this->boundPackageWorkspace();
        $this->package = $this->publishedPackage($workspace, $owner);
        [$this->workspace, $this->owner] = [$workspace, $owner];
        $token = $workspace->refresh()->package_catalog_token;
        $this->page = "/s/{$token}/web-hosting";
        $this->url = "/s/{$token}/web-hosting/requests";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(string $formToken, array $overrides = []): array
    {
        return [
            'form_token' => $formToken,
            'company_name' => 'Lakeside Synthetic Bakery',
            'contact_name' => 'Morgan Synthetic',
            'email' => 'morgan.lakeside@example.com',
            'answers' => ['website_address' => 'lakeside.example.com'],
            ...$overrides,
        ];
    }

    private function formToken(string $firstQuestion = 'website_address'): string
    {
        $token = null;
        $this->get($this->page)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$token, $firstQuestion) {
            $page->where('request.submit_url', $this->url)
                ->where('request.honeypot_field', PublicPackageController::HONEYPOT)
                ->where('request.questions.0.key', $firstQuestion)
                ->where('request.prefill', null);
            $token = $page->toArray()['props']['request']['form_token'];
        });

        return (string) $token;
    }

    #[Requirement('PUB-4')]
    public function test_a_person_who_takes_a_moment_to_fill_the_form_sends_a_request(): void
    {
        $token = $this->formToken();
        $this->travel(10)->seconds();

        $this->postJson($this->url, $this->form($token))->assertCreated()->assertExactJson(['status' => 'sent']);

        $this->assertSame(1, ServicePackageRequest::query()->count());
    }

    /** @return iterable<string, array{\Closure(self, string): array<string, mixed>}> */
    public static function refusals(): iterable
    {
        yield 'filled honeypot' => [fn (self $test, string $token): array => $test->form($token, [PublicPackageController::HONEYPOT => 'https://spam.example.net'])];
        yield 'no form token' => [fn (self $test, string $token): array => $test->form('')];
        yield 'forged form token' => [fn (self $test, string $token): array => $test->form('not-encrypted')];
    }

    /** @param \Closure(self, string): array<string, mixed> $form */
    #[DataProvider('refusals')]
    #[Requirement('PUB-5')]
    public function test_a_bot_is_refused_with_the_generic_sentence(\Closure $form): void
    {
        $token = $this->formToken();
        $this->travel(10)->seconds();

        $this->postJson($this->url, $form($this, $token))->assertStatus(422)
            ->assertExactJson(['message' => PackageRefusal::RequestRefused->message(), 'code' => PackageRefusal::RequestRefused->value]);
        $this->assertSame(0, ServicePackageRequest::query()->count());
    }

    #[Requirement('PUB-5')]
    public function test_too_fast_too_late_and_too_often_all_look_the_same(): void
    {
        $generic = ['message' => PackageRefusal::RequestRefused->message(), 'code' => PackageRefusal::RequestRefused->value];

        $fast = $this->formToken();
        $this->postJson($this->url, $this->form($fast))->assertStatus(422)->assertExactJson($generic);

        $stale = $this->formToken();
        $this->travel(2)->days();
        $this->postJson($this->url, $this->form($stale))->assertStatus(422)->assertExactJson($generic);

        $token = $this->formToken();
        $this->travel(10)->seconds();
        for ($i = 0; $i < PublicPackageController::PER_ADDRESS_PER_MINUTE; $i++) {
            $this->postJson($this->url, $this->form($token, ['email' => "visitor{$i}@example.com"]))->assertCreated();
        }
        $this->postJson($this->url, $this->form($token, ['email' => 'one.more@example.com']))->assertStatus(422)->assertExactJson($generic);
        $this->assertSame(PublicPackageController::PER_ADDRESS_PER_MINUTE, ServicePackageRequest::query()->count());
    }

    /**
     * A version published while the visitor fills the form: a question added
     * since, or one removed, is never put to them as a field error they cannot
     * act on. They are asked to reload; on the reloaded form the request is
     * recorded against the version it showed.
     */
    #[Requirement('PUB-4', 'EDGE-7')]
    public function test_a_form_shown_before_a_new_version_was_published_is_asked_to_reload(): void
    {
        $token = $this->formToken();
        $shown = (int) $this->package->published_version_id;
        app(ServicePackageWorkflow::class)->update($this->owner, $this->workspace, $this->package->refresh(), ['intake_questions' => [
            ['label' => 'Expected monthly visitors', 'kind' => 'short_text', 'required' => true],
        ]]);
        app(ServicePackageWorkflow::class)->publish($this->owner, $this->workspace, $this->package->refresh());
        $this->assertNotSame($shown, (int) $this->package->refresh()->published_version_id);
        $this->travel(10)->seconds();

        $this->postJson($this->url, $this->form($token))->assertStatus(422)
            ->assertExactJson(['message' => PackageRefusal::FormChanged->message(), 'code' => PackageRefusal::FormChanged->value]);
        $this->assertSame(0, ServicePackageRequest::query()->count());

        $reloaded = $this->formToken('expected_monthly_visitors');
        $this->travel(10)->seconds();
        $this->postJson($this->url, $this->form($reloaded, ['answers' => ['expected_monthly_visitors' => 'About 2,000']]))->assertCreated();
        $this->assertSame((int) $this->package->published_version_id, (int) ServicePackageRequest::query()->sole()->service_package_version_id);
    }

    public function test_a_field_a_person_can_fix_gets_an_ordinary_validation_error(): void
    {
        $token = $this->formToken();
        $this->travel(10)->seconds();

        $this->postJson($this->url, $this->form($token, ['email' => 'not an address']))->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    #[Requirement('X-1')]
    public function test_with_the_feature_off_the_form_does_not_exist(): void
    {
        $token = $this->formToken();
        config(['svc.service_packages.enabled' => false]);
        $this->travel(10)->seconds();

        $this->postJson($this->url, $this->form($token))->assertNotFound();
    }
}
