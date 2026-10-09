@extends('emails.layout', [
  'title' => 'Kill switch',
  'kicker' => 'Team notice · Kill switch',
  'preheader' => $ticker.' was switched off after '.$losses.' master losses in a row.',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#b91c1c;">
    {{ $ticker }} was switched off
  </h1>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    {{ $reason }} The kill switch has turned the coin off, so the bot opens no new trades on it for
    anyone. Positions that are already open still close normally on the next exit signal.
  </p>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    It stays off until someone turns it back on in Trading Assets. When it is turned back on, the
    kill switch starts counting the master's losses again from that moment.
  </p>

  @include('emails.partials.facts', ['rows' => [
      ['Coin', $ticker.($broker !== '' ? ' · '.$broker : ''), ['strong' => true]],
      ['Why', $losses.' losing trades in a row on the master account', ['color' => '#b91c1c']],
      ['Switched off', $trippedAt],
  ]])

  @include('emails.partials.button', ['url' => $reviewUrl, 'label' => 'Open Trading Assets →'])
@endsection

@section('footnote')
  Team notice — sent to the Pixel Alpha team, never to customers.
@endsection
