<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Your Pixel Alpha password reset code</title>
</head>
{{-- Fixed light colours on purpose: mail clients ignore the app theme, and a
     dark-mode client inverting a dark template makes the code unreadable. --}}
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;border:1px solid #e5e7eb;">
          <tr>
            <td style="padding:32px 36px 8px;">
              <div style="font-size:20px;font-weight:800;letter-spacing:-0.02em;">Pixel Alpha</div>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 36px 0;">
              <p style="margin:0 0 12px;font-size:16px;line-height:1.6;">Hi {{ $name }},</p>
              <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
                Someone asked to reset the password on your Pixel Alpha account. Enter this code on the reset screen:
              </p>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:4px 36px 20px;">
              <div style="display:inline-block;padding:16px 28px;border-radius:12px;background:#f3f4f6;border:1px solid #e5e7eb;font-family:'IBM Plex Mono',SFMono-Regular,Menlo,Consolas,monospace;font-size:32px;font-weight:700;letter-spacing:0.35em;color:#111827;">{{ $code }}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:0 36px 8px;">
              <p style="margin:0 0 12px;font-size:14px;line-height:1.7;color:#374151;">
                The code expires in <strong>{{ $ttlMinutes }} minutes</strong> and works once.
              </p>
              <p style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#6b7280;">
                If you did not request this, you can ignore this email — your password stays as it is. Nobody at Pixel Alpha will ever ask you for this code.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 36px 28px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.6;color:#9ca3af;">
              Questions? Write to
              <a href="mailto:support@pixel-alpha.com" style="color:#6b7280;">support@pixel-alpha.com</a>.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
