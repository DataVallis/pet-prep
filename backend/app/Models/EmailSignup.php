<?php

namespace App\Models;

use Database\Factories\EmailSignupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

/**
 * One submission of an e-mail on petprep.si (M6-S09): what form (`source`), what for (`purpose`),
 * where (`page_path` + `section`), and the targeting context (language, species, breed, stage,
 * quiz, UTM, referrer host). Append-only; no IP, no user agent, no name.
 *
 * @property int $id
 * @property int $email_contact_id
 * @property string $purpose newsletter | early_access
 * @property string $source real_cost | breed_quiz | early_access_form | any ^[a-z0-9_]{1,32}$
 * @property string|null $page_path
 * @property string|null $section
 * @property string|null $locale
 * @property string|null $species dog | cat
 * @property string|null $breed
 * @property string|null $stage adult | puppy
 * @property list<string>|null $quiz_result up to 3 breed ids
 * @property string|null $quiz_answers
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_content
 * @property string|null $utm_term
 * @property string|null $referrer_host
 * @property string $consent_version
 * @property Carbon $created_at
 */
class EmailSignup extends Model
{
    /** @use HasFactory<EmailSignupFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    public const PURPOSES = ['newsletter', 'early_access'];

    /** @var list<string> */
    public const SPECIES = ['dog', 'cat'];

    /** @var list<string> */
    public const STAGES = ['adult', 'puppy'];

    /** Sources the OLD POST /api/summary-leads still accepts (old cached pages). */
    public const LEGACY_SOURCES = ['real_cost', 'breed_quiz'];

    /** @var list<string> */
    public const UTM_KEYS = ['source', 'medium', 'campaign', 'content', 'term'];

    /** @var array<string, mixed> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quiz_result' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<EmailContact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(EmailContact::class, 'email_contact_id');
    }

    /** Readable label of a source for /admin and the CSV; an unknown source shows as stored. */
    public static function sourceLabel(string $source): string
    {
        $key = 'admin.email_signups.sources.'.$source;

        return Lang::has($key) ? __($key) : $source;
    }

    public static function purposeLabel(string $purpose): string
    {
        $key = 'admin.email_signups.purposes.'.$purpose;

        return Lang::has($key) ? __($key) : $purpose;
    }

    /** `Breed › Breed › Breed` of the quiz result, null without one. */
    public function quizText(): ?string
    {
        return $this->quiz_result ? implode(' › ', $this->quiz_result) : null;
    }

    /** `source / medium / campaign / content / term` of the filled UTM values, null without any. */
    public function utmText(): ?string
    {
        $parts = [];
        foreach (self::UTM_KEYS as $key) {
            $value = $this->{'utm_'.$key};
            if ($value !== null) {
                $parts[] = $key.'='.$value;
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }
}
