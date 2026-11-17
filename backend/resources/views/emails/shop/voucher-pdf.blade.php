{{--
    Printable gift voucher (M6-S09, spec §3.2) — attached to the gift e-mail, rendered
    by Shop\VoucherPdfRenderer with dompdf: A4 portrait, two identical vouchers with a
    fold line. dompdf has no flex / grid: absolute positioning + tables.
--}}
<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $t('pdf_title') }}</title>
<style>
@foreach ($fonts as $family => $path)
@font-face { font-family: '{{ $family }}'; font-style: normal; font-weight: normal; src: url('{{ $path }}') format('truetype'); }
@endforeach
@page { size: A4 portrait; margin: 0; }
html, body { margin: 0; padding: 0; }
body { color: #121614; font-family: 'InstrumentRegular'; }
.v { position: absolute; left: 48px; width: 698px; height: 470px; border: 6px solid #7FE0B4; border-radius: 26px; }
.fold { position: absolute; left: 0; top: 561px; width: 794px; border-top: 1px dashed #A3ADA6; }
.mark { position: absolute; left: 34px; top: 30px; width: 56px; height: 56px; }
.eyebrow { position: absolute; left: 106px; top: 34px; font-family: 'InstrumentSemiBold'; font-size: 13px; letter-spacing: 1.4px; color: #1A7A55; }
.title { position: absolute; left: 106px; top: 54px; font-family: 'BricolageExtraBold'; font-size: 30px; }
.tag { position: absolute; left: 34px; top: 112px; width: 630px; font-family: 'InstrumentMedium'; font-size: 17px; color: #5A635D; }
.fields { position: absolute; left: 34px; top: 160px; width: 630px; }
.label { font-family: 'InstrumentSemiBold'; font-size: 13px; color: #5A635D; }
.line { border-bottom: 1px solid #A3ADA6; height: 34px; }
.code { position: absolute; left: 34px; top: 290px; width: 630px; background: #DDF6EA; border-radius: 16px; text-align: center; padding: 14px 0; }
.codetxt { font-family: 'BricolageBold'; font-size: 30px; letter-spacing: 3px; }
.foot { position: absolute; left: 34px; top: 404px; width: 630px; font-size: 14px; color: #5A635D; }
td { padding: 0; vertical-align: bottom; }
</style>
</head>
<body>
@foreach ([30, 592] as $top)
<div class="v" style="top: {{ $top }}px;">
    <img class="mark" src="{{ $mark }}" alt="">
    <div class="eyebrow">{{ mb_strtoupper($t('pdf_title')) }}</div>
    <div class="title">{{ $t('pdf_product') }}</div>
    <div class="tag">{{ $t('pdf_tagline') }}</div>
    <table class="fields"><tr>
        <td style="width: 48%;"><div class="label">{{ $t('pdf_for') }}</div><div class="line"></div></td>
        <td style="width: 4%;"></td>
        <td style="width: 48%;"><div class="label">{{ $t('pdf_from') }}</div><div class="line"></div></td>
    </tr></table>
    <div class="code">
        <div class="label">{{ $t('pdf_code') }}</div>
        <div class="codetxt">{{ $code }}</div>
    </div>
    <div class="foot">{{ $t('pdf_valid') }}: {{ $validUntil }} · {{ $t('pdf_redeem') }} {{ $redeemDisplay }}</div>
</div>
@endforeach
<div class="fold"></div>
</body>
</html>
