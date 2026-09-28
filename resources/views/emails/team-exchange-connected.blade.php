@php use App\Support\MailFormat as F; @endphp
@extends('emails.layout', [
  'title' => 'Exchange connected',
  'kicker' => 'Team notice · Exchange connected',
  'preheader' => $name.' connected '.$exchange.' ('.$mode.').',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#111827;">
    {{ $name }} connected {{ $exchange }}
  </h1>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    @if ($mode === 'Demo')
      A demo (test-money) account was connected. It trades on the exchange's test network and is never invoiced.
    @else
      A live account was connected. It starts receiving trades once its deposit reaches the minimum.
    @endif
  </p>

  @include('emails.partials.facts', ['rows' => [
      ['Customer', $name, ['strong' => true]],
      ['Email', $email, ['href' => 'mailto:'.$email]],
      ['Exchange', $exchange.' · '.$mode],
      ['Balance', $balance === null ? 'Not read yet — shows within a few minutes' : F::money($balance)],
      ['Connected', $connectedAt],
      ['Account ID', $uniId, ['mono' => true]],
  ]])

  @include('emails.partials.button', ['url' => $userUrl, 'label' => 'Open customer →'])
@endsection

@section('footnote')
  Team notice — sent to the Pixel Alpha team, never to customers.
@endsection
