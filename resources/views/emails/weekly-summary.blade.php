@php
  use App\Support\MailFormat as F;
  $losses = max(0, $trades - $wins);
  $cell = 'padding:12px 10px;border:1px solid #e5e7eb;border-radius:12px;text-align:center;';
@endphp
@extends('emails.layout', [
  'title' => 'Your weekly summary',
  'kicker' => 'Weekly summary · '.$weekLabel,
  'preheader' => 'Your week: '.F::signedMoney($pnl).' ('.F::signedPct($returnPct).') across '.$trades.' trades.',
])

@section('content')
  <h1 style="margin:0 0 6px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#111827;">
    Your week, {{ $name }}
  </h1>
  <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#6b7280;">{{ $weekLabel }}</p>

  {{-- The headline: what landed in the account, after exchange fees. --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px;border:1px solid #e5e7eb;border-radius:12px;background:#f9fafb;">
    <tr>
      <td style="padding:18px 18px 4px;font-size:12px;color:#6b7280;">Profit &amp; loss this week</td>
    </tr>
    <tr>
      <td style="padding:0 18px 4px;font-size:30px;font-weight:800;letter-spacing:-0.02em;color:{{ F::tone($pnl) }};">
        {{ F::signedMoney($pnl) }}<span style="font-size:16px;font-weight:700;">&nbsp;&nbsp;{{ F::signedPct($returnPct) }}</span>
      </td>
    </tr>
    <tr>
      <td style="padding:0 18px 16px;font-size:12px;line-height:1.6;color:#6b7280;">
        After {{ F::money($fees) }} in exchange fees · {{ F::signedMoney($pnl + $fees) }} before fees
      </td>
    </tr>
  </table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border-collapse:separate;border-spacing:0;">
    <tr>
      <td width="32%" style="{{ $cell }}">
        <div style="font-size:12px;color:#6b7280;">Trades</div>
        <div style="font-size:18px;font-weight:800;color:#111827;">{{ $trades }}</div>
        <div style="font-size:11px;color:#9ca3af;">{{ $wins }}W · {{ $losses }}L</div>
      </td>
      <td width="2%"></td>
      <td width="32%" style="{{ $cell }}">
        <div style="font-size:12px;color:#6b7280;">Win rate</div>
        <div style="font-size:18px;font-weight:800;color:#111827;">{{ $trades > 0 ? round($wins / $trades * 100) : 0 }}%</div>
        <div style="font-size:11px;color:#9ca3af;">&nbsp;</div>
      </td>
      <td width="2%"></td>
      <td width="32%" style="{{ $cell }}">
        <div style="font-size:12px;color:#6b7280;">Best day</div>
        <div style="font-size:18px;font-weight:800;color:{{ F::tone($bestDayPnl) }};">{{ F::signedMoney($bestDayPnl) }}</div>
        <div style="font-size:11px;color:#9ca3af;">{{ $bestDayLabel }}</div>
      </td>
    </tr>
  </table>

  @if (count($assets) > 0)
    <div style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#6b7280;margin:0 0 10px;">
      By asset
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border:1px solid #e5e7eb;border-radius:12px;">
      @foreach ($assets as $i => $a)
        @php $sep = $i > 0 ? 'border-top:1px solid #e5e7eb;' : ''; @endphp
        <tr>
          <td style="{{ $sep }}padding:11px 16px;font-family:'IBM Plex Mono',SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;font-weight:700;color:#111827;">{{ $a['symbol'] }}</td>
          <td style="{{ $sep }}padding:11px 8px;font-size:13px;color:#6b7280;text-align:right;white-space:nowrap;">{{ $a['trades'] }} {{ $a['trades'] === 1 ? 'trade' : 'trades' }}</td>
          <td style="{{ $sep }}padding:11px 16px;font-size:14px;font-weight:700;color:{{ F::tone($a['pnl']) }};text-align:right;white-space:nowrap;">{{ F::signedMoney($a['pnl']) }}</td>
        </tr>
      @endforeach
    </table>
  @endif

  @include('emails.partials.facts', ['rows' => [
      ['Account balance at the end of the week', F::money($balance), ['strong' => true]],
  ]])

  @include('emails.partials.button', ['url' => $analyticsUrl, 'label' => 'See the full breakdown →'])

  <p style="margin:0 0 20px;font-size:13px;line-height:1.7;color:#6b7280;">
    The weekly return is measured on the balance each day started with, so deposits and withdrawals do not
    move it. Past performance is not a promise of future results.
  </p>
@endsection
