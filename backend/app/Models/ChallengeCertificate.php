<?php

namespace App\Models;

use App\Enums\BreedType;
use App\Enums\CertificateOutcome;
use App\Enums\CertificateReason;
use App\Enums\Species;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The end of one child's 12-week challenge on one pet (M2-01d,
 * CERTIFICATE_SPEC). Written once by ChallengeCertificateService (tick);
 * number / outcome / reason / care_score / dates / snapshots never change.
 * A parent generates (and may regenerate) the PDF for an outcome
 * `certificate`: child_name, pet_name, locale, pdf_path, generated_*.
 *
 * @property int $id
 * @property int $family_id
 * @property int $pet_id
 * @property int $child_user_id
 * @property string|null $number
 * @property CertificateOutcome $outcome
 * @property CertificateReason|null $reason
 * @property int|null $care_score
 * @property Carbon $started_at
 * @property Carbon $completed_at
 * @property string $completed_local_date
 * @property Species $species
 * @property BreedType $breed_type
 * @property string|null $default_pet_name
 * @property string|null $child_name
 * @property string|null $pet_name
 * @property string|null $locale
 * @property Carbon|null $generated_at
 * @property string|null $pdf_path
 * @property int|null $generated_by_user_id
 * @property Carbon|null $notified_at
 */
class ChallengeCertificate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'family_id', 'pet_id', 'child_user_id', 'number', 'outcome', 'reason', 'care_score',
        'started_at', 'completed_at', 'completed_local_date', 'species', 'breed_type', 'default_pet_name',
        'child_name', 'pet_name', 'locale', 'generated_at', 'pdf_path', 'generated_by_user_id', 'notified_at',
    ];

    /**
     * Storage path never leaves the server (the apps get a signed URL).
     *
     * @var list<string>
     */
    protected $hidden = ['pdf_path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => CertificateOutcome::class,
            'reason' => CertificateReason::class,
            'care_score' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'species' => Species::class,
            'breed_type' => BreedType::class,
            'generated_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function isCertificate(): bool
    {
        return $this->outcome === CertificateOutcome::Certificate;
    }

    public function isGenerated(): bool
    {
        return $this->generated_at !== null && $this->pdf_path !== null;
    }

    /** Family-local date of the end (Y-m-d), whatever the DB driver returns. */
    public function localDate(): string
    {
        return substr((string) $this->getRawOriginal('completed_local_date', $this->completed_local_date), 0, 10);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(User::class, 'child_user_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
