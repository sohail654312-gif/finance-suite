<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Team;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateUser extends CreateRecord
{
    #[\Override]
    protected static string $resource = UserResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();
        abort_if($tenant === null || (string) $tenant->getKey() !== (string) getPermissionsTeamId(), 403);
        $team = Team::query()->findOrFail($tenant->getKey());
        Gate::authorize('addTeamMember', $team);

        $photo = $data['profile_photo_path'] ?? null;
        unset($data['profile_photo_path']);

        return DB::transaction(function () use ($data, $photo, $team): Model {
            $record = parent::handleRecordCreation($data);
            $team->users()->attach($record->getKey(), ['role' => 'viewer']);
            $record->forceFill(['current_team_id' => $team->getKey()]);
            if ($photo !== null) {
                $record->forceFill(['profile_photo_path' => $photo]);
            }
            $record->save();

            return $record;
        });
    }
}
