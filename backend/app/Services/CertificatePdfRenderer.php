<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use FontLib\Font;
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
            'childNameSpacing' => self::childNameLetterSpacing(self::childNameSize($data['child_name'])),
            // dompdf's line box of a small name is tighter: keep the underline clear of it.
            'underlineGap' => (int) round(2 + (68 - self::childNameSize($data['child_name'])) * 0.3),
            // A pet name that wraps moves the card's content up so the breed stays inside.
            'petNameWraps' => self::textWidth($data['pet_name'], 'BricolageBold', self::petNameSize($data['pet_name'])) > self::PET_NAME_WIDTH,
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

    /** Text column of the child's name (px) and the pet card's inner width. */
    public const CHILD_NAME_WIDTH = 606;

    public const PET_NAME_WIDTH = 168;

    /**
     * The child's name: the largest size from the design's 68 px down to 20 px
     * whose MEASURED width (font advance widths + letter spacing) fits the
     * 606 px column; below that it wraps (break-word). Up to 30 characters of
     * the widest letter fit at 20 px.
     */
    public static function childNameSize(string $name): int
    {
        return self::fitSize($name, 'BricolageExtraBold', 68, 20, self::CHILD_NAME_WIDTH, true);
    }

    /** The pet's name inside its card (design 28 px, down to 14 px, then it wraps). */
    public static function petNameSize(string $name): int
    {
        return self::fitSize($name, 'BricolageBold', 28, 14, self::PET_NAME_WIDTH, false);
    }

    /** Letter spacing of the child's name at $size px (design: −2 px at 68 px). */
    public static function childNameLetterSpacing(int $size): float
    {
        return round(-2 * $size / 68, 2);
    }

    private static function fitSize(string $text, string $family, int $max, int $min, int $width, bool $childSpacing): int
    {
        for ($size = $max; $size > $min; $size--) {
            $spacing = $childSpacing ? self::childNameLetterSpacing($size) : 0.0;
            if (self::textWidth($text, $family, $size, $spacing) <= $width) {
                return $size;
            }
        }

        return $min;
    }

    /**
     * Width in px of $text set in $family at $size px: sum of the glyph
     * advance widths (php-font-lib, the TTF dompdf embeds) + letter spacing
     * per character. Kerning is ignored (it only narrows the text).
     */
    public static function textWidth(string $text, string $family, float $size, float $letterSpacing = 0.0): float
    {
        $m = self::metrics($family);
        $units = 0;
        $chars = mb_str_split($text);
        foreach ($chars as $char) {
            $units += $m['widths'][mb_ord($char)] ?? $m['upm'];
        }

        return $units / $m['upm'] * $size + $letterSpacing * count($chars);
    }

    /**
     * True when every character of $text has a glyph in $family (QA M4: the
     * brand fonts are Latin / Latin-ext only — anything else would print blank).
     */
    public static function supports(string $text, string $family): bool
    {
        $m = self::metrics($family);
        foreach (mb_str_split($text) as $char) {
            if (! isset($m['widths'][mb_ord($char)])) {
                return false;
            }
        }

        return true;
    }

    /** @var array<string, array{upm: int, widths: array<int, int>}> */
    private static array $metrics = [];

    /**
     * @return array{upm: int, widths: array<int, int>} code point → advance width (font units)
     */
    private static function metrics(string $family): array
    {
        if (isset(self::$metrics[$family])) {
            return self::$metrics[$family];
        }

        $font = Font::load(self::fonts()[$family]);
        $font->parse();
        $upm = (int) $font->getData('head', 'unitsPerEm');
        $hmtx = $font->getData('hmtx');
        $widths = [];
        foreach ($font->getUnicodeCharMap() as $codePoint => $glyph) {
            if (isset($hmtx[$glyph][0])) {
                $widths[(int) $codePoint] = (int) $hmtx[$glyph][0];
            }
        }
        $font->close();

        return self::$metrics[$family] = ['upm' => $upm, 'widths' => $widths];
    }

    /**
     * @return array<string, string> family → absolute TTF path
     */
    public static function fonts(): array
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
