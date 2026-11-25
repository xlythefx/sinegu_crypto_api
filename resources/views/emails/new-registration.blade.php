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

  {{-- The facts an admin needs before opening the queue: who, how to reach
       them, where and on what they signed up from, how they found us, which
       row, and how they arrived.
       Telegram / WhatsApp are typed by the person (never checked); the IP and
       country are the REGISTRATION's, read from the sign-up record. --}}
  @include('emails.partials.facts', ['rows' => [
      ['Name', $name, ['strong' => true]],
      ['Email', $email, ['href' => 'mailto:'.$email]],
      $telegram
          ? ['Telegram', '@'.$telegram, ['href' => 'https://t.me/'.$telegram]]
          : ['Telegram', 'Not given', ['color' => '#6b7280']],
      $whatsapp
          ? ['WhatsApp', $whatsapp, ['href' => 'https://wa.me/'.ltrim($whatsapp, '+')]]
          : ['WhatsApp', 'Not given', ['color' => '#6b7280']],
      ['Location (from their IP address)', $location ?? 'Not known', $location ? [] : ['color' => '#6b7280']],
      ['IP address', $ip ?? 'Not recorded', $ip ? ['mono' => true] : ['color' => '#6b7280']],
      ['Device', $device ?? 'Not known', $device ? [] : ['color' => '#6b7280']],
      ['Heard about us from', $heardFrom ?? 'Did not say', $heardFrom ? [] : ['color' => '#6b7280']],
      ['Signed up with', $via],
      ['Registered', $registeredAt],
      ['Account ID', $uniId, ['mono' => true]],
  ]])

  <p style="margin:0 0 22px;font-size:13px;line-height:1.7;color:#6b7280;">
    Telegram and WhatsApp are as they typed them at sign-up; neither is checked.
  </p>

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
