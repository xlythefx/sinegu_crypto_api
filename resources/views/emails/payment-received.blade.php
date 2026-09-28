@php use App\Support\MailFormat as F; @endphp
@extends('emails.layout', [
  'title' => 'Payment received',
  'kicker' => 'Payment received',
  'preheader' => 'Thank you — your '.$period.' invoice is paid.',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#111827;">
    Thank you, {{ $name }}
  </h1>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    We have received your payment for {{ $period }}. Your invoice is settled — there is nothing else you need to do.
    @if ($wasPaused)
      <strong style="color:#15803d;">Trading on your account is switched back on.</strong>
    @endif
  </p>

  @include('emails.partials.facts', ['rows' => [
      ['Amount paid', F::money($amount), ['strong' => true, 'color' => '#15803d']],
      ['Paid on', $paidAt],
      ['Method', $method],
      ['Reference', $reference, ['mono' => true]],
      ['Invoice', '#'.$invoiceId, ['mono' => true]],
  ]])

  @include('emails.partials.button', ['url' => $invoiceUrl, 'label' => 'View receipt →'])

  <p style="margin:0 0 20px;font-size:13px;line-height:1.7;color:#6b7280;">
    You can download the invoice for your records from the same page at any time.
  </p>
@endsection
