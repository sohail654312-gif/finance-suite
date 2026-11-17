<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Documentable;
use App\Models\Document;
use App\Models\DocumentVersion;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DocumentService
{
    private function disk(): string
    {
        return (string) config('documents.disk', 'local');
    }

    public function attach(Documentable $owner, UploadedFile $file, ?Carbon $retentionUntil = null): Document
    {
        return DB::transaction(function () use ($owner, $file, $retentionUntil): Document {
            /** @var Document $document */
            $document = $owner->documents()->create([
                'name' => $file->getClientOriginalName(),
                'disk' => $this->disk(),
                'retention_until' => $retentionUntil,
            ]);

            $this->addVersion($document, $file);

            return $document;
        });
    }

    public function addVersion(Document $document, UploadedFile $file): DocumentVersion
    {
        $teamSegment = $document->team_id ?? 'shared';
        $path = $file->store("documents/{$teamSegment}", $document->disk);
        if ($path === false) {
            throw new RuntimeException('Document upload could not be stored.');
        }

        try {
            return DB::transaction(function () use ($document, $file, $path): DocumentVersion {
                // Lock the document row even when it has no versions.
                // PostgreSQL does not allow FOR UPDATE on the aggregate query.
                $locked = Document::query()->lockForUpdate()->findOrFail($document->getKey());
                $next = (int) $locked->versions()->max('version_number') + 1;

                return $locked->versions()->create([
                    'version_number' => $next,
                    'path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize() ?: 0,
                    'uploaded_by' => auth()->id(),
                ]);
            });
        } catch (Throwable $error) {
            Storage::disk($document->disk)->delete($path);
            throw $error;
        }
    }

    public function prune(): int
    {
        $count = 0;
        $expired = Document::query()
            ->whereNotNull('retention_until')
            ->whereDate('retention_until', '<', today())
            ->with('versions')
            ->get();

        foreach ($expired as $document) {
            foreach ($document->versions as $version) {
                Storage::disk($document->disk)->delete($version->path);
            }
            $document->delete();
            $count++;
        }

        return $count;
    }
}
