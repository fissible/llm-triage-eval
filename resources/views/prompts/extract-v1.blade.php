Extract the fields from this failure.

@if (!empty($case['message']))
Message: {{ $case['message'] }}
@endif
@if (!empty($case['root_exception']))
Root exception: {{ $case['root_exception'] }}
@endif
@if (!empty($case['error_detail']))
Details:
@foreach ($case['error_detail'] as $d)
- [{{ $d['code'] ?? '?' }}] {{ $d['description'] ?? ($d['message'] ?? '') }}
@endforeach
@endif
@if (!empty($case['stack_top']))
Stack (top frames):
@foreach (array_slice($case['stack_top'], 0, 3) as $frame)
  {{ $frame }}
@endforeach
@endif
