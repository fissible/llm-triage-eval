Classify this integration failure.

@if (!empty($case['message']))
Message: {{ $case['message'] }}
@endif
@if (!empty($case['element']))
Flow/element: {{ $case['element'] }}
@endif
@if (!empty($case['root_exception']))
Root exception: {{ $case['root_exception'] }}
@endif
@if (!empty($case['error_detail']))
Correlated details (the true root cause is often here, behind a generic error):
@foreach ($case['error_detail'] as $d)
- [{{ $d['code'] ?? '?' }}] {{ $d['description'] ?? ($d['message'] ?? '') }}
@endforeach
@endif
@if (!empty($case['http_method']))
HTTP: {{ $case['http_method'] }} {{ $case['http_status'] ?? '' }} {{ $case['resource_url'] ?? '' }}
@endif
@if (!empty($case['stack_top']))
Stack (top frames):
@foreach (array_slice($case['stack_top'], 0, 4) as $frame)
  {{ $frame }}
@endforeach
@endif
