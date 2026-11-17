<?php

namespace App\Services\Shop;

use App\Models\GiftVoucher;
use App\Services\CertificatePdfRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * The printable gift voucher PDF attached to the gift e-mail (M6-S09, spec §3.2) —
 * the same voucher the website's thank-you page draws. dompdf like the certificate
 * (remote off, fonts from resources/fonts).
 */
class VoucherPdfRenderer
{
    public function render(GiftVoucher $voucher, string $locale): string
    {
        $t = fn (string $key, array $replace = []): string => (string) trans('shop.'.$key, $replace, $locale);
        $html = view('emails.shop.voucher-pdf', [
            'locale' => $locale,
            't' => $t,
            'fonts' => CertificatePdfRenderer::fonts(),
            'mark' => CertificatePdfRenderer::svgDataUri('mark.svg'),
            'code' => $voucher->code(),
            'validUntil' => ShopLinks::date($voucher->expires_at, $locale),
            'redeemDisplay' => ShopLinks::redeemDisplay($locale),
        ])->render();

        $cache = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($cache);
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([resource_path('fonts'), resource_path('certificates')]);
        $options->setFontDir($cache);
        $options->setFontCache($cache);
        $options->setTempDir($cache);
        $options->setDpi(96);
        $options->setIsFontSubsettingEnabled(true);
        $options->setDefaultFont('InstrumentRegular');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->addInfo('Title', $t('pdf_title'));
        $dompdf->addInfo('Creator', 'PetPrep');

        return (string) $dompdf->output();
    }

    public static function fileName(string $locale): string
    {
        return trans('shop.pdf_file', [], $locale).'.pdf';
    }
}
