<?php

use App\Http\Requests\ConfirmDeletionRequest;
use App\Models\User;
use App\Support\RequestLocale;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M1-18 — server texts in the request language (English default + Slovenian)
|--------------------------------------------------------------------------
*/

function i18nParentWithChild(): array
{
    $parent = User::factory()->parent()->create(['name' => 'Mama Ana']);
    $child = User::factory()->pinOnlyChild()->create(['parent_id' => $parent->id, 'name' => 'Maja']);

    return [$parent, $child];
}

describe('Accept-Language parsing', function () {
    it('picks the best supported language', function (?string $header, ?string $expected) {
        expect(RequestLocale::fromHeader($header))->toBe($expected)
            ->and(RequestLocale::resolve($header))->toBe($expected ?? 'en');
    })->with([
        'missing' => [null, null],
        'empty' => ['', null],
        'plain sl' => ['sl', 'sl'],
        'app tag sl-SI' => ['sl-SI', 'sl'],
        'app tag en-GB' => ['en-GB', 'en'],
        'underscore + case' => ['SL_si', 'sl'],
        'unsupported only' => ['de-DE, fr;q=0.8', null],
        'unsupported first, sl later' => ['de-DE, sl;q=0.5', 'sl'],
        'q-values decide' => ['en;q=0.4, sl;q=0.9', 'sl'],
        'tie keeps header order' => ['en, sl', 'en'],
        'iOS style list' => ['en-US;q=1, sl-SI;q=0.9', 'en'],
        'q=0 means not acceptable' => ['sl;q=0, en;q=0.1', 'en'],
        'only q=0' => ['sl;q=0', null],
        'wildcard' => ['*', null],
        'wildcard + sl' => ['*;q=0.9, sl;q=0.2', 'sl'],
        'malformed q ignored' => ['sl;q=abc, en;q=0.1', 'en'],
        'q above 1 ignored' => ['sl;q=2, en;q=0.5', 'en'],
        'spaces' => ['  sl-SI ; q=0.8 ,  en ; q=0.7 ', 'sl'],
        'garbage' => ['<script>alert(1)</script>', null],
        'separators only' => [';;;,,,', null],
        'binary junk' => ["\x00\xff\xfe", null],
    ]);

    it('copes with a huge header', function () {
        expect(RequestLocale::fromHeader(str_repeat('xx-YY;q=0.1, ', 5000).'sl'))->toBeNull()
            ->and(RequestLocale::resolve(str_repeat('a', 100000)))->toBe('en');
    });

    it('reads the supported list and the default from config/locales.php', function () {
        expect(RequestLocale::supported())->toBe(['en', 'sl'])
            ->and(RequestLocale::default())->toBe('en');

        config(['locales.supported' => ['en', 'sl', 'hr']]);
        expect(RequestLocale::fromHeader('hr-HR'))->toBe('hr');
    });
});

describe('SetRequestLocale middleware (API group)', function () {
    it('sets the app locale per request and answers with Content-Language', function () {
        $parent = User::factory()->parent()->create();
        actingAsRole($parent);

        $sl = getJson('/api/user', ['Accept-Language' => 'sl-SI'])->assertOk()->assertHeader('Content-Language', 'sl');
        expect($sl->headers->get('Vary'))->toContain('Accept-Language');
        expect(App::getLocale())->toBe('sl');

        getJson('/api/user', ['Accept-Language' => 'fr-FR'])->assertOk()->assertHeader('Content-Language', 'en');
        expect(App::getLocale())->toBe('en');

        // No header after a Slovenian request: back to the default, nothing leaks.
        getJson('/api/user', ['Accept-Language' => 'sl'])->assertHeader('Content-Language', 'sl');
        getJson('/api/user')->assertOk()->assertHeader('Content-Language', 'en');
        expect(App::getLocale())->toBe('en');
    });

    it('also localises error answers (guest 401 still gets the header)', function () {
        $r = getJson('/api/user', ['Accept-Language' => 'sl'])->assertUnauthorized()->assertHeader('Content-Language', 'sl');
        expect($r->headers->get('Vary'))->toContain('Accept-Language');
    });

    it('has every lang key in every supported language', function () {
        foreach (['push', 'account'] as $file) {
            $keys = fn (string $locale): array => array_keys(Arr::dot(require lang_path("{$locale}/{$file}.php")));
            foreach (RequestLocale::supported() as $locale) {
                expect($keys($locale))->toBe($keys('en'), "lang/{$locale}/{$file}.php");
            }
        }
    });
});

