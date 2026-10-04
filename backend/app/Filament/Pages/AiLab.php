<?php

namespace App\Filament\Pages;

use App\Enums\PetStateEnum;
use App\Filament\Widgets\AiSpendOverview;
use App\Models\MediaLabResult;
use App\Models\MediaLabRun;
use App\Models\User;
use App\Services\Media\AiCallException;
use App\Services\Media\MediaLabService;
use App\Services\Media\MediaProfiles;
use App\Services\Media\ModelProfile;
use App\Services\Media\PetDnaService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * AI Lab (M4-02, superadmin only): compare fal.ai image / video model profiles
 * on DNA v2 prompts. Every call passes the spend caps (M4-07). No child data.
 *
 * @property Form $imageForm
 * @property Form $videoForm
 */
class AiLab extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'AI media';

    protected static ?string $navigationLabel = 'AI Lab';

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
                        Section::make('Fixed traits (optional)')->schema($traitFields)->columns(3)->collapsed(),
                        Placeholder::make('estimate')
                            ->label('Estimated cost')
                            ->content(fn (Get $get) => $this->estimateLabel(fn (MediaLabService $lab) => $lab->estimateImageRunUsd(
                                array_values((array) $get('profiles')),
                                (int) $get('samples'),
                            ))),
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
                            ->options(collect(PetStateEnum::cases())->mapWithKeys(fn (PetStateEnum $s) => [$s->value => $s->value.' — '.$s->description()])->all())
                            ->required(),
                        CheckboxList::make('profiles')
                            ->label('Video models')
                            ->options($this->profileOptions(ModelProfile::KIND_VIDEO))
                            ->required()
                            ->live(),
                        Placeholder::make('estimate')
                            ->label('Estimated cost')
                            ->content(fn (Get $get) => $this->estimateLabel(fn (MediaLabService $lab) => $lab->estimateVideoRunUsd(
                                array_values((array) $get('profiles')),
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
        ), 'Video run queued');
    }

    public function checkPending(int $runId, MediaLabService $lab): void
    {
        $this->admin();
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
            $options[$key] = sprintf('%s — ~$%.3f / %s%s', $profile->label, $profile->estimatedCostUsd(), $unit, $profile->verified ? '' : ' (unverified)');
        }

        return $options;
    }

    private function estimateLabel(callable $estimate): string
    {
        try {
            $usd = app()->call($estimate);
        } catch (InvalidArgumentException) {
            return '—';
        }

        return sprintf('~$%.3f (lab limit per run $%.2f)', $usd, (float) config('media.lab.max_run_usd', 3));
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
