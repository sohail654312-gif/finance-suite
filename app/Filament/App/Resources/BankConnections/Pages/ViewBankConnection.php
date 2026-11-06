<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\BankConnections\Pages;

use App\Filament\App\Resources\BankConnections\BankConnectionResource;
use App\Jobs\SyncPlaidTransactionsJob;
use App\Models\BankConnection;
use App\Services\PlaidService;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

/**
 * @property-read BankConnection $record
 */
class ViewBankConnection extends ViewRecord
{
    #[\Override]
    protected static string $resource = BankConnectionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Action::make('sync_transactions')
                ->label('Sync Transactions')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn (): bool => $this->record->plaid_item_id !== null)
                ->action(function (): void {
                    try {
                        $plaidService = app(PlaidService::class);
                        $job = new SyncPlaidTransactionsJob((int) $this->record->getKey());
                        $job->handle($plaidService);
                        $result = $job->summary;

                        $addedCount = $result['added'];
                        $modifiedCount = $result['modified'];
                        $removedCount = $result['removed'];

                        Notification::make()
                            ->title('Transactions Synced Successfully')
                            ->body("Added: {$addedCount}, Modified: {$modifiedCount}, Removed: {$removedCount}")
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Sync Failed')
                            ->body('Transactions could not be synchronized. Review the connection and retry.')
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
