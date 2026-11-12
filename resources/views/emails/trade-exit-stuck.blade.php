@extends('emails.layout', [
  'title' => 'Close failed',
  'kicker' => 'Trade alert · Needs you now',
  'preheader' => $ticker.' '.$side.' may still be open on '.$total.' '.($total === 1 ? 'account' : 'accounts').'.',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#b91c1c;">
    The bot could not close {{ $ticker }} {{ $side }}
  </h1>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    @if ($why === 'abandoned')
      The exit signal came in, but {{ $broker }} did not confirm the close, and the bot has stopped
      retrying after {{ $attempts ?? 'several' }} attempts.
    @else
      The exit signal came in, but {{ $broker }} refused the close, so the bot will not try again by itself.
    @endif
    These positions may still be <strong>open</strong>. Check them and close them by hand if they are.
  </p>

  @include('emails.partials.facts', ['rows' => [
      ['Coin', $ticker.' · '.$side.' · '.$broker, ['strong' => true]],
      ['When', $at],
      ['Accounts', (string) $total, ['color' => '#b91c1c', 'strong' => true]],
  ]])

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border:1px solid #e5e7eb;border-radius:12px;">
    @foreach ($accounts as $i => $a)
      <tr>
        <td style="{{ $i > 0 ? 'border-top:1px solid #e5e7eb;' : '' }}padding:12px 18px;">
          <div style="font-size:15px;font-weight:700;color:#111827;">
            {{ $a['owner'] ?? 'Unknown user' }}@if (! empty($a['account']) && $a['account'] !== $a['owner'])<span style="font-weight:400;color:#6b7280;"> · {{ $a['account'] }}</span>@endif
          </div>
          @if (! empty($a['error']))
            <div style="margin-top:4px;font-family:'IBM Plex Mono',SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;color:#b91c1c;word-break:break-word;">{{ $a['error'] }}</div>
          @endif
        </td>
      </tr>
    @endforeach
    @if ($total > count($accounts))
      <tr><td style="border-top:1px solid #e5e7eb;padding:12px 18px;font-size:13px;color:#6b7280;">… and {{ $total - count($accounts) }} more</td></tr>
    @endif
  </table>

  @include('emails.partials.button', ['url' => $positionsUrl, 'label' => 'Open positions →'])

  <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">
    Admin → Dashboard → Open positions can close them for you. Every attempt is in the
    <a href="{{ $logUrl }}" style="color:#b8862a;text-decoration:none;">Signal Log</a>.
  </p>
@endsection

@section('footnote')
  Trade alert — sent the moment a close needs a person. Everything else comes in the hourly summary.
@endsection
