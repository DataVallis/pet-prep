<?php

namespace App\Services;

/**
 * The two names printed on a certificate (M2-01d): the child's and the pet's.
 * One rule set for the generate request and for the form defaults, so a
 * default is either printable or empty (the parent types one).
 *
 *  - normalized like a pet name (PetNameService::normalize), 1–30 characters;
 *  - pet name: letters, space, hyphen, apostrophe (PetNameService) — the
 *    breed default is cleaned first ("Poodle (Standard)" → "Poodle Standard",
 *    QA m2);
 *  - child name: the nickname characters (CreateChildRequest): letters,
 *    digits, space, hyphen, apostrophe, dot;
 *  - every letter must be Latin script AND have a glyph in the certificate
 *    fonts (QA M4: Bricolage Grotesque is Latin / Latin-ext only — other
 *    scripts would print blank) → `name_unsupported_script`;
 *  - the EN / SL word filter (PetNameService::isAllowed) → `name_not_allowed`.
 */
class CertificateNames
{
    public const INVALID = 'name_invalid';

    public const TOO_LONG = 'name_too_long';

    public const UNSUPPORTED_SCRIPT = 'name_unsupported_script';

    public const NOT_ALLOWED = 'name_not_allowed';

    /** Nickname characters (CreateChildRequest `display_name`), any script. */
    private const CHILD_PATTERN = "/^[\\pL\\pM\\pN][\\pL\\pM\\pN '\\-.]*$/u";

    /** Pet name characters (PetNameService::PATTERN: letters, space, hyphen, apostrophe), any script. */
    private const PET_PATTERN = PetNameService::PATTERN;

    /** A letter (or mark) that is not Latin script. */
    private const NON_LATIN_LETTER = '/[^\\p{Latin}\\P{L}]|[\\p{M}]/u';

    public function __construct(private readonly PetNameService $petNames) {}

    /**
     * Null when $name may be printed as the `child_name` / `pet_name`, else
     * the 422 code. $name is already normalized.
     */
    public function problem(string $field, ?string $name): ?string
    {
        if ($name === null || $name === '') {
            return self::INVALID;
        }
        if (mb_strlen($name, 'UTF-8') > ChallengeCertificateService::MAX_NAME_LENGTH) {
            return self::TOO_LONG;
        }
        $pattern = $field === 'pet_name' ? self::PET_PATTERN : self::CHILD_PATTERN;
        if (preg_match($pattern, $name) !== 1 || preg_match('/\p{L}/u', $name) !== 1
            || PetNameService::hasInvisibleOrStackedMarks($name)) {
            return self::INVALID;
        }
        $font = $field === 'pet_name' ? 'BricolageBold' : 'BricolageExtraBold';
        if (preg_match(self::NON_LATIN_LETTER, $this->composed($name)) === 1 || ! CertificatePdfRenderer::supports($name, $font)) {
            return self::UNSUPPORTED_SCRIPT;
        }
        if (! $this->petNames->isAllowed($name)) {
            return self::NOT_ALLOWED;
        }

        return null;
    }

    /**
     * A form default: cleaned (parentheses → spaces), cut to 30 characters,
     * and "" when it still could not be printed.
     */
    public function printableDefault(string $field, ?string $value): string
    {
        $name = PetNameService::normalize(str_replace(['(', ')', '[', ']', '/', ','], ' ', (string) $value));
        if (! is_string($name)) {
            return '';
        }
        $name = trim(mb_substr($name, 0, ChallengeCertificateService::MAX_NAME_LENGTH));

        return $this->problem($field, $name) === null ? $name : '';
    }

    /** NFC, so a letter with a combining accent counts as one Latin letter. */
    private function composed(string $name): string
    {
        $nfc = \Normalizer::normalize($name, \Normalizer::FORM_C);

        return is_string($nfc) ? $nfc : $name;
    }
}
