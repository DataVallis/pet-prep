<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBreedQuizEventRequest;
use App\Services\BreedQuizStatService;
use Illuminate\Http\Response;

/**
 * Anonymous counters of the petprep.si breed quiz (M6-S06).
 */
class BreedQuizEventController extends Controller
{
    public function __construct(private readonly BreedQuizStatService $stats) {}

    /**
     * Count one quiz event. Public, no token, no cookie; throttled per IP
     * (60 / hour, IPv6 /64) — the address is only the throttle key, never stored
     * or logged. `event` completed | calculator_click | app_click, `locale` one
     * of the 15 website languages, `breeds` = the top 3 in rank order for
     * `completed`, exactly one id for a click. A filled hidden `website` field
     * = bot: 204 and nothing stored.
     *
     * 204 no content; 422 invalid body; 429 throttled.
     *
     * POST /api/breed-quiz/events
     */
    public function store(StoreBreedQuizEventRequest $request): Response
    {
        /** @var array{event: string, locale: string, breeds: list<string>} $data */
        $data = $request->safe()->only(['event', 'locale', 'breeds']);

        $this->stats->record($data['event'], $data['locale'], $data['breeds']);

        return response()->noContent();
    }
}
