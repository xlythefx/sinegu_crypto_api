{{-- The one call to action: @include('emails.partials.button', ['url' => …, 'label' => …]) --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 {{ $gap ?? 24 }}px;">
  <tr>
    <td align="center" style="border-radius:999px;background:#b8862a;">
      <a href="{{ $url }}" style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;">{{ $label }}</a>
    </td>
  </tr>
</table>
