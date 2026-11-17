{{--
    Shop e-mail (M6-S09, WEBSITE_SALES_SPEC §3.6): gift voucher or promise kit, in the
    buyer's language (lang/<locale>/shop.php). Rendered ONCE by ShopEmailComposer and
    stored whole in outbound_emails.html — e-mail clients: tables + inline styles only.
    CGP v2: graphite #121614, fog #F3F5F2, mint #7FE0B4, mint text #1A7A55, muted #5A635D.
--}}
<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#F3F5F2;font-family:Helvetica,Arial,sans-serif;color:#121614;">
<div style="display:none;max-height:0;overflow:hidden;">{{ $preheader }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F5F2;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:20px;">
<tr><td style="padding:28px 32px 8px 32px;">
    <img src="{{ $logoUrl }}" width="160" alt="PetPrep" style="display:block;border:0;width:160px;height:auto;">
</td></tr>
<tr><td style="padding:16px 32px 0 32px;font-size:16px;line-height:24px;">
    <p style="margin:0 0 12px 0;">{{ $t('hello') }}</p>
    <p style="margin:0 0 20px 0;">{{ $intro }}</p>
</td></tr>
@if ($code !== null)
<tr><td style="padding:0 32px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#DDF6EA;border-radius:16px;">
    <tr><td align="center" style="padding:20px 16px;">
        <div style="font-size:13px;line-height:18px;color:#1A7A55;font-weight:bold;">{{ $t('code_label') }}</div>
        <div style="font-size:28px;line-height:36px;font-weight:bold;letter-spacing:3px;margin:6px 0;">{{ $code }}</div>
        <div style="font-size:14px;line-height:20px;color:#5A635D;">{{ $t('valid_until', ['date' => $validUntil]) }}</div>
    </td></tr>
    </table>
</td></tr>
<tr><td style="padding:24px 32px 0 32px;font-size:16px;line-height:24px;">
    <p style="margin:0 0 8px 0;font-weight:bold;">{{ $t('how_title') }}</p>
    <ol style="margin:0 0 20px 20px;padding:0;">
        <li style="margin:0 0 6px 0;">{{ $t('step_print') }}</li>
        <li style="margin:0 0 6px 0;">{{ $t('step_redeem', ['url' => $redeemDisplay]) }}</li>
        <li style="margin:0 0 6px 0;">{{ $t('step_start') }}</li>
    </ol>
    <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:#121614;border-radius:999px;">
        <a href="{{ $redeemUrl }}" style="display:inline-block;padding:12px 24px;color:#FFFFFF;text-decoration:none;font-weight:bold;font-size:15px;">{{ $t('redeem_button') }}</a>
    </td></tr></table>
</td></tr>
@endif
@if ($downloads !== [])
<tr><td style="padding:24px 32px 0 32px;font-size:16px;line-height:24px;">
    @if ($code !== null)
        <p style="margin:0 0 4px 0;font-weight:bold;">{{ $t('kit_title') }}</p>
        <p style="margin:0 0 12px 0;">{{ $t('kit_text') }}</p>
    @endif
    @foreach ($downloads as $i => $d)
        @if ($i === 0)
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 10px 0;"><tr><td style="background:#121614;border-radius:999px;">
                <a href="{{ $d['url'] }}" style="display:inline-block;padding:12px 24px;color:#FFFFFF;text-decoration:none;font-weight:bold;font-size:15px;">{{ $t('download') }}</a>
            </td></tr></table>
        @else
            <p style="margin:0 0 6px 0;font-size:14px;"><a href="{{ $d['url'] }}" style="color:#1A7A55;">{{ $t('download_lang', ['language' => $d['language']]) }}</a></p>
        @endif
    @endforeach
    <p style="margin:8px 0 0 0;font-size:13px;line-height:19px;color:#5A635D;">{{ $t('links_valid', ['days' => $linkDays]) }}</p>
</td></tr>
@endif
<tr><td style="padding:24px 32px 0 32px;font-size:14px;line-height:21px;color:#5A635D;">
    <p style="margin:0 0 12px 0;">{{ $t($code !== null ? 'help' : 'help_kit', ['email' => $support, 'number' => $number]) }}</p>
    <p style="margin:0;color:#121614;">{{ $t('signoff') }}<br>{{ $t('signature') }}</p>
</td></tr>
<tr><td style="padding:24px 32px 28px 32px;font-size:12px;line-height:18px;color:#5A635D;border-top:1px solid #DDE3DE;">
    <p style="margin:16px 0 4px 0;">{{ $t('order', ['number' => $number]) }}</p>
    <p style="margin:0;">{{ $t('receipt') }}</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
