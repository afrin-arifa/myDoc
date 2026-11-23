{!! $appName !!} – {!! $heading !!}

@if ($recipientName)
Hello {!! $recipientName !!},

@endif
{!! $introduction !!}

@if ($scheduleChange)
Previous time: {!! implode(', ', array_filter([$scheduleChange['previous']['date'], $scheduleChange['previous']['time'], $scheduleChange['previous']['location'] ?? null])) !!}
New time:      {!! implode(', ', array_filter([$scheduleChange['current']['date'], $scheduleChange['current']['time'], $scheduleChange['current']['location'] ?? null])) !!}

@endif
@foreach ($details as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach

{!! $actionLabel !!}: {!! $actionUrl !!}

@if ($closing)
{!! $closing !!}

@endif
--
{!! $footerTextNote ?? 'This is an automated message from '.$appName.'. For your privacy, it contains scheduling details only.' !!}
