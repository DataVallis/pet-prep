<?php

namespace App\Enums;

use App\Services\BreedCatalogService;

/**
 * Breeds (M5-R06_PLAN T1, T9): the enum stays (it is used in payments and
 * ~20 files), the free / paid rule is data — `breed_configs.premium_unlock`
 * is the single source of truth (read through BreedCatalogService, cached).
 * Every new case needs: slug(), species(), defaultPremium(), the
 * `pets_breed_type_check` / `pets_species_breed_check` constraints and a
 * BreedConfigsSeeder row.
 */
enum BreedType: string
{
    case Mutt = 'mutt';
    case BorderCollie = 'border_collie';
    case LabradorRetriever = 'labrador_retriever';
    case GoldenRetriever = 'golden_retriever';
    case FrenchBulldog = 'french_bulldog';
    case GermanShepherd = 'german_shepherd';
    case CavalierKingCharlesSpaniel = 'cavalier_king_charles_spaniel';
    case Beagle = 'beagle';
    case StandardPoodle = 'standard_poodle';
    case Dachshund = 'dachshund';
    case AustralianShepherd = 'australian_shepherd';
    case Havanese = 'havanese';
    case WestHighlandWhiteTerrier = 'west_highland_white_terrier';
    case BerneseMountainDog = 'bernese_mountain_dog';
    case SiberianHusky = 'siberian_husky';
    case Rottweiler = 'rottweiler';
    case Bulldog = 'bulldog';
    case GermanShorthairedPointer = 'german_shorthaired_pointer';
    case YorkshireTerrier = 'yorkshire_terrier';
    case CaneCorso = 'cane_corso';
    case PembrokeWelshCorgi = 'pembroke_welsh_corgi';
    case Dobermann = 'dobermann';
    case Boxer = 'boxer';
    case MiniatureSchnauzer = 'miniature_schnauzer';
    case ShihTzu = 'shih_tzu';
    case GreatDane = 'great_dane';
    case Pomeranian = 'pomeranian';
    case BostonTerrier = 'boston_terrier';
    case MiniatureAmericanShepherd = 'miniature_american_shepherd';
    case Chihuahua = 'chihuahua';
    case EnglishSpringerSpaniel = 'english_springer_spaniel';
    case ShetlandSheepdog = 'shetland_sheepdog';
    case Brittany = 'brittany';
    case AmericanCockerSpaniel = 'american_cocker_spaniel';
    case BelgianMalinois = 'belgian_malinois';
    case BassetHound = 'basset_hound';
    case HungarianVizsla = 'hungarian_vizsla';
    case EnglishCockerSpaniel = 'english_cocker_spaniel';
    case Maltese = 'maltese';
    case Pug = 'pug';
    case RoughCollie = 'rough_collie';
    case Mastiff = 'mastiff';
    case ShibaInu = 'shiba_inu';
    case RhodesianRidgeback = 'rhodesian_ridgeback';
    case Papillon = 'papillon';
    case PortugueseWaterDog = 'portuguese_water_dog';
    case BichonFrise = 'bichon_frise';
    case Newfoundland = 'newfoundland';
    case Dalmatian = 'dalmatian';
    case AustralianCattleDog = 'australian_cattle_dog';
    case Whippet = 'whippet';
    case WirehairedPointingGriffon = 'wirehaired_pointing_griffon';
    case ChesapeakeBayRetriever = 'chesapeake_bay_retriever';
    case Samoyed = 'samoyed';
    case ScottishTerrier = 'scottish_terrier';
    case Weimaraner = 'weimaraner';
    case ItalianGreyhound = 'italian_greyhound';
    case GiantSchnauzer = 'giant_schnauzer';
    case Bloodhound = 'bloodhound';
    case Akita = 'akita';
    case CardiganWelshCorgi = 'cardigan_welsh_corgi';
    case GermanWirehairedPointer = 'german_wirehaired_pointer';
    case SaintBernard = 'saint_bernard';
    case StaffordshireBullTerrier = 'staffordshire_bull_terrier';
    case AiredaleTerrier = 'airedale_terrier';
    case RussellTerrier = 'russell_terrier';
    case Bullmastiff = 'bullmastiff';
    case ChineseCrested = 'chinese_crested';
    case NovaScotiaDuckTollingRetriever = 'nova_scotia_duck_tolling_retriever';
    case CairnTerrier = 'cairn_terrier';
    case DomesticCat = 'domestic_cat';
    case MaineCoon = 'maine_coon';
    case BritishShorthair = 'british_shorthair';
    case Ragdoll = 'ragdoll';
    case SiberianCat = 'siberian_cat';
    case NorwegianForestCat = 'norwegian_forest_cat';
    case Bengal = 'bengal';

