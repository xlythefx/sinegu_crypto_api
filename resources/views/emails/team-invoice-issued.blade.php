@php use App\Support\MailFormat as F; @endphp
@extends('emails.layout', [
  'title' => 'Invoice issued',
  'kicker' => 'Team notice · Invoice issued',
  'preheader' => $name.' was invoiced '.F::money($amount).' for '.$period.'.',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#111827;">
    {{ $name }} was invoiced {{ F::money($amount) }}
  </h1>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    Their {{ $period }} invoice was generated and emailed to them. If it is still unpaid you will be told
    again with each reminder, before trading pauses.
  </p>

  @include('emails.partials.facts', ['rows' => [
      ['Customer', $name, ['strong' => true]],
      ['Email', $email, ['href' => 'mailto:'.$email]],
      ['Amount', F::money($amount), ['strong' => true]],
      ['Period', $period.' · '.$exchange],
      ['Due by', $dueDate],
      ['Invoice', '#'.$invoiceId, ['mono' => true]],
  ]])

  @include('emails.partials.button', ['url' => $invoicesUrl, 'label' => 'Open invoice history →'])
@endsection

@section('footnote')
  Team notice — sent to the Pixel Alpha team, never to customers.
@endsection
