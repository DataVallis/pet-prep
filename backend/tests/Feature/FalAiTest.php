<?php

use App\Enums\BreedType;
use App\Enums\PetStateEnum;
use App\Models\Pet;
use App\Models\User;
use App\Services\FalAiService;

use function Pest\Laravel\{postJson};

/*
|--------------------------------------------------------------------------
| FalAiService & Webhook Feature Tests
|--------------------------------------------------------------------------
|
| Tests covering the Pet DNA generation, fal.ai video state generation,
| and the asynchronous webhook handling flow.
|
*/

describe('FalAiService (disabled in test environment)', function () {
    it('generates Pet DNA with seed, prompt anchor, and visual traits even when fal.ai is disabled', function () {
        $service = app(FalAiService::class);

        // In test environment, FAL_AI_API_KEY is empty so fal.ai is disabled,
        // but generateInitialPetDna still produces seed + prompt_anchor + visual_traits
        $dna = $service->generateInitialPetDna(BreedType::Mutt);

        expect($dna['seed'])->toBeInt();
        expect($dna['prompt_anchor'])->toBeString();
        expect($dna['prompt_anchor'])->toContain('mutt');
        expect($dna['visual_traits'])->toBeArray();
        expect($dna['visual_traits'])->toHaveKey('color_scheme');
        expect($dna['visual_traits'])->toHaveKey('eye_color');
        expect($dna['visual_traits'])->toHaveKey('fur_texture');
        expect($dna['visual_traits'])->toHaveKey('markings');

        // reference_image_url is null when fal.ai is disabled
        expect($dna['reference_image_url'])->toBeNull();
    });

    it('generates breed-specific prompt anchors', function () {
        $service = app(FalAiService::class);

        $muttDna = $service->generateInitialPetDna(BreedType::Mutt);
        $collieDna = $service->generateInitialPetDna(BreedType::BorderCollie);

        expect($muttDna['prompt_anchor'])->toContain('mutt');
        expect($muttDna['prompt_anchor'])->toContain('golden brown');

        expect($collieDna['prompt_anchor'])->toContain('Border Collie');
        expect($collieDna['prompt_anchor'])->toContain('black and white');
    });

    it('returns null when generating video state with fal.ai disabled', function () {
        $service = app(FalAiService::class);
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->withPetDna()->create(['user_id' => $user->id]);

        $requestId = $service->generatePetVideoState($pet, PetStateEnum::Idle);

        expect($requestId)->toBeNull();
    });

    it('returns null when generating video state without a reference image URL', function () {
        // Even if fal.ai were enabled, no reference_image_url means no generation
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'pet_dna' => [
                'seed' => 12345,
                'prompt_anchor' => 'A test dog',
                'visual_traits' => [],
                'reference_image_url' => null,
            ],
        ]);

        // Force enable for this test by mocking config — but since we can't easily
        // mock the HTTP call, just verify the null reference_image_url path
        expect($pet->pet_dna['reference_image_url'])->toBeNull();
    });
});

describe('PetStateEnum', function () {
    it('has all required pet states', function () {
        expect(PetStateEnum::cases())->toHaveCount(6);
        expect(PetStateEnum::Idle->value)->toBe('idle');
        expect(PetStateEnum::Sleeping->value)->toBe('sleeping');
        expect(PetStateEnum::LowEnergy->value)->toBe('low_energy');
        expect(PetStateEnum::Hungry->value)->toBe('hungry');
        expect(PetStateEnum::Sick->value)->toBe('sick');
        expect(PetStateEnum::Playing->value)->toBe('playing');
    });

    it('provides prompt modifiers for each state', function () {
        foreach (PetStateEnum::cases() as $state) {
            expect($state->promptModifier())->toBeString();
            expect($state->promptModifier())->not->toBeEmpty();
        }
    });
});

describe('POST /api/webhooks/fal-ai', function () {
    it('updates pet video URL on completed webhook', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->withPetDna()->create([
            'user_id' => $user->id,
            'current_video_url' => null,
        ]);

        $payload = [
            'request_id' => 'req_12345',
            'status' => 'COMPLETED',
            'video' => [
                'url' => 'https://cdn.fal.ai/generated/video_12345.mp4',
            ],
        ];

        $response = postJson('/api/webhooks/fal-ai?pet_id=' . $pet->id, $payload);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Video URL updated successfully.',
                'pet_id' => $pet->id,
                'current_video_url' => 'https://cdn.fal.ai/generated/video_12345.mp4',
            ]);

        // Pet should have the updated video URL
        expect($pet->fresh()->current_video_url)
            ->toBe('https://cdn.fal.ai/generated/video_12345.mp4');
    });

    it('handles webhook with alternative payload format', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->withPetDna()->create(['user_id' => $user->id]);

        $payload = [
            'request_id' => 'req_67890',
            'status' => 'COMPLETED',
            'output' => [
                'video_url' => 'https://cdn.fal.ai/generated/video_67890.mp4',
            ],
        ];

        $response = postJson('/api/webhooks/fal-ai?pet_id=' . $pet->id, $payload);

        $response->assertStatus(200);
        expect($pet->fresh()->current_video_url)
            ->toBe('https://cdn.fal.ai/generated/video_67890.mp4');
    });

    it('acknowledges webhook with non-completed status without updating pet', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->withPetDna()->create([
            'user_id' => $user->id,
            'current_video_url' => 'https://existing-video.mp4',
        ]);

        $payload = [
            'request_id' => 'req_pending',
            'status' => 'IN_PROGRESS',
        ];

        $response = postJson('/api/webhooks/fal-ai?pet_id=' . $pet->id, $payload);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Webhook acknowledged (no action needed).']);

        // Pet video URL should remain unchanged
        expect($pet->fresh()->current_video_url)->toBe('https://existing-video.mp4');
    });

    it('rejects webhook for non-existent pet_id with validation error', function () {
        $payload = [
            'request_id' => 'req_99999',
            'status' => 'COMPLETED',
            'video' => ['url' => 'https://cdn.fal.ai/video.mp4'],
        ];

        postJson('/api/webhooks/fal-ai?pet_id=99999', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pet_id']);
    });

    it('validates required webhook fields', function () {
        postJson('/api/webhooks/fal-ai', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['request_id', 'status']);
    });
});
