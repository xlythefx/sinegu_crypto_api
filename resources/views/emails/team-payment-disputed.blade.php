@extends('emails.layout', [
  'title' => 'Disputed payment',
  'kicker' => 'Team notice · Disputed payment',
  'preheader' => $claimantName.' and '.$holderName.' both say a '.$amount.' '.$asset.' payment is theirs.',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#b91c1c;">
    Two customers claim the same payment
  </h1>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    A payment of <strong style="color:#111827;">{{ $amount }} {{ $asset }}</strong> was matched to
    {{ $holderName }}'s invoice by {{ $firstVia }}. {{ $claimantName }} has now entered the same
    transaction ID as proof that it was theirs. Only one of them sent it, so one invoice may be paid with
    the other customer's money.
  </p>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    Ask both for the withdrawal record from their exchange (it shows the transaction ID, amount and time).
    Whoever cannot show it did not send it. Then correct the invoices and mark the dispute resolved on
    Crypto Transfers. {{ $claimantName }}'s invoice stays unpaid until then.
  </p>

  @include('emails.partials.facts', ['rows' => [
      ['Payment', $amount.' '.$asset.' · '.$network, ['strong' => true]],
      ['Transaction ID', $txHash, ['mono' => true]],
      ['Received', $receivedAt],
      ['Currently paying', $holderName.' · invoice #'.$holderInvoiceId.' ('.$holderPeriod.')', ['strong' => true]],
      ['Their email', $holderEmail, ['href' => 'mailto:'.$holderEmail]],
      ['Also claimed by', $claimantName.' · invoice #'.$claimantInvoiceId.' ('.$claimantPeriod.')', ['strong' => true, 'color' => '#b91c1c']],
      ['Their email', $claimantEmail, ['href' => 'mailto:'.$claimantEmail]],
      ['Claimed', $claimedAt],
  ]])

  @include('emails.partials.button', ['url' => $reviewUrl, 'label' => 'Review on Crypto Transfers →'])
@endsection

@section('footnote')
  Team notice — sent to the Pixel Alpha team, never to customers.
@endsection
