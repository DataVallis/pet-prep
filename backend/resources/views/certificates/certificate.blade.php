{{--
    Responsibility Certificate (M2-01d, CERTIFICATE_SPEC §2) — rendered by
    App\Services\CertificatePdfRenderer with dompdf (A4 portrait, 96 dpi, so
    1 px = the 794 × 1123 px design). dompdf has no flex / grid: tables and
    absolute positioning. Every text comes from lang/<locale>/certificate.php.
    Colours (CGP v2): mint #7FE0B4, graphite #121614, mint text #1A7A55,
    tint #DDF6EA, muted #5A635D, line #DDE3DE, raspberry only on the logo nose.
--}}
<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $t('document_title') }}</title>
<style>
@foreach ($fonts as $family => $path)
@font-face { font-family: '{{ $family }}'; font-style: normal; font-weight: normal; src: url('{{ $path }}') format('truetype'); }
@endforeach
@page { size: A4 portrait; margin: 0; }
html, body { margin: 0; padding: 0; }
body { background: #FFFFFF; color: #121614; font-family: 'InstrumentRegular'; font-weight: normal; }
.frame { position: absolute; left: 28px; top: 28px; width: 718px; height: 1047px; border: 10px solid #7FE0B4; border-radius: 30px; }
.inner { position: absolute; left: 56px; top: 52px; width: 606px; text-align: center; }
.bottom { position: absolute; left: 56px; bottom: 66px; width: 606px; }
.bri7 { font-family: 'BricolageBold'; font-weight: normal; }
.bri8 { font-family: 'BricolageExtraBold'; font-weight: normal; }
.in5 { font-family: 'InstrumentMedium'; font-weight: normal; }
.in6 { font-family: 'InstrumentSemiBold'; font-weight: normal; }
.eyebrow { font-size: 12px; line-height: 16px; letter-spacing: 1.4px; }
.mint { color: #1A7A55; }
.muted { color: #5A635D; }
table { border-collapse: collapse; }
td { padding: 0; vertical-align: top; }
.card { height: 132px; border-radius: 22px; text-align: center; }
.card-score { background: #DDF6EA; height: 150px; }
.card-line { border: 1px solid #DDE3DE; height: 148px; }
</style>
</head>
<body>
<div class="frame">
    <div class="inner">
        <div style="height: 72px;"><img src="{{ $mark }}" width="72" height="72" alt=""></div>
        <div class="bri8" style="margin-top: 8px; font-size: 34px; line-height: 34px; letter-spacing: -0.4px;">petprep</div>

        <div class="in6 eyebrow mint" style="margin-top: 26px;">{{ $t('eyebrow') }}</div>
        <div class="bri7" style="margin-top: 3px; font-size: 32px; line-height: 36px; letter-spacing: -0.6px;">{{ $t('title') }}</div>

        <div class="muted" style="margin-top: 28px; font-size: 16px; line-height: 24px;">{{ $t('awarded_to') }}</div>
        <div class="bri8" style="margin: 0 auto; width: 606px; word-wrap: break-word; font-size: {{ $childNameSize }}px; line-height: {{ $childNameSize + 2 }}px; letter-spacing: {{ $childNameSpacing }}px;">{{ $childName }}</div>
        <table style="margin: {{ $underlineGap }}px auto 0;"><tr><td style="width: 120px; height: 3px; background: #121614; border-radius: 2px;"></td></tr></table>
        <div style="margin: 20px auto 0; width: 440px; font-size: 18px; line-height: 22px;">{{ $t('sentence') }}</div>

        <table style="margin-top: 34px; width: 606px;">
            <tr>
                <td style="width: 192px;">
                    <div class="card card-score">
                        <div class="in6 eyebrow mint" style="padding-top: 14px;">{{ $t('care_score') }}</div>
                        <div class="bri7" style="margin-top: 8px; font-size: 64px; line-height: 50px; letter-spacing: -2px;">{{ $careScore }}</div>
                        <div class="in5 muted" style="margin-top: 0; font-size: 13px; line-height: 14px;">{{ $t('out_of') }}</div>
                    </div>
                </td>
                <td style="width: 15px;"></td>
                <td style="width: 192px;">
                    <div class="card card-line">
                        <div class="in6 eyebrow muted" style="padding-top: {{ $petNameWraps ? 12 : 25 }}px;">{{ $t('pet') }}</div>
                        <div class="bri7" style="margin: 2px auto 0; width: 168px; word-wrap: break-word; font-size: {{ $petNameSize }}px; line-height: {{ $petNameSize }}px;">{{ $petName }}</div>
                        <div class="muted" style="margin: 0 10px 0; font-size: 15px; line-height: 18px;">{{ $breedLabel }}</div>
                    </div>
                </td>
                <td style="width: 15px;"></td>
                <td style="width: 192px;">
                    <div class="card card-line">
                        <div class="in6 eyebrow muted" style="padding-top: 25px;">{{ $t('duration') }}</div>
                        <div class="bri7" style="margin-top: 2px; font-size: 28px; line-height: 28px;">{{ $t('weeks') }}</div>
                        <div class="muted" style="margin-top: 0; font-size: 15px; line-height: 18px;">{{ $t('days_of_care') }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="bottom">
        <table style="width: 606px;">
            <tr>
                <td style="width: 233px; vertical-align: bottom;">
                    <div class="bri7" style="font-size: 20px; line-height: 24px;">{{ $date }}</div>
                    <div style="margin-top: 5px; height: 1px; background: #121614;"></div>
                    <div class="in5 muted" style="margin-top: 5px; font-size: 13px; line-height: 18px;">{{ $t('date') }}</div>
                </td>
                <td style="width: 32px;"></td>
                <td style="width: 233px; vertical-align: bottom;">
                    <div style="height: 24px;"></div>
                    <div style="margin-top: 5px; height: 1px; background: #121614;"></div>
                    <div class="in5 muted" style="margin-top: 5px; font-size: 13px; line-height: 18px;">{{ $t('parent_signature') }}</div>
                </td>
                <td style="width: 32px;"></td>
                <td style="width: 76px; vertical-align: bottom; text-align: center;">
                    <div style="height: 76px;"><img src="{{ $seal }}" width="76" height="76" alt=""></div>
                    <div class="in6 eyebrow" style="margin-top: 6px;">{{ $t('verified') }}</div>
                </td>
            </tr>
        </table>
        <table style="margin-top: 2px; width: 606px; border-top: 1px solid #DDE3DE;">
            <tr>
                <td class="bri7" style="padding-top: 10px; font-size: 15px; line-height: 20px; text-align: left;">{{ $t('slogan') }}</td>
                <td class="in5 muted" style="padding-top: 10px; font-size: 13px; line-height: 18px; text-align: right; white-space: nowrap;">{{ $t('site') }} · {{ $t('number') }} {{ $number }}</td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>
