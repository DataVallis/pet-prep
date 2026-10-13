<?php

namespace App\Services;

use App\Enums\BreedFactAudience;
use App\Enums\BreedFactCategory;
use App\Enums\BreedFactStatus;
use App\Enums\BreedType;
use App\Models\BreedFact;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Imports breed facts from git (M5-R09b, DID_YOU_KNOW_SPEC §5 / §7; David:
 * everything lives in the repo). One JSON file per breed,
 * `<breed>.json` with `{breed, facts: [...]}` — format in
 * docs/research/breed-facts/README.md.
 *
 * Canonical content: docs/research/breed-facts/ (written and verified by the
 * content agents). The production image is built from backend/ only, so the
 * files are mirrored byte-for-byte into backend/database/data/breed-facts/
 * (DEFAULT_DIR, shipped in the image; BreedFactImportTest fails CI when the
 * two folders differ). The import never runs on its own — an operator runs
 * `php artisan breeds:import-facts`.
 *
 * Rules per file (a file with ANY error is rejected as a whole, nothing of it
 * is written):
 *  - the file name is `<breed>.json`, `breed` is a BreedType value and not a
 *    breed without a pedigree (mutt, domestic_cat — David §0.5);
 *  - every fact: key `<breed>-…` (unique), category / audience enums (family
 *    and health only for parents), title en + sl ≤ 120, why null or en + sl
 *    ≤ 140, source_id / source_name / http(s) source_url / verbatim quote
 *    non-empty, game_rule_key null or snake_case, integer sort, status draft
 *    | verified (`approved` is refused — only David approves, in the admin),
 *    verified needs verified_by;
 *  - a key that already belongs to another breed is refused.
 *  - unknown keys (top level or per fact) are errors;
 *  - `verified` counts only with proof for the exact text: `verified_hash`
 *    must equal the fact's content hash (BreedFact::hashOf, also for new
 *    facts) — otherwise the fact is imported as a DRAFT and reported
 *    (QA PR #210).
 * Upsert by key:
 *  - new key → inserted (verified with proof, else draft);
 *  - content hash unchanged → only `sort` may change (not content) and
 *    draft → verified with proof; never demoted, an approval stays;
 *  - content changed (any status) → new content, verified with proof else
 *    draft, approval cleared (David approves again);
 *  - facts in the DB but missing in the file are kept and reported.
 * Idempotent: a second run changes nothing.
 */
class BreedFactImportService
{
    /** Shipped copy of docs/research/breed-facts (relative to backend/). */
    public const DEFAULT_DIR = 'database/data/breed-facts';

    /** The canonical folder in the repo (relative to backend/; not in the production image). */
    public const DOCS_DIR = '../docs/research/breed-facts';

    public const KEY_MAX = 100;

    /** Allowed top-level keys of a file (QA PR #210: unknown keys are errors). */
    public const FILE_KEYS = ['breed', 'facts'];

    /** Allowed keys of one fact. */
    public const FACT_KEYS = [
        'key', 'category', 'audience', 'title', 'why', 'source_id', 'source_name', 'source_url',
        'quote', 'game_rule_key', 'sort', 'status', 'verified_by', 'verified_hash',
    ];

    /** Breeds without a pedigree never get facts (David 2026-10-09, spec §0.5). */
    public const NON_PEDIGREE = [BreedType::Mutt, BreedType::DomesticCat];

    /**
     * Absolute directory for an option value (absolute, or relative to backend/).
     */
    public function directory(?string $option = null): string
    {
        $dir = $option === null || $option === '' ? self::DEFAULT_DIR : $option;

        return str_starts_with($dir, '/') ? rtrim($dir, '/') : rtrim(base_path($dir), '/');
    }

    /**
     * The breed JSON files of a directory, sorted; filtered to $breeds
     * (BreedType values) when given.
     *
     * @param  list<string>  $breeds
     * @return list<string>
     */
    public function files(string $dir, array $breeds = []): array
    {
        $files = glob($dir.'/*.json') ?: [];
        sort($files);
        if ($breeds !== []) {
            $files = array_values(array_filter($files, fn (string $f) => in_array(basename($f, '.json'), $breeds, true)));
        }

        return array_values($files);
    }

    /**
     * Validate and (unless $dryRun) apply one file.
     *
     * @return array{file: string, breed: string|null, ok: bool, errors: list<string>, created: list<string>, updated: list<string>, reopened: list<string>, promoted: list<string>, reordered: list<string>, unchanged: list<string>, unproven: list<string>, missing: list<string>}
     */
    public function importFile(string $path, bool $dryRun = false): array
    {
        $result = [
            'file' => basename($path), 'breed' => null, 'ok' => false, 'errors' => [],
            'created' => [], 'updated' => [], 'reopened' => [], 'promoted' => [], 'reordered' => [], 'unchanged' => [], 'unproven' => [], 'missing' => [],
        ];

        [$breed, $rows, $errors] = $this->parse($path);
        $result['breed'] = $breed?->value;
        if ($errors !== []) {
            $result['errors'] = $errors;

            return $result;
        }

        /** @var BreedType $breed */
        $existing = BreedFact::whereIn('key', array_keys($rows))->get()->keyBy('key');
        foreach ($existing as $key => $fact) {
            if ($fact->breed !== $breed) {
                $result['errors'][] = "{$key}: the key already belongs to breed {$fact->breed->value}";
            }
        }
        if ($result['errors'] !== []) {
            return $result;
        }

        $writes = [];
        foreach ($rows as $key => $row) {
            $hash = BreedFact::hashOf($row['content']);
            /** @var BreedFact|null $fact */
            $fact = $existing->get($key);
            // QA PR #210: "verified" only with proof for THIS text — verified_hash
            // must equal the content hash, else the fact arrives as a draft.
            $proven = $row['status'] === BreedFactStatus::Verified && $row['verified_hash'] === $hash;
            if ($row['status'] === BreedFactStatus::Verified && ! $proven) {
                $result['unproven'][] = $key;
            }
            $review = $proven
                ? ['status' => BreedFactStatus::Verified->value, 'verified_by' => $row['verified_by']]
                : ['status' => BreedFactStatus::Draft->value, 'verified_by' => null];

            if ($fact === null) {
                $result['created'][] = $key;
                $writes[] = ['create', $key, $row['content'] + ['key' => $key, 'sort' => $row['sort'], 'content_hash' => $hash] + $review];

                continue;
            }

            if ($fact->content_hash !== $hash) {
                // New text: never keeps an earlier verify / approval.
                $result[$fact->isApproved() ? 'reopened' : 'updated'][] = $key;
                $writes[] = ['update', $key, $row['content'] + [
                    'sort' => $row['sort'], 'content_hash' => $hash, 'approved_by' => null, 'approved_at' => null,
                ] + $review];

                continue;
            }

            // Same text. Order may change (sort is not content); draft → verified with proof; never demoted.
            $attributes = [];
            if ($fact->sort !== $row['sort']) {
                $attributes['sort'] = $row['sort'];
            }
            if ($fact->status === BreedFactStatus::Draft && $proven) {
                $attributes += $review;
                $result['promoted'][] = $key;
            } elseif ($attributes !== []) {
                $result['reordered'][] = $key;
            } else {
                $result['unchanged'][] = $key;
            }
            if ($attributes !== []) {
                $writes[] = ['update', $key, $attributes];
            }
        }

        $result['missing'] = BreedFact::where('breed', $breed->value)
            ->whereNotIn('key', array_keys($rows))
            ->orderBy('sort')->orderBy('id')
            ->pluck('key')->all();
        $result['ok'] = true;

        if (! $dryRun && $writes !== []) {
            DB::transaction(function () use ($writes): void {
                foreach ($writes as [$op, $key, $attributes]) {
                    if ($op === 'create') {
                        BreedFact::create($attributes);
                    } else {
                        BreedFact::where('key', $key)->firstOrFail()->update($attributes);
                    }
                }
            });
        }

        return $result;
    }

    /**
     * Read + validate a file.
     *
     * @return array{0: BreedType|null, 1: array<string, array{content: array<string, mixed>, sort: int, status: BreedFactStatus, verified_by: string|null, verified_hash: string|null}>, 2: list<string>}
     */
    public function parse(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [null, [], ['cannot read the file']];
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [null, [], ['invalid JSON: '.$e->getMessage()]];
        }
        if (! is_array($data) || array_is_list($data)) {
            return [null, [], ['the file must be a JSON object {"breed": …, "facts": […]}']];
        }

        $unknownTop = array_diff(array_keys($data), self::FILE_KEYS);
        if ($unknownTop !== []) {
            return [null, [], ['unknown top-level key(s): '.implode(', ', $unknownTop).' (allowed: '.implode(', ', self::FILE_KEYS).')']];
        }
        $breed = is_string($data['breed'] ?? null) ? BreedType::tryFrom($data['breed']) : null;

        if ($breed === null) {
            return [null, [], ['unknown breed '.json_encode($data['breed'] ?? null)]];
        }
        $errors = [];
        if (in_array($breed, self::NON_PEDIGREE, true)) {
            $errors[] = "breed {$breed->value} has no pedigree — no facts (David 2026-10-09)";
        }
        if (basename($path) !== $breed->value.'.json') {
            $errors[] = 'the file name must be '.$breed->value.'.json';
        }
        $facts = $data['facts'] ?? null;
        if (! is_array($facts) || ! array_is_list($facts)) {
            $errors[] = '"facts" must be a list';

            return [$breed, [], $errors];
        }

        $rows = [];
        foreach ($facts as $i => $fact) {
            $label = is_array($fact) && is_string($fact['key'] ?? null) ? $fact['key'] : "facts[{$i}]";
            if (! is_array($fact) || array_is_list($fact)) {
                $errors[] = "{$label}: must be an object";

                continue;
            }
            $factErrors = $this->validateFact($breed, $fact);
            foreach ($factErrors as $e) {
                $errors[] = "{$label}: {$e}";
            }
            if ($factErrors !== []) {
                continue;
            }
            if (isset($rows[$fact['key']])) {
                $errors[] = "{$label}: duplicate key";

                continue;
            }
            $rows[$fact['key']] = [
                'content' => [
                    'breed' => $breed->value,
                    'category' => $fact['category'],
                    'audience' => $fact['audience'],
                    'title_en' => $fact['title']['en'],
                    'title_sl' => $fact['title']['sl'],
                    'why_en' => $fact['why']['en'] ?? null,
                    'why_sl' => $fact['why']['sl'] ?? null,
                    'source_id' => $fact['source_id'],
                    'source_name' => $fact['source_name'],
                    'source_url' => $fact['source_url'],
                    'quote' => $fact['quote'],
                    'game_rule_key' => $fact['game_rule_key'] ?? null,
                ],
                'sort' => $fact['sort'],
                'status' => BreedFactStatus::from($fact['status']),
                'verified_by' => $fact['verified_by'] ?? null,
                'verified_hash' => $fact['verified_hash'] ?? null,
            ];
        }

        return [$breed, $errors === [] ? $rows : [], $errors];
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return list<string>
     */
    private function validateFact(BreedType $breed, array $fact): array
    {
        $errors = [];
        $unknown = array_diff(array_keys($fact), self::FACT_KEYS);
        if ($unknown !== []) {
            $errors[] = 'unknown key(s): '.implode(', ', $unknown);
        }
        foreach (['title', 'why'] as $field) {
            if (is_array($fact[$field] ?? null) && ($extra = array_diff(array_keys($fact[$field]), ['en', 'sl'])) !== []) {
                $errors[] = "{$field}: unknown language key(s): ".implode(', ', $extra);
            }
        }
        $text = fn (mixed $v): bool => is_string($v) && trim($v) !== '';
        $len = fn (string $v): int => mb_strlen($v);

        $key = $fact['key'] ?? null;
        if (! $text($key)) {
            $errors[] = 'key is required';
        } elseif (! str_starts_with($key, $breed->value.'-') || $len($key) > self::KEY_MAX || ! preg_match('/^[a-z0-9_]+-[a-z0-9_-]+$/', $key)) {
            $errors[] = 'key must look like "'.$breed->value.'-001" (lower case, ≤ '.self::KEY_MAX.' chars)';
        }

        $category = is_string($fact['category'] ?? null) ? BreedFactCategory::tryFrom($fact['category']) : null;
        if ($category === null) {
            $errors[] = 'category must be one of '.implode(', ', BreedFactCategory::values());
        }
        $audience = is_string($fact['audience'] ?? null) ? BreedFactAudience::tryFrom($fact['audience']) : null;
        if ($audience === null) {
            $errors[] = 'audience must be child or parent';
        }
        if ($category?->isParentOnly() && $audience === BreedFactAudience::Child) {
            $errors[] = "a {$category->value} fact is for parents only (audience must be parent)";
        }

        $title = $fact['title'] ?? null;
        foreach (['en', 'sl'] as $lang) {
            $value = is_array($title) ? ($title[$lang] ?? null) : null;
            if (! $text($value)) {
                $errors[] = "title.{$lang} is required";
            } elseif ($len($value) > BreedFact::TITLE_MAX) {
                $errors[] = "title.{$lang} is longer than ".BreedFact::TITLE_MAX.' characters';
            }
        }

        $why = $fact['why'] ?? null;
        if ($why !== null) {
            foreach (['en', 'sl'] as $lang) {
                $value = is_array($why) ? ($why[$lang] ?? null) : null;
                if (! $text($value)) {
                    $errors[] = "why.{$lang} is required when why is given (or set why to null)";
                } elseif ($len($value) > BreedFact::WHY_MAX) {
                    $errors[] = "why.{$lang} is longer than ".BreedFact::WHY_MAX.' characters';
                }
            }
        }

        foreach (['source_id' => 50, 'source_name' => 200, 'source_url' => 500] as $field => $max) {
            $value = $fact[$field] ?? null;
            if (! $text($value)) {
                $errors[] = "{$field} is required";
            } elseif ($len($value) > $max) {
                $errors[] = "{$field} is longer than {$max} characters";
            }
        }
        if ($text($fact['source_url'] ?? null) && ! preg_match('#^https?://[^\s]+$#i', $fact['source_url'])) {
            $errors[] = 'source_url must be an http(s) URL';
        }
        if (! $text($fact['quote'] ?? null)) {
            $errors[] = 'quote (verbatim from the source) is required';
        }

        $rule = $fact['game_rule_key'] ?? null;
        if ($rule !== null && (! is_string($rule) || ! preg_match('/^[a-z][a-z0-9_]{0,49}$/', $rule))) {
            $errors[] = 'game_rule_key must be null or snake_case (≤ 50 chars)';
        }
        if (! is_int($fact['sort'] ?? null)) {
            $errors[] = 'sort must be an integer';
        }

        $status = $fact['status'] ?? null;
        if ($status === BreedFactStatus::Approved->value) {
            $errors[] = 'status approved is not allowed in a file — only David approves, in the admin';
        } elseif (! in_array($status, [BreedFactStatus::Draft->value, BreedFactStatus::Verified->value], true)) {
            $errors[] = 'status must be draft or verified';
        }
        $verifiedBy = $fact['verified_by'] ?? null;
        if ($verifiedBy !== null && (! $text($verifiedBy) || $len($verifiedBy) > 100)) {
            $errors[] = 'verified_by must be null or a name (≤ 100 chars)';
        }
        if ($status === BreedFactStatus::Verified->value && ! $text($verifiedBy)) {
            $errors[] = 'a verified fact needs verified_by';
        }
        $verifiedHash = $fact['verified_hash'] ?? null;
        if ($verifiedHash !== null && (! is_string($verifiedHash) || ! preg_match('/^[0-9a-f]{64}$/', $verifiedHash))) {
            $errors[] = 'verified_hash must be null or a lower-case sha256 hex (64 chars)';
        }

        return $errors;
    }
}
