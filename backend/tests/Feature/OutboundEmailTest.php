<?php

use App\Jobs\SendShopAlert;
use App\Models\OutboundEmail;
use App\Services\Mail\OutboundEmailSender;
use App\Services\Shop\ShopFiles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;

/*
|--------------------------------------------------------------------------
| M6-S09 (WEBSITE_SALES_SPEC §3.6, David 2026-11-17): the e-mail queue — stored with
| its full HTML, sent by `emails:send` with retries, failed rows alert the admin.
|--------------------------------------------------------------------------
*/

require_once __DIR__.'/../Support/ShopFakes.php';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(SH_NOW, 'UTC'));
    shConfig();
    gvSwitch(true);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** @return list<SentMessage> */
function oeSent(): array
{
    return app('mailer')->getSymfonyTransport()->messages()->all();
}

it('E1: a paid gift order\'s e-mail goes out from Curio with reply-to hello@, HTML + text, and the voucher PDF attached', function () {
    $stripe = shStripe();
    [, $code] = shPaidOrder($stripe);

    expect(app(OutboundEmailSender::class)->run())->toBe(['sent' => 1, 'retry' => 0, 'failed' => 0]);

    $email = OutboundEmail::sole();
    expect($email->status)->toBe('sent')->and($email->attempts)->toBe(1)->and($email->sent_at)->not->toBeNull();
    $message = oeSent()[0]->getOriginalMessage();
    expect($message->getFrom()[0]->getAddress())->toBe('curio@petprep.si')
        ->and($message->getFrom()[0]->getName())->toBe('Curio iz PetPrep') // sender name in the buyer's language
        ->and($message->getReplyTo()[0]->getAddress())->toBe('hello@petprep.si')
        ->and($message->getTo()[0]->getAddress())->toBe('buyer@example.com')
        ->and($message->getSubject())->toBe('Vaš darilni bon PetPrep')
        ->and($message->getHtmlBody())->toContain($code)
        ->and($message->getTextBody())->toContain($code);
    // The logo is embedded (cid), not a remote image.
    expect($message->getHtmlBody())->toContain('cid:')->not->toContain('petprep-wordmark-320.png');
    $attachments = array_values(array_filter($message->getAttachments(), fn ($p) => $p->getDisposition() !== 'inline'));
    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->getFilename())->toBe('petprep-darilni-bon.pdf')
        ->and(substr($attachments[0]->getBody(), 0, 5))->toBe('%PDF-');

    // Nothing left to send.
    expect(app(OutboundEmailSender::class)->run())->toBe(['sent' => 0, 'retry' => 0, 'failed' => 0]);
});

it('E2: a temporary SMTP error retries after 5 min, 30 min, … and the sixth failure marks it failed + one alert', function () {
    Queue::fake([SendShopAlert::class]);
    $stripe = shStripe();
    shPaidOrder($stripe);
    Mail::shouldReceive('html')->andThrow(new TransportException('Expected response code "250" but got code "451", with message "451 Try again later".'));
    $sender = app(OutboundEmailSender::class);

    expect($sender->run())->toBe(['sent' => 0, 'retry' => 1, 'failed' => 0]);
    $email = OutboundEmail::sole();
    expect($email->status)->toBe('pending')->and($email->attempts)->toBe(1)
        ->and($email->next_attempt_at->toDateTimeString())->toBe('2026-11-17 10:05:00')
        ->and($email->last_error)->toContain('451');

    // Not due yet → nothing happens.
    expect($sender->run())->toBe(['sent' => 0, 'retry' => 0, 'failed' => 0]);

    foreach ([5, 30, 120, 360, 1440] as $i => $minutes) {
        Carbon::setTestNow(now()->addMinutes($minutes));
        $sender->run();
    }
    $email->refresh();
    expect($email->status)->toBe('failed')->and($email->attempts)->toBe(6)->and($email->alerted_at)->not->toBeNull();
    Queue::assertPushed(SendShopAlert::class, 1);
    Queue::assertPushed(SendShopAlert::class, fn (SendShopAlert $j) => $j->reason === 'email_failed' && $j->emailId === $email->id);
});

it('E3: a permanent 5xx refusal (no such mailbox) fails at once; »Send again« starts a new series', function () {
    Queue::fake([SendShopAlert::class]);
    $stripe = shStripe();
    shPaidOrder($stripe);
    Mail::shouldReceive('html')->once()->andThrow(new TransportException('Expected response code "250" but got code "550", with message "550 5.1.1 User unknown".'));
    $sender = app(OutboundEmailSender::class);

    expect($sender->run())->toBe(['sent' => 0, 'retry' => 0, 'failed' => 1]);
    $email = OutboundEmail::sole();
    expect($email->status)->toBe('failed')->and($email->attempts)->toBe(1);
    Queue::assertPushed(SendShopAlert::class, 1);

    $sender->requeue($email);
    expect($email->fresh()->status)->toBe('pending')->and($email->fresh()->attempts)->toBe(0);
});