describe('Deletion confirmation word (ConfirmDeletionRequest)', function () {
    it('accepts the word of any supported language with the app normalisation', function (string $typed, bool $ok) {
        expect(ConfirmDeletionRequest::isConfirmWord($typed))->toBe($ok);
    })->with([
        ['IZBRIŠI', true],
        ['  izbriši ', true],
        ['Izbriši', true],
        ['DELETE', true],
        [' delete ', true],
        'NFD (S + combining caron)' => ["IZBRIS\u{030C}I", true],
        'NFD lower case' => ["izbris\u{030C}i", true],
        ['IZBRISI', false], // "š" required, as in the app
        ['IZBRIŠ', false],
        ['DELET', false],
        ['', false],
        ['   ', false],
    ]);

    it('deletes a child profile with the Slovenian or the English word, whatever the request language', function (string $word, string $language) {
        [$parent, $child] = i18nParentWithChild();
        actingAsRole($parent);

        deleteJson("/api/parent/children/{$child->id}", ['password' => 'password', 'confirm' => true, 'confirm_word' => $word], ['Accept-Language' => $language])
            ->assertOk();
        expect(User::find($child->id))->toBeNull();
    })->with([
        'IZBRIŠI from a Slovenian app' => ['IZBRIŠI', 'sl-SI'],
        'DELETE from an English app' => ['DELETE', 'en-GB'],
        'IZBRIŠI from an English request' => ['izbriši', 'en'],
        'IZBRIŠI typed in NFD' => ["IZBRIS\u{030C}I", 'sl-SI'],
    ]);

    it('refuses an explicitly sent empty, blank or null word (present key must match)', function (mixed $word) {
        [$parent, $child] = i18nParentWithChild();
        actingAsRole($parent);

        deleteJson("/api/parent/children/{$child->id}", ['password' => 'password', 'confirm' => true, 'confirm_word' => $word], ['Accept-Language' => 'sl'])
            ->assertStatus(422)
            ->assertJsonPath('errors.confirm_word.0', 'Za potrditev izbrisa vpišite IZBRIŠI.');
        expect(User::find($child->id))->not->toBeNull();
    })->with([
        'empty' => [''],
        'blank' => ['   '],
        'null' => [null],
        'array' => [['IZBRIŠI']],
    ]);

    it('still works without confirm_word (app builds before M1-18)', function () {
        [$parent, $child] = i18nParentWithChild();
        actingAsRole($parent);

        deleteJson("/api/parent/children/{$child->id}", ['password' => 'password', 'confirm' => true])->assertOk();
    });

    it('refuses a wrong word with a message in the request language and deletes nothing', function () {
        [$parent, $child] = i18nParentWithChild();
        actingAsRole($parent);
        $body = ['password' => 'password', 'confirm' => true, 'confirm_word' => 'IZBRISI'];

        deleteJson("/api/parent/children/{$child->id}", $body, ['Accept-Language' => 'sl-SI'])
            ->assertStatus(422)
            ->assertJsonPath('errors.confirm_word.0', 'Za potrditev izbrisa vpišite IZBRIŠI.');
        deleteJson("/api/parent/children/{$child->id}", $body, ['Accept-Language' => 'en-GB'])
            ->assertStatus(422)
            ->assertJsonPath('errors.confirm_word.0', 'Type DELETE to confirm the deletion.');
        postJson('/api/parent/account/delete', $body + ['confirm_word' => 'nope'], ['Accept-Language' => 'sl'])
            ->assertStatus(422)->assertJsonValidationErrors('confirm_word');

        expect(User::find($child->id))->not->toBeNull()
            ->and(User::find($parent->id))->not->toBeNull();
    });

    it('localises the confirm / password errors', function () {
        [$parent] = i18nParentWithChild();
        actingAsRole($parent);

        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => false], ['Accept-Language' => 'sl'])
            ->assertStatus(422)->assertJsonPath('errors.confirm.0', 'Potrdite izbris.');
        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => false])
            ->assertStatus(422)->assertJsonPath('errors.confirm.0', 'Please confirm the deletion.');
        postJson('/api/parent/account/delete', ['confirm' => true], ['Accept-Language' => 'sl-SI'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Vpišite svoje geslo.');
        postJson('/api/parent/account/delete', ['confirm' => true], ['Accept-Language' => 'en-GB'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Enter your password.');
    });
});

describe('GDPR export in the request language', function () {
    it('writes `about` and the file name in Slovenian or English', function () {
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        actingAsRole($parent);

        $sl = getJson('/api/parent/account/export', ['Accept-Language' => 'sl-SI'])->assertOk();
        expect($sl->json('about'))->toStartWith('Izvoz podatkov družine iz aplikacije PetPrep (GDPR čl. 15 in 20). ')
            ->and($sl->headers->get('Content-Disposition'))->toStartWith('attachment; filename="petprep-izvoz-');

        $en = getJson('/api/parent/account/export', ['Accept-Language' => 'en-GB'])->assertOk();
        expect($en->json('about'))->toStartWith('Family data export from the PetPrep app (GDPR Art. 15 and 20). ')
            ->and($en->headers->get('Content-Disposition'))->toStartWith('attachment; filename="petprep-export-');
    });

    it('lists each push device with its language', function () {
        $parent = User::factory()->parent()->create();
        DB::table('device_push_tokens')->insert([
            'user_id' => $parent->id, 'expo_push_token' => 'ExponentPushToken[exportLangAAAAAAAAAAA]', 'platform' => 'ios',
            'locale' => 'sl', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        actingAsRole($parent);

        getJson('/api/parent/account/export')->assertOk()
            ->assertJsonPath('parents.0.push_devices.0.locale', 'sl');
    });
});

describe('device_push_tokens.locale migration', function () {
    it('backfills installs registered before M1-18 with sl; later rows are written by POST /api/devices', function () {
        $migration = require database_path('migrations/2026_10_18_120000_add_locale_to_device_push_tokens.php');
        $parent = User::factory()->parent()->create();

        $migration->down();
        expect(Schema::hasColumn('device_push_tokens', 'locale'))->toBeFalse();
        DB::table('device_push_tokens')->insert([
            'user_id' => $parent->id, 'expo_push_token' => 'ExponentPushToken[legacyAAAAAAAAAAAAAAA]', 'platform' => 'android',
            'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();
        expect(DB::table('device_push_tokens')->where('expo_push_token', 'ExponentPushToken[legacyAAAAAAAAAAAAAAA]')->value('locale'))->toBe('sl');

        DB::table('device_push_tokens')->insert([
            'user_id' => $parent->id, 'expo_push_token' => 'ExponentPushToken[afterBBBBBBBBBBBBBBBB]', 'platform' => 'ios',
            'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        expect(DB::table('device_push_tokens')->where('expo_push_token', 'ExponentPushToken[afterBBBBBBBBBBBBBBBB]')->value('locale'))->toBeNull();
    });
});
