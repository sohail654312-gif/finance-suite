<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Models\ForecastScenario;
use App\Services\ForecastService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Pick a forecast scenario + period count and compare the rolling baseline
 * against the scenario-adjusted projection (net income per period).
 */
/**
 * @property-read Schema $form
 */
class ForecastComparison extends Page
{
    #[\Override]
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    #[\Override]
    protected static ?string $title = 'Forecast Comparison';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    #[\Override]
    protected string $view = 'filament.app.pages.forecast-comparison';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function mount(): void
    {
        $this->form->fill(['periods' => 3]);
    }

    /**
     * Scenarios belonging to the current tenant team only.
     *
     * @return array<int, string>
     */
    public function visibleScenarios(): array
    {
        return ForecastScenario::where('team_id', $this->selectedTeamId() ?? -1)->pluck('name', 'id')->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('forecast_scenario_id')
                    ->label('Scenario')
                    ->options(fn (): array => $this->visibleScenarios())
                    ->required(),
                TextInput::make('periods')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(120)
                    ->default(3)
                    ->required(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Group::make([
                EmbeddedSchema::make('form'),
                Actions::make([
                    Action::make('generate')
                        ->label('Generate')
                        ->action(fn () => $this->generate()),
                ]),
            ]),
        ]);
    }

    public function generate(): void
    {
        $this->result = null;
        $teamId = $this->selectedTeamId();
        if ($teamId === null) {
            return;
        }
        $data = Validator::make(['data' => $this->data ?? []], [
            'data.forecast_scenario_id' => ['required', 'integer', 'min:1'],
            'data.periods' => ['required', 'integer', 'between:1,120'],
        ])->validate()['data'];
        $scenario = ForecastScenario::where('team_id', $teamId)->find($data['forecast_scenario_id']);
        if ($scenario !== null) {
            $this->result = app(ForecastService::class)->compare((int) $data['periods'], $scenario, $teamId);
        }
    }

    private function selectedTeamId(): ?int
    {
        $tenant = Filament::getTenant();

        return $tenant !== null && auth()->user()?->canAccessTenant($tenant) === true
            ? (int) $tenant->getKey() : null;
    }
}
