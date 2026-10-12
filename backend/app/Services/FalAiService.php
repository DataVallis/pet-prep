<?php

namespace App\Services;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\PetStateEnum;
use App\Enums\Species;
use App\Models\Pet;
use App\Services\Media\AiCallException;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaProfiles;
use App\Services\Media\PetAppearancePrompt;
use Illuminate\Support\Facades\Log;

/**
 * FalAiService — pet media on fal.ai.
 *
 * - Reference image (Pet DNA anchor): synchronous call (profile
 *   media.reference_image_profile), only ever executed from a queued job
 *   (never inside an HTTP request / DB transaction).
 * - State videos (image-to-video, profile media.state_video_profile): submitted
 *   to the queue API with our signed webhook; matched via pet_media.request_id
 *   (M4-03). The pipeline itself lives in Media\PetMediaService.
 *
 * All HTTP goes through Media\FalGateway (spend caps + ledger, M4-07).
 * Pet DNA v2 (unique traits) lives in Media\PetDnaService; generateInitialPetDna()
 * below is the pre-M4 v1 builder (AI_PET_DNA_VERSION=1).
 */
class FalAiService
{
    public function __construct(
        private readonly FalGateway $gateway,
        private readonly MediaProfiles $profiles,
        private readonly PetAppearancePrompt $prompts,
    ) {}

    /**
     * Check if fal.ai integration is enabled (API key configured).
     */
    public function isEnabled(): bool
    {
        return filled(config('services.fal_ai.key'));
    }

    // ──────────────────────────────────────────────────────────────
    //  Pet DNA
    // ──────────────────────────────────────────────────────────────

    /**
     * Build the Pet DNA payload for a new pet. Pure and offline: no network call.
     * The reference image is generated later by GeneratePetReferenceImageJob.
     *
     * @return array{seed: int, visual_traits: array<string, string>, prompt_anchor: string, reference_image_url: null}
     */
    public function generateInitialPetDna(BreedType $breed): array
    {
        // Legacy DNA v1 has dog prompts only (M5-R06-01 / M5-R06-07): a cat never
        // gets one — PairingService builds DNA v2 for every cat.
        if ($breed->species() !== Species::Dog) {
            throw new \InvalidArgumentException("Legacy pet DNA v1 has no prompts for {$breed->value}.");
        }

        $seed = random_int(1, 4294967295);

        return [
            'seed' => $seed,
            'visual_traits' => $this->buildVisualTraits($breed, $seed),
            'prompt_anchor' => $this->buildPromptAnchor($breed),
            'reference_image_url' => null,
        ];
    }

