<?php

declare(strict_types=1);

namespace Liberu\Accounting\JournalApprovalsFilament\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Accounting\JournalApprovals\ApprovalAuthority;
use Liberu\Accounting\JournalApprovals\Models\JournalApproval;

final class JournalApprovalResource extends Resource
{
    protected static ?string $model = JournalApproval::class;

    protected static ?string $navigationLabel = 'Journal approvals';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('team_id', app(ApprovalAuthority::class)->selectedTeamId() ?? 0);
    }

    public static function canViewAny(): bool
    {
        return app(ApprovalAuthority::class)->selectedTeamId() !== null;
    }

    public static function canView(Model $record): bool
    {
        $teamId = app(ApprovalAuthority::class)->selectedTeamId();

        return $teamId !== null && (int) $record->getAttribute('team_id') === $teamId;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('approval_ref')->required(), TextInput::make('journal_type')->required(), TextInput::make('journal_source')->required(), TextInput::make('journal_ref')->required(), TextInput::make('preparer_ref')->required(), TextInput::make('currency')->required()->length(3), TextInput::make('amount')->numeric()->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('approval_ref')->searchable(), TextColumn::make('journal_type')->searchable(), TextColumn::make('journal_ref'), TextColumn::make('amount'), TextColumn::make('currency'), TextColumn::make('status')->badge()->sortable(), TextColumn::make('submitted_at')->dateTime()->sortable()])->defaultSort('submitted_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListJournalApprovals::route('/')];
    }
}
