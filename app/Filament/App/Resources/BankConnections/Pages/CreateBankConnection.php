<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\BankConnections\Pages;

use App\Filament\App\Resources\BankConnections\BankConnectionResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateBankConnection extends CreateRecord
{
    #[\Override]
    protected static string $resource = BankConnectionResource::class;

    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $tenant = Filament::getTenant();
        abort_if($tenant === null, 403);
        $data['team_id'] = $tenant->getKey();

        return $data;
    }
}
