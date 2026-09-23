@extends('emails.layout', [
  'title' => 'New Pixel Alpha registration',
  'kicker' => 'Approval queue',
  'preheader' => $name.' signed up and is waiting for approval.',
])

@section('content')
  <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;font-weight:800;letter-spacing:-0.02em;color:#111827;">
    {{ $name }} is waiting for approval
  </h1>

  <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#374151;">
    A new account was created and parked in the approval queue. They can sign in and look around,
    but every trading feature stays locked — connecting an exchange included — until you approve them.
  </p>

  {{-- The facts an admin needs before opening the queue: who, which address,
       which row, and how they arrived. --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border:1px solid #e5e7eb;border-radius:12px;background:#f9fafb;">
    <tr>
      <td style="padding:14px 18px 6px;font-size:12px;color:#6b7280;">Name</td>
    </tr>
    <tr>
      <td style="padding:0 18px 12px;font-size:15px;font-weight:600;color:#111827;">{{ $name }}</td>
    </tr>
    <tr>
      <td style="padding:0 18px 6px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;padding-top:12px;">Email</td>
    </tr>
    <tr>
      <td style="padding:0 18px 12px;font-size:15px;color:#111827;">
        <a href="mailto:{{ $email }}" style="color:#b8862a;text-decoration:none;">{{ $email }}</a>
      </td>
    </tr>
    <tr>
      <td style="padding:0 18px 6px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;padding-top:12px;">Signed up with</td>
    </tr>
    <tr>
      <td style="padding:0 18px 12px;font-size:15px;color:#111827;">{{ $via }}</td>
    </tr>
    <tr>
      <td style="padding:0 18px 6px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;padding-top:12px;">Registered</td>
    </tr>
    <tr>
      <td style="padding:0 18px 12px;font-size:15px;color:#111827;">{{ $registeredAt }}</td>
    </tr>
    <tr>
      <td style="padding:0 18px 6px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;padding-top:12px;">Account ID</td>
    </tr>
    <tr>
      <td style="padding:0 18px 16px;font-family:'IBM Plex Mono',SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;color:#374151;word-break:break-all;">{{ $uniId }}</td>
    </tr>
  </table>

  <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">
    <tr>
      <td align="center" style="border-radius:999px;background:#b8862a;">
        <a href="{{ $reviewUrl }}" style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;">Open the approval queue →</a>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 20px;font-size:13px;line-height:1.7;color:#6b7280;">
    Approving unlocks the dashboard and sends them a confirmation email. Rejecting suspends the
    account and tells them nothing.
  </p>
@endsection

@section('footnote')
  You are receiving this because your address is set as the Pixel Alpha admin contact. It is not sent to customers.
@endsection
