<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    #[\Override]
    protected static string $resource = UserResource::class;

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (array_key_exists('profile_photo_path', $data)) {
            $record->forceFill(['profile_photo_path' => $data['profile_photo_path']]);
            unset($data['profile_photo_path']);
        }

        return parent::handleRecordUpdate($record, $data);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
