@extends('emails.layout', [
  'title' => 'Welcome to Pixel Alpha',
  'kicker' => 'Welcome',
  'preheader' => 'Thanks for registering — while we review your account, here is how to get ready.',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#111827;">
    Thanks for joining, {{ $name }}
  </h1>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    Your Pixel Alpha account has been created and is now <strong style="color:#111827;">waiting for approval</strong>.
    We review every new account by hand, and you will get an email the moment yours is unlocked.
  </p>

  <p style="margin:0 0 22px;font-size:15px;line-height:1.7;color:#374151;">
    You can use the wait to get ready, so you can start trading the minute you are approved:
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
    <tr>
      <td width="30" valign="top" style="padding:0 0 16px;">
        <div style="width:22px;height:22px;border-radius:50%;background:#fdf3e2;color:#b8862a;font-size:12px;font-weight:700;text-align:center;line-height:22px;">1</div>
      </td>
      <td valign="top" style="padding:0 0 16px;font-size:14px;line-height:1.7;color:#374151;">
        <strong style="color:#111827;">Read the connection guide.</strong>
        It walks you through creating trade-only API keys on your exchange, screen by screen.<br>
        <a href="{{ $guideUrl }}" style="color:#b8862a;text-decoration:none;font-weight:600;">How to connect Binance →</a>
      </td>
    </tr>
    <tr>
      <td width="30" valign="top" style="padding:0 0 16px;">
        <div style="width:22px;height:22px;border-radius:50%;background:#fdf3e2;color:#b8862a;font-size:12px;font-weight:700;text-align:center;line-height:22px;">2</div>
      </td>
      <td valign="top" style="padding:0 0 16px;font-size:14px;line-height:1.7;color:#374151;">
        <strong style="color:#111827;">Fund your futures wallet.</strong>
        The bots trade on your own exchange account, and your funds never leave it.
      </td>
    </tr>
    <tr>
      <td width="30" valign="top" style="padding:0 0 4px;">
        <div style="width:22px;height:22px;border-radius:50%;background:#fdf3e2;color:#b8862a;font-size:12px;font-weight:700;text-align:center;line-height:22px;">3</div>
      </td>
      <td valign="top" style="padding:0 0 4px;font-size:14px;line-height:1.7;color:#374151;">
        <strong style="color:#111827;">Check the FAQ.</strong>
        How fees work (20% of profit only — no profit, no fee), what the bots trade, and how to stop at any time.<br>
        <a href="{{ $faqUrl }}" style="color:#b8862a;text-decoration:none;font-weight:600;">Read the FAQ →</a>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $guideUrl, 'label' => 'Open the connection guide →'])
@endsection
