<?php

use App\Enums\BreedFactStatus;
use App\Filament\Resources\BreedFactResource;
use App\Filament\Resources\BreedFactResource\Pages\ListBreedFacts;
use App\Filament\Resources\BreedFactResource\Pages\ViewBreedFact;
use App\Models\BreedFact;
use App\Models\User;
use App\Services\BreedFactImportService;
use App\Services\BreedFactReviewService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M5-R09b — breed facts import (`breeds:import-facts`) and admin review
|--------------------------------------------------------------------------
| Content lives in git (docs/research/breed-facts, shipped copy in
| backend/database/data/breed-facts). Upsert by key; an approved fact with
| unchanged content is never touched; changed content of an approved fact
| goes back to review; status `approved` in a file is refused; missing
| facts are kept and reported; a dry run writes nothing.
*/

const BF_FIXTURE = __DIR__.'/../Fixtures/breed-facts/border_collie.json';

/** A temp folder with border_collie.json = the fixture modified by $edit. */
function bfiDir(?callable $edit = null, string $file = 'border_collie.json'): string
{
    $dir = sys_get_temp_dir().'/bfi-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $data = json_decode((string) file_get_contents(BF_FIXTURE), true);
    if ($edit !== null) {
        $data = $edit($data);
    }
    file_put_contents($dir.'/'.$file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return $dir;
}

/** verified_hash for a fact of a file (same definition as the README / --print-hashes). */
function bfiHash(string $breed, array $f): string
{
    $values = [$breed, $f['category'], $f['audience'], $f['title']['en'], $f['title']['sl'], $f['why']['en'] ?? null, $f['why']['sl'] ?? null,
        $f['source_id'], $f['source_name'], $f['source_url'], $f['quote'], $f['game_rule_key'] ?? null];

    return hash('sha256', (string) json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function bfiImport(string $dir, array $options = [])
{
    return test()->artisan('breeds:import-facts', array_merge(['--dir' => $dir], $options));
}

function bfiErrors(callable $edit): array
{
    return app(BreedFactImportService::class)->importFile(bfiDir($edit).'/border_collie.json')['errors'];
}

describe('import', function () {
    it('imports a file, keeps the statuses and is idempotent', function () {
        $dir = bfiDir();

        bfiImport($dir)->expectsOutputToContain('border_collie.json: 3 new, 0 updated')->assertSuccessful();

        $facts = BreedFact::orderBy('sort')->get();
        expect($facts->pluck('key')->all())->toBe(['border_collie-001', 'border_collie-002', 'border_collie-003'])
            ->and($facts->pluck('status')->map->value->all())->toBe(['verified', 'draft', 'verified'])
            ->and($facts[0]->toArray())->toMatchArray([
                'breed' => 'border_collie', 'category' => 'exercise', 'audience' => 'child', 'game_rule_key' => 'daily_steps',
                'title_sl' => 'Border collie potrebuje več kot dve uri gibanja na dan.', 'verified_by' => 'claude-verifier',
                'source_id' => 'S5', 'quote' => 'Exercise: More than 2 hours per day', 'sort' => 1,
            ])
            ->and($facts[1]->why_en)->toBeNull()
            ->and($facts[0]->content_hash)->toBe($facts[0]->computeHash());

        $snapshot = fn () => BreedFact::orderBy('id')->get()->map(fn (BreedFact $f) => [$f->id, $f->status->value, $f->content_hash, $f->updated_at->toIso8601String()])->all();
        $before = $snapshot();
        bfiImport($dir)->expectsOutputToContain('0 new, 0 updated, 0 approved → re-review, 0 draft → verified, 0 reordered, 3 unchanged')->assertSuccessful();
        expect($snapshot())->toBe($before);
    });

    it('writes nothing on a dry run', function () {
        bfiImport(bfiDir(), ['--dry-run' => true])
            ->expectsOutputToContain('3 new')
            ->expectsOutputToContain('Dry run — nothing was written.')
            ->assertSuccessful();

        expect(BreedFact::count())->toBe(0);
    });

    it('never touches an approved fact whose content is unchanged', function () {
        $dir = bfiDir();
        bfiImport($dir)->assertSuccessful();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $fact = BreedFact::where('key', 'border_collie-001')->sole();
        app(BreedFactReviewService::class)->approve($fact, $admin);
        $approvedAt = $fact->fresh()->approved_at;

        // The file still says "verified" — the approval stays.
        bfiImport($dir)->assertSuccessful();
        expect($fact->fresh())->status->toBe(BreedFactStatus::Approved)
            ->approved_by->toBe($admin->id)
            ->approved_at->toEqual($approvedAt);
    });

    it('sends an approved fact whose content changed back to review', function () {
        bfiImport(bfiDir())->assertSuccessful();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        app(BreedFactReviewService::class)->approve(BreedFact::where('key', 'border_collie-001')->sole(), $admin);

        // New text, the file still carries the OLD verified_hash → draft (not re-verified).
        bfiImport(bfiDir(function (array $d) {
            $d['facts'][0]['title']['sl'] = 'Border collie potrebuje več kot 2 uri gibanja na dan.';

            return $d;
        }))
            ->expectsOutputToContain('1 approved → re-review')
            ->expectsOutputToContain('border_collie-001: approved content changed')
            ->expectsOutputToContain('without a matching verified_hash (kept as draft): border_collie-001')
            ->assertSuccessful();

        expect(BreedFact::where('key', 'border_collie-001')->sole())
            ->status->toBe(BreedFactStatus::Draft)
            ->verified_by->toBeNull()
            ->approved_by->toBeNull()
            ->approved_at->toBeNull()
            ->title_sl->toBe('Border collie potrebuje več kot 2 uri gibanja na dan.');

        // Re-verified for the new text (verified_hash = new content hash) → verified.
        bfiImport(bfiDir(function (array $d) {
            $d['facts'][0]['title']['sl'] = 'Border collie potrebuje več kot 2 uri gibanja na dan.';
            $d['facts'][0]['verified_hash'] = bfiHash('border_collie', $d['facts'][0]);

            return $d;
        }))->expectsOutputToContain('1 draft → verified')->assertSuccessful();
        expect(BreedFact::where('key', 'border_collie-001')->sole()->status)->toBe(BreedFactStatus::Verified);
    });

    it('imports "verified" only with a verified_hash of the exact text (also new facts)', function () {
        bfiImport(bfiDir(function (array $d) {
            unset($d['facts'][0]['verified_hash']);                // no proof
            $d['facts'][2]['verified_hash'] = str_repeat('0', 64); // proof for another text

            return $d;
        }))
            ->expectsOutputToContain('3 new')
            ->expectsOutputToContain('without a matching verified_hash (kept as draft): border_collie-001, border_collie-003')
            ->assertSuccessful();

        expect(BreedFact::orderBy('sort')->pluck('status')->map->value->all())->toBe(['draft', 'draft', 'draft'])
            ->and(BreedFact::whereNotNull('verified_by')->count())->toBe(0);
    });

    it('sends a changed verified (not approved) fact to draft unless re-verified', function () {
        bfiImport(bfiDir())->assertSuccessful();

        bfiImport(bfiDir(function (array $d) {
            $d['facts'][2]['quote'] = 'A different verbatim quote.';

            return $d;
        }))->expectsOutputToContain('1 updated')->assertSuccessful();
        expect(BreedFact::where('key', 'border_collie-003')->sole())->status->toBe(BreedFactStatus::Draft)->verified_by->toBeNull();
    });

    it('changes the order without re-opening the review (sort is not content)', function () {
        bfiImport(bfiDir())->assertSuccessful();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $fact = BreedFact::where('key', 'border_collie-001')->sole();
        app(BreedFactReviewService::class)->approve($fact, $admin);

        bfiImport(bfiDir(function (array $d) {
            $d['facts'][0]['sort'] = 10;

            return $d;
        }))->expectsOutputToContain('1 reordered')->assertSuccessful();
        expect($fact->fresh())->sort->toBe(10)->status->toBe(BreedFactStatus::Approved)->approved_by->toBe($admin->id);
    });

    it('prints the content hashes (verified_hash values)', function () {
        $data = json_decode((string) file_get_contents(BF_FIXTURE), true);
        $hash = bfiHash('border_collie', $data['facts'][1]);

        bfiImport(bfiDir(), ['--print-hashes' => true])
            ->expectsOutputToContain('border_collie-001 '.$data['facts'][0]['verified_hash'].' verified_hash-ok')
            ->expectsOutputToContain("border_collie-002 {$hash} no-verified_hash")
            ->assertSuccessful();
        expect(BreedFact::count())->toBe(0)
            ->and(BreedFact::hashOf([
                'breed' => 'border_collie', 'category' => 'origin', 'audience' => 'child',
                'title_en' => $data['facts'][1]['title']['en'], 'title_sl' => $data['facts'][1]['title']['sl'],
                'source_id' => 'S1', 'source_name' => 'FCI', 'source_url' => 'https://www.fci.be/', 'quote' => 'Test fixture quote.',
            ]))->toBe($hash);
    });

    it('updates drafts, promotes draft → verified, never demotes an admin verify', function () {
        bfiImport(bfiDir())->assertSuccessful();
        $draft = BreedFact::where('key', 'border_collie-002')->sole();

        // Same content, the file now says verified (with the hash) → promoted.
        bfiImport(bfiDir(function (array $d) {
            $d['facts'][1]['status'] = 'verified';
            $d['facts'][1]['verified_by'] = 'claude-verifier';
            $d['facts'][1]['verified_hash'] = bfiHash('border_collie', $d['facts'][1]);

            return $d;
        }))->expectsOutputToContain('1 draft → verified')->assertSuccessful();
        expect($draft->fresh()->status)->toBe(BreedFactStatus::Verified);

        // Back to the original file (draft): an unchanged verified fact is not demoted.
        bfiImport(bfiDir())->expectsOutputToContain('3 unchanged')->assertSuccessful();
        expect($draft->fresh()->status)->toBe(BreedFactStatus::Verified);

        // Changed content → the file's status (draft).
        bfiImport(bfiDir(function (array $d) {
            $d['facts'][1]['quote'] = 'Another verbatim quote.';

            return $d;
        }))->expectsOutputToContain('1 updated')->assertSuccessful();
        expect($draft->fresh())->status->toBe(BreedFactStatus::Draft)->quote->toBe('Another verbatim quote.');
    });

    it('keeps and reports facts that are missing from the file', function () {
        bfiImport(bfiDir())->assertSuccessful();

        bfiImport(bfiDir(function (array $d) {
            array_pop($d['facts']);

            return $d;
        }))->expectsOutputToContain('not in the file (kept): border_collie-003')->assertSuccessful();

        expect(BreedFact::count())->toBe(3);
    });

    it('rejects a whole file with any error and imports nothing of it', function () {
        bfiImport(bfiDir(function (array $d) {
            $d['facts'][1]['audience'] = 'kids';

            return $d;
        }))
            ->expectsOutputToContain('border_collie.json: REJECTED')
            ->expectsOutputToContain('border_collie-002: audience must be child or parent')
            ->assertFailed();

        expect(BreedFact::count())->toBe(0);
    });

    it('validates every field', function (callable $edit, string $error) {
        expect(implode("\n", bfiErrors($edit)))->toContain($error);
    })->with([
        'status approved' => [function ($d) {
            $d['facts'][0]['status'] = 'approved';

            return $d;
        }, 'status approved is not allowed'],
        'unknown category' => [function ($d) {
            $d['facts'][0]['category'] = 'fun';

            return $d;
        }, 'category must be one of'],
        'health for a child' => [function ($d) {
            $d['facts'][2]['audience'] = 'child';

            return $d;
        }, 'a health fact is for parents only'],
        'family for a child' => [function ($d) {
            $d['facts'][0]['category'] = 'family';

            return $d;
        }, 'a family fact is for parents only'],
        'title too long' => [function ($d) {
            $d['facts'][0]['title']['sl'] = str_repeat('č', 121);

            return $d;
        }, 'title.sl is longer than 120'],
        'title 120 is fine' => [function ($d) {
            $d['facts'][0]['title']['sl'] = str_repeat('č', 120);
            $d['facts'][0]['category'] = 'nope';

            return $d;
        }, 'category must be one of'],
        'missing title' => [function ($d) {
            unset($d['facts'][0]['title']['en']);

            return $d;
        }, 'title.en is required'],
        'why too long' => [function ($d) {
            $d['facts'][0]['why']['en'] = str_repeat('a', 141);

            return $d;
        }, 'why.en is longer than 140'],
        'why half missing' => [function ($d) {
            $d['facts'][0]['why'] = ['en' => 'x'];

            return $d;
        }, 'why.sl is required when why is given'],
        'no source name' => [function ($d) {
            $d['facts'][0]['source_name'] = ' ';

            return $d;
        }, 'source_name is required'],
        'no source id' => [function ($d) {
            unset($d['facts'][0]['source_id']);

            return $d;
        }, 'source_id is required'],
        'bad url' => [function ($d) {
            $d['facts'][0]['source_url'] = 'javascript:alert(1)';

            return $d;
        }, 'source_url must be an http(s) URL'],
        'empty quote' => [function ($d) {
            $d['facts'][0]['quote'] = '';

            return $d;
        }, 'quote (verbatim from the source) is required'],
        'bad game rule' => [function ($d) {
            $d['facts'][0]['game_rule_key'] = 'Daily Steps';

            return $d;
        }, 'game_rule_key must be null or snake_case'],
        'sort not int' => [function ($d) {
            $d['facts'][0]['sort'] = '1';

            return $d;
        }, 'sort must be an integer'],
        'verified without verifier' => [function ($d) {
            $d['facts'][0]['verified_by'] = null;

            return $d;
        }, 'a verified fact needs verified_by'],
        'key of another breed' => [function ($d) {
            $d['facts'][0]['key'] = 'maine_coon-001';

            return $d;
        }, 'key must look like "border_collie-001"'],
        'duplicate key' => [function ($d) {
            $d['facts'][1]['key'] = 'border_collie-001';

            return $d;
        }, 'duplicate key'],
        'unknown fact key' => [function ($d) {
            $d['facts'][0]['verifed_by'] = 'typo';

            return $d;
        }, 'border_collie-001: unknown key(s): verifed_by'],
        'unknown language' => [function ($d) {
            $d['facts'][0]['title']['de'] = 'Hund';

            return $d;
        }, 'title: unknown language key(s): de'],
        'bad verified_hash' => [function ($d) {
            $d['facts'][0]['verified_hash'] = 'ABC';

            return $d;
        }, 'verified_hash must be null or a lower-case sha256 hex'],
        'unknown top-level key' => [function ($d) {
            $d['version'] = 2;

            return $d;
        }, 'unknown top-level key(s): version'],
        'facts not a list' => [function ($d) {
            $d['facts'] = ['a' => 1];

            return $d;
        }, '"facts" must be a list'],
    ]);

    it('rejects mutt, domestic cat, unknown breeds and a wrong file name', function () {
        $service = app(BreedFactImportService::class);
        $as = fn (string $breed) => fn ($d) => array_merge($d, ['breed' => $breed]);

        expect($service->importFile(bfiDir($as('mutt'), 'mutt.json').'/mutt.json')['errors'])->toContain('breed mutt has no pedigree — no facts (David 2026-10-09)')
            ->and($service->importFile(bfiDir($as('domestic_cat'), 'domestic_cat.json').'/domestic_cat.json')['errors'][0])->toContain('domestic_cat has no pedigree')
            ->and($service->importFile(bfiDir($as('unicorn'), 'unicorn.json').'/unicorn.json')['errors'])->toBe(['unknown breed "unicorn"'])
            ->and(implode(' ', $service->importFile(bfiDir(null, 'maine_coon.json').'/maine_coon.json')['errors']))->toContain('the file name must be border_collie.json');

        $bad = sys_get_temp_dir().'/bfi-bad-'.bin2hex(random_bytes(4));
        mkdir($bad);
        file_put_contents($bad.'/border_collie.json', '{not json');
        bfiImport($bad)->expectsOutputToContain('invalid JSON')->assertFailed();
        expect(BreedFact::count())->toBe(0);
    });

    it('refuses a key that already belongs to another breed', function () {
        BreedFact::create([
            'key' => 'border_collie-001', 'breed' => 'maine_coon', 'category' => 'origin', 'audience' => 'child',
            'title_en' => 'x', 'title_sl' => 'x', 'source_id' => 'C1', 'source_name' => 'x', 'source_url' => 'https://x.test',
            'quote' => 'x', 'sort' => 1, 'status' => 'draft', 'content_hash' => str_repeat('0', 64),
        ]);

        bfiImport(bfiDir())->expectsOutputToContain('border_collie-001: the key already belongs to breed maine_coon')->assertFailed();
        expect(BreedFact::count())->toBe(1);
    });

    it('filters by breed and rejects unknown --breed values', function () {
        $dir = bfiDir();
        bfiImport($dir, ['--breed' => ['maine_coon']])->expectsOutputToContain('No breed fact files found')->assertSuccessful();
        bfiImport($dir, ['--breed' => ['border-collie']])->expectsOutputToContain('3 new')->assertSuccessful();
        bfiImport($dir, ['--breed' => ['unicorn']])->expectsOutputToContain('Unknown breed: unicorn')->assertFailed();
        bfiImport('/nonexistent/folder')->assertFailed();
    });

    it('also blocks the DB from parent-only child facts and mutt facts', function () {
        $row = fn (array $over) => array_merge([
            'key' => 'k-'.bin2hex(random_bytes(3)), 'breed' => 'border_collie', 'category' => 'health', 'audience' => 'child',
            'title_en' => 'x', 'title_sl' => 'x', 'source_id' => 'S1', 'source_name' => 'x', 'source_url' => 'https://x.test',
            'quote' => 'x', 'sort' => 1, 'status' => 'draft', 'content_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now(),
        ], $over);

        expect(fn () => DB::table('breed_facts')->insert($row([])))->toThrow(QueryException::class);
        expect(fn () => DB::table('breed_facts')->insert($row(['audience' => 'parent', 'breed' => 'mutt'])))->toThrow(QueryException::class);
        expect(fn () => DB::table('breed_facts')->insert($row(['audience' => 'parent', 'status' => 'approved'])))->toThrow(QueryException::class);
    });
});

describe('shipped copy', function () {
    it('ships exactly the JSON files of docs/research/breed-facts', function () {
        $docs = base_path(BreedFactImportService::DOCS_DIR);
        $shipped = base_path(BreedFactImportService::DEFAULT_DIR);
        $list = fn (string $dir): array => array_map('basename', glob($dir.'/*.json') ?: []);

        expect(is_dir($docs))->toBeTrue('docs/research/breed-facts is missing')
            ->and($list($shipped))->toBe($list($docs), 'copy docs/research/breed-facts/*.json to backend/database/data/breed-facts/');
        foreach ($list($docs) as $file) {
            expect(hash_file('sha256', "{$shipped}/{$file}"))->toBe(hash_file('sha256', "{$docs}/{$file}"), "{$file} differs from docs/research/breed-facts — copy it again");
        }
    });

    it('validates every shipped file', function () {
        $service = app(BreedFactImportService::class);
        foreach ($service->files($service->directory()) as $file) {
            expect($service->parse($file)[2])->toBe([], basename($file));
        }
        expect(true)->toBeTrue();
    });
});

describe('admin review', function () {
    beforeEach(function () {
        bfiImport(bfiDir())->assertSuccessful();
    });

    it('walks draft → verified → approved and back to draft', function () {
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $review = app(BreedFactReviewService::class);
        $draft = BreedFact::where('key', 'border_collie-002')->sole();

        expect(fn () => $review->approve($draft, $admin))->toThrow(InvalidArgumentException::class, 'Only a verified fact');
        $review->verify($draft, $admin);
        expect($draft->fresh())->status->toBe(BreedFactStatus::Verified)->verified_by->toBe('admin#'.$admin->id);
        $review->approve($draft->fresh(), $admin);
        expect($draft->fresh())->status->toBe(BreedFactStatus::Approved)->approved_by->toBe($admin->id)->approved_at->not->toBeNull();
        $review->reject($draft->fresh(), $admin);
        expect($draft->fresh())->status->toBe(BreedFactStatus::Draft)->approved_by->toBeNull()->approved_at->toBeNull()->verified_by->toBeNull();

        $parent = User::factory()->parent()->create();
        $draft2 = BreedFact::where('key', 'border_collie-002')->sole();
        expect(fn () => $review->approve(BreedFact::where('key', 'border_collie-001')->sole(), $parent))->toThrow(InvalidArgumentException::class, 'superadmin')
            ->and(fn () => $review->verify($draft2, $parent))->toThrow(InvalidArgumentException::class, 'superadmin')
            ->and(fn () => $review->reject(BreedFact::where('key', 'border_collie-001')->sole(), $parent))->toThrow(InvalidArgumentException::class, 'superadmin')
            ->and(fn () => $review->approveMany([BreedFact::where('key', 'border_collie-001')->sole()], $parent))->toThrow(InvalidArgumentException::class, 'superadmin')
            ->and($draft2->fresh()->status)->toBe(BreedFactStatus::Draft)
            ->and($parent->can('verify', $draft2))->toBeFalse()
            ->and($admin->can('verify', $draft2))->toBeTrue();
    });

    it('needs the source fields before verifying', function () {
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $draft = BreedFact::where('key', 'border_collie-002')->sole();
        $draft->forceFill(['quote' => ''])->save();

        expect(fn () => app(BreedFactReviewService::class)->verify($draft, $admin))->toThrow(InvalidArgumentException::class, 'Source id, name, URL and quote');
        expect($draft->fresh()->status)->toBe(BreedFactStatus::Draft);
    });

    it('lists, filters, approves in bulk (verified only) and previews in the admin', function () {
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);
        $facts = BreedFact::orderBy('sort')->get();

        // Default order: breed, sort, id (not id alone).
        $facts[0]->forceFill(['sort' => 10])->save();
        Livewire::test(ListBreedFacts::class)->assertCanSeeTableRecords([$facts[1], $facts[2], $facts[0]], inOrder: true);
        $facts[0]->forceFill(['sort' => 1])->save();

        Livewire::test(ListBreedFacts::class)
            ->assertCanSeeTableRecords($facts, inOrder: true)
            ->filterTable('status', 'draft')
            ->assertCanSeeTableRecords($facts->where('key', 'border_collie-002'))
            ->assertCanNotSeeTableRecords($facts->where('key', 'border_collie-001'))
            ->resetTableFilters()
            ->filterTable('audience', 'parent')
            ->assertCanSeeTableRecords($facts->where('key', 'border_collie-003'))
            ->assertCanNotSeeTableRecords($facts->where('key', 'border_collie-001'))
            ->resetTableFilters()
            ->callTableBulkAction('approveSelected', $facts);

        expect(BreedFact::orderBy('sort')->pluck('status')->map->value->all())->toBe(['approved', 'draft', 'approved'])
            ->and(BreedFact::whereNotNull('approved_by')->pluck('approved_by')->unique()->all())->toBe([$admin->id]);

        $facts = $facts->map->fresh();
        Livewire::test(ListBreedFacts::class)
            ->assertTableActionHidden('approve', $facts[0])
            ->assertTableActionVisible('reject', $facts[0])
            ->assertTableActionVisible('verify', $facts[1])
            ->callTableAction('verify', $facts[1]);
        expect($facts[1]->fresh()->status)->toBe(BreedFactStatus::Verified);

        Livewire::test(ViewBreedFact::class, ['record' => $facts[2]->getRouteKey()])
            ->assertOk()
            ->assertSee('Test fact: a health topic for parents.')
            ->assertSee('Testna zanimivost: zdravstvena tema za starše.')
            ->assertSee('Informativno, ni veterinarsko preverjeno.')
            ->assertSee('Test fixture quote (health).')
            ->assertSee('https://www.pdsa.org.uk/');
    });

    it('is localized (SL) and closed to non-superadmins', function () {
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);
        app()->setLocale('sl');
        Livewire::test(ListBreedFacts::class)->assertSee('Zanimivost (SL)')->assertSee('Preverjeno')->assertSee('Osnutek')->assertSee('Gibanje');
        expect(BreedFactResource::canCreate())->toBeFalse();

        app()->setLocale('en');
        $this->actingAs(User::factory()->parent()->create());
        $this->get(BreedFactResource::getUrl('index'))->assertForbidden();
    });
});
