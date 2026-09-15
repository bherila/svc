<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $subjectLine }}</title></head>
<body>
<h1>Invoice ready for review</h1>
<p>{{ $clientName }} invoice {{ $invoiceNumber }} was issued for {{ $currency }} {{ number_format($totalAmount / 100, 2) }}.</p>
<p>The PDF for the revision originally issued is attached. Review the invoice before sending it to the client.</p>
<p><a href="{{ $openUrl }}" style="display:inline-block;padding:10px 16px;background:#111827;color:#ffffff;text-decoration:none;border-radius:6px">Open</a></p>
<p>This link requires your normal sign-in and invoice permissions.</p>
</body>
</html>
