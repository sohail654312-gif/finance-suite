<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ReminderSettings\Pages;

use App\Filament\App\Resources\ReminderSettings\ReminderSettingResource;
use App\Models\ReminderSetting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListReminderSettings extends ListRecords
{
    #[\Override]
    protected static string $resource = ReminderSettingResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('configure')
                ->label('Configure reminders')
                ->visible(fn (): bool => Filament::getTenant() !== null && ! $this->getResource()::getEloquentQuery()->exists())
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    abort_unless($tenant !== null && auth()->user()?->canAccessTenant($tenant) === true, 403);
                    $setting = ReminderSetting::query()->firstOrCreate(
                        ['team_id' => $tenant->getKey()],
                        ['days_before_reminder' => 3, 'reminder_frequency_days' => 7, 'max_reminders' => 3, 'is_active' => false],
                    );
                    $this->redirect($this->getResource()::getUrl('edit', ['record' => $setting]));
                }),
        ];
    }
}
