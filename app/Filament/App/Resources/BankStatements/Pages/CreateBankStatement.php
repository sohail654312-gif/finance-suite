<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\BankStatements\Pages;

use App\Filament\App\Resources\BankStatements\BankStatementResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateBankStatement extends CreateRecord
{
    #[\Override]
    protected static string $resource = BankStatementResource::class;

    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        abort_if($tenant === null, 403);
        $data['team_id'] = $tenant->getKey();

        return $data;
    }
}
