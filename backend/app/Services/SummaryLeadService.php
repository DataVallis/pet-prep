<?php

namespace App\Services;

use App\Models\SummaryLead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores a parent's e-mail from the page »Koliko dela je pes« (M6-S05).
 * One row per e-mail: the address is trimmed and lower-cased, a repeat visit
 * updates the existing row (visit counter, last visit, latest breed / stage /
 * language) and never adds a second one.
 */
class SummaryLeadService
{
    /**
     * @param  array{email: string, consent_version: string, source: string, locale?: string|null, breed?: string|null, stage?: string|null}  $data
     */
    public function record(array $data): SummaryLead
    {
        $email = Str::lower(trim($data['email']));
        $now = now();
        $context = [
            'locale' => $data['locale'] ?? null,
            'breed' => $data['breed'] ?? null,
            'stage' => $data['stage'] ?? null,
        ];

        return DB::transaction(function () use ($email, $data, $now, $context): SummaryLead {
            // createOrFirst inside: two simultaneous first visits end as one row (unique index).
            $lead = SummaryLead::firstOrCreate(['email' => $email], [
                'consent_version' => $data['consent_version'],
                'consented_at' => $now,
                'source' => $data['source'],
                'visits' => 1,
                'last_seen_at' => $now,
                ...$context,
            ]);

            if ($lead->wasRecentlyCreated) {
                return $lead;
            }

            $lead = SummaryLead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            // Keep what an earlier visit told us when this one says nothing.
            $update = array_filter($context, fn (?string $v): bool => $v !== null);
            $update['visits'] = $lead->visits + 1;
            $update['last_seen_at'] = $now;
            // The first consent stays as given; a newer consent sentence accepted now replaces it.
            if ($lead->consent_version !== $data['consent_version']) {
                $update['consent_version'] = $data['consent_version'];
                $update['consented_at'] = $now;
            }
            $lead->update($update);

            return $lead;
        });
    }
}
