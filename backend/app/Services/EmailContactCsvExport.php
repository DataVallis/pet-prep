<?php

namespace App\Services;

use App\Models\EmailContact;
use App\Models\EmailSignup;
use Illuminate\Database\Eloquent\Builder;

/**
 * CSV of the (filtered) e-mail contacts for /admin (M6-S09) — for Klaviyo or another provider's
 * import. One row per CONTACT: the latest signup's source / page / targeting context plus every
 * source and purpose the person ever used. Contains personal data: superadmin download only,
 * partners get numbers, never this file. Cells that a spreadsheet could read as a formula are
 * neutralised (an address may start with `+`, a UTM value with `=`).
 */
class EmailContactCsvExport
{
    /** @var list<string> */
    public const COLUMNS = [
        'email', 'sources', 'purposes', 'latest_source', 'latest_purpose', 'page_path', 'section', 'locale', 'species',
        'breed', 'stage', 'quiz_result', 'quiz_answers', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
        'utm_term', 'referrer_host', 'first_seen_at', 'last_seen_at', 'signups_count', 'consent_version', 'consented_at',
        'unsubscribed_at', 'provider_synced_at',
    ];

    /**
     * Writes the whole file to $out (a stream), UTF-8 with BOM so Excel reads it correctly.
     *
     * @param  Builder<EmailContact>  $query
     * @param  resource  $out
     */
    public function write(Builder $query, $out): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::COLUMNS, ',', '"', '');

        $query->reorder('email_contacts.id')->with('signups')->chunkById(500, function ($contacts) use ($out): void {
            foreach ($contacts as $contact) {
                fputcsv($out, array_map([$this, 'safe'], $this->row($contact)), ',', '"', '');
            }
        }, 'email_contacts.id', 'id');
    }

    /**
     * @return list<string|int|null>
     */
    public function row(EmailContact $contact): array
    {
        $latest = $contact->latestSignup();
        $all = $contact->signups;

        return [
            $contact->email,
            $all->pluck('source')->unique()->implode(';'),
            $all->pluck('purpose')->unique()->implode(';'),
            $latest?->source,
            $latest?->purpose,
            $latest?->page_path,
            $latest?->section,
            $latest?->locale,
            $latest?->species,
            $latest?->breed,
            $latest?->stage,
            $latest?->quiz_result ? implode(';', $latest->quiz_result) : null,
            $latest?->quiz_answers,
            ...array_map(fn (string $k): ?string => $latest?->{'utm_'.$k}, EmailSignup::UTM_KEYS),
            $latest?->referrer_host,
            $contact->first_seen_at->utc()->format('Y-m-d H:i:s'),
            $contact->last_seen_at->utc()->format('Y-m-d H:i:s'),
            $contact->signups_count,
            $contact->consent_version,
            $contact->consented_at->utc()->format('Y-m-d H:i:s'),
            $contact->unsubscribed_at?->utc()->format('Y-m-d H:i:s'),
            $contact->provider_synced_at?->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /** Spreadsheet formula injection: a cell starting with = + - @ tab or CR gets a leading apostrophe. */
    public function safe(string|int|null $value): string|int|null
    {
        if (is_string($value) && $value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'".$value;
        }

        return $value;
    }
}
