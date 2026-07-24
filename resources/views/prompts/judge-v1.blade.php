Score this summary against the evidence.

=== EVIDENCE ===
App: {{ $case['app'] ?? 'unknown' }}
@if (!empty($case['error_type']))
Error type: {{ $case['error_type'] }}
@endif
@if (!empty($case['message']))
Message: {{ $case['message'] }}
@endif
@if (!empty($case['root_exception']))
Root exception: {{ $case['root_exception'] }}
@endif
@if (!empty($case['error_detail']))
Correlated details:
@foreach ($case['error_detail'] as $d)
- [{{ $d['code'] ?? '?' }}] {{ $d['description'] ?? ($d['message'] ?? '') }}
@endforeach
@endif

=== SUMMARY TO SCORE ===
{{ $summary }}
