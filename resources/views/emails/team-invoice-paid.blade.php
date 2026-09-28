@php use App\Support\MailFormat as F; @endphp
@extends('emails.layout', [
  'title' => 'Invoice paid',
  'kicker' => 'Team notice · Invoice paid',
  'preheader' => $name.' paid '.F::money($amount).' for '.$period.'.',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#15803d;">
    {{ $name }} paid {{ F::money($amount) }}
  </h1>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    Their {{ $period }} invoice is settled and they have been sent a thank-you. If their trading was paused for it,
    it is already back on.
  </p>

  @include('emails.partials.facts', ['rows' => [
      ['Customer', $name, ['strong' => true]],
      ['Email', $email, ['href' => 'mailto:'.$email]],
      ['Amount', F::money($amount), ['strong' => true, 'color' => '#15803d']],
      ['Method', $method],
      ['Reference', $reference, ['mono' => true]],
      ['Paid', $paidAt],
      ['Invoice', '#'.$invoiceId.' · '.$period, ['mono' => true]],
  ]])

  @include('emails.partials.button', ['url' => $invoicesUrl, 'label' => 'Open invoice history →'])
@endsection

@section('footnote')
  Team notice — sent to the Pixel Alpha team, never to customers.
@endsection
