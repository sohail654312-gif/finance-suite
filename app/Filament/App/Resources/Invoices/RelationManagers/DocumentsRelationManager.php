<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Invoices\RelationManagers;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Invoice;
use App\Services\DocumentService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    #[\Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Invoice && self::belongsToSelectedTenant($ownerRecord)
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    private static function belongsToSelectedTenant(Invoice $invoice): bool
    {
        $tenant = Filament::getTenant();

        return $tenant !== null
            && (string) $invoice->team_id === (string) $tenant->getKey()
            && auth()->user()?->canAccessTenant($tenant) === true;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn ($query) => $query->where('documents.team_id', Filament::getTenant()?->getKey() ?? -1))
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('currentVersion.version_number')
                    ->label('Version'),
                TextColumn::make('retention_until')
                    ->date(),
            ])
            ->headerActions([
                Action::make('attach')
                    ->label('Attach')
                    ->form([
                        FileUpload::make('file')
                            ->storeFiles(false)
                            ->required(),
                        DatePicker::make('retention_until'),
                    ])
                    ->action(function (array $data): void {
                        /** @var UploadedFile $file */
                        $file = $data['file'];
                        /** @var string|null $retentionUntil */
                        $retentionUntil = $data['retention_until'] ?? null;

                        $owner = $this->getOwnerRecord();
                        abort_unless($owner instanceof Invoice && self::belongsToSelectedTenant($owner), 403);

                        app(DocumentService::class)->attach(
                            $owner,
                            $file,
                            filled($retentionUntil) ? Carbon::parse($retentionUntil) : null,
                        );
                    }),
            ])
            ->recordActions([
                Action::make('uploadVersion')
                    ->label('Upload new version')
                    ->form([
                        FileUpload::make('file')
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (Document $record, array $data): void {
                        $this->authorizeDocument($record);
                        /** @var UploadedFile $file */
                        $file = $data['file'];

                        app(DocumentService::class)->addVersion($record, $file);
                    }),
                Action::make('download')
                    ->label('Download')
                    ->action(function (Document $record) {
                        $this->authorizeDocument($record);
                        /** @var DocumentVersion|null $version */
                        $version = $record->currentVersion;

                        if ($version === null) {
                            abort(404);
                        }

                        return Storage::disk($record->disk)->download($version->path, $record->name);
                    }),
            ]);
    }

    private function authorizeDocument(Document $document): void
    {
        $owner = $this->getOwnerRecord();
        abort_unless($owner instanceof Invoice && self::belongsToSelectedTenant($owner)
            && (string) $document->team_id === (string) $owner->team_id
            && $owner->documents()->whereKey($document->getKey())->exists(), 403);
    }
}
