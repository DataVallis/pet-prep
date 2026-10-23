<?php

namespace App\Filament\Pages;

use App\Enums\CatsAvailability;
use App\Enums\UserRole;
use App\Models\AppSettingChange;
use App\Models\User;
use App\Services\AppSettingsService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * Feature switches (M5-R06-09, David 2026-10-10, superadmin
 * only): who may create a NEW cat — off / test families (parents picked by
 * e-mail) / everyone. Stored in app_settings via AppSettingsService
 * (cached ≤ 60 s, busted on save, every change audited in
 * app_setting_changes + the log). Existing cats keep working in every mode.
 * Also: unique vs shared AI media (M5-R21), state media on / off (M5-T08b) and
 * school & game phase clips on / off (M5-T09 / T10 QA M2) and the automatic clip
 * quality check on / off (David 2026-10-23).
 *
 * @property Form $form
 */
class FeatureSwitches extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $slug = 'feature-switches';

    protected static string $view = 'filament.pages.feature-switches';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.configuration');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.features.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.features.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public function mount(AppSettingsService $settings): void
    {
        $this->authorizeAccess();
        $stored = $settings->storedCats();
        $this->form->fill([
            'cats_mode' => $stored['mode']->value,
            'cats_test_parent_ids' => array_map('strval', $stored['test_parent_ids']),
            'unique_pet_media' => $settings->uniquePetMedia(),
            'state_media' => $settings->stateMedia(),
            'phase_clips' => $settings->phaseClips(),
            'quality_check' => $settings->qualityCheckMode(),
        ]);
    }

    public function form(Form $form): Form
    {
        $settings = app(AppSettingsService::class);

        return $form
            ->statePath('data')
            ->schema([
                Section::make(__('admin.features.cats_section'))
                    ->description(__('admin.features.cats_description'))
                    ->schema([
                        Placeholder::make('cats_env_override')
                            ->hiddenLabel()
                            ->visible(fn (): bool => $settings->catsForcedByEnv())
                            ->content(new HtmlString(__('admin.features.env_override'))),
                        Radio::make('cats_mode')
                            ->label(__('admin.features.cats_mode'))
                            ->options(collect(CatsAvailability::cases())->mapWithKeys(fn (CatsAvailability $m): array => [$m->value => $m->label()])->all())
                            ->in(array_map(fn (CatsAvailability $m): string => $m->value, CatsAvailability::cases()))
                            ->required()
                            ->live(),
                        Select::make('cats_test_parent_ids')
                            ->label(__('admin.features.test_parents'))
                            ->multiple()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => self::parentsMatching($search))
                            ->getOptionLabelsUsing(fn (array $values): array => self::parentLabels($values))
                            ->required(fn (Get $get): bool => $get('cats_mode') === CatsAvailability::TestFamilies->value)
                            ->helperText(__('admin.features.test_parents_help')),
                        Placeholder::make('cats_help')
                            ->hiddenLabel()
                            ->content(new HtmlString(__('admin.features.help'))),
                    ]),
                // M5-R21 (David 2026-10-14): unique vs shared AI media of paid breeds.
                Section::make(__('admin.features.media_section'))
                    ->description(__('admin.features.media_description'))
                    ->schema([
                        Toggle::make('unique_pet_media')
                            ->label(__('admin.features.unique_media'))
                            ->default(false),
                    ]),
                // M5-T08b (David 2026-10-21): state key images, loops and transitions.
                Section::make(__('admin.features.state_media_section'))
                    ->description(__('admin.features.state_media_description'))
                    ->schema([
                        Toggle::make('state_media')
                            ->label(__('admin.features.state_media'))
                            ->default(true),
                    ]),
                // M5-T09 / T10 (QA M2, David 2026-10-21): school & game phase clips.
                Section::make(__('admin.features.phase_clips_section'))
                    ->description(__('admin.features.phase_clips_description'))
                    ->schema([
                        Toggle::make('phase_clips')
                            ->label(__('admin.features.phase_clips'))
                            ->default(true),
                    ]),
                // David 2026-10-23: automatic technical + AI vision check of every new clip.
                Section::make(__('admin.features.quality_check_section'))
                    ->description(__('admin.features.quality_check_description'))
                    ->schema([
                        // QA #363 M1: off / shadow (default until calibrated) / enforce.
                        Radio::make('quality_check')
                            ->label(__('admin.features.quality_check'))
                            ->options([
                                AppSettingsService::QUALITY_OFF => __('admin.features.quality_check_off'),
                                AppSettingsService::QUALITY_SHADOW => __('admin.features.quality_check_shadow'),
                                AppSettingsService::QUALITY_ENFORCE => __('admin.features.quality_check_enforce'),
                            ])
                            ->in(AppSettingsService::QUALITY_CHECK_MODES)
                            ->required(),
                    ]),
            ]);
    }

    public function save(AppSettingsService $settings): void
    {
        $this->authorizeAccess();
        $data = $this->form->getState();
        $mode = CatsAvailability::from((string) $data['cats_mode']);
        /** @var list<int|string> $ids */
        $ids = array_values($data['cats_test_parent_ids'] ?? []);

        $stored = $settings->storedCats();
        $storedIds = $stored['test_parent_ids'];
        $newIds = array_values(array_unique(array_map('intval', $ids)));
        sort($newIds);
        $unique = (bool) ($data['unique_pet_media'] ?? false);
        $stateMedia = (bool) ($data['state_media'] ?? AppSettingsService::stateMediaDefault());
        $phaseClips = (bool) ($data['phase_clips'] ?? AppSettingsService::phaseClipsDefault());
        $qualityCheck = (string) ($data['quality_check'] ?? AppSettingsService::qualityCheckDefault());

        try {
            // Each switch is written only when its own fields changed, so saving
            // one switch never adds an audit row (or a no-row default) for the other.
            if ($mode !== $stored['mode'] || $newIds !== $storedIds) {
                $settings->updateCats($this->admin(), $mode, $ids);
            }
            if ($unique !== $settings->uniquePetMedia()) {
                $settings->updateUniquePetMedia($this->admin(), $unique);
            }
            if ($stateMedia !== $settings->stateMedia()) {
                $settings->updateStateMedia($this->admin(), $stateMedia);
            }
            if ($phaseClips !== $settings->phaseClips()) {
                $settings->updatePhaseClips($this->admin(), $phaseClips);
            }
            if ($qualityCheck !== $settings->qualityCheckMode()) {
                $settings->updateQualityCheck($this->admin(), $qualityCheck);
            }
        } catch (InvalidArgumentException $e) {
            Notification::make()->title(__('admin.features.not_saved'))->body($e->getMessage())->danger()->send();

            return;
        }

        $media = $settings->uniquePetMedia() ? __('admin.features.unique_media_on') : __('admin.features.unique_media_off');
        $states = $settings->stateMedia() ? __('admin.features.state_media_on') : __('admin.features.state_media_off');
        $phases = $settings->phaseClips() ? __('admin.features.state_media_on') : __('admin.features.state_media_off');
        Notification::make()->title(__('admin.features.saved'))->body(__('admin.features.saved_body_media', ['mode' => $mode->label(), 'media' => $media])
            .' · '.__('admin.features.saved_body_state_media', ['state' => $states])
            .' · '.__('admin.features.saved_body_phase_clips', ['state' => $phases])
            .' · '.__('admin.features.saved_body_quality_check', ['state' => __('admin.features.quality_check_'.$settings->qualityCheckMode())]))->success()->send();
    }

    /**
     * @return Collection<int, AppSettingChange>
     */
    public function getChanges(): Collection
    {
        return AppSettingChange::with('user:id,name,email')
            ->whereIn('key', [AppSettingsService::CATS_KEY, AppSettingsService::UNIQUE_MEDIA_KEY, AppSettingsService::STATE_MEDIA_KEY, AppSettingsService::PHASE_CLIPS_KEY, AppSettingsService::QUALITY_CHECK_KEY])
            ->latest('id')->limit(20)->get();
    }

    /**
     * @return array<string, string>
     */
    public static function parentsMatching(string $search): array
    {
        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($search))).'%';

        return User::where('role', UserRole::Parent->value)
            ->where(fn ($q) => $q->whereRaw('LOWER(email) LIKE ?', [$term])->orWhereRaw('LOWER(name) LIKE ?', [$term]))
            ->orderBy('email')
            ->limit(20)
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $u): array => [(string) $u->id => self::label($u)])
            ->all();
    }

    /**
     * @param  array<int|string>  $values
     * @return array<string, string>
     */
    public static function parentLabels(array $values): array
    {
        return User::whereIn('id', array_map('intval', $values))
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $u): array => [(string) $u->id => self::label($u)])
            ->all();
    }

    private static function label(User $user): string
    {
        return "{$user->email} ({$user->name}, #{$user->id})";
    }

    private function authorizeAccess(): void
    {
        if (! static::canAccess()) {
            throw new AuthorizationException;
        }
    }

    private function admin(): User
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }
}
