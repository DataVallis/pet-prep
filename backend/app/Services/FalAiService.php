<?php

namespace App\Services;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\PetPhaseClip;
use App\Enums\PetStateEnum;
use App\Enums\PetTransition;
use App\Enums\Species;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Services\Media\AiCallException;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaProfiles;
use App\Services\Media\ModelProfile;
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

            // M5-R10-27: FCI 183 (S221) / AKC (S225) — pepper and salt as the anchor (all three
            // standards); natural drop ears and tail, never cropped or docked; never white.
            BreedType::MiniatureSchnauzer => 'A friendly, alert Miniature Schnauzer dog with a pepper and salt coat and a dark facial mask, '
                .'harsh wiry dense coat with bushy eyebrows and a beard, natural uncropped V-shaped ears folding forward, '
                .'dark oval eyes, black nose, small, sturdy, nearly square build, natural undocked tail, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-28: FCI 208 (S229) / RKC standard (S232) — gold and white parti-colour as the
            // anchor; welfare breed (BVA S236): moderate muzzle with open nostrils; never merle.
            BreedType::ShihTzu => 'A friendly, alert Shih Tzu dog with a long gold and white coat, '
                .'long dense straight coat, large dark round eyes that are not prominent, large drooping ears with long hair, '
                .'short square muzzle drawn moderate with clearly open nostrils, black nose, small sturdy body, '
                .'heavily plumed tail carried over the back, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-29: FCI 235 (S239) / RKC standard (S242) — fawn with a black mask as the anchor;
            // welfare (RKC Breed Watch S244): tight eyelids and a sound build; natural ears; never merle.
            BreedType::GreatDane => 'A friendly, noble Great Dane dog with a fawn coat and a black mask, '
                .'very short smooth glossy coat, dark medium-sized eyes with tight eyelids, natural uncropped ears folded forward, '
                .'long narrow head with a broad muzzle, black nose, very large, powerful and elegant body, '
                .'natural tail reaching to the hocks, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-30: RKC standard (S249) / FCI 97 (S246) — bright orange as the anchor (a whole
            // colour both standards name by name); natural untrimmed double coat; never merle, never white
            // markings on a coloured dog.
            BreedType::Pomeranian => 'A lively, friendly Pomeranian dog with a bright orange coat, '
                .'profuse natural double coat with a full frill round the neck and chest, small erect ears set high, '
                .'fox-like head with a fine muzzle, dark medium-sized eyes, black nose, '
                .'plumed tail turned over the back, tiny compact toy body, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-31: FCI 140 (S254) / RKC standard (S257) — brindle with white markings as the anchor
            // (the colour both standards prefer); natural erect ears (never cropped) and a short natural
            // tail (never docked); welfare breed (brachycephalic — PDSA S259, RKC S261, BVA S92): moderate
            // muzzle with clearly open nostrils, no wrinkles; never solid, blue, liver, fawn or merle.
            BreedType::BostonTerrier => 'A friendly, lively Boston Terrier dog with a brindle coat and white markings, '
                .'white muzzle band, white blaze between the eyes and white forechest, short smooth bright coat, '
                .'small natural erect ears, large round dark eyes set wide apart that are not bulging, '
                .'moderate short square muzzle free from wrinkles with a black wide nose and clearly open nostrils, '
                .'compact well-balanced body, short natural straight tail carried low, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-32: FCI 367 (S265) / AKC standard (S266) — colours "with no order of preference",
            // blue merle as the anchor (merle is standard for this breed, as the Australian Shepherd);
            // triangular high-set folding ears, natural undocked tail (the game never draws docking).
            BreedType::MiniatureAmericanShepherd => 'An alert, athletic Miniature American Shepherd dog with a blue merle coat with white and copper markings, '
                .'medium-length straight to wavy weather-resistant coat, triangular high-set ears folding forward, '
                .'almond-shaped eyes fully surrounded by colour, moderate muzzle, natural undocked tail, '
                .'small, agile body slightly longer than tall, photorealistic, studio quality, natural lighting',

            // M5-R10-33: KC standard (S274) / FCI 218 (S273) — every colour except merle; fawn as the anchor;
            // smooth coat (the RKC Smooth Coat breed, S275); welfare: a moderate apple-dome head and muzzle,
            // eyes not protruding (S274 "not protruding"); natural sickle tail.
            BreedType::Chihuahua => 'A small, alert, smooth-coated Chihuahua dog with a fawn coat, '
                .'short soft close and glossy coat, moderately rounded apple-dome head with a moderately short, slightly pointed muzzle, '
                .'large round dark eyes that are not protruding, large flaring erect ears, '
                .'medium-length sickle tail carried up over the back, tiny dainty compact body, '
                .'photorealistic, studio quality, natural lighting',
            // M5-R10-34: KC standard (S282) — liver and white as the anchor (also black and white, either
            // with tan markings; never roan / lemon / red / orange); natural tail (KC "Previously customarily docked.").
            BreedType::EnglishSpringerSpaniel => 'A medium-sized, compact and athletic English Springer Spaniel dog with a liver and white coat, '
                .'close straight medium-length coat with moderate feathering on the ears, legs and chest, '
                .'long lobular well-feathered ears hanging close to the head, medium almond-shaped dark hazel eyes with a kind expression, '
                .'natural undocked feathered tail carried low, '
                .'photorealistic, studio quality, natural lighting',
            // M5-R10-35: FCI standard (S290) — sable and white as the anchor (also tricolour, blue merle,
            // black and white, black and tan; never brindle, white body patches or a double merle).
            BreedType::ShetlandSheepdog => 'A small, lithe and graceful Shetland Sheepdog dog with a sable and white coat, '
                .'long harsh straight double coat with an abundant mane and frill, '
                .'small ears carried semi-erect with the tips folding forward, dark brown almond-shaped eyes with a gentle expression, '
                .'head shaped like a long blunt wedge, natural low-set tail with abundant hair, '
                .'photorealistic, studio quality, natural lighting',
            // M5-R10-36: RKC standard (S299) / FCI 95 (S297) — orange and white as the anchor (also liver and white,
            // roans, black and white, tricolour; never a solid colour); naturally short (bob) tail (S298 / S297).
            BreedType::Brittany => 'A medium-sized, compact, short-backed and athletic Brittany dog with an orange and white coat, '
                .'dense, fairly fine, flat or slightly wavy medium-length coat, '
                .'high-set triangular ears slightly rounded at the tip lying flat against the cheeks, expressive brown eyes, '
                .'slightly rounded skull with a tapered muzzle, naturally short bob tail, leggy and agile pointing-dog build, '
                .'photorealistic, studio quality, natural lighting',
            // M5-R10-37: AKC standard (S307) / FCI 167 (S304) — buff as the anchor (also black, black and tan, red,
            // chocolate, parti-colours with white; never merle, never blue eyes); natural undocked tail in the game.
            BreedType::AmericanCockerSpaniel => 'A small, compact and sturdy American Cocker Spaniel dog with a buff coat, '
                .'silky, flat or slightly wavy coat with moderate feathering on the ears, chest, belly and legs, '
                .'long lobular well-feathered ears set low, round full dark brown eyes, '
                .'rounded skull with a pronounced stop and a broad deep muzzle, natural undocked tail carried level with the back, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-38: FCI 15 Malinois variety (S313) / AKC standard (S315) — fawn with black overlay and a black
            // mask as the anchor (also mahogany, AKC); never black, brindle, merle or white patches; natural ears and tail.
            BreedType::BelgianMalinois => 'A medium-large, square, elegant and powerful Belgian Malinois dog (Belgian Shepherd, short-haired variety) '
                .'with a short fawn coat with black overlay and a pronounced black mask, short straight hard coat fuller at the neck and tail, '
                .'rather small high-set triangular erect ears, medium almond-shaped dark brown eyes, '
                .'proud head carriage, natural full tail reaching the hock carried low, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-39: FCI 163 (S320) — black, white and tan tricolour as the anchor (also lemon and white);
            // welfare (RKC Breed Watch Category 3, S322): moderate ears, tight eyelids, little loose skin,
            // adequate ground clearance. Never merle.
            BreedType::BassetHound => 'A short-legged, sturdy, well-balanced Basset Hound dog '
                .'with a smooth short black, white and tan tricolour coat, long low-set velvety ears of moderate length, '
                .'dark lozenge-shaped eyes with tight eyelids, supple skin with only a little wrinkle at the brow, '
                .'adequate ground clearance, rather long tapering tail, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-40: FCI 57 (S327) — russet gold as the anchor (also dark sandy gold); self-coloured
            // nose (AKC S331), natural tail (FCI where docking is prohibited). Never brown, pale yellow or
            // parti-coloured.
            BreedType::HungarianVizsla => 'A medium-sized, elegant, lean and muscular Hungarian Vizsla dog '
                .'with a short, dense, smooth russet gold coat, a self-coloured reddish-brown nose, '
                .'slightly oval brown eyes, medium-set ears hanging close to the cheeks, '
                .'natural undocked tail reaching the hock, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-41: FCI 5 (S336) / AKC standard (S343) — golden as the anchor (also black, liver, roans,
            // parti-colours, tricolour); natural undocked tail (the AKC standard says docked). Never merle.
            BreedType::EnglishCockerSpaniel => 'A merry, sturdy, compact English Cocker Spaniel dog with a golden coat, '
                .'flat silky coat of medium length with moderate feathering on the ears, chest and legs, '
                .'long lobular ears set low with silky feathering, brown eyes, '
                .'square muzzle with a distinct stop, natural undocked tail carried level, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-42: FCI 65 (S346) / AKC standard (S349) — pure white is the only standard colour;
            // long, straight, silky single coat, very dark round eyes with black rims, black nose,
            // plumed tail over the back.
            BreedType::Maltese => 'A gentle, lively little Maltese dog with a pure white coat, '
                .'long, straight, silky single coat falling like a mantle without curls, '
                .'drop ears heavily feathered with long hair, very dark round eyes with black rims, black nose, '
                .'plumed tail carried over the back, small longish body, photorealistic, studio quality, natural lighting',

            // M5-R10-43: FCI 253 (S354) — fawn as the anchor (fawn / black / apricot / silver); welfare breed
            // (flat-faced, Breed Watch Category 3, S356): a moderate face with clearly open nostrils, light
            // wrinkles, eyes that do not bulge, a fit body; tail curled over the hip.
            BreedType::Pug => 'A charming, even-tempered Pug dog with a fawn coat, black mask and black velvet ears, '
                .'fine smooth short glossy coat, moderate short blunt square muzzle with a black nose and clearly open nostrils, '
                .'light forehead wrinkles without a heavy over-nose wrinkle, dark round lustrous eyes that are not bulging, '
                .'small rose or button ears, square compact fit body, tail tightly curled over the hip, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-44: FCI 156 (S364) / RKC standard (S366) — sable and white as the anchor (sable / tricolour /
            // blue merle, always with the typical white Collie markings); very dense coat with an abundant
            // mane and frill, smooth face, long blunt muzzle, semi-erect ears, natural low tail.
            BreedType::RoughCollie => 'A dignified, friendly Rough Collie dog with a sable and white coat and the typical white collar, '
                .'very dense long straight harsh outer coat with a very abundant mane and frill, smooth face, '
                .'long blunt well-rounded muzzle with a black nose, medium almond-shaped dark brown eyes, '
                .'semi-erect ears with the tips tipping forward, long tail carried low with a slight upward swirl, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-45: FCI 264 (S371) / AKC standard (S373) — fawn with a dark mask as the anchor (apricot /
            // fawn / brindle); massive but moderate build, moderate skin and eyelids (Breed Watch points,
            // S375), short broad muzzle with open nostrils, small ears close to the cheeks, natural tail.
            BreedType::Mastiff => 'A calm, dignified Mastiff dog with a fawn coat and a dark mask, dark ears and a black nose, '
                .'short close-lying coat, large massive symmetrical body with a broad square-looking head, '
                .'moderate skin with only a lightly wrinkled forehead, hazel brown eyes with tight eyelids, '
                .'short broad muzzle with open nostrils, small thin ears lying close to the cheeks, natural tail reaching the hocks, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-46: FCI 257 (S377) / AKC standard (S380) — red with urajiro as the anchor (red / black and
            // tan / sesame); small, well-balanced spitz, pricked triangular ears, triangular dark eyes, curled tail.
            BreedType::ShibaInu => 'An alert, confident Shiba Inu dog with a red coat and cream-white urajiro on the muzzle sides, cheeks, throat and chest, '
                .'short harsh double coat, small well-balanced muscular spitz body, '
                .'small triangular firmly pricked ears, triangular dark brown eyes, black nose, '
                .'thick tail curled over the back, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-47: FCI 146 (S385) / RKC standard (S388) — red wheaten as the anchor (light to red wheaten);
            // well balanced, muscular, agile hound; the ridge with two crowns; black nose, dark eyes.
            BreedType::RhodesianRidgeback => 'An athletic, dignified Rhodesian Ridgeback dog with a short, dense, sleek red wheaten coat of uniform colour, '
                .'well balanced, strong, muscular and agile hound body, never massive, '
                .'a clearly defined ridge of reversed hair along the spine from behind the shoulders to the haunch with two identical crowns, '
                .'medium-sized ears set rather high and carried close to the head, round bright dark eyes, black nose, '
                .'tapering tail of moderate length carried with a slight upward curve, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-48: FCI 77 (S396) / AKC-approved standard (S399) — white with red patches as the anchor (white with
            // patches of any colour); the erect-eared Papillon variety (ears like butterfly wings), not the Phalène.
            BreedType::Papillon => 'A happy, alert little Papillon dog, white with red patches, colour covering both ears and over both eyes with a white blaze, '
                .'long, fine, silky, straight single coat with a frill on the chest and feathering on the legs, '
                .'large erect fringed ears with rounded tips carried obliquely like the spread wings of a butterfly, '
                .'dark round eyes with black rims, small black nose, long plumed tail arched over the back, small elegant body, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-49: RKC breed standard (S403) — solid black as the anchor ("Black, white, various shades of brown, black
            // and white, brown and white."); the traditional lion clip described by the standard (muzzle and hindquarters clipped).
            BreedType::PortugueseWaterDog => 'A happy, alert Portuguese Water Dog, solid black with a black nose, '
                .'robust, well-balanced, almost square and strongly muscled medium-sized body, '
                .'profuse curly single coat in the traditional lion clip: full coat over the front half, muzzle and hindquarters clipped short, '
                .'heart-shaped thin drop ears, dark round eyes, tail carried in a ring with a plume at the tip, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-50: FCI 215 (S410) / RKC breed standard (S412) — pure white is the only adult colour.
            BreedType::BichonFrise => 'A cheerful, lively little Bichon Frise dog, pure white, '
                .'loose, soft corkscrew-curled coat in a natural rounded trim, well-balanced small body, '
                .'very dark round eyes with black rims and dark haloes, round black nose, drooping ears covered with hair, '
                .'plumed tail carried gracefully curved over the back, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-51: FCI 50 (S420) / AKC standard (S421) — solid black as the anchor (black, Landseer,
            // brown); large, deep-bodied but balanced, water-resistant double coat, small deep-set eyes with
            // closely fitting eyelids (Breed Watch point, S425), broad deep muzzle, natural tail.
            BreedType::Newfoundland => 'A gentle, dignified Newfoundland dog with a solid black coat, '
                .'flat, water-resistant, moderately long double coat, large deep-bodied muscular body, heavily boned but balanced, '
                .'dark brown small deep-set eyes with closely fitting eyelids, broad deep muzzle with a black nose, '
                .'small triangular ears lying close to the head, natural tail hanging to the hock, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-52: FCI 153 (S428) — pure white with black spots as the anchor (black-spotted, liver-spotted);
            // rectangular, strong, muscled body, short shiny coat, spotted hanging ears, fitting eyelids, sabre tail.
            BreedType::Dalmatian => 'A friendly, elegant Dalmatian dog with a pure white coat covered in round, clearly defined black spots, '
                .'short, shiny, hard and dense coat, rectangular, strong and muscular body, '
                .'oval dark eyes with closely fitting eyelids, black nose, hanging spotted ears, '
                .'tail reaching the hock carried in a slight sabre curve, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-53: Kennel Club standard (S436) — blue speckled as the anchor (blue, red speckle);
            // strong, compact, symmetrical working dog, smooth weather-resistant double coat, pricked ears, low-set brush tail.
            BreedType::AustralianCattleDog => 'An alert, intelligent Australian Cattle Dog with a blue speckled coat and black and tan markings on the head, '
                .'smooth, short, hard and weather-resistant double coat, strong, compact, symmetrical and muscular body, '
                .'medium oval dark brown eyes, black nose, moderate pricked and moderately pointed ears, '
                .'low-set tail reaching the hock with a slight curve and a good brush, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-54: Kennel Club standard (S443) — fawn as the anchor (any colour except merle);
            // elegant, muscular sighthound, fine short coat, rose ears, long tapering tail; healthy lean condition.
            BreedType::Whippet => 'A gentle, alert Whippet with a fawn coat, '
                .'fine, short and close coat, elegant and muscular sighthound build with a graceful outline, deep chest and gently arched loin, '
                .'healthy lean condition, long lean head tapering to the muzzle, oval bright dark eyes, small rose-shaped ears, '
                .'long tapering tail without feathering, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-55: FCI standard (S449) — steel grey with brown (liver) patches as the anchor (never black);
            // vigorous, rustic pointing dog, harsh wiry coat with moustache and eyebrows, brown nose, natural tail.
            BreedType::WirehairedPointingGriffon => 'A gentle, alert Wirehaired Pointing Griffon with a steel grey coat with brown (liver) patches, '
                .'harsh, wiry medium-length coat with a soft undercoat, a moustache and bushy eyebrows, '
                .'vigorous, rustic and athletic pointing dog, slightly longer than tall, strong limbs, '
                .'long square muzzle with a brown nose, large rounded dark-yellow eyes, medium-sized flat ears set level with the eyes, '
                .'natural tail carried horizontally, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-56: FCI standard No. 263 (S458) — solid brown as the anchor (never black, no white except a small chest
            // spot); powerful working water retriever, thick short wavy coat with a woolly undercoat, yellowish or amber eyes.
            BreedType::ChesapeakeBayRetriever => 'A friendly, alert Chesapeake Bay Retriever with a solid brown coat, '
                .'thick, short, slightly wavy coat with a dense woolly undercoat, '
                .'powerful, athletic working water retriever with a strong, deep chest, size and substance not excessive, '
                .'broad round skull, muzzle about as long as the skull, tapering, clear yellowish or amber eyes, small ears hanging loosely, '
                .'natural tail of medium length, straight or slightly curved, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-57: FCI standard No. 212 (S464) — pure white as the anchor (never beige, never blue eyes; cream and
            // white and biscuit are the other standard colours); elegant medium-sized Arctic spitz, the "Samoyed smile".
            BreedType::Samoyed => 'A friendly, alert Samoyed with a pure white coat, '
                .'abundant, thick, dense double coat standing off the body with a silver sheen and a ruff around the neck, '
                .'elegant, medium-sized Arctic spitz, strong and agile, never clumsy, '
                .'strong muzzle about as long as the skull, black lips slightly upturned at the corners in the "Samoyed smile", black nose, '
                .'dark brown almond-shaped eyes with black rims, small, thick, erect triangular ears slightly rounded at the tips, '
                .'plumed tail carried curved forward over the back, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-58: Kennel Club standard (S473) — black as the anchor (wheaten and brindle are the other standard
            // colours; no other colour, no white patches); thick-set, short-legged terrier with a long head.
            BreedType::ScottishTerrier => 'A friendly, alert Scottish Terrier with a black coat, '
                .'harsh, dense, wiry close-lying double coat, neatly trimmed with the usual beard, eyebrows and leg furnishings, '
                .'small, thick-set, short-legged terrier, sturdy, agile and alert in carriage, '
                .'long head with a nearly flat skull, skull and foreface of equal length, large black nose, '
                .'dark brown almond-shaped eyes set under the eyebrows, small, neat, pointed erect ears set on top of the skull, '
                .'tail of moderate length, thick at the root, carried upright, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-59: FCI standard No. 99 (S478) — silver grey as the anchor (roe and mouse grey are the other standard
            // shades; grey only, never blue, brown or tan markings); short-haired variety, natural undocked tail.
            BreedType::Weimaraner => 'A friendly, alert short-haired Weimaraner with a silver grey coat, '
                .'short, strong, very dense, smooth-lying coat, '
                .'medium to large, sinewy and very muscular working pointer, functional and athletic, '
                .'long muzzle, large nose of dark flesh colour merging into grey, amber eyes, broad, fairly long ears set on high, '
                .'natural undocked tail hanging down in repose, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-60: Kennel Club standard (S485) — fawn as the anchor (black, blue, cream, red, white or any of these
            // broken with white are the other standard colours; never merle, brindle or tan markings); slender but healthy.
            BreedType::ItalianGreyhound => 'A friendly, alert Italian Greyhound with a fawn coat, '
                .'short, fine, glossy coat over fine, supple skin, '
                .'a Greyhound in miniature, more slender in all proportions, elegant and graceful, slender but healthy, '
                .'long, flat, narrow skull with a slight stop, fine long muzzle, dark nose, rather large bright eyes, '
                .'small rose-shaped ears folded back, low-set long fine tail carried low, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-61: Royal Kennel Club standard (S494) — solid black as the anchor (pepper and salt with a dark mask and
            // black and silver are the other standard colours); natural ears and tail, never cropped or docked.
            BreedType::GiantSchnauzer => 'A friendly, alert Giant Schnauzer with a solid black coat, '
                .'harsh, wiry, dense coat with bushy eyebrows and a beard, '
                .'powerfully built, robust and sinewy, almost square, '
                .'strong, long head with a flat forehead, blunt wedge-shaped muzzle, black nose, dark oval eyes, '
                .'natural uncropped V-shaped ears folding forward, natural undocked sabre-shaped tail, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-62: FCI standard No. 84 (S501) — black and tan as the anchor (liver and tan and red are the other
            // standard colours); welfare (RKC Breed Watch Category 2, S502): moderate skin, healthy eyelids.
            BreedType::Bloodhound => 'A gentle, calm Bloodhound with a black and tan coat, '
                .'short, dense, weatherproof coat, '
                .'large, powerful scent hound with moderate loose skin, never exaggerated wrinkles, '
                .'long head with moderate folds, black nose, healthy dark brown eyes with normal eyelids, '
                .'long, thin, velvety low-set ears, long strong tail set high, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-63: FCI standard No. 344 (S507) — red fawn with a black mask as the anchor (pinto, brindle and white are
            // other standard colours; never merle or liver); natural erect ears and curled tail.
            BreedType::Akita => 'A dignified, calm Akita (American type) with a red fawn coat and a black mask, '
                .'thick double coat with a harsh outer coat standing somewhat off the body, '
                .'large, sturdy, well balanced dog with heavy bone, '
                .'broad head forming a blunt triangle, deep muzzle, black nose, small dark brown eyes, '
                .'small, strongly erect ears, large well-furnished tail curled over the back, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-64: FCI 38 (S514) / RKC (S516) — red with white markings as the anchor (the first
            // colour of the game list); the Cardigan has always been undocked (RKC S515): fox-brush tail.
            BreedType::CardiganWelshCorgi => 'An alert, steady Cardigan Welsh Corgi dog with a red coat and white markings on the neck, chest and legs, '
                .'short to medium hard-textured weatherproof coat, erect, rather large ears with slightly rounded tips, '
                .'fox-like head, dark eyes, black nose, sturdy body of moderate length with a level back and enough ground clearance, '
                .'long natural tail like a fox\'s brush, carried low, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-65: FCI 98 (S520) / RKC (S522) / AKC (S523) — liver roan with liver patches as the anchor (the first
            // colour of the game list, accepted by every standard); natural undocked tail (RKC S522 undocked description).
            BreedType::GermanWirehairedPointer => 'An alert, biddable German Wirehaired Pointer dog with a liver roan coat, solid liver patches and a liver head, '
                .'harsh, wiry, flat-lying coat with a short beard and bushy eyebrows, medium-sized high-set ears, '
                .'hazel to dark brown eyes, liver nose, long strong muzzle, athletic medium-large body slightly longer than tall, '
                .'natural undocked tail reaching to the hocks, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-66: RKC standard (S530) / FCI 61 (S528) — short-haired variety, red mantle with white markings and a
            // dark mask as the anchor (the first colour of the game list); welfare (Breed Watch S531): tight eyelids, moderate lips.
            BreedType::SaintBernard => 'A calm, kindly Saint Bernard dog with a short, dense, close-lying red and white coat, '
                .'red mantle with white muzzle, blaze, collar, chest, feet and tail tip and a dark mask, '
                .'giant, well-proportioned powerful body with sound hindquarters, broad head with a black nose, '
                .'moderate lips and tight, healthy eyelids, medium-sized dark brown eyes, medium ears lying close to the cheeks, '
                .'long heavy tail carried low, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-67: FCI 76 (S537) / RKC standard (S539) — brindle as the anchor (the first colour of the game list);
            // natural rose ears and tail; a moderate head with a visible muzzle (never flat-faced).
            BreedType::StaffordshireBullTerrier => 'A bold, affectionate Staffordshire Bull Terrier dog with a smooth, short, close brindle coat, '
                .'muscular, compact, well-balanced body of great strength for its size, '
                .'short, deep head with a broad skull, pronounced cheek muscles, a distinct stop and a short but clearly visible muzzle, '
                .'black nose with open nostrils, round dark eyes looking straight ahead, small rose or half-pricked ears, '
                .'medium-length, low-set tapering natural tail carried rather low, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-68: FCI 7 (S546) / RKC standard (S548) — black saddle and tan as the anchor (the first colour of the game
            // list); natural V-shaped ears and an undocked tail.
            BreedType::AiredaleTerrier => 'An alert, confident Airedale Terrier dog with a hard, dense, wiry tan coat and a black saddle over the back, '
                .'top of the neck and top of the tail, large, muscular, fairly cobby terrier body with straight legs, '
                .'long flat head with a strong, well-filled muzzle, short beard and a black nose, small dark eyes full of terrier expression, '
                .'small V-shaped ears folded to the side, natural undocked tail set on high and carried gaily, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-70: FCI 345 (S558) — white predominant with tan markings as the anchor (the first colour of the game
            // list); smooth coat, button / dropped ears and a natural undocked tail.
            BreedType::RussellTerrier => 'A lively, alert Jack Russell (Russell) Terrier dog with a smooth, weatherproof, predominantly white coat '
                .'and tan markings on the head and ears, small, strong, active and lithe working terrier body of medium length, '
                .'black nose, small dark almond-shaped eyes with a keen expression, small button ears folded forward, '
                .'natural undocked tail carried erect, photorealistic, studio quality, natural lighting',

            // M5-R10-71: FCI 157 (S566) — fawn with a black mask as the anchor (the first colour of the game list); a moderate
            // muzzle with open nostrils (PDSA S568 BOAS), natural ears and tail.
            BreedType::Bullmastiff => 'A powerful, calm Bullmastiff dog with a short, hard fawn coat and a black muzzle mask toning off towards the eyes, '
                .'large, symmetrical and strong but not cumbersome body, large square skull, moderately short broad muzzle with clearly open nostrils, '
                .'dark medium-sized eyes with tight eyelids, V-shaped ears folded back, natural tail reaching the hocks, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-72: FCI 288 (S571) — Hairless variety, pink skin with dark spots as the anchor (the first colour of the
            // game list); healthy bare skin (PetMD S574), natural crest, socks and plume.
            BreedType::ChineseCrested => 'A small, graceful Chinese Crested dog of the Hairless variety with smooth, healthy pink skin with dark grey spots, '
                .'a long, soft, silky white crest on the head, white hairy socks on the feet and a flowing white plume on the tail, '
                .'fine-boned elegant body, large erect ears with a light fringe, very dark almond-shaped eyes, lean slightly tapering muzzle, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-73: FCI 312 (S577) — red with the usual white markings as the anchor (the first colour of the game
            // list); medium-length water-repellent double coat, feathered tail, amber eyes.
            BreedType::NovaScotiaDuckTollingRetriever => 'A medium-sized, compact and well-muscled Nova Scotia Duck Tolling Retriever with a red double coat of medium length, '
                .'lighter feathering, a white blaze, white chest, white feet and a white tail tip, '
                .'triangular ears set high with a very slightly erect base, almond-shaped amber eyes, a nose blending with the coat, '
                .'a broad, luxuriant, heavily feathered tail, alert and agile expression, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-75: Kennel Club standard (S584) — wheaten as the anchor (the first colour of the game list); natural,
            // shaggy weather-resistant double coat, darker ears and muzzle, small erect ears, short gaily carried tail.
            BreedType::CairnTerrier => 'A small, agile, workmanlike Cairn Terrier with a profuse, harsh, weather-resistant wheaten double coat of natural, shaggy look, '
                .'darker ears and muzzle, a small head with a broad skull and well-furnished face, shaggy eyebrows over dark hazel eyes, '
                .'small pointed erect ears, a black nose, a short well-furnished tail carried gaily, alert and fearless expression, '
                .'photorealistic, studio quality, natural lighting',

            // M5-R10-76: FCI-Standard N° 11 (S590) — white as the anchor (the first colour of the game list); egg-shaped head,
            // small erect ears, narrow triangular dark eyes, short flat glossy coat.
            BreedType::BullTerrier => 'A strongly built, muscular, well balanced Bull Terrier with a short, flat, glossy pure white coat, '
                .'a long, strong, egg-shaped head with no stop and a profile curving gently down to a black nose, '
                .'small narrow triangular dark eyes, small thin ears held erect, a short low-set tail carried horizontally, keen and determined expression, '
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
            // M5-R10-27: FCI 183 (S221) pepper and salt, black/silver, black; white is not drawn
            // (AKC S225 disqualifies it); never white patches or merle.
            BreedType::MiniatureSchnauzer => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'pepper and salt',
                    1 => 'black and silver',
                    2 => 'pepper and salt',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'harsh, wiry and dense with bushy eyebrows and a beard',
                'markings' => match ($variantIndex) {
                    0 => 'dark facial mask',
                    1 => 'silver eyebrows, muzzle, chest and legs',
                    2 => 'lighter grey beard, eyebrows and legs',
                },
            ],
            // M5-R10-28: parti-colours only (RKC S232 "All colours permissible except merle"; FCI S229
            // white blaze / white tail tip in parti-colours); never merle.
            BreedType::ShihTzu => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'gold and white',
                    1 => 'black and white',
                    2 => 'gold and white',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'long, dense and straight with a moderate undercoat',
                'markings' => match ($variantIndex) {
                    0 => 'white blaze on the forehead and white tail tip',
                    1 => 'white blaze, chest and paws',
                    2 => 'gold ears and white face',
                },
            ],
            // M5-R10-29: colours both standards list (FCI S239 / RKC S242); never merle, never a
            // white blaze or collar.
            BreedType::GreatDane => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'fawn',
                    1 => 'brindle',
                    2 => 'harlequin',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'very short, smooth and glossy',
                'markings' => match ($variantIndex) {
                    0 => 'black mask',
                    1 => 'black stripes and a black mask',
                    2 => 'torn black patches on a pure white base',
                },
            ],
            // M5-R10-30: RKC whole colours the FCI allows (RKC S249 / FCI S246); never merle,
            // never white markings on a coloured dog.
            BreedType::Pomeranian => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'bright orange',
                    1 => 'cream',
                    2 => 'white',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'profuse, fluffy double coat with a frill round the neck',
                'markings' => 'none (whole-coloured)',
            ],
            // M5-R10-31: brindle, seal or black, always with the required white markings (FCI S254 /
            // RKC S257); never solid colours, grey / blue, liver, fawn or merle.
            BreedType::BostonTerrier => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'brindle and white',
                    1 => 'black and white',
                    2 => 'seal and white',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'short, smooth, bright and fine',
                'markings' => 'white muzzle band, white blaze between the eyes and white forechest',
            ],
            // M5-R10-32: black, blue merle, red (liver), red merle, with or without white and tan
            // markings (FCI S265 / AKC S266); never white body splashes.
            BreedType::MiniatureAmericanShepherd => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'blue merle',
                    1 => 'black',
                    2 => 'red merle',
                },
                'eye_color' => match ($variantIndex) {
                    0 => 'blue',
                    1, 2 => 'brown',
                },
                'fur_texture' => 'medium-length, straight to wavy, weather resistant',
                'markings' => 'white collar, chest and legs with copper points',
            ],
            // M5-R10-33: any colour or mixture of colours except merle (KC S274 / FCI S273).
            BreedType::Chihuahua => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'fawn',
                    1 => 'cream',
                    2 => 'black and tan',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'short, soft, close and glossy',
                'markings' => 'none or small white chest marking',
            ],
            // M5-R10-34: KC standard colours (S282) — liver and white, black and white, either with tan markings.
            BreedType::EnglishSpringerSpaniel => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'liver and white',
                    1 => 'black and white',
                    2 => 'liver and white with tan markings',
                },
                'eye_color' => 'dark hazel',
                'fur_texture' => 'close, straight, medium-length with moderate feathering',
                'markings' => 'white blaze, collar, chest and legs',
            ],
            // M5-R10-35: FCI standard colours (S290) — sable, tricolour, blue merle (white collar, frill, legs, tail tip).
            BreedType::ShetlandSheepdog => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'sable and white',
                    1 => 'tricolour (black, tan and white)',
                    2 => 'blue merle and white',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'long, harsh, straight double coat with an abundant mane and frill',
                'markings' => 'white collar, frill, chest, legs and tail tip',
            ],
            // M5-R10-36: RKC / FCI standard colours (S299, S297) — orange, liver or black and white, clear or roan.
            BreedType::Brittany => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'orange and white',
                    1 => 'liver and white',
                    2 => 'orange roan',
                },
                'eye_color' => 'brown',
                'fur_texture' => 'dense, fairly fine, flat or slightly wavy medium-length coat',
                'markings' => 'white with orange or liver patches, light ticking on the muzzle and legs',
            ],
            // M5-R10-37: AKC / FCI standard varieties (S307, S304) — ASCOB, black, parti-colour.
            BreedType::AmericanCockerSpaniel => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'buff',
                    1 => 'black',
                    2 => 'red and white parti-colour',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'silky, flat or slightly wavy coat with moderate feathering',
                'markings' => 'solid colour, white only on the chest or throat for solid colours',
            ],
            // M5-R10-38: FCI / AKC standard colours (S313, S315) — fawn to mahogany with black overlay and mask.
            BreedType::BelgianMalinois => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'fawn with black overlay',
                    1 => 'rich fawn with black overlay',
                    2 => 'mahogany with black overlay',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'short, straight, hard coat with a dense undercoat',
                'markings' => 'pronounced black mask over the muzzle, lips and eyelids, black-tipped hairs',
            ],
            // M5-R10-39: FCI standard colours (S320) — tricolour, lemon and white.
            BreedType::BassetHound => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'black, white and tan tricolour',
                    1 => 'black, white and tan tri-colour',
                    2 => 'lemon and white',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'smooth, short, close coat',
                'markings' => 'white chest, legs and tail tip; long low-set ears',
            ],
            // M5-R10-40: FCI S327 / AKC S331 colours — russet gold, golden rust (AKC wording), dark sandy gold; uniform colour.
            BreedType::HungarianVizsla => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'russet gold',
                    1 => 'golden rust',
                    2 => 'dark sandy gold',
                },
                'eye_color' => 'brown, in harmony with the coat',
                'fur_texture' => 'short, dense, smooth coat without undercoat',
                'markings' => 'uniform colour, self-coloured reddish-brown nose, ears a little darker',
            ],
            // M5-R10-41: AKC / FCI standard colours (S343, S336) — solid colours, roans, parti-colours.
            BreedType::EnglishCockerSpaniel => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'golden',
                    1 => 'blue roan',
                    2 => 'black',
                },
                'eye_color' => 'brown',
                'fur_texture' => 'flat, silky coat with moderate feathering',
                'markings' => 'solid colours with at most a small white spot on the chest; roans and parti-colours with white',
            ],
            // M5-R10-42: FCI / AKC (S346, S349) — pure white only ("Colour: Pure white, a pale ivory
            // tinge is permitted."); the variants differ in coat length / texture only.
            BreedType::Maltese => [
                'color_scheme' => 'pure white',
                'eye_color' => 'very dark brown',
                'fur_texture' => match ($variantIndex) {
                    0 => 'long, straight, silky single coat without undercoat',
                    1 => 'long, straight, silky coat with the head hair in a small topknot',
                    2 => 'silky white coat kept at a medium pet length',
                },
                'markings' => 'none',
            ],
            // M5-R10-43: FCI S354 "Silver, apricot, fawn or black." — the variants use the three most
            // typical; fawn / apricot always with the black mask and ears.
            BreedType::Pug => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'fawn with a black mask',
                    1 => 'black',
                    2 => 'apricot with a black mask',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'fine, smooth, short and glossy',
                'markings' => 'black mask, black velvet ears, light forehead wrinkles',
            ],
            // M5-R10-44: FCI S364 sable / tricolour / blue merle; RKC S366 "All should carry typical white
            // Collie markings to a greater or lesser degree."
            BreedType::RoughCollie => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'sable and white',
                    1 => 'tricolour (black with tan markings and white)',
                    2 => 'blue merle and white',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'very dense, long, straight and harsh outer coat with a soft undercoat, abundant mane and frill',
                'markings' => 'typical white Collie markings: collar, frill, legs and tail tip',
            ],
            // M5-R10-45: FCI S371 "Apricot, fawn or brindle."; AKC S373 dark muzzle, ears and nose.
            BreedType::Mastiff => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'fawn with a dark mask',
                    1 => 'apricot with a dark mask',
                    2 => 'brindle with a dark mask',
                },
                'eye_color' => 'hazel brown',
                'fur_texture' => 'short, dense, close-lying coat, coarser over neck and shoulders',
                'markings' => 'dark mask, dark ears, lightly wrinkled forehead',
            ],
            // M5-R10-46: FCI S377 "Colour: Red, black and tan, sesame, black sesame, red sesame."; urajiro (S377, S380).
            BreedType::ShibaInu => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'red with cream-white urajiro',
                    1 => 'black and tan with cream-white urajiro',
                    2 => 'sesame with cream-white urajiro',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'short double coat, harsh straight outer coat over a soft dense undercoat',
                'markings' => 'cream-white urajiro on the muzzle sides, cheeks, throat, chest, belly and inner legs',
            ],
            // M5-R10-47: FCI S385 / RKC S388 "Light wheaten to red wheaten."; the ridge (S388).
            BreedType::RhodesianRidgeback => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'red wheaten',
                    1 => 'light wheaten',
                    2 => 'wheaten with a dark muzzle and ears',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'short, dense, sleek and glossy coat',
                'markings' => 'clearly defined ridge of reversed hair along the spine with two identical crowns',
            ],
            // M5-R10-48: FCI S396 "All colours are admitted on a coat with a white background."; AKC-approved S399
            // "Always parti-color or white with patches of any color(s)." — never all white, never without white.
            BreedType::Papillon => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'white with red patches',
                    1 => 'white with sable patches',
                    2 => 'tricolour: white with black patches and tan markings',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'long, fine, silky, straight single coat with a chest frill',
                'markings' => 'colour covering both ears and over both eyes, white blaze, large erect fringed butterfly ears',
            ],
            // M5-R10-49: RKC standard S403 "Black, white, various shades of brown, black and white, brown and white."
            // — "Any other colour or combination of colours unacceptable."
            BreedType::PortugueseWaterDog => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'solid black',
                    1 => 'black with white on the chin and chest',
                    2 => 'brown',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'profuse curly single coat without undercoat',
                'markings' => 'traditional lion clip: muzzle and hindquarters clipped, plume at the tail tip, heart-shaped drop ears',
            ],
            // M5-R10-50: FCI S410 "Pure white." / RKC S412 "White, but cream or apricot markings acceptable up to 18 months."
            // — an adult is always pure white.
            BreedType::BichonFrise => [
                'color_scheme' => 'pure white',
                'eye_color' => 'very dark',
                'fur_texture' => match ($variantIndex) {
                    0 => 'loose, soft corkscrew curls in a natural rounded trim',
                    1 => 'loose, soft corkscrew curls, slightly fuller on the head',
                    2 => 'loose, soft corkscrew curls, neatly scissored',
                },
                'markings' => 'black eye rims with dark haloes, plumed tail over the back',
            ],
            // M5-R10-51: FCI S420 "Color: Negro, blanco y negro y castaño."; AKC S421 gray — never (not FCI).
            BreedType::Newfoundland => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'solid black',
                    1 => 'white and black (Landseer)',
                    2 => 'solid brown',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'flat, water-resistant, moderately long double coat with a dense undercoat',
                'markings' => 'closely fitting eyelids, broad deep muzzle',
            ],
            // M5-R10-52: FCI S428 "Black spotted variety with black spots, liver spotted variety with brown spots."
            BreedType::Dalmatian => [
                'color_scheme' => match ($variantIndex) {
                    0, 2 => 'pure white with round black spots',
                    1 => 'pure white with round liver (brown) spots',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'short, shiny, hard and dense coat',
                'markings' => 'clearly defined round spots, spotted ears and tail, no patches',
            ],
            // M5-R10-53: KC standard S436 "Blue, blue-mottled or blue speckled with or without other markings." / red speckle.
            BreedType::AustralianCattleDog => [
                'color_scheme' => match ($variantIndex) {
                    0, 2 => 'blue speckled with black and tan head markings',
                    1 => 'even red speckle with darker red head markings',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'smooth, short, hard and weather-resistant double coat',
                'markings' => 'evenly distributed head markings, full low-set tail with a good brush',
            ],
            // M5-R10-54: KC standard S443 "Any colour or mixture of colours, except merle." (never merle).
            BreedType::Whippet => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'fawn',
                    1 => 'brindle',
                    2 => 'fawn and white parti-colour',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'fine, short and close coat',
                'markings' => 'no merle, long tapering tail without feathering',
            ],
            // M5-R10-55: FCI standard S449 colours (never black — AKC S451 / UKC S452).
            BreedType::WirehairedPointingGriffon => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'steel grey with brown (liver) patches',
                    1 => 'liver roan',
                    2 => 'white and orange',
                },
                'eye_color' => 'dark yellow or brown',
                'fur_texture' => 'harsh, wiry medium-length coat with a soft undercoat',
                'markings' => 'moustache and bushy eyebrows, brown nose, natural tail carried horizontally',
            ],
            // M5-R10-56: FCI standard No. 263 S458 colours (never black, no white except a small chest spot).
            BreedType::ChesapeakeBayRetriever => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'solid brown',
                    1 => 'solid sedge (reddish gold)',
                    2 => 'solid deadgrass (straw to dull tan)',
                },
                'eye_color' => 'clear yellowish or amber',
                'fur_texture' => 'thick, short, slightly wavy coat with a dense woolly undercoat',
                'markings' => 'no white except at most a small spot on the chest or toes, natural tail of medium length',
            ],
            // M5-R10-57: FCI standard No. 212 S464 colours (pure white, cream, white and biscuit; never beige, never blue eyes).
            BreedType::Samoyed => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'pure white',
                    1 => 'cream',
                    2 => 'white and biscuit',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'abundant, thick, dense double coat with a silver sheen',
                'markings' => 'black lips upturned in the "Samoyed smile", black eye rims, plumed tail curved over the back',
            ],
            // M5-R10-58: Kennel Club standard S473 colours (black, brindle, wheaten; no other colour, no white patches).
            BreedType::ScottishTerrier => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'black',
                    1 => 'brindle',
                    2 => 'wheaten',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'harsh, dense, wiry close-lying double coat',
                'markings' => 'trimmed beard, eyebrows and leg furnishings, small pointed erect ears, tail carried upright',
            ],
            // M5-R10-59: FCI standard No. 99 S478 colours (silver, roe or mouse grey; grey only, small white marks on
            // the chest and toes at most).
            BreedType::Weimaraner => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'silver grey',
                    1 => 'roe grey',
                    2 => 'mouse grey',
                },
                'eye_color' => 'amber',
                'fur_texture' => 'short, strong, very dense, smooth-lying coat',
                'markings' => 'grey only, at most small white marks on the chest and toes, natural undocked tail',
            ],
            // M5-R10-60: Kennel Club standard S485 colours (black, blue, cream, fawn, red, white, or any of these broken
            // with white; never merle, brindle or tan markings).
            BreedType::ItalianGreyhound => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'fawn',
                    1 => 'blue grey',
                    2 => 'black',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'short, fine, glossy coat',
                'markings' => 'solid colour, at most white on the chest and feet, small rose-shaped ears, long fine low-carried tail',
            ],
            // M5-R10-61: Royal Kennel Club standard S494 colours (pure black, pepper and salt, black and silver; any other
            // colour unacceptable).
            BreedType::GiantSchnauzer => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'solid black',
                    1 => 'pepper and salt with a dark facial mask',
                    2 => 'black and silver',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'harsh, wiry, dense coat with bushy eyebrows and a beard',
                'markings' => 'no white patches, natural uncropped V-shaped ears, natural undocked tail',
            ],
            // M5-R10-62: FCI standard S501 colours (black and tan, liver and tan, red; no excessive white).
            BreedType::Bloodhound => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'black and tan',
                    1 => 'liver and tan',
                    2 => 'red',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'short, dense, weatherproof coat with moderate loose skin',
                'markings' => 'no excessive white, long low-set velvety ears, healthy eyelids',
            ],
            // M5-R10-63: FCI standard S507 colours (any colour incl. pinto, brindle and white; never merle or liver).
            BreedType::Akita => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'red fawn with a black mask',
                    1 => 'pinto (white with large, evenly placed patches)',
                    2 => 'brindle with a black mask',
                },
                'eye_color' => 'dark brown',
                'fur_texture' => 'thick double coat with a harsh, straight outer coat',
                'markings' => 'never merle or liver, small strongly erect ears, large tail curled over the back',
            ],
            // M5-R10-64: FCI 38 (S514) colours — red, brindle, sable, blue merle, tricolour; white only as
            // markings (never predominating); long fox-brush tail, never docked.
            BreedType::CardiganWelshCorgi => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'red',
                    1 => 'brindle',
                    2 => 'sable',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'short to medium, hard-textured and weatherproof, with a good undercoat',
                'markings' => 'white markings on the neck, chest and legs, never predominantly white, long fox-brush tail',
            ],
            // M5-R10-65: colours every standard read accepts (RKC S522, FCI S520, AKC S523) — liver roan, liver and white,
            // solid liver; never black, solid black or tricolour; natural undocked tail.
            BreedType::GermanWirehairedPointer => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'liver roan',
                    1 => 'liver and white',
                    2 => 'solid liver',
                },
                'eye_color' => 'hazel to dark brown',
                'fur_texture' => 'harsh, wiry, flat-lying outer coat with a dense undercoat, short beard and eyebrows',
                'markings' => 'liver head, never black, natural undocked tail reaching to the hocks',
            ],
            // M5-R10-66: colours of the RKC (S530) and FCI (S528) standards — red mantle, white with red patches, red-brindle
            // and white; never solid white, solid red, black, merle or tricolour; tight eyelids (Breed Watch S531).
            BreedType::SaintBernard => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'red and white with a dark mask',
                    1 => 'white with red patches',
                    2 => 'red-brindle and white',
                },
                'eye_color' => 'dark brown, with tight healthy eyelids',
                'fur_texture' => 'short, dense, smooth and close-lying double coat',
                'markings' => 'white muzzle, blaze, collar, chest, feet and tail tip, never solid white or solid red',
            ],
            // M5-R10-67: colours of the FCI (S537) and RKC (S539) standards — brindle, red, fawn, black, blue, white or any
            // of these with white; never black and tan or liver (unacceptable); natural rose ears and tail.
            BreedType::StaffordshireBullTerrier => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'brindle',
                    1 => 'brindle and white',
                    2 => 'red',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'smooth, short and close',
                'markings' => 'never black and tan or liver, small rose ears, short but clearly visible muzzle, natural tapering tail',
            ],
            // M5-R10-68: colours of the FCI (S546) and RKC (S548) standards — black or grizzle saddle, all other parts tan;
            // any other colour unacceptable; natural V-shaped ears and an undocked tail.
            BreedType::AiredaleTerrier => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'tan with a black saddle',
                    1 => 'tan with a grizzle saddle',
                    2 => 'rich tan with a dark black saddle',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'hard, dense and wiry, lying straight and close',
                'markings' => 'black or grizzle saddle on the back, top of the neck and top of the tail, all other parts tan, never solid black, natural undocked tail',
            ],
            // M5-R10-70: colours of the FCI standard (S558) — white must predominate with black and/or tan markings;
            // button or dropped ears and a natural undocked tail.
            BreedType::RussellTerrier => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'white with tan markings',
                    1 => 'white with black and tan markings',
                    2 => 'white with black markings',
                },
                'eye_color' => 'dark',
                'fur_texture' => 'smooth, short and weatherproof',
                'markings' => 'predominantly white body, markings mostly on the head, ears and around the tail, never mostly black or tan, button or dropped ears, natural undocked tail',
            ],
            // M5-R10-71: colours of the FCI standard (S566) — any shade of fawn, red or brindle with an essential black muzzle;
            // moderate muzzle with open nostrils (PDSA S568), natural ears and tail.
            BreedType::Bullmastiff => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'fawn with a black mask',
                    1 => 'red with a black mask',
                    2 => 'brindle with a black mask',
                },
                'eye_color' => 'dark or hazel',
                'fur_texture' => 'short, hard and lying flat',
                'markings' => 'black muzzle mask toning off towards the eyes, at most a slight white mark on the chest, no other white, moderate muzzle with open nostrils, natural tail',
            ],
            // M5-R10-72: any colour except merle (FCI S571) — three common Hairless skin colourings; healthy bare skin with a
            // silky crest, socks and tail plume (never a Powder Puff).
            BreedType::ChineseCrested => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'pink skin with dark grey spots, white crest, socks and plume',
                    1 => 'solid dark slate-grey skin, white crest, socks and plume',
                    2 => 'solid pink skin, cream crest, socks and plume',
                },
                'eye_color' => 'very dark, almost black',
                'fur_texture' => 'hairless smooth skin with soft, silky long hair only on the head, feet and tail',
                'markings' => 'bare body skin, long silky crest on the head, hairy socks, flowing tail plume, large erect ears, never merle',
            ],
            // M5-R10-73: various shades of red or orange (FCI S577), white markings only on the tail tip, feet, chest and
            // blaze (never on the body), never black, grey or silver.
            BreedType::NovaScotiaDuckTollingRetriever => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'red with a white blaze, chest, feet and tail tip',
                    1 => 'orange with a white blaze, chest, feet and tail tip',
                    2 => 'solid red with lighter feathering',
                },
                'eye_color' => 'amber to brown',
                'fur_texture' => 'water-repellent double coat of medium length, soft with a dense undercoat, feathered tail',
                'markings' => 'lighter feathering and underside of the tail, white only on the tail tip, feet, chest and blaze, never black, grey or silver',
            ],
            // M5-R10-75: cream, wheaten, red, grey or nearly black, brindling acceptable (KC standard S584); never solid black,
            // white, or black and tan.
            BreedType::CairnTerrier => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'wheaten with darker ears and muzzle',
                    1 => 'red with darker ears and muzzle',
                    2 => 'grey brindle with darker ears and muzzle',
                },
                'eye_color' => 'dark hazel',
                'fur_texture' => 'profuse, harsh, weather-resistant double coat with a short soft undercoat, natural and shaggy',
                'markings' => 'darker points on the ears and muzzle, shaggy eyebrows, never solid black, white, or black and tan',
            ],
            // M5-R10-76: white, or coloured — brindle (preferred), black brindle, red, fawn, tricolour (FCI S590); never blue,
            // liver or merle.
            BreedType::BullTerrier => [
                'color_scheme' => match ($variantIndex) {
                    0 => 'pure white',
                    1 => 'brindle with white markings',
                    2 => 'red with white markings',
                },
                'eye_color' => 'dark, almost black',
                'fur_texture' => 'short, flat, harsh coat with a fine gloss and tight skin',
                'markings' => 'egg-shaped head, small narrow triangular eyes, never blue, liver or merle',
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
        return $this->editImageWith($this->profiles->stageEdit(), $prompt, $sourceImageUrl, $seed, $petId, $petMediaId);
    }

    /**
     * M5-T08: a state's KEY IMAGE (M5-T09 / T10: or a POSE image) as an edit of the
     * stage's reference image (profile media.state_media.keyframe_profile — Nano Banana
     * Pro Edit, David 2026-10-21). Same budget / ledger as every image (purpose
     * reference_image).
     *
     * Fix 2026-10-28 (David): submitted to fal's QUEUE with the signed webhook, like the
     * videos — the sync endpoint often took > 60 s, the job timed out and every paid try
     * was thrown away. The result (`images[0].url`) arrives on the webhook (or media:sweep
     * polls `status_url` / `response_url`). Call only from a queued job, never inside a
     * transaction.
     *
     * @return array{request_id: string, status_url: string|null, response_url: string|null, profile: string}
     *
     * @throws AiCallException (Disabled when fal or the profile is off)
     */
    public function submitKeyframe(string $prompt, string $sourceImageUrl, int $seed, ?int $petId = null, ?int $petMediaId = null): array
    {
        $profile = $this->profiles->keyframe();

        if (! $this->isEnabled() || $profile === null) {
            throw new AiCallException(AiCallFailure::Disabled);
        }

        $submitted = $this->gateway->submit(
            $profile,
            $profile->editInput($prompt, $sourceImageUrl, $seed),
            AiSpendPurpose::ReferenceImage,
            $this->webhookUrl(),
            petId: $petId,
            petMediaId: $petMediaId,
            // Well below GeneratePetKeyframe::$timeout (75 s): fal's queue answers at once.
            timeoutSeconds: self::KEYFRAME_SUBMIT_TIMEOUT_SECONDS,
        );

        return [
            'request_id' => $submitted['request_id'],
            'status_url' => $submitted['status_url'],
            'response_url' => $submitted['response_url'],
            'profile' => $profile->key,
        ];
    }

    /** The fal queue answers a submit within a second or two; a hung one is cut well before the job timeout. */
    public const KEYFRAME_SUBMIT_TIMEOUT_SECONDS = 30;

    /**
     * @return array{url: string, profile: string}|null
     *
     * @throws AiCallException
     */
    private function editImageWith(?ModelProfile $profile, string $prompt, string $sourceImageUrl, int $seed, ?int $petId, ?int $petMediaId): ?array
    {
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

    /**
     * M5-T08: a state's LOOP video — start frame = the state's key image. Fix
     * 2026-10-23 (David »uredi«): sent with the START frame only. Kling 3.0 Pro
     * given the same image as `end_image_url` returned an almost frozen clip
     * (production pet #8, mean frame difference ~0.08 / 255), so a loop now gets
     * a clearly-visible-motion prompt that ends close to the start pose instead
     * (recipe `start_only`; the seam is no longer pixel-identical).
     *
     * @param  array<string, string>  $traits
     * @return array{request_id: string, profile: string, duration_seconds: int, recipe: string|null}
     *
     * @throws AiCallException
     */
    public function submitLoopVideoFor(string $breedKey, array $traits, ?LifeStage $stage, PetStateEnum $state, string $keyImageUrl, ?int $petId = null, ?int $petMediaId = null): array
    {
        $profile = $this->profiles->stateVideo();
        $prompt = $this->prompts->loopVideoPrompt($breedKey, $state, $traits, $stage);

        return $this->submitClip($profile, $breedKey, $prompt, $keyImageUrl, $keyImageUrl, $petId, $petMediaId, sameFrame: true);
    }

    /**
     * M5-T08: a TRANSITION clip — start = key image of the source state, end =
     * key image of the target state (played once on a state change). Without
     * an end-frame profile only the start frame is sent (the prompt names the end pose).
     *
     * @param  array<string, string>  $traits
     * @return array{request_id: string, profile: string, duration_seconds: int}
     *
     * @throws AiCallException
     */
    public function submitTransitionFor(string $breedKey, array $traits, ?LifeStage $stage, PetTransition $transition, string $startImageUrl, string $endImageUrl, ?int $petId = null, ?int $petMediaId = null): array
    {
        $prompt = $this->prompts->transitionVideoPrompt($breedKey, $transition, $traits, $stage);

        return $this->submitClip($this->profiles->clipVideo('transition'), $breedKey, $prompt, $startImageUrl, $endImageUrl, $petId, $petMediaId);
    }

    /**
     * M5-T09 / T10: a school / game PHASE clip (3 s — media.state_media.clip_seconds,
     * David 2026-10-21): start = the clip's start pose, end = its end pose (Kling 3.0 Pro
     * `end_image_url`). Played once by the app, held on the last frame. A clip whose
     * start and end pose are the same picture (PetPhaseClip::sharesFrames) is sent
     * with the start frame only (fix 2026-10-23, see submitLoopVideoFor).
     *
     * @param  array<string, string>  $traits
     * @return array{request_id: string, profile: string, duration_seconds: int, recipe: string|null}
     *
     * @throws AiCallException
     */
    public function submitPhaseClipFor(string $breedKey, array $traits, ?LifeStage $stage, PetPhaseClip $clip, string $startImageUrl, string $endImageUrl, ?int $petId = null, ?int $petMediaId = null): array
    {
        $prompt = $this->prompts->phaseVideoPrompt($breedKey, $clip, $traits, $stage);

        return $this->submitClip($this->profiles->clipVideo($clip->purpose()), $breedKey, $prompt, $startImageUrl, $endImageUrl, $petId, $petMediaId, sameFrame: $clip->sharesFrames());
    }

    /**
     * THE one place that decides whether a clip gets an end frame (fix 2026-10-23):
     * same start and end picture (a loop, a same-pose phase clip, or two identical
     * URLs) → no `end_image_url` — Kling 3.0 Pro freezes such a clip; different
     * pictures (transitions, performing, ball running / fetching, wand stalking /
     * catching) → the end frame as before.
     */
    public static function endFrameUrl(string $startImageUrl, ?string $endImageUrl, bool $sameFrame): ?string
    {
        return $sameFrame || $endImageUrl === $startImageUrl ? null : $endImageUrl;
    }

    /**
     * @return array{request_id: string, profile: string, duration_seconds: int, recipe: string|null}
     *
     * @throws AiCallException
     */
    private function submitClip(ModelProfile $profile, string $breedKey, string $prompt, string $startImageUrl, ?string $endImageUrl, ?int $petId, ?int $petMediaId, bool $sameFrame = false): array
    {
        $species = PetAppearancePrompt::speciesOf($breedKey);
        $end = self::endFrameUrl($startImageUrl, $endImageUrl, $sameFrame);
        $startOnly = $end === null;

        if ($startOnly && ! $sameFrame && $endImageUrl !== null) {
            // QA #361 m4: two different frames resolved to the same picture — sent start-only
            // (would freeze otherwise); the prompt still names an end frame, so log it.
            Log::warning('FalAiService: clip frames resolved to the same image — sent without end frame', ['pet_id' => $petId, 'pet_media_id' => $petMediaId]);
        }
        // A start-only clip must move: no "fast motion" in its negative prompt (loop / same-pose phase).
        $negative = $startOnly ? $this->prompts->loopNegativePrompt($species) : $this->prompts->videoNegativePrompt($species);

        $submitted = $this->gateway->submit(
            $profile,
            $profile->videoInput($startImageUrl, $prompt, $negative, $end),
            AiSpendPurpose::StateVideo,
            $this->webhookUrl(),
            petId: $petId,
            petMediaId: $petMediaId,
        );

        return [
            'request_id' => $submitted['request_id'],
            'profile' => $profile->key,
            'duration_seconds' => $profile->durationSeconds,
            // pet_media.recipe: `start_only` = made from the start frame alone (fix 2026-10-23).
            'recipe' => $startOnly ? PetMedia::RECIPE_START_ONLY : null,
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
     * `$image` (fix 2026-10-28): the request was a queued key / pose image — the result
     * is `payload.images[0].url` instead of `payload.video.url`. `url` is the media URL
     * either way; `video_url` mirrors it for videos (AI Lab).
     *
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, url: string|null, video_url: string|null, error: string|null, reason: AiCallFailure|null}
     */
    public function parseWebhookResult(array $body, bool $image = false): array
    {
        if (($body['status'] ?? null) !== 'OK') {
            $error = $body['error'] ?? 'fal.ai reported an error';

            return ['ok' => false, 'url' => null, 'video_url' => null, 'error' => is_string($error) ? $error : 'fal.ai reported an error', 'reason' => AiCallFailure::GenerationFailed];
        }

        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $url = $image ? ($payload['images'][0]['url'] ?? null) : ($payload['video']['url'] ?? null);

        if (! is_string($url) || ! $this->isAllowedMediaUrl($url)) {
            return ['ok' => false, 'url' => null, 'video_url' => null, 'error' => 'Missing or untrusted '.($image ? 'image' : 'video').' URL in payload', 'reason' => AiCallFailure::InvalidResponse];
        }

        return ['ok' => true, 'url' => $url, 'video_url' => $image ? null : $url, 'error' => null, 'reason' => null];
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
