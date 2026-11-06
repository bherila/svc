<?php

namespace App\Services\Submission;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The approved timesheet as a PDF (docs/ux-overhaul.md section 7e).
 *
 * A plain table in the font Dompdf ships, on white paper: a timesheet is read
 * and sent, not designed. The same rows as the screen and the CSV.
 */
final class ApprovedTimesheetPdf
{
    /** @param array<string, mixed> $timesheet */
    public function render(string $workspaceName, array $timesheet): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('timesheets.approved', ['workspaceName' => $workspaceName, 'timesheet' => $timesheet])->render());
        $dompdf->setPaper([0.0, 0.0, 612.0, 792.0]);
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
