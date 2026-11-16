<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTimeFormatRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * 12 / 24 h clock (David 2026-11-16: »da se lahko nastavi 24-urni ali pa
 * 12-urni format, ker gremo globalno«). Default `auto` = follow the phone.
 */
class TimeFormatController extends Controller
{
    /**
     * Save the signed-in person's clock choice. Used by the app for every
     * clock time it shows and by push copy for `:time` on this person's
     * phones (`auto` = each phone's own setting, sent with POST /api/devices).
     *
     * PUT /api/user/time-format
     */
    public function update(UpdateTimeFormatRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['time_format' => $request->timeFormat()->value])->save();

        return response()->json([
            /**
             * @var 'auto'|'24h'|'12h'
             */
            'time_format' => $user->timeFormat()->value,
        ]);
    }
}