    /**
     * Get the breed slug as used in the breed_configs table.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Mutt => 'mutt',
            self::BorderCollie => 'border-collie',
            self::LabradorRetriever => 'labrador-retriever',
            self::GoldenRetriever => 'golden-retriever',
            self::FrenchBulldog => 'french-bulldog',
            self::GermanShepherd => 'german-shepherd-dog',
            self::CavalierKingCharlesSpaniel => 'cavalier-king-charles-spaniel',
            self::Beagle => 'beagle',
            self::StandardPoodle => 'poodle-standard',
            self::Dachshund => 'dachshund',
            self::AustralianShepherd => 'australian-shepherd',
            self::Havanese => 'havanese',
            self::WestHighlandWhiteTerrier => 'west-highland-white-terrier',
            self::BerneseMountainDog => 'bernese-mountain-dog',
            self::SiberianHusky => 'siberian-husky',
            self::Rottweiler => 'rottweiler',
            self::Bulldog => 'bulldog',
            self::GermanShorthairedPointer => 'german-shorthaired-pointer',
            self::YorkshireTerrier => 'yorkshire-terrier',
            self::CaneCorso => 'cane-corso',
            self::PembrokeWelshCorgi => 'pembroke-welsh-corgi',
            self::Dobermann => 'dobermann',
            self::Boxer => 'boxer',
            self::MiniatureSchnauzer => 'miniature-schnauzer',
            self::ShihTzu => 'shih-tzu',
            self::GreatDane => 'great-dane',
            self::Pomeranian => 'pomeranian',
            self::BostonTerrier => 'boston-terrier',
            self::MiniatureAmericanShepherd => 'miniature-american-shepherd',
            self::Chihuahua => 'chihuahua',
            self::EnglishSpringerSpaniel => 'english-springer-spaniel',
            self::ShetlandSheepdog => 'shetland-sheepdog',
            self::Brittany => 'brittany',
            self::AmericanCockerSpaniel => 'american-cocker-spaniel',
            self::BelgianMalinois => 'belgian-malinois',
            self::BassetHound => 'basset-hound',
            self::HungarianVizsla => 'hungarian-vizsla',
            self::EnglishCockerSpaniel => 'english-cocker-spaniel',
            self::Maltese => 'maltese',
            self::Pug => 'pug',
            self::RoughCollie => 'rough-collie',
            self::Mastiff => 'mastiff',
            self::ShibaInu => 'shiba-inu',
            self::RhodesianRidgeback => 'rhodesian-ridgeback',
            self::Papillon => 'papillon',
            self::PortugueseWaterDog => 'portuguese-water-dog',
            self::BichonFrise => 'bichon-frise',
            self::Newfoundland => 'newfoundland',
            self::Dalmatian => 'dalmatian',
            self::AustralianCattleDog => 'australian-cattle-dog',
            self::Whippet => 'whippet',
            self::WirehairedPointingGriffon => 'wirehaired-pointing-griffon',
            self::ChesapeakeBayRetriever => 'chesapeake-bay-retriever',
            self::Samoyed => 'samoyed',
            self::ScottishTerrier => 'scottish-terrier',
            self::Weimaraner => 'weimaraner',
            self::ItalianGreyhound => 'italian-greyhound',
            self::GiantSchnauzer => 'giant-schnauzer',
            self::Bloodhound => 'bloodhound',
            self::Akita => 'akita',
            self::CardiganWelshCorgi => 'cardigan-welsh-corgi',
            self::GermanWirehairedPointer => 'german-wirehaired-pointer',
            self::SaintBernard => 'saint-bernard',
            self::StaffordshireBullTerrier => 'staffordshire-bull-terrier',
            self::AiredaleTerrier => 'airedale-terrier',
            self::RussellTerrier => 'jack-russell-terrier',
            self::Bullmastiff => 'bullmastiff',
            self::ChineseCrested => 'chinese-crested',
            self::NovaScotiaDuckTollingRetriever => 'nova-scotia-duck-tolling-retriever',
            self::CairnTerrier => 'cairn-terrier',
            self::DomesticCat => 'domestic-cat',
            self::MaineCoon => 'maine-coon',
            self::BritishShorthair => 'british-shorthair',
            self::Ragdoll => 'ragdoll',
            self::SiberianCat => 'siberian-cat',
            self::NorwegianForestCat => 'norwegian-forest-cat',
            self::Bengal => 'bengal',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }

    public function species(): Species
    {
        return match ($this) {
            self::Mutt, self::BorderCollie, self::LabradorRetriever, self::GoldenRetriever, self::FrenchBulldog, self::GermanShepherd, self::CavalierKingCharlesSpaniel, self::Beagle, self::StandardPoodle, self::Dachshund, self::AustralianShepherd, self::Havanese, self::WestHighlandWhiteTerrier, self::BerneseMountainDog, self::SiberianHusky, self::Rottweiler, self::Bulldog, self::GermanShorthairedPointer, self::YorkshireTerrier, self::CaneCorso, self::PembrokeWelshCorgi, self::Dobermann, self::Boxer, self::MiniatureSchnauzer, self::ShihTzu, self::GreatDane, self::Pomeranian, self::BostonTerrier, self::MiniatureAmericanShepherd, self::Chihuahua, self::EnglishSpringerSpaniel, self::ShetlandSheepdog, self::Brittany, self::AmericanCockerSpaniel, self::BelgianMalinois, self::BassetHound, self::HungarianVizsla, self::EnglishCockerSpaniel, self::Maltese, self::Pug, self::RoughCollie, self::Mastiff, self::ShibaInu, self::RhodesianRidgeback, self::Papillon, self::PortugueseWaterDog, self::BichonFrise, self::Newfoundland, self::Dalmatian, self::AustralianCattleDog, self::Whippet, self::WirehairedPointingGriffon, self::ChesapeakeBayRetriever, self::Samoyed, self::ScottishTerrier, self::Weimaraner, self::ItalianGreyhound, self::GiantSchnauzer, self::Bloodhound, self::Akita, self::CardiganWelshCorgi, self::GermanWirehairedPointer, self::SaintBernard, self::StaffordshireBullTerrier, self::AiredaleTerrier, self::RussellTerrier, self::Bullmastiff, self::ChineseCrested, self::NovaScotiaDuckTollingRetriever, self::CairnTerrier => Species::Dog,
            self::DomesticCat, self::MaineCoon, self::BritishShorthair, self::Ragdoll, self::SiberianCat, self::NorwegianForestCat, self::Bengal => Species::Cat,
        };
    }

    /**
     * Determine if this breed requires a premium unlock (the paid 12-week
     * challenge). Reads `breed_configs.premium_unlock` (M5-R06-01: the one
     * source of truth, cached); defaultPremium() only when the breed has no
     * config row.
     */
    public function isPremium(): bool
    {
        return app(BreedCatalogService::class)->isPremium($this);
    }

