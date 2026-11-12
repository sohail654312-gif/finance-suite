<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateUser extends CreateRecord
{
    #[\Override]
    protected static string $resource = UserResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $photo = $data['profile_photo_path'] ?? null;
        unset($data['profile_photo_path']);

        return DB::transaction(function () use ($data, $photo): Model {
            $record = parent::handleRecordCreation($data);
            if ($photo !== null) {
                $record->forceFill(['profile_photo_path' => $photo])->save();
            }

            return $record;
        });
    }
}
