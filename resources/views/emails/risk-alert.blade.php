@extends('emails.layout', [
  'title' => $label,
  'kicker' => 'High-risk alert · Needs you now',
  'preheader' => $title,
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#b91c1c;">
    {{ $title }}
  </h1>

  @foreach ($lines as $line)
    <p style="margin:0 0 10px;font-size:15px;line-height:1.7;color:#374151;">{{ $line }}</p>
  @endforeach

  @include('emails.partials.facts', ['rows' => array_values(array_filter([
      ['What', $label, ['strong' => true]],
      ['When', $at],
      $occurrences > 1 ? ['Seen', $occurrences.' times so far', ['color' => '#b91c1c', 'strong' => true]] : null,
  ]))])

  @include('emails.partials.button', ['url' => $fixUrl, 'label' => $fixLabel])

  <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">
    It stays on the <a href="{{ $overviewUrl }}" style="color:#b8862a;text-decoration:none;">Admin Overview</a>
    until someone marks it resolved.
  </p>
@endsection

@section('footnote')
  High-risk alert — sent the moment it happens, then at most once an hour while it keeps happening.
@endsection
