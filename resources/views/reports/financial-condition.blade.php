<!doctype html>
<html><head><meta charset="utf-8"><style>
@page { margin: 28px 36px; } body { font-family: Arial, sans-serif; font-size: 9px; color:#000; }
.header { position:relative; text-align:center; min-height:82px; line-height:1.35; }.logo-left,.logo-right { position:absolute; top:0; width:72px; height:72px; object-fit:contain; }.logo-left{left:10px}.logo-right{right:10px}
.org { font-weight:bold; text-transform:uppercase; }.acronym { font-weight:bold; }.affiliation { margin-top:8px; font-style:italic; }
h1 { margin:15px 0 0; text-align:center; font-size:10px; text-transform:uppercase; } .period { margin:10px 0 14px; text-align:center; font-weight:bold; }
table { width:100%; border-collapse:collapse; } td { padding:2.5px 4px; vertical-align:top; } td.amount { width:32%; text-align:right; font-family:DejaVu Sans, sans-serif; }
.strong td { font-weight:bold; }.total td { border-bottom:2px double #000; }.section-gap td { padding-top:10px; }
.note { margin-top:18px; text-align:center; color:#666; font-size:7.5px; }
</style></head><body>
<div class="header"><img class="logo-left" src="{{ $report['organization']['leftLogo'] }}"><img class="logo-right" src="{{ $report['organization']['rightLogo'] }}">
<div class="org">{{ $report['organization']['name'] }}</div><div class="acronym">({{ $report['organization']['acronym'] }})</div>
<div style="margin-top:6px">DOLE Registration No. 528, dated, October 2, 1997<br>CSC Accreditation No. 166, dated, October 7, 1998</div>
<div class="affiliation">Affiliated to Public Services Labor Independent Confederation<br>An accredited training Institution on Public Sector Unionism<br>Prescribed under CSC, MC. No.9, s. 1994</div></div>
<h1>Statement of Financial Condition</h1><div class="period">as of December 31, {{ $report['fiscalYear'] }}</div>
<table><tbody>@foreach($rows as $index => $row)<tr class="{{ $row['strong'] ? 'strong' : '' }} {{ $row['total'] ? 'total' : '' }} {{ $row['label'] === 'LIABILITIES AND MEMBERS’ EQUITY' ? 'section-gap' : '' }}">
<td style="padding-left:{{ 4 + ($row['level'] * 18) }}px">{{ $row['label'] }}</td><td class="amount">@if($row['amount'] !== null) ₱ {{ number_format($row['amount'], 2) }} @endif</td></tr>@endforeach</tbody></table>
<div class="note">Automatically generated from posted GCGEA-MLBMS transactions.</div></body></html>