it('E4: a row stuck in `sending` (crashed run) counts as a failed attempt and is sent on the retry ladder', function () {
    $stripe = shStripe();
    shPaidOrder($stripe);
    OutboundEmail::query()->update(['status' => 'sending', 'updated_at' => now()->subMinutes(16)]);

    expect(app(OutboundEmailSender::class)->run()['sent'])->toBe(0);
    $email = OutboundEmail::sole();
    expect($email->status)->toBe('pending')->and($email->attempts)->toBe(1)->and($email->last_error)->toContain('Stale');

    Carbon::setTestNow(now()->addMinutes(5));
    expect(app(OutboundEmailSender::class)->run()['sent'])->toBe(1);
});

it('E5: emails:send runs every minute with an explicit overlap expiry', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'emails:send'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')->and($event->expiresAt)->toBe(10);
});

it('E6: only a refusal of the recipient is permanent; a server-level 5xx (auth 535, relay) is not', function () {
    expect(OutboundEmailSender::isPermanent(new TransportException('got code "550"')))->toBeTrue()
        ->and(OutboundEmailSender::isPermanent(new TransportException('Expected response code "250" but got code "553", with message "553 5.1.3 Bad address".', 553)))->toBeTrue()
        ->and(OutboundEmailSender::isPermanent(new TransportException('got code "421"')))->toBeFalse()
        ->and(OutboundEmailSender::isPermanent(new TransportException('Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".', 535)))->toBeFalse()
        ->and(OutboundEmailSender::isServerDown(new TransportException('Expected response code "235" but got code "535".', 535)))->toBeTrue()
        ->and(OutboundEmailSender::isPermanent(new RuntimeException('Connection could not be established')))->toBeFalse();
});

it('E7: a wrong SMTP password (535) retries the first e-mail, puts the rest of the batch back untouched and alerts once', function () {
    Queue::fake([SendShopAlert::class]);
    $stripe = shStripe();
    shPaidOrder($stripe);
    shPaidOrder($stripe);
    Mail::shouldReceive('html')->once()->andThrow(new TransportException('Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".', 535));

    expect(app(OutboundEmailSender::class)->run())->toBe(['sent' => 0, 'retry' => 1, 'failed' => 0]);
    [$first, $second] = OutboundEmail::orderBy('id')->get()->all();
    expect($first->status)->toBe('pending')->and($first->attempts)->toBe(1)
        ->and($second->status)->toBe('pending')->and($second->attempts)->toBe(0)
        ->and($second->next_attempt_at->toDateTimeString())->toBe('2026-11-17 10:05:00');
    Queue::assertPushed(SendShopAlert::class, 1);
    Queue::assertPushed(SendShopAlert::class, fn (SendShopAlert $j) => $j->reason === 'smtp_down');
});

it('E8: an e-mail of an order refunded before it went out is cancelled, not sent', function () {
    $stripe = shStripe();
    [$order] = shPaidOrder($stripe);
    $order->forceFill(['status' => 'refunded'])->save();

    expect(app(OutboundEmailSender::class)->run()['sent'])->toBe(0)
        ->and(OutboundEmail::sole()->status)->toBe('cancelled')
        ->and(oeSent())->toBe([]);
});

it('E9: a kit order\'s e-mail carries the kit PDF in the buyer\'s language; the sender name is translated', function () {
    $stripe = shStripe();
    ShopFiles::disk()->put(ShopFiles::path('promise_kit', 'de'), '%PDF-1.4 kit de');
    shKitSwitch(true);
    shPaidOrder($stripe, 'promise_kit', ['metadata' => ['locale' => 'de']]);

    expect(app(OutboundEmailSender::class)->run()['sent'])->toBe(1);
    $message = oeSent()[0]->getOriginalMessage();
    $files = array_values(array_filter($message->getAttachments(), fn ($p) => $p->getDisposition() !== 'inline'));
    expect($message->getFrom()[0]->getName())->toBe('Curio von PetPrep')
        ->and($files)->toHaveCount(1)
        ->and($files[0]->getFilename())->toBe('petprep-promise-kit-de.pdf')
        ->and($files[0]->getBody())->toBe('%PDF-1.4 kit de');
});
