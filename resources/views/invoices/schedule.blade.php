<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Billing schedule</title></head><body>
<h1>Billing schedule</h1><p>Next run: {{ $schedule->next_run_on->format('Y-m-d') }}</p><p>Cadence: {{ $schedule->cadence }}</p>
</body></html>
