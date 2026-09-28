@php use App\Support\MailFormat as F; @endphp
@extends('emails.layout', [
  'title' => 'Invoice unpaid',
  'kicker' => $paused ? 'Team notice · Trading paused' : 'Team notice · Unpaid invoice',
  'preheader' => $name.' has not paid '.F::money($amount).' after '.$daysOpen.' days.',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:{{ $paused ? '#b91c1c' : '#111827' }};">
    @if ($paused)
      {{ $name }}'s trading is paused
    @else
      {{ $name }} has not paid yet
    @endif
  </h1>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    @if ($paused)
      Their {{ $period }} invoice is still unpaid after {{ $daysOpen }} days, so their account has been paused and
      has stopped taking trades. It switches back on by itself the moment they pay.
    @else
      Their {{ $period }} invoice has been open for {{ $daysOpen }} days, and they have just been sent a reminder.
      Trading on their account pauses on <strong style="color:#111827;">{{ $pauseDate }}</strong> if it is still
      unpaid — worth a personal nudge before then.
    @endif
  </p>

  @include('emails.partials.facts', ['rows' => [
      ['Customer', $name, ['strong' => true]],
      ['Email', $email, ['href' => 'mailto:'.$email]],
      ['Amount due', F::money($amount), ['strong' => true]],
      ['Issued', $issuedOn.' · '.$daysOpen.' days ago'],
      [$paused ? 'Paused since' : 'Trading pauses on', $pauseDate, ['color' => '#b91c1c', 'strong' => true]],
      ['Invoice', '#'.$invoiceId, ['mono' => true]],
  ]])

  @include('emails.partials.button', ['url' => $userUrl, 'label' => 'Open customer →'])
@endsection

@section('footnote')
  Team notice — sent to the Pixel Alpha team, never to customers.
@endsection
