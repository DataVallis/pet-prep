<?php

namespace App\Services;

use App\Models\SummaryLead;
use Illuminate\Support\Facades\DB;

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
        // ASCII only (StoreSummaryLeadRequest), so strtolower matches PostgreSQL lower().
        $email = strtolower(trim($data['email']));
        $context = [
            'locale' => $data['locale'] ?? null,
            'breed' => $data['breed'] ?? null,
            'stage' => $data['stage'] ?? null,
        ];

        // Second pass only when the row was deleted (admin / prune) between the read and the lock.
        return $this->store($email, $data, $context) ?? $this->store($email, $data, $context)
            ?? throw new \RuntimeException('Summary lead could not be stored.');
    }

    /**
     * @param  array{email: string, consent_version: string, source: string, locale?: string|null, breed?: string|null, stage?: string|null}  $data
     * @param  array{locale: string|null, breed: string|null, stage: string|null}  $context
     */
    private function store(string $email, array $data, array $context): ?SummaryLead
    {
        $now = now();

        return DB::transaction(function () use ($email, $data, $now, $context): ?SummaryLead {
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

            $lead = SummaryLead::whereKey($lead->id)->lockForUpdate()->first();
            if ($lead === null) {
                return null;
            }

            // Keep what an earlier visit told us when this one says nothing.
            $update = array_filter($context, fn (?string $v): bool => $v !== null);
            $update['visits'] = $lead->visits + 1;
            $update['last_seen_at'] = $now;
            // The stored consent stays as given; only a NEWER consent sentence accepted now replaces it
            // (a cached old page never downgrades it).
            if ($this->versionRank($data['consent_version']) > $this->versionRank($lead->consent_version)) {
                $update['consent_version'] = $data['consent_version'];
                $update['consented_at'] = $now;
            }
            $lead->update($update);

            return $lead;
        });
    }

    /** Position in SummaryLead::CONSENT_VERSIONS (oldest first); an unknown stored version ranks lowest. */
    private function versionRank(string $version): int
    {
        $rank = array_search($version, SummaryLead::CONSENT_VERSIONS, true);

        return $rank === false ? -1 : $rank;
    }
}
