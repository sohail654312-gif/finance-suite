<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ReminderSettings;

use App\Filament\App\Resources\ReminderSettings\Pages\EditReminderSetting;
use App\Filament\App\Resources\ReminderSettings\Pages\ListReminderSettings;
use App\Models\ReminderSetting;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReminderSettingResource extends Resource
{
    #[\Override]
    protected static ?string $model = ReminderSetting::class;

    protected static bool $isScopedToTenant = false;

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('team_id', Filament::getTenant()?->getKey() ?? -1);
    }

    #[\Override]
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    #[\Override]
    protected static ?string $navigationLabel = 'Reminder Settings';

    #[\Override]
    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    #[\Override]
    protected static ?int $navigationSort = 1;

    #[\Override]
    public static function getNavigationGroup(): ?string
    {
        return static::$navigationGroup;
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('days_before_reminder')
                    ->label('Days Before First Reminder')
                    ->integer()
                    ->required()
                    ->minValue(1)
                    ->helperText('Number of days after invoice date before sending first reminder'),

                TextInput::make('reminder_frequency_days')
                    ->label('Days Between Reminders')
                    ->integer()
                    ->required()
                    ->minValue(1)
                    ->helperText('Number of days to wait between reminders'),

                TextInput::make('max_reminders')
                    ->label('Maximum Number of Reminders')
                    ->integer()
                    ->required()
                    ->minValue(1)
                    ->helperText('Maximum number of reminders to send per invoice'),

                Toggle::make('is_active')
                    ->label('Enable Reminders')
                    ->required()
                    ->helperText('Toggle the reminder system on/off'),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('days_before_reminder')
                    ->label('Days Before First Reminder')
                    ->sortable(),
                TextColumn::make('reminder_frequency_days')
                    ->label('Reminder Frequency (Days)')
                    ->sortable(),
                TextColumn::make('max_reminders')
                    ->label('Max Reminders')
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([EditAction::make()]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListReminderSettings::route('/'),
            'edit' => EditReminderSetting::route('/{record}/edit'),
        ];
    }
}