    private function buildPromptAnchor(BreedType $breed): string
    {
        return match ($breed) {
            BreedType::Mutt => 'A friendly medium-sized mutt dog with a mix of golden brown and white fur, '
                .'short smooth coat, amber eyes, floppy ears, white blaze on chest, '
                .'photorealistic, studio quality, natural lighting',

            BreedType::BorderCollie => 'A beautiful Border Collie dog with classic black and white markings, '
                .'medium-length double coat, bright intelligent brown eyes, erect expressive ears, '
                .'white blaze on face, photorealistic, studio quality, natural lighting',

            // M5-R10: FCI 122 (S48) — solid colour, short dense coat, otter tail,
            // hanging ears, brown or hazel eyes (docs/research/dog-data labrador_retriever.appearance).
            BreedType::LabradorRetriever => 'A friendly Labrador Retriever dog with a solid yellow coat, '
                .'short dense coat, kind brown eyes, ears hanging close to the head, thick tapering otter tail, '
                .'strongly built with a broad head and deep chest, photorealistic, studio quality, natural lighting',

            // M5-R10-02: FCI 111 (S63) — gold or cream (neither red nor mahogany),
            // flat or wavy feathered coat, dense water-resisting undercoat, dark brown
            // eyes, level tail (docs/research/dog-data golden_retriever.appearance).
            BreedType::GoldenRetriever => 'A friendly Golden Retriever dog with a golden coat, '
                .'flat or wavy medium-length coat with good feathering and a dense water-resisting undercoat, '
                .'kind dark brown eyes, moderate-sized hanging ears, feathered tail carried level with the back, '
                .'symmetrical and powerful build, photorealistic, studio quality, natural lighting',

            // M5-R10-03: FCI 101 (S76) / RKC standard (S79) — small, compact, smooth
            // coat; fawn, brindle or pied only (never merle, S78); bat ears; moderate
            // face with visibly open nostrils, "no point exaggerated" (David 2026-10-10).
            BreedType::FrenchBulldog => 'A friendly French Bulldog dog with a fawn coat, '
                .'short smooth glossy coat, upright bat ears, dark round eyes, '
                .'moderate muzzle with visibly open nostrils, short low-set tail, '
                .'small, sturdy and compact build, photorealistic, studio quality, natural lighting',

            // M5-R10-04: FCI 166 (S95) / RKC standard (S98) — black with tan / gold
            // markings (never white, S95), erect ears, double coat; level back and
            // moderate hind legs, "free from exaggeration" (welfare rule, RKC Breed Watch S99).
            BreedType::GermanShepherd => 'A friendly German Shepherd dog with a black and tan coat, '
                .'dense double coat, erect pointed ears, dark almond-shaped eyes, '
                .'bushy tail hanging in a gentle curve, level back with moderate natural hind legs, '
                .'powerful, well-muscled, balanced build, photorealistic, studio quality, natural lighting',

            // M5-R10-05: FCI 136 (S103) / RKC standard (S106) — Blenheim, tricolour, ruby
            // or black and tan only (never chocolate, S105); long feathered ears, silky
            // coat; visible tapered muzzle, eyes "not prominent" (welfare rule, Breed Watch S107).
            BreedType::CavalierKingCharlesSpaniel => 'A friendly Cavalier King Charles Spaniel dog with a Blenheim coat, '
                .'long silky coat with plenty of feathering, long high-set feathered ears, '
                .'large dark round eyes that are not protruding, visible well-tapered muzzle with open nostrils, '
                .'small, graceful, well-balanced build, photorealistic, studio quality, natural lighting',

            // M5-R10-06: FCI 161 (S111) / RKC standard (S114) — standard colours only,
            // tricolour first; short dense coat, long low-set ears with rounded tips,
            // broad nose with wide nostrils, muzzle not snipy, white tail tip.
            BreedType::Beagle => 'A friendly Beagle dog with a tricolour coat, '
                .'short dense weatherproof coat, long low-set ears with rounded tips hanging close to the cheeks, '
                .'dark brown eyes with a mild appealing expression, broad nose with wide nostrils, moderate muzzle, '
                .'tail carried gaily with a white tip, sturdy, compact build, photorealistic, studio quality, natural lighting',

            // M5-R10-07: FCI 172 (S118) / RKC standard (S121) — solid standard colours only,
            // black first; dense curly coat in a short even trim (no show clip), long wide
            // low-set ears, long fine head with straight muzzle, natural undocked tail.
            BreedType::StandardPoodle => 'An elegant Standard Poodle dog with a solid black coat, '
                .'dense curly coat in a short, even all-over trim, long wide ears set low and hanging close to the face, '
                .'dark almond-shaped eyes, long fine head with a straight muzzle and black nose, '
                .'natural tail set rather high, well-balanced build with proud carriage, photorealistic, studio quality, natural lighting',

            // M5-R10-08: RKC standard (S126) — standard colours only, red first, never dapple;
            // smooth coat; welfare rule (long back, IVDD): moderate body length, ground clearance.
            BreedType::Dachshund => 'A Dachshund dog with a red, dense, short smooth coat, '
                .'broad rounded ears set high, dark almond-shaped eyes, long head tapering to a black nose, '
                .'moderately long muscular body with a level back and enough ground clearance, '
                .'tail continuing the line of the back, photorealistic, studio quality, natural lighting',

            // M5-R10-09: FCI 342 (S131) — standard colours only (merle is standard here),
            // blue merle first; medium-length coat with a moderate mane, triangular high-set
            // ears breaking forward, almond eyes, natural long tail (no docking).
            BreedType::AustralianShepherd => 'An alert Australian Shepherd dog with a blue merle coat with white and copper markings, '
                .'medium-length straight to wavy coat with a moderate mane, triangular high-set ears breaking forward, '
                .'almond-shaped eyes fully surrounded by colour, moderate muzzle, natural long tail, '
                .'well-balanced athletic build, photorealistic, studio quality, natural lighting',

            // M5-R10-10: FCI 250 (S136) — FCI colours only (never merle, RKC S139), fawn first;
            // very long soft wavy natural coat, drop ears along the cheeks, large dark almond
            // eyes, tail rolled over the back.
            BreedType::Havanese => 'A cheerful Havanese dog with a fawn coat, '
                .'very long, soft, wavy natural coat, drop ears falling along the cheeks, '
                .'large dark brown almond-shaped eyes, muzzle as long as the skull, tail carried high and curled over the back, '
                .'sturdy little body low on the legs, photorealistic, studio quality, natural lighting',

            // M5-R10-11: FCI 85 (S142) — white is the only standard colour; harsh double coat
            // about 5 cm, small erect pointed ears, dark eyes under heavy eyebrows, black nose,
            // straight tail carried jauntily (never docked); healthy skin (RKC Breed Watch S146).
            BreedType::WestHighlandWhiteTerrier => 'A confident West Highland White Terrier dog with a pure white coat, '
                .'harsh straight double coat about 5 cm long with healthy skin, small erect pointed ears, '
                .'dark eyes set wide apart under heavy eyebrows, black nose, straight tail carried jauntily, '
                .'small strongly built body with a deep chest, photorealistic, studio quality, natural lighting',

            // M5-R10-12: FCI 45 (S150) — tricolour is the only standard colour (jet black, rich
            // tan, symmetrical white blaze / chest); long shining straight or slightly wavy coat,
            // medium triangular hanging ears, dark brown almond eyes, bushy tail hanging down.
            BreedType::BerneseMountainDog => 'A calm Bernese Mountain Dog with a tricolour coat: jet black with rich tan '
                .'on the cheeks, above the eyes and on the legs, a symmetrical white blaze and white chest, '
                .'long shining straight coat, medium triangular ears hanging flat, dark brown almond-shaped eyes, '
                .'bushy tail hanging down, strong sturdy balanced body, photorealistic, studio quality, natural lighting',

            // M5-R10-13: FCI 270 (S158) — black, grey, agouti, sable, red and white (never merle
            // or brindle), grey and white first; double medium-length coat, thick erect
            // triangular ears, blue or brown almond eyes, fox-brush tail; moderate, never heavy.
            BreedType::SiberianHusky => 'A friendly Siberian Husky dog with a grey and white coat and a white face mask, '
                .'double medium-length well-furred coat, thick triangular strongly erect ears, '
                .'blue almond-shaped eyes set obliquely, well-furred fox-brush tail, '
                .'moderately compact athletic build, light on its feet, photorealistic, studio quality, natural lighting',

            // M5-R10-19: FCI 147 (S166) — black with clearly defined rich tan markings only (white
            // markings a fault); coarse dense flat coat, pendant triangular ears, dark brown almond
            // eyes, natural tail; Breed Watch S173 → moderate muzzle, level back, sound hind legs.
            BreedType::Rottweiler => 'A calm Rottweiler dog, black with clearly defined rich tan markings on the cheeks, '
                .'muzzle, chest and legs and above the eyes, short coarse dense flat coat, medium triangular pendant ears, '
                .'dark brown almond-shaped eyes, natural undocked tail, moderate muzzle, powerful compact build with a level back '
                .'and sound hind legs, photorealistic, studio quality, natural lighting',

            // M5-R10-20: RKC standard (S178) / FCI 149 (S175) — red, fawn, white, brindle, pied
            // (never black, black and tan, merle — S175, S179); rose ears, smooth coat; welfare
            // rule (Breed Watch S182): moderate face with wide-open nostrils, light wrinkle, straight tail.
            BreedType::Bulldog => 'A calm Bulldog dog with a red and white coat, '
                .'fine short smooth coat, small rose ears, very dark round eyes, '
                .'moderate short broad muzzle with large wide-open nostrils and only a light wrinkle, '
                .'natural straight low-set tail, thick-set but sound and compact build, photorealistic, studio quality, natural lighting',

            // M5-R10-21: AKC standard via GSPCA (S186) / FCI 119 (S184) — liver, liver and white,
            // black, black and white (never tan / red / lemon / solid white — S186); short hard
            // coat, broad flat ears, dark almond eyes; natural undocked tail (Claude's proposal, open for David).
            BreedType::GermanShorthairedPointer => 'An alert German Shorthaired Pointer dog with a liver roan coat and a solid liver head, '
                .'short dense hard coat, broad flat ears set high and hanging close to the head, dark brown almond-shaped eyes, '
                .'natural undocked tail, noble athletic balanced build, photorealistic, studio quality, natural lighting',

            // M5-R10-22: FCI 86 (S190) / RKC (S193) — dark steel blue and tan is the only standard
            // colour; long straight glossy silky coat that never impedes movement, small erect
            // V-shaped tan ears, dark eyes, black nose; natural undocked tail (an FCI option).
            BreedType::YorkshireTerrier => 'An alert Yorkshire Terrier dog with a dark steel blue and rich tan coat, '
                .'long straight glossy silky coat of natural pet length, small erect V-shaped tan ears, '
                .'dark sparkling eyes, black nose, natural undocked tail, tiny compact toy body, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-23: FCI 343 (S197) — natural uncropped ears and natural tail (FCI 2023;
            // cropping / docking illegal in the UK, PDSA S199); short shiny dense coat; strong
            // square moderate muzzle; solid black as the anchor (first FCI colour).
            BreedType::CaneCorso => 'A calm, alert Cane Corso dog with a solid black short shiny dense coat, '
                .'natural uncropped triangular ears held close to the cheeks, dark eyes, strong square moderate muzzle, '
                .'tight lips and eyelids, natural undocked tail, robust athletic build slightly longer than tall, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-24: FCI 39 (S202) — red with white markings as the anchor (first standard
            // colour); natural undocked tail (standard option, S205); moderate body length.
            BreedType::PembrokeWelshCorgi => 'An alert, friendly Pembroke Welsh Corgi dog with a red coat and white markings on the legs, chest and neck, '
                .'medium-length straight coat with a dense undercoat, pricked medium rounded ears, foxy head, round brown eyes, black nose, '
                .'low-set sturdy body of moderate length with a level back, natural undocked tail, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-25: FCI 143 (S207) — black with rust red tan markings as the anchor (first
            // standard colour); ears and tail left natural (FCI 2015), never cropped or docked.
            BreedType::Dobermann => 'A calm, alert Dobermann dog with a black coat and clearly defined rust red tan markings, '
                .'short hard smooth shiny coat, natural uncropped ears dropped close to the cheeks, long wedge-shaped head, '
                .'medium oval dark eyes, black nose, strong muscular elegant square build, natural undocked tail, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-26: RKC standard (S217) / FCI 144 (S213) — fawn with a black mask as the anchor
            // (first standard colour); natural ears and tail, never cropped or docked; welfare rule
            // (Breed Watch S216, VetCompass S220): moderate muzzle with wide open nostrils.
            BreedType::Boxer => 'A friendly, alert Boxer dog with a golden fawn coat and a black mask, '
                .'short glossy smooth coat, natural uncropped ears lying close to the cheeks, '
                .'broad, deep, moderate muzzle with wide open nostrils, dark brown eyes, '
                .'square, athletic, muscular build, natural undocked tail, '
                .'photorealistic, studio quality, natural lighting',

            // Unreachable: generateInitialPetDna() refuses non-dogs.
            BreedType::DomesticCat, BreedType::MaineCoon, BreedType::BritishShorthair, BreedType::Ragdoll, BreedType::SiberianCat, BreedType::NorwegianForestCat, BreedType::Bengal => throw new \InvalidArgumentException("No DNA v1 prompt for {$breed->value}."),
        };
    }

