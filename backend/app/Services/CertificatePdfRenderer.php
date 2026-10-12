<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * Renders the Responsibility Certificate (M2-01d, CERTIFICATE_SPEC §2) as an
 * A4 portrait PDF with dompdf — locally, no external HTTP (remote resources
 * are disabled; fonts, logo and seal come from `resources/`).
 *
 * Template: resources/views/certificates/certificate.blade.php (the approved
 * claude.ai design, ported to tables / absolute positioning — dompdf has no
 * flex or grid). Texts: lang/{en,sl}/certificate.php; breed names:
 * lang/{en,sl}/breeds.php. Fonts: Bricolage Grotesque 700 / 800 and
 * Instrument Sans 400 / 500 / 600 (SIL OFL, converted from brand/fonts/*.woff2
 * to resources/fonts/*.ttf; latin-ext, so č š ž render). dompdf only knows
 * normal / bold per family, so every weight is its own family name.
 */
class CertificatePdfRenderer
{
    /**
     * @param  array{locale: string, child_name: string, pet_name: string, breed_label: string, care_score: int, number: string, completed_local_date: string}  $data
     */
    public function render(array $data): string
    {
        $locale = $data['locale'];

        $html = view('certificates.certificate', [
            'locale' => $locale,
            't' => fn (string $key): string => (string) trans('certificate.'.$key, [], $locale),
            'childName' => $data['child_name'],
            'petName' => $data['pet_name'],
            'breedLabel' => $data['breed_label'],
            'careScore' => $data['care_score'],
            'number' => $data['number'],
            'date' => self::formatDate($data['completed_local_date'], $locale),
            'childNameSize' => self::childNameSize($data['child_name']),
            'petNameSize' => self::petNameSize($data['pet_name']),
            'fonts' => self::fonts(),
            'mark' => self::svgDataUri('mark.svg'),
            'seal' => self::svgDataUri('seal.svg'),
        ])->render();

        $fontCache = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($fontCache);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([resource_path('fonts'), resource_path('certificates')]);
        $options->setFontDir($fontCache);
        $options->setFontCache($fontCache);
        $options->setTempDir($fontCache);
        $options->setDefaultPaperSize('a4');
        $options->setDefaultPaperOrientation('portrait');
        $options->setDpi(96);
        $options->setIsFontSubsettingEnabled(true);
        $options->setDefaultFont('InstrumentRegular');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->addInfo('Title', (string) trans('certificate.document_title', [], $locale));
        $dompdf->addInfo('Creator', 'PetPrep');
        $dompdf->addInfo('Producer', 'PetPrep');

        return (string) $dompdf->output();
    }

    /**
     * Number of pages of a rendered PDF (tests / sanity check: always 1).
     */
    public static function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    /** SL `12. 10. 2026`, EN `12 Oct 2026` (CERTIFICATE_SPEC; the family-local completion date). */
    public static function formatDate(string $localDate, string $locale): string
    {
        $d = CarbonImmutable::parse($localDate, 'UTC');

        return $locale === 'sl' ? $d->format('j. n. Y') : $d->format('j M Y');
    }

    /** The child's name shrinks so up to 30 characters fit the 606 px column (design: 68 px). */
    public static function childNameSize(string $name): int
    {
        $len = mb_strlen($name);

        return match (true) {
            $len <= 12 => 68,
            $len <= 16 => 56,
            $len <= 22 => 42,
            default => 32,
        };
    }

    /** The pet's name inside its 192 px card (design: 28 px). */
    public static function petNameSize(string $name): int
    {
        $len = mb_strlen($name);

        return match (true) {
            $len <= 10 => 28,
            $len <= 14 => 22,
            $len <= 20 => 17,
            default => 14,
        };
    }

    /**
     * @return array<string, string> family → absolute TTF path
     */
    private static function fonts(): array
    {
        return [
            'BricolageBold' => resource_path('fonts/BricolageGrotesque-700.ttf'),
            'BricolageExtraBold' => resource_path('fonts/BricolageGrotesque-800.ttf'),
            'InstrumentRegular' => resource_path('fonts/InstrumentSans-400.ttf'),
            'InstrumentMedium' => resource_path('fonts/InstrumentSans-500.ttf'),
            'InstrumentSemiBold' => resource_path('fonts/InstrumentSans-600.ttf'),
        ];
    }

    private static function svgDataUri(string $file): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents(resource_path('certificates/'.$file)));
    }
}
