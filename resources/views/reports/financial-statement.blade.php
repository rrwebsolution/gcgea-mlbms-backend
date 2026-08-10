<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 32px 46px 28px; }
        body { margin: 0; color: #000; font-family: "Times New Roman", DejaVu Serif, serif; font-size: 12.5px; line-height: 1.65; }
        .letterhead { width: 100%; border-collapse: collapse; text-align: center; }
        .letterhead td { border: 0; vertical-align: middle; }
        .logo-cell { width: 18%; }
        .logo { width: 92px; height: 92px; object-fit: contain; }
        .organization { font-family: Arial, DejaVu Sans, sans-serif; font-size: 13px; font-weight: bold; }
        .acronym { font-family: Arial, DejaVu Sans, sans-serif; margin: 5px 0 8px; font-size: 13px; font-weight: bold; }
        .registration { font-family: Arial, DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.25; }
        .affiliation { margin-top: 10px; text-align: center; font-family: Arial, DejaVu Sans, sans-serif; font-size: 11px; font-style: italic; line-height: 1.25; }
        h1 { margin: 28px 0 0; text-align: center; font-size: 18px; line-height: 1.1; }
        .period { margin: 2px 0 28px; text-align: center; font-size: 13px; }
        p { margin: 0 0 17px; text-align: justify; }
        .signatories { width: 100%; margin-top: 45px; border-collapse: collapse; table-layout: fixed; }
        .signatories td { width: 33.33%; border: 0; padding: 0 8px; text-align: center; vertical-align: bottom; line-height: 1.25; }
        .name { border-bottom: 1px solid #000; font-weight: bold; font-size: 11px; text-transform: uppercase; }
        .role { font-size: 11px; }
    </style>
</head>
<body>
    <table class="letterhead">
        <tr>
            <td class="logo-cell"><img class="logo" src="{{ $leftLogo }}"></td>
            <td>
                <div class="organization">{{ mb_strtoupper($organization['organizationName']) }}</div>
                <div class="acronym">({{ $organization['acronym'] }})</div>
                <div class="registration">{{ $document['registrationLine'] }}</div>
                <div class="registration">{{ $document['accreditationLine'] }}</div>
            </td>
            <td class="logo-cell"><img class="logo" src="{{ $rightLogo }}"></td>
        </tr>
    </table>
    <div class="affiliation">
        @foreach($document['affiliationLines'] as $line)<div>{{ $line }}</div>@endforeach
    </div>
    <h1>Unaudited Financial Statement Disclaimer</h1>
    <div class="period">For the Year Ended December 31, {{ $document['year'] }}</div>
    @foreach($document['paragraphs'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
    <table class="signatories"><tr>
        <td><div class="name">{{ $organization['bookkeeperName'] }}</div><div class="role">Bookkeeper</div></td>
        <td><div class="name">{{ $organization['auditorName'] }}</div><div class="role">Auditor</div></td>
        <td><div class="name">{{ $organization['presidentName'] }}</div><div class="role">President</div></td>
    </tr></table>
</body>
</html>
