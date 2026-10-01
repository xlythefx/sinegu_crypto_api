@extends('emails.layout', [
  'title' => 'Your Pixel Alpha verification code',
  'kicker' => 'Confirm your email',
  'preheader' => 'Your verification code — it expires in '.$ttlMinutes.' minutes.',
])

@section('content')
  <p style="margin:0 0 12px;font-size:16px;line-height:1.6;color:#111827;">Hi {{ $name }},</p>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    Thanks for signing up to Pixel Alpha. Enter this code on the verification screen to confirm your email address:
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
    <tr>
      <td align="center">
        <div style="display:inline-block;padding:16px 28px;border-radius:12px;background:#f3f4f6;border:1px solid #e5e7eb;font-family:'IBM Plex Mono',SFMono-Regular,Menlo,Consolas,monospace;font-size:32px;font-weight:700;letter-spacing:0.35em;color:#111827;">{{ $code }}</div>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 12px;font-size:14px;line-height:1.7;color:#374151;">
    The code expires in <strong>{{ $ttlMinutes }} minutes</strong>. If it runs out, ask for a new one on the same screen.
  </p>

  <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#6b7280;">
    If you did not create a Pixel Alpha account, you can ignore this email. Nobody at Pixel Alpha will ever
    ask you for this code.
  </p>
@endsection
