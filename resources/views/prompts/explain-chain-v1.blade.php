Explain this transaction (correlation {{ $correlation }}), events in order:

@foreach ($events as $e)
--- {{ $e['occurred_at'] ?? '' }} · {{ $e['app'] ?? 'unknown' }} · {{ $e['error_type'] ?? '' }}
@if (!empty($e['message']))
{{ $e['message'] }}
@endif
@foreach ($e['error_detail'] ?? [] as $d)
  detail [{{ $d['code'] ?? '?' }}]: {{ $d['description'] ?? ($d['message'] ?? '') }}
@endforeach
@endforeach
