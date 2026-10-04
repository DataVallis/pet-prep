<?php

namespace App\Jobs;

use App\Enums\AiCallFailure;
use App\Events\PetUpdated;
use App\Models\Pet;
use App\Services\FalAiService;
use App\Services\Media\AiCallException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Generates the canonical reference image (Pet DNA anchor) for a newly born pet.
 *
 * Runs on the queue so pairing never waits on fal.ai and no external HTTP call
 * happens inside a database transaction. Dispatched after the pairing commit.
 */
class GeneratePetReferenceImage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /** Must stay below the queue connection's retry_after (90 s) — see config/queue.php. */
    public int $timeout = 75;

    /** Release the uniqueness lock even if the dispatching transaction rolled back. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $petId) {}

    public function uniqueId(): string
    {
        return (string) $this->petId;
    }

    public function handle(FalAiService $fal): void
    {
        $pet = Pet::find($this->petId);

        if (! $pet || ! $pet->is_active) {
            return;
        }

        if (! $fal->isEnabled()) {
            $pet->updateQuietly(['media_status' => 'disabled']);

            return;
        }

        $dna = $pet->pet_dna ?? [];

        if (! empty($dna['reference_image_url'])) {
            $pet->updateQuietly(['media_status' => 'ready']);

            return;
        }

        try {
            $url = $fal->generateReferenceImage(
                (string) ($dna['prompt_anchor'] ?? ''),
                (int) ($dna['seed'] ?? 0),
                $pet->id,
                isset($dna['negative_prompt']) ? (string) $dna['negative_prompt'] : null,
            );
        } catch (AiCallException $e) {
            // Budget cap / fal balance / disabled profile: a retry cannot help. The pet
            // keeps working without media (M4-07, fail closed); the reason shows in Filament.
            $pet->updateQuietly(['media_status' => 'failed', 'media_error' => $e->reason->value]);
            PetUpdated::afterCommit($pet->fresh(), 'reference_image_failed');
            Log::warning('GeneratePetReferenceImage: not generated', ['pet_id' => $pet->id, 'reason' => $e->reason->value]);

            return;
        }

        if ($url === null) {
            // Let the queue retry with backoff; failed() marks the pet when retries run out.
            throw new RuntimeException("fal.ai reference image generation failed for pet {$pet->id}");
        }

        $dna['reference_image_url'] = $url;
        $pet->updateQuietly([
            'pet_dna' => $dna,
            'media_status' => 'ready',
            'media_error' => null,
        ]);

        PetUpdated::afterCommit($pet->fresh(), 'reference_image_ready');
    }

    public function failed(?Throwable $exception): void
    {
        $pet = Pet::find($this->petId);

        if ($pet) {
            $pet->updateQuietly(['media_status' => 'failed', 'media_error' => AiCallFailure::HttpError->value]);
            PetUpdated::afterCommit($pet->fresh(), 'reference_image_failed');
        }

        Log::error('GeneratePetReferenceImage: giving up', [
            'pet_id' => $this->petId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
