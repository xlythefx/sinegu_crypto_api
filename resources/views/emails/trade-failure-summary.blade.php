@php
  $fmtUsd = fn ($v) => $v === null ? '—' : '$'.number_format((float) $v, (float) $v >= 1000 ? 0 : 2);
  $fmtQty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
  $kindColor = ['failed' => '#b91c1c', 'limit' => '#b45309', 'shrunk' => '#b45309', 'rejected' => '#b91c1c'];
  $factRows = [];
  if ($counts['failed'] > 0) { $factRows[] = ['Refused or unconfirmed orders', (string) $counts['failed'], ['color' => '#b91c1c', 'strong' => true]]; }
  if ($counts['limit'] > 0) { $factRows[] = ['Entries skipped at Binance’s position limit', (string) $counts['limit'], ['color' => '#b45309', 'strong' => true]]; }
  if ($counts['shrunk'] > 0) { $factRows[] = ['Entries shrunk to fit the limit', (string) $counts['shrunk'], ['color' => '#b45309', 'strong' => true]]; }
  if ($counts['rejected'] > 0) { $factRows[] = ['Signals rejected', (string) $counts['rejected'], ['color' => '#b91c1c', 'strong' => true]]; }
  if ($counts['resolved'] > 0) { $factRows[] = ['Failed, then fixed by a retry', (string) $counts['resolved']]; }
@endphp
@extends('emails.layout', [
  'title' => 'Trades this hour',
  'kicker' => 'Trade alert · Hourly summary',
  'preheader' => 'What needed attention between '.$window.'.',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#111827;">
    Trades that need a look
  </h1>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    Between {{ $window }}, these signals had accounts that did not trade as planned.
    Everything else went through normally.
  </p>

  @include('emails.partials.facts', ['rows' => $factRows])

  @foreach ($signals as $s)
    <div style="margin:0 0 18px;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
      <div style="padding:12px 18px;background:#f9fafb;border-bottom:1px solid #e5e7eb;">
        <span style="font-size:15px;font-weight:800;color:#111827;">{{ $s['ticker'] }} {{ $s['side'] }} {{ $s['type'] }}</span>
        <span style="font-size:13px;color:#6b7280;"> · {{ $s['broker'] }} · {{ \Carbon\Carbon::parse($s['at'])->utc()->format('H:i') }} UTC</span>
      </div>
      @foreach ($s['items'] as $i => $it)
        <div style="{{ $i > 0 ? 'border-top:1px solid #f1f5f9;' : '' }}padding:10px 18px;">
          <div style="font-size:14px;font-weight:700;color:#111827;">
            {{ $it['owner'] ?? $it['account'] ?? ($it['kind'] === 'rejected' ? 'Whole signal' : 'Unknown account') }}
            @if (! empty($it['account']) && ! empty($it['owner']) && $it['account'] !== $it['owner'])<span style="font-weight:400;color:#6b7280;"> · {{ $it['account'] }}</span>@endif
            @if ($it['is_master'])<span style="font-size:11px;font-weight:800;color:#b8862a;"> MASTER</span>@endif
          </div>
          <div style="margin-top:2px;font-size:13px;font-weight:600;color:{{ ($it['resolved'] && $it['kind'] === 'failed') ? '#15803d' : ($kindColor[$it['kind']] ?? '#374151') }};">
            @if ($it['kind'] === 'failed' && $it['resolved'])
              {{ $it['label'] }} — fixed by a retry
            @elseif ($it['kind'] === 'shrunk' && ! empty($it['limit']))
              Shrunk to fit Binance’s {{ $fmtUsd($it['limit']['cap_usd'] ?? null) }} limit at {{ $it['limit']['leverage'] ?? '?' }}×:
              {{ $fmtQty($it['limit']['requested_qty'] ?? null) }} → {{ $fmtQty($it['quantity'] ?? null) }} coins
            @elseif ($it['kind'] === 'limit' && ! empty($it['limit']))
              Skipped: already at Binance’s {{ $fmtUsd($it['limit']['cap_usd'] ?? null) }} limit at {{ $it['limit']['leverage'] ?? '?' }}×
            @else
              {{ $it['label'] }}@if ($it['kind'] === 'failed' && ! empty($it['retryable'])) — the bot was still retrying when this was sent @endif
            @endif
          </div>
          @if (! empty($it['message']))
            <div style="margin-top:4px;font-family:'IBM Plex Mono',SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:#6b7280;word-break:break-word;">{{ $it['message'] }}</div>
          @endif
        </div>
      @endforeach
    </div>
  @endforeach

  @if ($counts['limit'] > 0 || $counts['shrunk'] > 0)
    <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#374151;">
      A shrunk or skipped entry means the coin’s size is bigger than Binance allows for that account’s
      balance. Lower the base size or the leverage in
      <a href="{{ $assetsUrl }}" style="color:#b8862a;text-decoration:none;">Trading Assets</a>; the
      “Binance position limit” card shows the safe balance.
    </p>
  @endif

  @include('emails.partials.button', ['url' => $issuesUrl, 'label' => 'Open the Signal Log →'])

  @if ($counts['other_problems'] > 0)
    <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">
      Also {{ $counts['other_problems'] }} {{ $counts['other_problems'] === 1 ? 'account was' : 'accounts were' }} skipped for a known problem
      (a blocked API key, a deposit not synced yet). Those repeat on every signal, so they are only counted here;
      the Signal Log’s Issues view lists them.
    </p>
  @endif
@endsection

@section('footnote')
  Trade alert — sent once an hour, only when something needed a look. A close that fails is emailed straight away.
@endsection
