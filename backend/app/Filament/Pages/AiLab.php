<?php

namespace App\Filament\Pages;

use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\PetStateEnum;
use App\Enums\Species;
use App\Filament\Widgets\AiSpendOverview;
use App\Models\MediaLabResult;
use App\Models\MediaLabRun;
use App\Models\User;
use App\Services\Media\AiCallException;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\MediaLabService;
use App\Services\Media\MediaProfiles;
use App\Services\Media\ModelProfile;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetDnaService;
use App\Services\Media\PetLookPoolService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * AI Lab (M4-02, superadmin only): compare fal.ai image / video model profiles
 * on DNA v2 prompts. Every call passes the spend caps (M4-07). No child data.
 * Cats (M5-R06-07): the cat breeds come from config/breed_appearance.php,
 * an optional life stage tries the stage look (kitten, young Maine Coon),
 * the video states say which species has them, and "Per-pet cost" shows a
 * pet's media cost per species with the production models (checked before
 * cats are switched on).
 *
 * @property Form $imageForm
 * @property Form $videoForm
 */
class AiLab extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.ai_media');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.ai_lab');
    }

    protected static ?string $title = 'AI Lab';

    protected static ?string $slug = 'ai-lab';

    protected static string $view = 'filament.pages.ai-lab';

    /** @var array<string, mixed>|null */
    public ?array $imageData = [];

    /** @var array<string, mixed>|null */
    public ?array $videoData = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin() && (bool) config('media.lab.enabled', true);
    }

    public function mount(): void
    {
        $this->imageForm->fill(['breed' => 'mutt', 'samples' => 2, 'profiles' => ['flux_schnell'], 'fixed' => []]);
        $this->videoForm->fill(['state' => PetStateEnum::Idle->value, 'profiles' => []]);
    }

    /**
     * @return array<string, string>
     */
    protected function getForms(): array
    {
        return ['imageForm', 'videoForm'];
    }

    protected function getHeaderWidgets(): array
    {
        return [AiSpendOverview::class];
    }

    public function imageForm(Form $form): Form
    {
        $dna = app(PetDnaService::class);
        $traitFields = [];

        foreach ($this->allTraitKeys() as $trait) {
            $traitFields[] = Select::make("fixed.{$trait}")
                ->label(str_replace('_', ' ', ucfirst($trait)))
                ->placeholder('random')
                ->options(function (Get $get) use ($dna, $trait) {
                    $options = $dna->optionsFor((string) ($get('breed') ?: 'mutt'))[$trait] ?? [];

                    return array_combine($options, $options);
                })
                ->visible(fn (Get $get) => isset($dna->optionsFor((string) ($get('breed') ?: 'mutt'))[$trait]));
        }

        return $form
            ->schema([
                Section::make('1 · Images')
                    ->description('N samples × chosen models. Each sample has one random trait set (DNA v2) rendered by every model. Breed appearance data is NOT verified yet (M1-19).')
                    ->schema([
                        Select::make('breed')
                            ->options(collect($dna->breeds())->mapWithKeys(fn ($b) => [$b => (string) config("breed_appearance.{$b}.display_name", $b)])->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('fixed', [])),
                        Select::make('samples')
                            ->options(array_combine(range(1, (int) config('media.lab.max_samples', 4)), range(1, (int) config('media.lab.max_samples', 4))))
                            ->required()
                            ->live(),
                        CheckboxList::make('profiles')
                            ->label('Image models')
                            ->options($this->profileOptions(ModelProfile::KIND_IMAGE))
                            ->required()
                            ->live(),
                        Select::make('stage')
                            ->label('Life stage (optional)')
                            ->placeholder('none — the prompt a new pet stores')
                            ->options(fn (Get $get) => $this->stageOptions((string) ($get('breed') ?: 'mutt'))),
                        Section::make('Fixed traits (optional)')->schema($traitFields)->columns(3)->collapsed(),
                        Placeholder::make('estimate')
                            ->label('Estimated cost')
                            ->content(fn (Get $get) => $this->estimateLabel(fn (MediaLabService $lab) => $lab->estimateImageRunUsd(
                                array_values((array) $get('profiles')),
                                (int) $get('samples'),
                            ))),
                        Placeholder::make('per_pet_cost')
                            ->label('Per-pet cost per life stage (production models, list price)')
                            ->content(fn () => $this->perPetCostLabel()),
                        Placeholder::make('look_pool')
                            ->label('Free-pet look pool (M4-10)')
                            ->content(fn () => $this->lookPoolLabel()),
                    ])->columns(2),
            ])
            ->statePath('imageData');
    }

    public function videoForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('2 · Image → video')
                    ->description('Animate a finished lab image with one or more video models (fal queue + signed webhook).')
                    ->schema([
                        Select::make('source_result_id')
                            ->label('Lab image')
                            ->options(fn () => $this->completedImageOptions())
                            ->searchable()
                            ->required(),
                        Select::make('state')
                            ->options(collect(PetStateEnum::cases())->mapWithKeys(fn (PetStateEnum $s) => [$s->value => $s->value.' — '.$s->description().$this->speciesSuffix($s)])->all())
                            ->required(),
                        CheckboxList::make('profiles')
                            ->label('Video models')
                            ->options($this->profileOptions(ModelProfile::KIND_VIDEO))
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                // Drop a length the new model set does not share.
                                if (filled($get('duration')) && ! in_array((int) $get('duration'), $this->durationsFor($get), true)) {
                                    $set('duration', null);
                                }
                            }),
                        Select::make('motion_preset')
                            ->label('Motion preset (fills the custom prompt)')
                            ->placeholder('none')
                            ->options(fn () => $this->motionPresetOptions())
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (?string $state, Set $set) => $state !== null ? $set('motion_prompt', $this->motionPresetText($state)) : null),
                        Select::make('duration')
                            ->label('Duration (seconds)')
                            ->placeholder('model default')
                            ->helperText('Only lengths every selected model accepts (fal duration enum).')
                            ->options(fn (Get $get) => collect($this->durationsFor($get))->mapWithKeys(fn (int $s) => [$s => "{$s} s"])->all())
                            ->live(),
                        Textarea::make('motion_prompt')
                            ->label('Custom motion prompt (optional)')
                            ->helperText('Replaces the state motion for this run, e.g. "sits on command, then holds the sit looking up at the camera". The lab still adds the breed look from the image, a static camera and "no people, no hands, no text"; same negative prompt. Never put child data here.')
                            ->maxLength((int) config('media.lab.motion_prompt_max_chars', 500))
                            ->rows(3)
                            ->columnSpanFull(),
                        Placeholder::make('estimate')
                            ->label('Estimated cost')
                            ->content(fn (Get $get) => $this->estimateLabel(fn (MediaLabService $lab) => $lab->estimateVideoRunUsd(
                                array_values((array) $get('profiles')),
                                filled($get('duration')) ? (int) $get('duration') : null,
                            ))),
                    ])->columns(2),
            ])
            ->statePath('videoData');
    }

    public function generateImages(MediaLabService $lab): void
    {
        $data = $this->imageForm->getState();

        $this->runSafely(fn () => $lab->startImageRun(
            $this->admin(),
            (string) $data['breed'],
            (array) ($data['fixed'] ?? []),
            (int) $data['samples'],
            array_values((array) $data['profiles']),
            LifeStage::tryFrom((string) ($data['stage'] ?? '')),
        ), 'Image run queued');
    }

    public function animate(MediaLabService $lab): void
    {
        $data = $this->videoForm->getState();

        $this->runSafely(fn () => $lab->startVideoRun(
            $this->admin(),
            (int) $data['source_result_id'],
            array_values((array) $data['profiles']),
            PetStateEnum::from((string) $data['state']),
            filled($data['motion_prompt'] ?? null) ? (string) $data['motion_prompt'] : null,
            filled($data['duration'] ?? null) ? (int) $data['duration'] : null,
        ), 'Video run queued');
    }

    public function checkPending(int $runId, MediaLabService $lab): void
    {
        $this->admin();

        if (! static::canAccess()) {
            throw new AuthorizationException;
        }

        $run = MediaLabRun::findOrFail($runId);
        $count = $lab->pollPending($run);

        Notification::make()->title($count > 0 ? "Checking {$count} pending video(s)…" : 'Nothing pending')->info()->send();
    }

    /**
     * @return Collection<int, MediaLabRun>
     */
    public function getRuns(): Collection
    {
        return MediaLabRun::query()->with(['results', 'sourceResult'])->latest('id')->limit(15)->get();
    }

    public function hasPending(): bool
    {
        return MediaLabResult::query()
            ->whereIn('status', [MediaLabResult::STATUS_QUEUED, MediaLabResult::STATUS_RUNNING])
            ->where('created_at', '>=', now()->subHour())
            ->exists();
    }

    public function totalLabSpendUsd(): float
    {
        return round((float) MediaLabResult::query()->sum('estimated_cost_usd'), 4);
    }

    private function runSafely(callable $start, string $title): void
    {
        try {
            $run = $start();
        } catch (AiCallException $e) {
            Notification::make()->title('Not started: budget')->body($e->getMessage())->danger()->persistent()->send();

            return;
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Not started')->body($e->getMessage())->warning()->send();

            return;
        }

        Notification::make()
            ->title($title)
            ->body(sprintf('Run #%d · %d call(s) · est. $%.3f', $run->id, $run->results()->count(), $run->estimated_cost_usd))
            ->success()
            ->send();
    }

    private function admin(): User
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->isSuperadmin()) {
            throw new AuthorizationException;
        }

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function profileOptions(string $kind): array
    {
        $options = [];

        foreach (app(MediaProfiles::class)->forLab($kind) as $key => $profile) {
            $unit = $kind === ModelProfile::KIND_VIDEO ? sprintf('%ds video', $profile->durationSeconds) : 'image';
            $lengths = $kind === ModelProfile::KIND_VIDEO ? $this->durationRangeLabel($profile) : '';
            $options[$key] = sprintf('%s — ~$%.3f / %s%s%s', $profile->label, $profile->estimatedCostUsd(), $unit, $lengths, $profile->verified ? '' : ' (unverified)');
        }

        return $options;
    }

    /** " · $0.084/s, 3–15 s" / " · $0.10/s, 4/6/8 s" */
    private function durationRangeLabel(ModelProfile $profile): string
    {
        $d = $profile->supportedDurations();
        $contiguous = count($d) > 2 && end($d) - $d[0] === count($d) - 1;

        return sprintf(' · $%s/s, %s s', rtrim(rtrim(number_format($profile->pricing['usd'], 3), '0'), '.'), $contiguous ? $d[0].'–'.end($d) : implode('/', $d));
    }

    /**
     * @return list<int>
     */
    private function durationsFor(Get $get): array
    {
        return app(MediaLabService::class)->commonDurations(array_values((array) $get('profiles')));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function motionPresetOptions(): array
    {
        $options = [];

        foreach (MediaLabService::motionPresets() as $species => $presets) {
            foreach ($presets as $key => $motion) {
                $options[ucfirst($species)]["{$species}.{$key}"] = $motion;
            }
        }

        return $options;
    }

    private function motionPresetText(string $key): ?string
    {
        [$species, $preset] = array_pad(explode('.', $key, 2), 2, '');

        return MediaLabService::motionPresets()[$species][$preset] ?? null;
    }

    private function estimateLabel(callable $estimate): string
    {
        try {
            $usd = app()->call($estimate);
        } catch (InvalidArgumentException) {
            return '—';
        }

        return sprintf('~$%.3f (lab limit per run $%.2f)', $usd, app(AiSpendGuard::class)->labMaxRunUsd());
    }

    /**
     * Life stages with the species' names (dog: puppy … senior, cat: kitten …).
     *
     * @return array<string, string>
     */
    private function stageOptions(string $breedKey): array
    {
        $species = PetAppearancePrompt::speciesOf($breedKey);

        return collect(LifeStage::ordered())
            ->mapWithKeys(fn (LifeStage $stage) => [$stage->value => $stage->value.' — '.$stage->label($species, 'en')])
            ->all();
    }

    /** " (dogs only)" / " (cats only)" for the behaviour states. */
    private function speciesSuffix(PetStateEnum $state): string
    {
        return match (true) {
            ! $state->appliesTo(Species::Cat) => ' (dogs only)',
            ! $state->appliesTo(Species::Dog) => ' (cats only)',
            default => '',
        };
    }

    /**
     * M4-10: looks per free breed + what a free pet costs with the pool.
     */
    private function lookPoolLabel(): string
    {
        try {
            $pool = app(PetLookPoolService::class);
            $lab = app(MediaLabService::class);
            $counts = $pool->counts();
            $lines = [];

            foreach (BreedType::cases() as $breed) {
                if ($breed->isPremium() || ! PetDnaService::hasAppearance($breed->value)) {
                    continue;
                }

                $fill = $lab->lookPoolFillCostUsd($breed->species());
                $lines[] = sprintf(
                    '%s: %d / %d looks · fill once up to %d × %d stages × ~$%.2f ≈ $%.2f',
                    $breed->value,
                    $counts[$breed->value] ?? 0,
                    $pool->size(),
                    $fill['looks'],
                    $fill['stages'],
                    $fill['per_stage_usd'],
                    $fill['usd'],
                );
            }

            $state = $pool->enabled() ? 'on' : 'OFF (new free pets get a unique look)';

            $shared = sprintf('Paid breeds: %d shared looks (breed + colour, not counted above).', $pool->sharedCount());

            return "Pool {$state}. A free pet whose look already has its stage's media costs ~\$0. ".implode(' | ', $lines).' '.$shared;
        } catch (InvalidArgumentException) {
            return '—';
        }
    }

    private function perPetCostLabel(): string
    {
        try {
            $lab = app(MediaLabService::class);
            $lines = [];

            foreach (Species::cases() as $species) {
                $basic = $lab->perPetCostUsd($species, MediaEntitlementService::TIER_BASIC);
                $full = $lab->perPetCostUsd($species, MediaEntitlementService::TIER_FULL);
                $lines[] = sprintf(
                    '%s: free set 1 image + %d videos ~$%.2f · challenge set 1 image + up to %d videos ~$%.2f',
                    ucfirst($species->value),
                    $basic['videos'],
                    $basic['usd'],
                    $full['videos'],
                    $full['usd'],
                );
            }

            return implode(' | ', $lines);
        } catch (InvalidArgumentException) {
            return '—';
        }
    }

    /**
     * @return array<int, string>
     */
    private function completedImageOptions(): array
    {
        return MediaLabResult::query()
            ->where('kind', MediaLabRun::KIND_IMAGE)
            ->where('status', MediaLabResult::STATUS_COMPLETED)
            ->latest('id')
            ->limit(60)
            ->get()
            ->mapWithKeys(fn (MediaLabResult $r) => [$r->id => sprintf('#%d · run %d · sample %d · %s', $r->id, $r->media_lab_run_id, $r->sample_index + 1, $r->profile)])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function allTraitKeys(): array
    {
        $keys = [];

        foreach ((array) config('breed_appearance', []) as $breed) {
            foreach (array_keys((array) ($breed['traits'] ?? [])) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }
}
