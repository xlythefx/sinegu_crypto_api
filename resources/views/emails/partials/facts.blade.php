{{--
  A labelled fact sheet: @include('emails.partials.facts', ['rows' => [
      ['Label', 'value'],
      ['Label', 'value', ['mono' => true, 'strong' => true, 'color' => '#…', 'href' => '…']],
  ]])
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border:1px solid #e5e7eb;border-radius:12px;background:#f9fafb;">
  @foreach ($rows as $i => $row)
    @php
      $opt = $row[2] ?? [];
      $sep = $i > 0 ? 'border-top:1px solid #e5e7eb;' : '';
      $font = ! empty($opt['mono'])
          ? "font-family:'IBM Plex Mono',SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;word-break:break-all;"
          : 'font-size:15px;';
      $weight = ! empty($opt['strong']) ? 'font-weight:700;' : '';
      $color = $opt['color'] ?? '#111827';
    @endphp
    <tr>
      <td style="{{ $sep }}padding:12px 18px 4px;font-size:12px;color:#6b7280;">{{ $row[0] }}</td>
    </tr>
    <tr>
      <td style="padding:0 18px 12px;{{ $font }}{{ $weight }}color:{{ $color }};">
        @if (! empty($opt['href']))
          <a href="{{ $opt['href'] }}" style="color:#b8862a;text-decoration:none;">{{ $row[1] }}</a>
        @else
          {{ $row[1] }}
        @endif
      </td>
    </tr>
  @endforeach
</table>
