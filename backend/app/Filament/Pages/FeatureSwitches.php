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
 * "Funkcije" / feature switches (M5-R06-09, David 2026-10-10, superadmin
 * only): who may create a NEW cat — off / test families (parents picked by
 * e-mail) / everyone. Stored in app_settings via AppSettingsService
 * (cached ≤ 60 s, busted on save, every change audited in
 * app_setting_changes + the log). Existing cats keep working in every mode.
 *
 * @property Form $form
 */
class FeatureSwitches extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Funkcije';

    protected static ?string $title = 'Funkcije (feature switches)';

    protected static ?string $slug = 'feature-switches';

    protected static string $view = 'filament.pages.feature-switches';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

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
        ]);
    }

    public function form(Form $form): Form
    {
        $settings = app(AppSettingsService::class);

        return $form
            ->statePath('data')
            ->schema([
                Section::make('Mačke')
                    ->description('Kdo lahko ustvari NOVO mačko. Obstoječe mačke delujejo v vsakem načinu (izklop ustavi samo nove mačke).')
                    ->schema([
                        Placeholder::make('cats_env_override')
                            ->hiddenLabel()
                            ->visible(fn (): bool => $settings->catsForcedByEnv())
                            ->content(new HtmlString('<strong>Strežnik ima PETPREP_CATS_ENABLED=true</strong> — mačke so vklopljene za vse ne glede na to izbiro. Za testne družine odstrani vrstico iz /opt/petprep/.env in ponovno zaženi PHP vsebnike.')),
                        Radio::make('cats_mode')
                            ->label('Mačke')
                            ->options(collect(CatsAvailability::cases())->mapWithKeys(fn (CatsAvailability $m): array => [$m->value => $m->label()])->all())
                            ->in(array_map(fn (CatsAvailability $m): string => $m->value, CatsAvailability::cases()))
                            ->required()
                            ->live(),
                        Select::make('cats_test_parent_ids')
                            ->label('Testni starši (družina je testna, če je v njej kateri od teh staršev)')
                            ->multiple()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => self::parentsMatching($search))
                            ->getOptionLabelsUsing(fn (array $values): array => self::parentLabels($values))
                            ->required(fn (Get $get): bool => $get('cats_mode') === CatsAvailability::TestFamilies->value)
                            ->helperText('Poišči starševski račun po e-pošti ali imenu.'),
                        Placeholder::make('cats_help')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                'Mačke vidi samo nova različica aplikacije (CAT_UI_READY, pošlje <code>species_cat</code>) — tudi otrokov telefon mora imeti novo različico. '
                                .'Po spremembi naj uporabnik aplikacijo popolnoma zapre in znova odpre (katalog pasem je v aplikaciji shranjen 1 uro); strežnik novo nastavitev upošteva najkasneje v 1 minuti.'
                            )),
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

        try {
            $settings->updateCats($this->admin(), $mode, $ids);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Ni shranjeno')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Shranjeno')->body("Mačke: {$mode->label()}")->success()->send();
    }

    /**
     * @return Collection<int, AppSettingChange>
     */
    public function getChanges(): Collection
    {
        return AppSettingChange::with('user:id,name,email')->latest('id')->limit(20)->get();
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