    /**
     * Fallback for a breed without a `breed_configs` row (fresh / test
     * database before seeding). Must equal the BreedConfigsSeeder values —
     * BreedCatalogTest checks it. Never use it for a rule directly; call
     * isPremium().
     */
    public function defaultPremium(): bool
    {
        return match ($this) {
            self::Mutt, self::DomesticCat => false,
            self::BorderCollie, self::LabradorRetriever, self::GoldenRetriever, self::FrenchBulldog, self::GermanShepherd, self::CavalierKingCharlesSpaniel, self::Beagle, self::StandardPoodle, self::Dachshund, self::AustralianShepherd, self::Havanese, self::WestHighlandWhiteTerrier, self::BerneseMountainDog, self::SiberianHusky, self::Rottweiler, self::Bulldog, self::GermanShorthairedPointer, self::YorkshireTerrier, self::CaneCorso, self::PembrokeWelshCorgi, self::Dobermann, self::Boxer, self::MiniatureSchnauzer, self::ShihTzu, self::GreatDane, self::Pomeranian, self::BostonTerrier, self::MiniatureAmericanShepherd, self::Chihuahua, self::EnglishSpringerSpaniel, self::ShetlandSheepdog, self::Brittany, self::AmericanCockerSpaniel, self::BelgianMalinois, self::BassetHound, self::HungarianVizsla, self::EnglishCockerSpaniel, self::Maltese, self::Pug, self::RoughCollie, self::Mastiff, self::ShibaInu, self::RhodesianRidgeback, self::Papillon, self::PortugueseWaterDog, self::BichonFrise, self::Newfoundland, self::Dalmatian, self::AustralianCattleDog, self::Whippet, self::WirehairedPointingGriffon, self::ChesapeakeBayRetriever, self::Samoyed, self::ScottishTerrier, self::Weimaraner, self::ItalianGreyhound, self::GiantSchnauzer, self::Bloodhound, self::Akita, self::CardiganWelshCorgi, self::GermanWirehairedPointer, self::SaintBernard, self::StaffordshireBullTerrier, self::AiredaleTerrier, self::RussellTerrier, self::Bullmastiff, self::ChineseCrested, self::NovaScotiaDuckTollingRetriever, self::CairnTerrier, self::MaineCoon, self::BritishShorthair, self::Ragdoll, self::SiberianCat, self::NorwegianForestCat, self::Bengal => true,
        };
    }

    /**
     * The free breed of a species (see Species::freeBreed()).
     */
    public static function freeBreedFor(Species $species): self
    {
        return app(BreedCatalogService::class)->freeBreedFor($species);
    }

    /**
     * @return list<self>
     */
    public static function forSpecies(Species $species): array
    {
        return array_values(array_filter(self::cases(), fn (self $b) => $b->species() === $species));
    }
}
