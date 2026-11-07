<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Autopay turned on</title></head><body>
<h1>Autopay is on for {{ $companyName }}</h1>
<p>{{ $providerName }} turned on autopay for {{ $companyName }}, recording that you agreed to it outside SVC. From now on, {{ $providerName }} will charge {{ $methodLabel }} for each invoice on its due date, up to {{ $limit }} per invoice. An invoice over that limit is not charged and stays yours to pay by hand.</p>
<p>What {{ $providerName }} recorded about your agreement:</p>
<blockquote>{!! nl2br(e($attestation)) !!}</blockquote>
<p>If this is not what you agreed, you can turn autopay off at any time in your client portal: <a href="{{ $portalUrl }}">{{ $portalUrl }}</a></p>
</body></html>
