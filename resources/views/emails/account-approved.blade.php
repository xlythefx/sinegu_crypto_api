@extends('emails.layout', [
  'title' => 'Your Pixel Alpha account is approved',
  'kicker' => 'Account approved',
  'preheader' => 'Your account is approved — connect an exchange and the bots can start trading.',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#111827;">
    You're approved, {{ $name }}
  </h1>

  <p style="margin:0 0 22px;font-size:15px;line-height:1.7;color:#374151;">
    Your Pixel Alpha account has been reviewed and unlocked. You can connect your exchange now and
    let the strategies trade on it.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 26px;">
    <tr>
      <td align="center" style="border-radius:999px;background:#b8862a;">
        <a href="{{ $dashboardUrl }}" style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;">Open your dashboard →</a>
      </td>
    </tr>
  </table>

  <div style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#6b7280;margin:0 0 12px;">
    What happens next
  </div>

  {{-- Three steps, in the order the user meets them. The API-key sentence is
       the one that protects them: trade-only, never withdrawals. --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
    <tr>
      <td width="30" valign="top" style="padding:0 0 16px;">
        <div style="width:22px;height:22px;border-radius:50%;background:#fdf3e2;color:#b8862a;font-size:12px;font-weight:700;text-align:center;line-height:22px;">1</div>
      </td>
      <td valign="top" style="padding:0 0 16px;font-size:14px;line-height:1.7;color:#374151;">
        <strong style="color:#111827;">Connect your exchange.</strong>
        Binance is live today; Bybit and MEXC are coming soon. Create <strong>trade-only</strong> API keys —
        enable futures trading, and never enable withdrawals.
        <a href="{{ $guideUrl }}" style="color:#b8862a;text-decoration:none;font-weight:600;">Step-by-step Binance guide →</a>
      </td>
    </tr>
    <tr>
      <td width="30" valign="top" style="padding:0 0 16px;">
        <div style="width:22px;height:22px;border-radius:50%;background:#fdf3e2;color:#b8862a;font-size:12px;font-weight:700;text-align:center;line-height:22px;">2</div>
      </td>
      <td valign="top" style="padding:0 0 16px;font-size:14px;line-height:1.7;color:#374151;">
        <strong style="color:#111827;">The bots trade on your own account.</strong>
        Your funds never leave your exchange, and you can disconnect at any time from
        the dashboard.
      </td>
    </tr>
    <tr>
      <td width="30" valign="top" style="padding:0 0 4px;">
        <div style="width:22px;height:22px;border-radius:50%;background:#fdf3e2;color:#b8862a;font-size:12px;font-weight:700;text-align:center;line-height:22px;">3</div>
      </td>
      <td valign="top" style="padding:0 0 4px;font-size:14px;line-height:1.7;color:#374151;">
        <strong style="color:#111827;">You only pay on profit.</strong>
        20% of the profit you actually make, invoiced from your dashboard. No profit, no fee.
      </td>
    </tr>
  </table>

  <p style="margin:0 0 20px;font-size:13px;line-height:1.7;color:#6b7280;">
    Trading is leveraged and carries real risk — only fund an account with money you can afford to lose.
  </p>
@endsection
