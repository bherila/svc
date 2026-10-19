<?php

namespace Tests\Concerns;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Square\SquareLinkService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

trait FakesSquarePaymentSync
{
    use FakesSquare;

    /** @var array<string, mixed> */
    private array $paymentInvoice = [];

    /** @var array<string, mixed> */
    private array $paymentOrder = [];

    /** @var array<string, array<string, mixed>> */
    private array $squarePayments = [];

    /** @var array<string, array<string, mixed>> */
    private array $squareDisputes = [];

    private ?int $squareInvoiceFailureStatus = null;

    /** @return array{Workspace, User, ClientCompany, ClientInvoice} */
    private function paymentFixture(): array
    {
        Date::setTestNow('2030-02-02 12:00:00');
        config(['app.url' => 'https://svc.example.test', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        $workspace = Workspace::query()->create(['name' => 'Synthetic sync workspace', 'slug' => 'synthetic-sync-workspace']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic sync company', 'slug' => 'synthetic-sync-company']);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-001', 'invoice_kind' => 'ad_hoc', 'status' => 'issued', 'currency' => 'USD', 'total_amount' => 12_345, 'balance_amount' => 12_345, 'is_visible_to_client' => true]);
        $this->connectSquare($workspace, ['webhook_signature_key' => 'fixture-signature-key']);
        app(SquareLinkService::class)->link($workspace, $invoice, $owner, AgentApiVersion::for($invoice), new SquareInvoiceReference('inv_fixture_1', '000001-R-0001', 'USD', SquareMatchMethod::Manual), false);
        $this->paymentInvoice = self::squareInvoice('inv_fixture_1');
        $this->paymentInvoice['status'] = 'PAID';
        $this->paymentInvoice['payment_requests'][0]['total_completed_amount_money'] = ['amount' => 12_345, 'currency' => 'USD'];
        $this->paymentOrder = self::squareOrder();
        $this->squarePayments['pay_fixture_1'] = self::squarePayment();
        $this->squarePayments['pay_fixture_1']['created_at'] = '2030-02-01T16:00:00Z';
        $this->squarePayments['pay_fixture_1']['card_details']['card_payment_timeline'] = ['captured_at' => '2030-02-01T16:00:00Z'];
        Http::fake(function (Request $request) {
            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));
            if ($path === '/v2/invoices/inv_fixture_1') {
                if ($this->squareInvoiceFailureStatus !== null) {
                    return Http::response(['errors' => [['category' => 'AUTHENTICATION_ERROR', 'code' => 'UNAUTHORIZED']]], $this->squareInvoiceFailureStatus);
                }

                return Http::response(['invoice' => $this->paymentInvoice]);
            }
            if ($path === '/v2/orders/order_fixture_1') {
                return Http::response(['order' => $this->paymentOrder]);
            }
            if (str_starts_with($path, '/v2/payments/')) {
                $id = substr($path, strlen('/v2/payments/'));

                return Http::response(['payment' => $this->squarePayments[$id] ?? null]);
            }
            if (str_starts_with($path, '/v2/disputes/')) {
                $id = substr($path, strlen('/v2/disputes/'));

                return isset($this->squareDisputes[$id]) ? Http::response(['dispute' => $this->squareDisputes[$id]]) : Http::response(['errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'NOT_FOUND']]], 404);
            }

            return Http::response([], 404);
        });

        return [$workspace, $owner, $company, $invoice];
    }

    /** @return array<string, mixed> */
    private static function squareDispute(string $id, string $paymentId, string $state, int $amount = 12_345): array
    {
        return [
            'id' => $id,
            'amount_money' => ['amount' => $amount, 'currency' => 'USD'],
            'reason' => 'NOT_AS_DESCRIBED',
            'state' => $state,
            'disputed_payment' => ['payment_id' => $paymentId],
            'card_brand' => 'VISA',
            'location_id' => 'LOCFIXTURE01',
            'created_at' => '2030-02-02T10:00:00Z',
            'updated_at' => '2030-02-02T10:00:00Z',
        ];
    }
}
