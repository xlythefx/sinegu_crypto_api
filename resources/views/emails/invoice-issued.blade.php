@php
  use App\Support\MailFormat as F;
  $rate = rtrim(rtrim(number_format($feeRate, 2), '0'), '.');
@endphp
@extends('emails.layout', [
  'title' => 'Your Pixel Alpha invoice',
  'kicker' => 'Invoice · '.$period,
  'preheader' => 'Your '.$period.' invoice is ready: '.F::money($amount).', due '.$dueDate.'.',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#111827;">
    Your {{ $period }} invoice is ready
  </h1>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    Hi {{ $name }}, the strategies made you new profit on {{ $exchange }} in {{ $period }}.
    Our fee is {{ $rate }}% of that profit — nothing on anything else.
  </p>

  @include('emails.partials.facts', ['rows' => [
      ['New profit above your previous high', F::money($profit), ['color' => '#15803d', 'strong' => true]],
      ['Pixel Alpha fee ('.$rate.'%)', F::money($amount), ['strong' => true]],
      ['Due by', $dueDate],
      ['Invoice', '#'.$invoiceId, ['mono' => true]],
  ]])

  @include('emails.partials.button', ['url' => $invoiceUrl, 'label' => 'View and pay invoice →'])

  <p style="margin:0 0 20px;font-size:13px;line-height:1.7;color:#6b7280;">
    You pay in USDT (TRC-20) straight from your dashboard. The fee only ever applies to profit above your
    previous high — a losing month is never invoiced, and losses are recovered before a fee is due again.
  </p>
@endsection
