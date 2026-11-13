<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Schemas;

use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Liberu\Foundation\RolesPermissions\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('User Management')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Basic Information')
                            ->schema([
                                Section::make('Personal Details')
                                    ->description('Basic user information and profile settings')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('name')
                                            ->required()
                                            ->maxLength(255)
                                            ->columnSpanFull()
                                            ->placeholder('Enter full name'),

                                        TextInput::make('email')
                                            ->label('Email Address')
                                            ->email()
                                            ->required()
                                            ->unique(ignoreRecord: true)
                                            ->maxLength(255)
                                            ->placeholder('user@example.com'),

                                        TextInput::make('password')
                                            ->password()
                                            ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                                            ->dehydrated(fn ($state): bool => filled($state))
                                            ->required(fn (string $context): bool => $context === 'create')
                                            ->maxLength(255)
                                            ->placeholder('Enter password')
                                            ->helperText('Leave blank to keep current password (when editing)'),

                                        FileUpload::make('profile_photo_path')
                                            ->label('Profile Photo')
                                            ->image()
                                            ->preventFilePathTampering()
                                            ->imageEditor()
                                            ->maxSize(2048)
                                            ->directory('profile-photos')
                                            ->columnSpanFull()
                                            ->helperText('Upload a profile photo (max 2MB)'),
                                    ]),
                            ]),

                        Tab::make('Roles & Permissions')
                            ->schema([
                                Section::make('Role Assignment')
                                    ->description('Assign roles to control user access and permissions')
                                    ->schema([
                                        Select::make('roles')
                                            ->relationship('roles', 'name', modifyQueryUsing: static fn (Builder $query): Builder => self::assignableRoles($query))
                                            ->pivotData(static fn (): array => ['team_id' => getPermissionsTeamId()])
                                            ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                                if (! is_array($value) || ! array_is_list($value)) {
                                                    $fail('Select valid roles for the current team.');

                                                    return;
                                                }
                                                foreach ($value as $id) {
                                                    if ((! is_int($id) && ! is_string($id))
                                                        || ! self::assignableRoles(Role::query())->whereKey($id)->exists()) {
                                                        $fail('Select valid roles for the current team.');

                                                        return;
                                                    }
                                                }
                                            })
                                            ->multiple()
                                            ->preload()
                                            ->searchable()
                                            ->placeholder('Select roles')
                                            ->helperText('Users inherit all permissions from their assigned roles')
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                    ]),
            ]);
    }

    private static function assignableRoles(Builder $query): Builder
    {
        $teamId = getPermissionsTeamId();
        if ($teamId === null || (string) $teamId !== (string) Filament::getTenant()?->getKey()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('roles.guard_name', 'web')
            ->where(fn (Builder $query): Builder => $query->where('roles.team_id', $teamId)->orWhereNull('roles.team_id'))
            ->when(! Filament::auth()->user()?->hasRole('super_admin'),
                fn (Builder $query): Builder => $query->where('roles.name', '<>', 'super_admin'));
    }
}
