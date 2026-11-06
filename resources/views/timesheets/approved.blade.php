@php
    use App\Support\Submission\TimesheetCsv;
    /** @var array<string, mixed> $timesheet */
    /** @var string $workspaceName */
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Approved timesheet {{ $timesheet['period_label'] }}</title>
    {{-- Dompdf prints this: tables and block boxes only, white paper, dark ink. --}}
    <style>
        @page { size: 8.5in 11in; margin: 0.7in 0.6in; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 10px; line-height: 1.35; background: #ffffff; }
        h1 { margin: 0 0 2px; font-size: 18px; }
        p { margin: 2px 0 10px; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { text-align: left; padding: 4px 5px; border-bottom: 1px solid #d1d5db; vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; }
        th { background: #f3f4f6; font-size: 9px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .right { text-align: right; }
        .total td { font-weight: bold; border-top: 2px solid #111827; border-bottom: 0; }
    </style>
</head>
<body>
    <h1>Approved timesheet</h1>
    <p>{{ $workspaceName }} &middot; {{ $timesheet['period_label'] }}</p>
    <table>
        <colgroup>
            <col style="width: 11%"><col style="width: 15%"><col style="width: 31%"><col style="width: 7%">
            <col style="width: 12%"><col style="width: 14%"><col style="width: 10%">
        </colgroup>
        <thead>
            <tr><th>Date</th><th>Project</th><th>Description</th><th class="right">Hours</th><th>Worked by</th><th>Approved by</th><th>How</th></tr>
        </thead>
        <tbody>
        @forelse ($timesheet['entries'] as $entry)
            <tr>
                <td>{{ $entry['worked_on'] }}</td>
                <td>{{ $entry['project'] }}</td>
                <td>{{ $entry['description'] }}@if ($entry['task']) ({{ $entry['task'] }})@endif</td>
                <td class="right">{{ TimesheetCsv::hours((int) $entry['minutes']) }}</td>
                <td>{{ $entry['worked_by'] }}</td>
                <td>{{ $entry['approved_by'] }}@if ($entry['approved_at']), {{ substr($entry['approved_at'], 0, 10) }}@endif</td>
                <td>{{ $entry['approved_via'] }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Nothing was approved this month.</td></tr>
        @endforelse
            <tr class="total"><td colspan="3">Total</td><td class="right">{{ TimesheetCsv::hours((int) $timesheet['total_minutes']) }}</td><td colspan="3"></td></tr>
        </tbody>
    </table>
    @if ($timesheet['truncated'])
        <p>This timesheet is cut short at the first {{ count($timesheet['entries']) }} entries.</p>
    @endif
</body>
</html>
