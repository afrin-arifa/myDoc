{!! $appName !!} – {!! $heading !!}

@if ($recipientName)
Hello {!! $recipientName !!},

@endif
{!! $introduction !!}

@if ($scheduleChange)
Previous time: {!! $scheduleChange['previous']['date'] !!}, {!! $scheduleChange['previous']['time'] !!}
New time:      {!! $scheduleChange['current']['date'] !!}, {!! $scheduleChange['current']['time'] !!}

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
