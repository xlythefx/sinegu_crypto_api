{{--
  The frame every Pixel Alpha email is drawn in.

  FIXED LIGHT COLOURS, never a theme token — the same rule the printable
  invoice follows. A mail client knows nothing about the app's theme, and a
  dark-mode client inverting a dark template makes the content unreadable.

  Tables and inline styles, because Outlook still ignores most of everything
  else. Children extend it with their own title and inbox preview line:

      @extends('emails.layout', ['title' => '…', 'preheader' => '…'])
      @section('content') … @endsection
--}}
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;-webkit-font-smoothing:antialiased;">
  {{-- Preview line: the inbox list shows it, the open email never does. --}}
  <div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;">{{ $preheader ?? '' }}</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;border:1px solid #e5e7eb;">
          <tr>
            <td style="padding:30px 36px 0;">
              <table role="presentation" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="padding-right:10px;">
                    <div style="width:26px;height:26px;border-radius:8px;background:#b8862a;"></div>
                  </td>
                  <td style="font-size:19px;font-weight:800;letter-spacing:-0.02em;color:#111827;">Pixel Alpha</td>
                </tr>
              </table>
              @isset($kicker)
                <div style="margin-top:22px;font-family:'IBM Plex Mono',SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;color:#b8862a;">{{ $kicker }}</div>
              @endisset
            </td>
          </tr>
          <tr>
            <td style="padding:14px 36px 4px;">
              @yield('content')
            </td>
          </tr>
          <tr>
            <td style="padding:18px 36px 28px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.7;color:#9ca3af;">
              @hasSection('footnote')
                <div style="margin-bottom:8px;">@yield('footnote')</div>
              @endif
              Questions? Write to
              <a href="mailto:support@pixel-alpha.com" style="color:#6b7280;">support@pixel-alpha.com</a>
              or message <a href="https://t.me/pixel_alpha_support" style="color:#6b7280;">@pixel_alpha_support</a> on Telegram.
            </td>
          </tr>
        </table>

        <div style="max-width:520px;padding:16px 8px 0;font-size:11px;line-height:1.6;color:#9ca3af;">
          Pixel Alpha — automated trading on your own exchange account. Trading carries risk; past performance is not a promise of future results.
        </div>
      </td>
    </tr>
  </table>
</body>
</html>