    /**
     * @return array<string, string>
     */
    private function buildVisualTraits(BreedType $breed, int $seed): array
    {
        $variantIndex = $seed % 3;

        return match ($breed) {
            BreedType::Mutt => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'golden brown with white patches',
                    1 => 'black and tan with white chest',
                    2 => 'cream and brown mixed',
                },
                'eye_color' => 'amber',
                'fur_texture' => 'short and smooth',
                'markings' => match ($variantIndex) {
                    0 => 'white blaze on chest',
                    1 => 'white paws and tail tip',
                    2 => 'brown mask around eyes',
                },
            ],
            BreedType::BorderCollie => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'classic black and white',
                    1 => 'black white and tan tri-color',
                    2 => 'blue merle and white',
                },
                'eye_color' => 'brown',
                'fur_texture' => 'medium-length double coat',
                'markings' => match ($variantIndex) {
                    0 => 'white blaze on face and white collar',
                    1 => 'full white chest and white socks',
                    2 => 'merle patches on body',
                },
            ],
            // M5-R10: FCI 122 (S48) colours black / yellow / liver-chocolate only; a
            // small white chest spot is permissible; no other markings.
            BreedType::LabradorRetriever => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'solid yellow',
                    1 => 'solid black',
                    2 => 'solid chocolate',
                },
                'eye_color' => 'brown',
                'fur_texture' => 'short dense coat with a weather-resistant undercoat',
                'markings' => match ($variantIndex) {
                    0 => 'no markings',
                    1 => 'a small white spot on the chest',
                    2 => 'no markings',
                },
            ],
            // M5-R10-02: FCI 111 (S63) any shade of gold or cream, neither red nor
            // mahogany; RKC (S66) a few white hairs on the chest only, permissible.
            BreedType::GoldenRetriever => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'rich gold',
                    1 => 'light gold',
                    2 => 'cream',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'flat or wavy feathered coat with a dense water-resisting undercoat',
                'markings' => match ($variantIndex) {
                    0 => 'no markings',
                    1 => 'a few white hairs on the chest',
                    2 => 'no markings',
                },
            ],
            // M5-R10-03: RKC standard (S79) "The only correct colours are: Brindle;
            // Fawn; Pied;" — never merle / blue (S78); smooth coat without undercoat (S76).
            BreedType::FrenchBulldog => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'fawn',
                    1 => 'brindle',
                    2 => 'pied (white with fawn patches)',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'short smooth glossy coat',
                'markings' => match ($variantIndex) {
                    0 => 'no markings',
                    1 => 'no markings',
                    2 => 'white body with fawn patches',
                },
            ],
            // M5-R10-04: FCI (S95) "black with reddish-brown, brown and yellow to light
            // grey markings"; RKC (S97) standard colours incl. sable and black — never white.
            BreedType::GermanShepherd => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'black and tan',
                    1 => 'sable',
                    2 => 'solid black',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'dense double coat',
                'markings' => match ($variantIndex) {
                    0 => 'black saddle with tan legs and face',
                    1 => 'darker tips and mask',
                    2 => 'no markings',
                },
            ],
            // M5-R10-05: FCI (S103) / RKC (S106) colours Blenheim, Tricolour, Ruby,
            // Black and Tan — "Any other colour or combination of colours unacceptable."
            BreedType::CavalierKingCharlesSpaniel => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'Blenheim (rich chestnut on pearly white)',
                    1 => 'tricolour (black and white with tan)',
                    2 => 'ruby (whole rich red)',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'long silky coat with feathering',
                'markings' => match ($variantIndex) {
                    0 => 'chestnut patches well broken up on white',
                    1 => 'tan markings over the eyes and on the cheeks',
                    2 => 'no markings',
                },
            ],
            // M5-R10-06: FCI (S111) / RKC (S114) colours — "No other colours are
            // permissible. Tip of stern white."
            BreedType::Beagle => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'tricolour (black, tan and white)',
                    1 => 'tan and white',
                    2 => 'lemon and white',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'short, dense, weatherproof',
                'markings' => match ($variantIndex) {
                    0 => 'black saddle, tan head, white legs, chest and tail tip',
                    1 => 'tan patches on white, white tail tip',
                    2 => 'pale lemon patches on white, white tail tip',
                },
            ],
            // M5-R10-07: FCI (S118) colours — "Solid colour: black, white, brown, grey,
            // fawn."; not of solid colour / white marks = disqualification.
            BreedType::StandardPoodle => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'solid black',
                    1 => 'solid white',
                    2 => 'solid brown',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'dense, curly, short even trim',
                'markings' => 'no markings',
            ],
            // M5-R10-08: RKC standard (S126) colours — red, black or chocolate with tan
            // markings; no white (small chest patch tolerated); never dapple (S125).
            BreedType::Dachshund => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'red',
                    1 => 'black and tan',
                    2 => 'chocolate and tan',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'dense, short, smooth',
                'markings' => match ($variantIndex) {
                    0 => 'no white markings',
                    1, 2 => 'tan points, no white',
                },
            ],
            // M5-R10-09: FCI (S131) colours blue merle, black, red merle, red — with or without
            // white and/or tan markings; white within the standard's limits (no body splashes).
            BreedType::AustralianShepherd => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'blue merle',
                    1 => 'black',
                    2 => 'red merle',
                },
                'eye_color' => match ($variantIndex) {
                    0 => 'blue',
                    1, 2 => 'brown',
                },
                'fur_texture' => 'medium-length, straight to wavy, moderate mane',
                'markings' => 'white collar, chest and legs with copper points',
            ],
            // M5-R10-10: FCI (S136) colours fawn, black, havana brown (tobacco, reddish brown,
            // white also standard) — patches in these colours or tan markings allowed; never merle.
            BreedType::Havanese => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'fawn',
                    1 => 'black',
                    2 => 'havana brown',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'very long, soft, wavy',
                'markings' => match ($variantIndex) {
                    0, 2 => 'solid colour',
                    1 => 'small white patches on chest and paws',
                },
            ],
            // M5-R10-11: FCI / RKC (S142, S145) — white only ("Any other colour or combination
            // of colours unacceptable"); the variants differ in coat texture only.
            BreedType::WestHighlandWhiteTerrier => [
                'color_scheme' => 'pure white',
                'eye_color' => 'dark brown',
                'fur_texture' => match ($variantIndex) {
                    0 => 'harsh, straight, about 5 cm, soft close undercoat',
                    1 => 'harsh, straight, slightly fuller on the head',
                    2 => 'harsh, straight, neatly tidied',
                },
                'markings' => 'none',
            ],
            // M5-R10-12: FCI / RKC (S150, S153) — tricolour only ("Any other colour or
            // combination of colours unacceptable"); the variants differ in coat texture only.
            BreedType::BerneseMountainDog => [
                'color_scheme' => 'tricolour: jet black, rich tan and white',
                'eye_color' => 'dark brown',
                'fur_texture' => match ($variantIndex) {
                    0 => 'long, shining and straight',
                    1 => 'long, shining and slightly wavy',
                    2 => 'long and silky with a bright natural sheen',
                },
                'markings' => 'symmetrical white blaze, white muzzle band and white chest; tan on cheeks, above the eyes and on the legs',
            ],
            // M5-R10-13: FCI / RKC (S158, S161) — black, grey, agouti, sable, red and white;
            // never merle or brindle; eyes brown or blue (one of each acceptable).
            BreedType::SiberianHusky => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'grey and white',
                    1 => 'black and white',
                    2 => 'red and white',
                },
                'eye_color' => match ($variantIndex) {
                    0, 1 => 'blue',
                    2 => 'brown',
                },
                'fur_texture' => 'double, medium-length, well furred',
                'markings' => 'white face mask, white chest, legs and underside',
            ],
            // M5-R10-19: FCI 147 (S166) — one standard colour, black and tan; never white markings.
            // The variants differ in the shade of tan only (rich tan / rust / mahogany, S166 / S170).
            BreedType::Rottweiler => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'black with rich tan markings',
                    1 => 'black with rust markings',
                    2 => 'black with mahogany markings',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'short, coarse, dense and flat',
                'markings' => 'clearly defined tan on the cheeks, muzzle, throat, chest and legs and above each eye',
            ],
            // M5-R10-20: RKC standard (S178) "Whole colours are red, fawn, white." + brindle / pied;
            // never black, black and tan (S175) or merle (S179).
            BreedType::Bulldog => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'red and white',
                    1 => 'fawn brindle',
                    2 => 'pied (white with red patches)',
                },
                'eye_color' => 'very dark brown',
                'fur_texture' => 'fine, short, close and smooth',
                'markings' => match ($variantIndex) {
                    0 => 'white blaze, chest and feet',
                    1 => 'no markings',
                    2 => 'white body with red patches',
                },
            ],
            // M5-R10-21: AKC standard (S186) solid liver / liver and white / solid black / black and
            // white; never tan, red, orange, lemon or solid white (S186).
            BreedType::GermanShorthairedPointer => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'liver roan',
                    1 => 'solid liver',
                    2 => 'black roan',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'short, dense, rough and hard',
                'markings' => match ($variantIndex) {
                    0 => 'solid liver head with liver patches on a ticked body',
                    1 => 'no markings',
                    2 => 'solid black head with black patches on a ticked body',
                },
            ],
            // M5-R10-22: FCI / RKC (S190, S193) — dark steel blue and tan only (never fawn,
            // bronze, parti-colour or merle); the variants differ in coat length only.
            BreedType::YorkshireTerrier => [
                'color_scheme' => 'dark steel blue and rich tan',
                'eye_color' => 'dark',
                'fur_texture' => match ($variantIndex) {
                    0 => 'long, perfectly straight, glossy and silky',
                    1 => 'medium-long, straight and silky',
                    2 => 'long, straight and silky with a parting down the back',
                },
                'markings' => 'tan head, chest and legs; darker blue tail',
            ],
            // M5-R10-23: FCI 343 (S197) colours only — never black-and-tan (AKC S200), never large
            // white patches; a small white patch on the chest is acceptable (S197).
            BreedType::CaneCorso => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'solid black',
                    1 => 'solid grey (blue)',
                    2 => 'black brindle',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'short, shiny and very dense',
                'markings' => 'none, or a small white patch on the chest',
            ],
            // M5-R10-24: FCI 39 (S202) self colours only, with or without white markings on legs,
            // brisket and neck — never merle, blue or mostly white.
            BreedType::PembrokeWelshCorgi => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'red',
                    1 => 'sable',
                    2 => 'fawn',
                },
                'eye_color' => 'brown',
                'fur_texture' => 'medium-length, straight, with a dense undercoat',
                'markings' => 'white markings on the legs, chest and neck',
            ],
            // M5-R10-25: FCI 143 (S207) — black or brown with rust red tan markings; never white
            // markings, blue, fawn or merle.
            BreedType::Dobermann => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'black and rust',
                    1 => 'brown and rust',
                    2 => 'black and rust',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'short, hard, smooth and tight-lying',
                'markings' => 'clearly defined rust red tan markings on the muzzle, cheeks, eyebrows, chest and legs',
            ],
            // M5-R10-26: RKC (S217) "Fawn or brindle." with a black mask; white markings never
            // more than a third; never all white, black or merle.
            BreedType::Boxer => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'fawn with a black mask',
                    1 => 'brindle with a black mask',
                    2 => 'fawn with a black mask',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'short, glossy, smooth and tight to the body',
                'markings' => match ($variantIndex) {
                    0 => 'black mask on the muzzle',
                    1 => 'black mask on the muzzle',
                    2 => 'black mask and small white markings on the chest and paws',
                },
            ],
            // Unreachable: generateInitialPetDna() refuses non-dogs.
            BreedType::DomesticCat, BreedType::MaineCoon, BreedType::BritishShorthair, BreedType::Ragdoll, BreedType::SiberianCat, BreedType::NorwegianForestCat, BreedType::Bengal => throw new \InvalidArgumentException("No DNA v1 traits for {$breed->value}."),
        };
    }

    // ──────────────────────────────────────────────────────────────
    //  Reference image (synchronous — call only from a queued job)
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate the canonical reference image for a pet with the configured
     * profile (config media.reference_image_profile — Nano Banana Pro since
     * 2026-10-05). Every call passes the spend cap first and is recorded in
     * ai_spend_ledger (M4-07), linked to the pet_media slot.
     *
     * @return array{url: string, profile: string}|null fal.media URL of the image, or null on a retryable failure
     *
     * @throws AiCallException for failures a retry cannot fix (budget cap, fal balance, profile disabled)
     */
    public function generateReferenceImage(string $prompt, int $seed, ?int $petId = null, ?string $negativePrompt = null, ?int $petMediaId = null): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $profile = $this->profiles->referenceImage();

        try {
            $result = $this->gateway->run(
                $profile,
                $profile->imageInput($prompt, $seed, $negativePrompt),
                AiSpendPurpose::ReferenceImage,
                petId: $petId,
                petMediaId: $petMediaId,
            );
        } catch (AiCallException $e) {
            if ($e->retryable()) {
                Log::error('FalAiService: reference image generation failed', ['pet_id' => $petId, 'reason' => $e->reason->value]);

                return null;
            }

            throw $e;
        }

        $url = $result['body']['images'][0]['url'] ?? null;

        if (! is_string($url) || ! $this->isAllowedMediaUrl($url)) {
            Log::error('FalAiService: reference image response had no usable URL', ['pet_id' => $petId]);

            return null;
        }

        return ['url' => $url, 'profile' => $profile->key];
    }

    /**
     * Life-stage growth (M5-R01): the next reference image as an EDIT of the
     * previous one (image-to-image, profile media.stage_edit_profile — Nano
     * Banana Pro Edit), so the dog keeps its identity. Same budget / ledger
     * as a reference image. Returns null when the profile is disabled (the
     * caller falls back to text-to-image) or on a retryable failure.
     *
     * @return array{url: string, profile: string}|null
     *
     * @throws AiCallException budget / balance / non-retryable failures
     */
    public function editReferenceImage(string $prompt, string $sourceImageUrl, int $seed, ?int $petId = null, ?int $petMediaId = null): ?array
    {
        $profile = $this->profiles->stageEdit();

        if (! $this->isEnabled() || $profile === null) {
            return null;
        }

        try {
            $result = $this->gateway->run(
                $profile,
                $profile->editInput($prompt, $sourceImageUrl, $seed),
                AiSpendPurpose::ReferenceImage,
                petId: $petId,
                petMediaId: $petMediaId,
            );
        } catch (AiCallException $e) {
            if ($e->retryable()) {
                Log::error('FalAiService: stage image edit failed', ['pet_id' => $petId, 'reason' => $e->reason->value]);

                return null;
            }

            throw $e;
        }

        $url = $result['body']['images'][0]['url'] ?? null;

        if (! is_string($url) || ! $this->isAllowedMediaUrl($url)) {
            Log::error('FalAiService: stage image edit response had no usable URL', ['pet_id' => $petId]);

            return null;
        }

        return ['url' => $url, 'profile' => $profile->key];
    }

    // ──────────────────────────────────────────────────────────────
    //  State videos (asynchronous via queue API + signed webhook)
    // ──────────────────────────────────────────────────────────────

    /**
     * Submit an image-to-video job (profile media.state_video_profile — Kling
     * 3.0 Pro, 5 s, no audio) for one pet_media video slot. The result
     * arrives on the signed webhook and is matched by request_id. Call only
     * from a queued job (SubmitPetStateVideo), never inside a transaction.
     *
     * @return array{request_id: string, profile: string, duration_seconds: int}
     *
     * @throws AiCallException
     */
    public function submitStateVideo(Pet $pet, PetStateEnum $state, string $startImageUrl, ?int $petMediaId = null): array
    {
        $dna = is_array($pet->pet_dna) ? $pet->pet_dna : [];
        $breedKey = (string) ($dna['breed'] ?? $pet->breed_type->value);
        // DNA v2 traits describe the dog; v1 pets rely on the start image alone.
        $traits = (int) ($dna['version'] ?? 1) >= 2 && is_array($dna['traits'] ?? null) ? $dna['traits'] : [];

        return $this->submitStateVideoFor($breedKey, $traits, $pet->life_stage, $state, $startImageUrl, $pet->id, $petMediaId);
    }

    /**
     * submitStateVideo() from breed + traits + stage — also for a shared look
     * of the free-pet pool (M4-10: no pet, the ledger row links the look's
     * pet_media row).
     *
     * @param  array<string, string>  $traits
     * @return array{request_id: string, profile: string, duration_seconds: int}
     *
     * @throws AiCallException
     */
    public function submitStateVideoFor(string $breedKey, array $traits, ?LifeStage $stage, PetStateEnum $state, string $startImageUrl, ?int $petId = null, ?int $petMediaId = null): array
    {
        $profile = $this->profiles->stateVideo();
        // M5-R06-07: cat templates for a cat (videoPrompt throws for a state the species never has).
        $species = PetAppearancePrompt::speciesOf($breedKey);

        $submitted = $this->gateway->submit(
            $profile,
            $profile->videoInput($startImageUrl, $this->prompts->videoPrompt($breedKey, $state, $traits, $stage), $this->prompts->videoNegativePrompt($species)),
            AiSpendPurpose::StateVideo,
            $this->webhookUrl(),
            petId: $petId,
            petMediaId: $petMediaId,
        );

        return [
            'request_id' => $submitted['request_id'],
            'profile' => $profile->key,
            'duration_seconds' => $profile->durationSeconds,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Webhook payload
    // ──────────────────────────────────────────────────────────────

    /**
     * Interpret a (signature-verified) fal.ai webhook body.
     *
     * Format: {request_id, gateway_request_id, status: "OK"|"ERROR", payload, error?}
     *
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, video_url: string|null, error: string|null, reason: AiCallFailure|null}
     */
    public function parseWebhookResult(array $body): array
    {
        if (($body['status'] ?? null) !== 'OK') {
            $error = $body['error'] ?? 'fal.ai reported an error';

            return ['ok' => false, 'video_url' => null, 'error' => is_string($error) ? $error : 'fal.ai reported an error', 'reason' => AiCallFailure::GenerationFailed];
        }

        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $videoUrl = $payload['video']['url'] ?? null;

        if (! is_string($videoUrl) || ! $this->isAllowedMediaUrl($videoUrl)) {
            return ['ok' => false, 'video_url' => null, 'error' => 'Missing or untrusted video URL in payload', 'reason' => AiCallFailure::InvalidResponse];
        }

        return ['ok' => true, 'video_url' => $videoUrl, 'error' => null, 'reason' => null];
    }

    /**
     * Only accept HTTPS media served from fal.ai-owned hosts. Defence in depth:
     * a URL shown full-screen to a child must never point somewhere arbitrary.
     */
    public function isAllowedMediaUrl(string $url): bool
    {
        // Characters that parse differently in PHP vs. WHATWG URL parsers (React Native,
        // browsers) — e.g. "https://evil.com\\@v3.fal.media" — are rejected outright.
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\\\\@\s]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach ((array) config('services.fal_ai.media_hosts', ['fal.media']) as $allowed) {
            $allowed = strtolower((string) $allowed);
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    // ──────────────────────────────────────────────────────────────
    //  Config helpers
    // ──────────────────────────────────────────────────────────────

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/webhooks/fal-ai';
    }
}
