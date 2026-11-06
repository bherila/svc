<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounting\AccountingReadService;
use App\Support\Accounting\AccountingFeed;
use App\Support\Accounting\AccountingFeedQuery;
use App\Support\Accounting\IncomeExportQuery;
use App\Support\Accounting\IncomeYearCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The accounting reads for bookkeeping and tax software over REST, and over
 * MCP through the same routes. A thin adapter: {@see AccountingReadService}
 * checks the caller manages the workspace and computes every figure.
 */
final class AgentAccountingController extends Controller
{
    public function __construct(private readonly AccountingReadService $accounting) {}

    public function invoiceLines(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->accounting->invoiceLines(
            $this->principal($request),
            $workspace,
            AccountingFeedQuery::fromInput(AccountingFeed::InvoiceLines, $request->query()),
        ));
    }

    public function cashIncome(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->accounting->cashIncome(
            $this->principal($request),
            $workspace,
            AccountingFeedQuery::fromInput(AccountingFeed::CashIncome, $request->query()),
        ));
    }

    public function incomeExport(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json(['data' => $this->accounting->incomeYear($this->principal($request), $workspace, IncomeExportQuery::year($request->query()))]);
    }

    /** The same figures as {@see incomeExport()}, streamed as CSV. */
    public function incomeExportCsv(Request $request, Workspace $workspace): StreamedResponse
    {
        $year = IncomeExportQuery::year($request->query());
        // Computed before anything is sent, so a refusal is a status and not a broken file.
        $lines = IncomeYearCsv::lines($this->accounting->incomeYear($this->principal($request), $workspace, $year));

        return response()->streamDownload(static function () use ($lines): void {
            foreach ($lines as $line) {
                echo $line."\r\n";
            }
        }, 'income-'.$year.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function principal(Request $request): User|AgentPrincipal
    {
        $principal = $request->user();
        abort_unless($principal instanceof User || $principal instanceof AgentPrincipal, 401);

        return $principal;
    }
}
