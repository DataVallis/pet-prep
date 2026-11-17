<?php

namespace App\Services;

use App\Models\EmailContact;
use App\Models\EmailSignup;
use Illuminate\Support\Facades\DB;

/**
 * Stores every e-mail left on petprep.si (M6-S09): one CONTACT per lower-cased address and one
 * SIGNUP row per submission. A repeat signup adds a signup row, bumps the contact's counter and
 * last-seen time and never creates a second contact. The stored consent stays as given; only a
 * NEWER consent sentence accepted now replaces it (a cached old page never downgrades it).
 *
 * Written by POST /api/email-signups and the old POST /api/summary-leads. Nothing about the
 * visitor's address or browser is stored, and nothing here logs or alerts with the e-mail.
 */
class EmailSignupService
{
    /**
     * @param  array<string, mixed>  $data  `email`, `consent_version`, `purpose`, `source` and the optional
     *                                      `page_path`, `section`, `locale`, `species`, `breed`, `stage`, `quiz_result` (list), `quiz_answers`,
     *                                      `utm_source|medium|campaign|content|term`, `referrer_host`
     */
    public function record(array $data): EmailContact
    {
        // ASCII only (the requests), so strtolower matches PostgreSQL lower().
        $email = strtolower(trim((string) $data['email']));

        // Second pass only when the contact was deleted (admin) between the read and the lock.
        return $this->store($email, $data) ?? $this->store($email, $data)
            ?? throw new \RuntimeException('E-mail signup could not be stored.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function store(string $email, array $data): ?EmailContact
    {
        $now = now();

        return DB::transaction(function () use ($email, $data, $now): ?EmailContact {
            // createOrFirst inside: two simultaneous first signups end as one contact (unique index).
            $contact = EmailContact::firstOrCreate(['email' => $email], [
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'signups_count' => 1,
                'consent_version' => $data['consent_version'],
                'consented_at' => $now,
            ]);

            if (! $contact->wasRecentlyCreated) {
                $contact = EmailContact::whereKey($contact->id)->lockForUpdate()->first();
                if ($contact === null) {
                    return null;
                }

                $update = ['signups_count' => $contact->signups_count + 1, 'last_seen_at' => $now];
                if ($this->versionRank($data['consent_version']) > $this->versionRank($contact->consent_version)) {
                    $update['consent_version'] = $data['consent_version'];
                    $update['consented_at'] = $now;
                }
                $contact->update($update);
            }

            $contact->signups()->create($this->signupRow($data, $now));

            return $contact;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function signupRow(array $data, \DateTimeInterface $now): array
    {
        $row = [
            'purpose' => $data['purpose'],
            'source' => $data['source'],
            'consent_version' => $data['consent_version'],
            'created_at' => $now,
        ];
        foreach (['page_path', 'section', 'locale', 'species', 'breed', 'stage', 'quiz_answers', 'referrer_host'] as $key) {
            $row[$key] = $data[$key] ?? null;
        }
        foreach (EmailSignup::UTM_KEYS as $key) {
            $row['utm_'.$key] = $data['utm_'.$key] ?? null;
        }
        $row['quiz_result'] = ! empty($data['quiz_result']) ? array_values($data['quiz_result']) : null;
        if ($row['referrer_host'] !== null) {
            $row['referrer_host'] = strtolower($row['referrer_host']);
        }

        return $row;
    }

    /** Position in EmailContact::CONSENT_VERSIONS (oldest first); an unknown stored version ranks lowest. */
    private function versionRank(string $version): int
    {
        $rank = array_search($version, EmailContact::CONSENT_VERSIONS, true);

        return $rank === false ? -1 : $rank;
    }
}
