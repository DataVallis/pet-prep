<?php

namespace App\Services;

use App\Models\ChallengeCertificate;

/**
 * One challenge end (M2-01d) for the parent app: list item of
 * GET /api/parent/certificates, body of POST …/generate and the per-child
 * `certificate` of the family dashboard. Typed for Scramble. Instants ISO 8601
 * in the family timezone; `completed_local_date` = the family-local end day.
 *
 * `defaults` pre-fill the parent's "Create certificate" form: the child's
 * nickname, the pet's name (or the breed in the request language) and the
 * request language. `pdf_url` is a signed, expiring URL (60–90 min) once a
 * parent generated the PDF, else null. Report rows (`outcome: report`) carry
 * the reason and score but never a number or a PDF.
 */
final class CertificatePayload
{
    /**
     * @param  'certificate'|'report'  $outcome
     * @param  'low_score'|'game_over'|null  $reason
     * @param  'en'|'sl'|null  $locale
     * @param  array{child_name: string, pet_name: string, locale: 'en'|'sl'}  $defaults
     */
    public function __construct(
        public readonly int $id,
        public readonly int $childId,
        public readonly ?string $childNickname,
        public readonly int $petId,
        public readonly string $species,
        public readonly string $breedType,
        public readonly string $outcome,
        public readonly ?string $reason,
        public readonly ?int $careScore,
        public readonly ?string $number,
        public readonly string $startedAt,
        public readonly string $completedAt,
        public readonly string $completedLocalDate,
        public readonly bool $generated,
        public readonly ?string $generatedAt,
        public readonly ?string $childName,
        public readonly ?string $petName,
        public readonly ?string $locale,
        public readonly array $defaults,
        public readonly ?string $pdfUrl,
    ) {}

    public static function for(ChallengeCertificate $certificate, string $timezone, ?string $requestLocale = null): self
    {
        return app(ChallengeCertificateService::class)->payload($certificate, $timezone, $requestLocale);
    }

    /**
     * @return array{id: int, child_id: int, child_nickname: string|null, pet_id: int, species: string, breed_type: string, outcome: 'certificate'|'report', reason: 'low_score'|'game_over'|null, care_score: int|null, number: string|null, started_at: string, completed_at: string, completed_local_date: string, generated: bool, generated_at: string|null, child_name: string|null, pet_name: string|null, locale: 'en'|'sl'|null, defaults: array{child_name: string, pet_name: string, locale: 'en'|'sl'}, pdf_url: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'child_id' => $this->childId,
            // The child's current nickname (null if unknown).
            'child_nickname' => $this->childNickname,
            'pet_id' => $this->petId,
            // dog | cat
            'species' => $this->species,
            'breed_type' => $this->breedType,
            /**
             * certificate: final Care Score ≥ 70 and no game over — the parent may generate the PDF;
             * report: no certificate (see `reason`).
             *
             * @var 'certificate'|'report'
             */
            'outcome' => $this->outcome,
            /** @var 'low_score'|'game_over'|null */
            'reason' => $this->reason,
            // Final Care Score (frozen; null when the child had no scored routine).
            'care_score' => $this->careScore,
            // PP-YYYY-NNNNNN — only for outcome `certificate`.
            'number' => $this->number,
            'started_at' => $this->startedAt,
            // End of the 84 program days (or the game over).
            'completed_at' => $this->completedAt,
            'completed_local_date' => $this->completedLocalDate,
            // A parent generated the PDF (pdf_url is set).
            'generated' => $this->generated,
            'generated_at' => $this->generatedAt,
            // As printed on the last generated PDF (null before the first generation).
            'child_name' => $this->childName,
            'pet_name' => $this->petName,
            /** @var 'en'|'sl'|null */
            'locale' => $this->locale,
            'defaults' => $this->defaults,
            'pdf_url' => $this->pdfUrl,
        ];
    }
}
