<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px 28px; }
        body { color: {{ $template['body']['bodyTextColor'] }}; font-family: "{{ $template['body']['bodyFontFamily'] }}", DejaVu Sans, sans-serif; font-size: {{ $template['body']['bodyFontSize'] }}px; font-weight: {{ $template['body']['bodyFontWeight'] }}; font-style: {{ $template['body']['bodyFontStyle'] }}; text-decoration: {{ $template['body']['bodyTextDecoration'] }}; }
        .header { width: 100%; margin-bottom: 14px; border-bottom: 2px solid {{ $template['body']['primaryColor'] }}; padding-bottom: 8px; }
        .header td { border: 0; text-align: center; vertical-align: middle; }
        .logo { width: 72px; height: 72px; object-fit: contain; }
        .country, .address { font-size: 11px; }
        .organization, .acronym { font-size: 15px; font-weight: bold; line-height: 1.15; }
        h1 { margin: 8px 0 10px; text-align: {{ $template['body']['titleAlignment'] }}; color: {{ $template['body']['primaryColor'] }}; font-size: 14px; }
        .generated { margin-bottom: 6px; text-align: right; color: #555; font-size: 8px; }
        .report { width: 100%; border-collapse: collapse; }
        .report th, .report td { border: {{ $template['body']['showBorders'] ? '1px solid #555' : '0' }}; padding: {{ $template['body']['preset'] === 'compact' ? '2px 3px' : '4px' }}; vertical-align: top; white-space: pre-line; text-align: {{ $template['body']['bodyTextAlignment'] }}; }
        .report th { background: {{ $template['body']['headerBackground'] }}; color: {{ $template['body']['primaryColor'] }}; font-weight: bold; text-align: left; }
        @if($template['body']['stripedRows'])
        .report tbody tr:nth-child(even) { background: #f8fafc; }
        @endif
        .report tr { page-break-inside: avoid; }
        .caption {
            margin: -5px 0 10px;
            font-family: '{{ $template['body']['captionStyle']['fontFamily'] }}';
            font-size: {{ $template['body']['captionStyle']['fontSize'] }}pt;
            font-weight: {{ $template['body']['captionStyle']['fontWeight'] }};
            font-style: {{ $template['body']['captionStyle']['fontStyle'] }};
            text-decoration: {{ $template['body']['captionStyle']['textDecoration'] }};
            color: {{ $template['body']['captionStyle']['textColor'] }};
            text-align: {{ $template['body']['captionStyle']['textAlignment'] }};
        }
        .note {
            margin-top: 10px; border-top: 1px solid #aaa; padding-top: 6px;
            font-family: '{{ $template['body']['noteStyle']['fontFamily'] }}';
            font-size: {{ $template['body']['noteStyle']['fontSize'] }}pt;
            font-weight: {{ $template['body']['noteStyle']['fontWeight'] }};
            font-style: {{ $template['body']['noteStyle']['fontStyle'] }};
            text-decoration: {{ $template['body']['noteStyle']['textDecoration'] }};
            color: {{ $template['body']['noteStyle']['textColor'] }};
            text-align: {{ $template['body']['noteStyle']['textAlignment'] }};
        }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 16%"><img class="logo" src="{{ $leftLogo }}"></td>
            <td style="width: 68%">
                <div class="country">{{ $template['countryLine'] }}</div>
                <div class="organization">{{ $template['organizationLine'] }}</div>
                <div class="acronym">{{ $template['acronymLine'] }}</div>
                <div class="address">{{ $template['addressLine'] }}</div>
            </td>
            <td style="width: 16%"><img class="logo" src="{{ $rightLogo }}"></td>
        </tr>
    </table>
    <h1>{{ $title }}</h1>
    @if($template['body']['captionText'] !== '')
        <div class="caption">{{ $template['body']['captionText'] }}</div>
    @endif
    @if($template['showGeneratedDate'])
        <div class="generated">Generated: {{ now()->format('F j, Y g:i A') }}</div>
    @endif
    <table class="report">
        <thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr>@foreach($headers as $index => $_header)<td>{{ $row[$index] ?? '' }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($headers) }}">No records found.</td></tr>
        @endforelse
        </tbody>
    </table>
    @if($template['body']['noteText'] !== '')
        <div class="note"><strong>Note:</strong> {{ $template['body']['noteText'] }}</div>
    @endif
</body>
</html>
