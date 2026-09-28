@php
  use App\Support\MailFormat as F;
  use App\Mail\PaymentReminder as R;
  $paused = $stage === R::STAGE_PAUSED;
@endphp
@extends('emails.layout', [
  'title' => 'Invoice reminder',
  'kicker' => $paused ? 'Trading paused' : 'Payment reminder',
  'preheader' => $paused
      ? 'Your invoice is unpaid, so trading on your account is paused. Paying switches it back on.'
      : 'Your '.$period.' invoice of '.F::money($amount).' is still open.',
])

@section('content')
  @if ($stage === R::STAGE_GENTLE)
    <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#111827;">
      A quick reminder, {{ $name }}
    </h1>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
      Your {{ $period }} invoice is still open. If you have already paid, thank you — it can take a few
      minutes to show up, and you can ignore this email.
    </p>
  @elseif ($stage === R::STAGE_FIRM)
    <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#111827;">
      Your invoice is still unpaid
    </h1>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
      Hi {{ $name }}, we have not received payment for your {{ $period }} invoice yet.
      To keep the bots trading on your account, please pay it before
      <strong style="color:#111827;">{{ $pauseDate }}</strong> — after that, trading pauses until it is settled.
    </p>
  @else
    <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#b91c1c;">
      Trading on your account is paused
    </h1>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
      Hi {{ $name }}, your {{ $period }} invoice is still unpaid, so the bots have stopped opening new trades
      on your account. Nothing has been removed — your exchange connection and your history are exactly as
      they were.
    </p>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
      <strong style="color:#111827;">As soon as the invoice is paid, trading switches back on by itself.</strong>
    </p>
  @endif

  @include('emails.partials.facts', ['rows' => [
      ['Amount due', F::money($amount), ['strong' => true]],
      ['Issued', $issuedOn],
      [$paused ? 'Paused since' : 'Trading pauses on', $pauseDate, $stage === R::STAGE_GENTLE ? [] : ['color' => '#b91c1c', 'strong' => true]],
      ['Invoice', '#'.$invoiceId, ['mono' => true]],
  ]])

  @include('emails.partials.button', ['url' => $invoiceUrl, 'label' => $paused ? 'Pay now and resume trading →' : 'Pay invoice →'])

  <p style="margin:0 0 20px;font-size:13px;line-height:1.7;color:#6b7280;">
    Having trouble paying, or think this is a mistake? Reply to this email and we will sort it out with you.
  </p>
@endsection
