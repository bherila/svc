<!doctype html><html lang="en"><head><meta charset="utf-8"><title>{{ $heading }}</title></head><body>
<h1>{{ $heading }}</h1>
@foreach ($paragraphs as $paragraph)
<p>{!! nl2br(e($paragraph)) !!}</p>
@endforeach
@if ($actionUrl !== null)
<p><a href="{{ $actionUrl }}">{{ $actionLabel ?? $actionUrl }}</a></p>
@endif
</body></html>
