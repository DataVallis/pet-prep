<?php

namespace App\Jobs;

use App\Enums\FamilyRole;
use App\Models\DevicePushToken;
use App\Models\FamilyMember;
use App\Models\GiftVoucher;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\ExpoPushException;
use App\Services\Push\PushCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * M6-S01 (CONTRACT §6.5): one push to the parents of the family that redeemed a
 * website gift voucher. No `push_notifications` row (that table is pet-bound);
 * Expo is called directly in chunks of 100. Idempotent: `gift_vouchers.push_sent_at`
 * is claimed with a conditional update (released when sending fails, so a retry
 * may send). Receipts are not checked (accepted).
 *
 * The copy never names anyone and never says voucher / gift / code / web (the
 * mobile app must not learn about website purchases). `data.type =
 * challenge_credit_added` is unknown to every app version → a tap just opens the app.
 */
class SendGiftRedeemedPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const DATA_TYPE = 'challenge_credit_added';

    public const TITLE = 'PetPrep';

    public const BODY = [
        'sl' => 'Kupljen 12-tedenski izziv vas čaka v aplikaciji. Izberite, kateri ljubljenček ga dobi.',
        'en' => 'A purchased 12-week challenge is waiting in the app. Choose which pet it is for.',
    ];

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $voucherId)
    {
        $this->onQueue('default');
    }

    public function handle(ExpoPushClient $client): void
    {
        if (! config('push.enabled')) {
            return;
        }
        $voucher = GiftVoucher::find($this->voucherId);
        if ($voucher === null || $voucher->status !== GiftVoucher::STATUS_REDEEMED || $voucher->family_id === null) {
            return;
        }

        $parentIds = FamilyMember::where('family_id', $voucher->family_id)
            ->where('role', FamilyRole::Parent->value)
            ->pluck('user_id')
            ->all();
        $devices = DevicePushToken::enabled()->whereIn('user_id', $parentIds)->orderBy('id')->get();
        if ($devices->isEmpty()) {
            return;
        }

        // Claim the one push (another worker / a replayed job loses).
        $won = GiftVoucher::whereKey($voucher->id)->whereNull('push_sent_at')->update(['push_sent_at' => now()]);
        if ($won !== 1) {
            return;
        }

        try {
            foreach ($devices->chunk((int) config('push.chunk_size', 100)) as $chunk) {
                $client->send($chunk->values()->map(fn (DevicePushToken $d): array => self::message($d))->all());
            }
        } catch (Throwable $e) {
            GiftVoucher::whereKey($voucher->id)->update(['push_sent_at' => null]);
            Log::warning('SendGiftRedeemedPush: Expo send failed', ['voucher_id' => $voucher->id, 'error' => $e->getMessage()]);
            if ($e instanceof ExpoPushException && ! $e->retryable) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(DevicePushToken $device): array
    {
        // The install's language, else the app default (same rule as every other push).
        $locale = PushCopy::locale($device->locale);
        $locale = array_key_exists($locale, self::BODY) ? $locale : 'en';

        return [
            'to' => $device->expo_push_token,
            'title' => self::TITLE,
            'body' => self::BODY[$locale],
            'data' => ['type' => self::DATA_TYPE],
            'sound' => 'default',
            'priority' => 'default',
            'channelId' => 'default',
        ];
    }
}
