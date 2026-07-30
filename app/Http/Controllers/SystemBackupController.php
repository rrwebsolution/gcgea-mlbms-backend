<?php

namespace App\Http\Controllers;

use App\Models\BackupRecord;
use App\Services\SystemBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SystemBackupController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeBackup($request);

        return response()->json(
            BackupRecord::latest()->get()->map(fn (BackupRecord $record) => $this->resource($record))
        );
    }

    public function store(Request $request, SystemBackupService $service)
    {
        $this->authorizeBackup($request);

        try {
            return response()->json(
                $this->resource($service->create('Manual', $request->user()->full_name)),
                201
            );
        } catch (\Throwable $error) {
            report($error);

            return response()->json([
                'message' => 'Backup creation failed: '.$this->safeFailureMessage($error->getMessage()),
            ], 422);
        }
    }

    public function download(Request $request, BackupRecord $backup)
    {
        $this->authorizeBackup($request);
        abort_unless($backup->status === 'Completed' && Storage::disk('local')->exists($backup->path), 404);

        return Storage::disk('local')->download($backup->path, $backup->name);
    }

    public function destroy(Request $request, BackupRecord $backup, SystemBackupService $service)
    {
        $this->authorizeBackup($request);
        $service->delete($backup);

        return response()->json(['message' => 'Backup deleted.']);
    }

    private function authorizeBackup(Request $request): void
    {
        if (! $request->user()->hasPermission('settings.backup')) {
            abort(403, "You don't have permission to manage system backups.");
        }
    }

    private function resource(BackupRecord $record): array
    {
        return [
            'id' => (string) $record->id,
            'name' => $record->name,
            'date' => $record->created_at?->toIso8601String(),
            'type' => $record->type,
            'size' => $this->formatBytes($record->size_bytes),
            'status' => $record->status,
            'createdBy' => $record->created_by ?? 'System',
            'includesAttachments' => $record->includes_attachments,
            'errorMessage' => $record->error_message,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        return number_format(max($bytes, 0) / 1024, 1).' KB';
    }

    private function safeFailureMessage(string $message): string
    {
        if (str_contains($message, 'pg_dump')) {
            return 'PostgreSQL could not create the database archive. Verify the configured database credentials and PG_DUMP_PATH.';
        }

        return 'The server could not write the backup file. Check the backup history for details.';
    }
}
