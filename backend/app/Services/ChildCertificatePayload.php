<?php

namespace App\Services;

/**
 * `certificate` in the child state (M2-01d, ChildPetStateResource): the
 * requesting child's own challenge end on this pet, or null while it runs.
 * Outcome `report` carries nothing else (all other fields null / false): the
 * child sees a kind "challenge finished" — never a score or a reason (no
 * shaming; the parent sees the report). Outcome `certificate`: the names and
 * language a parent chose, the score, number, end and — once generated — the
 * signed PDF URL (60–90 min).
 */
final class ChildCertificatePayload
{
    /**
     * @param  'certificate'|'report'  $outcome
     * @param  'en'|'sl'|null  $locale
     */
    public function __construct(
        public readonly string $outcome,
        public readonly bool $generated,
        public readonly ?string $childName,
        public readonly ?string $petName,
        public readonly ?int $careScore,
        public readonly ?string $number,
        public readonly ?string $completedAt,
        public readonly ?string $locale,
        public readonly ?string $pdfUrl,
    ) {}

    /**
     * @return array{outcome: 'certificate'|'report', generated: bool, child_name: string|null, pet_name: string|null, care_score: int|null, number: string|null, completed_at: string|null, locale: 'en'|'sl'|null, pdf_url: string|null}
     */
    public function toArray(): array
    {
        return [
            /** @var 'certificate'|'report' */
            'outcome' => $this->outcome,
            // A parent generated the PDF (only for `certificate`).
            'generated' => $this->generated,
            'child_name' => $this->childName,
            'pet_name' => $this->petName,
            'care_score' => $this->careScore,
            'number' => $this->number,
            'completed_at' => $this->completedAt,
            /** @var 'en'|'sl'|null */
            'locale' => $this->locale,
            'pdf_url' => $this->pdfUrl,
        ];
    }
}
