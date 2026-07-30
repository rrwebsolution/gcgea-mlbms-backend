<?php

namespace App\Services;

use App\Models\BackupRecord;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class SystemBackupService
{
    public function create(string $type, string $createdBy): BackupRecord
    {
        $settings = $this->settings();
        $stamp = now()->format('Y-m-d_His');
        $baseName = "gcgea-mlbms-".strtolower($type)."-{$stamp}";
        $directory = Storage::disk('local')->path('backups');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $dumpPath = "{$directory}/{$baseName}.dump";
        $finalPath = $settings['includeAttachments'] ? "{$directory}/{$baseName}.zip" : $dumpPath;
        $record = BackupRecord::create([
            'name' => basename($finalPath),
            'path' => 'backups/'.basename($finalPath),
            'type' => $type,
            'status' => 'Processing',
            'created_by' => $createdBy,
            'includes_attachments' => $settings['includeAttachments'],
        ]);

        try {
            try {
                $this->dumpDatabase($dumpPath);
            } catch (\Throwable) {
                @unlink($dumpPath);
                $dumpPath = "{$directory}/{$baseName}.json";
                $this->exportPortableDatabase($dumpPath);
                if (! $settings['includeAttachments']) {
                    $finalPath = $dumpPath;
                    $record->update([
                        'name' => basename($finalPath),
                        'path' => 'backups/'.basename($finalPath),
                    ]);
                }
            }
            if ($settings['includeAttachments']) {
                $this->zipBackup($dumpPath, $finalPath);
                @unlink($dumpPath);
            }
            $record->update([
                'size_bytes' => filesize($finalPath) ?: 0,
                'status' => 'Completed',
            ]);
            $this->prune($settings['retentionDays']);
        } catch (\Throwable $error) {
            @unlink($dumpPath);
            @unlink($finalPath);
            $record->update(['status' => 'Failed', 'error_message' => $error->getMessage()]);
            throw $error;
        }

        return $record->fresh();
    }

    public function runAutomaticIfDue(): ?BackupRecord
    {
        $settings = $this->settings();
        if (! $settings['automaticBackup']) {
            return null;
        }

        $last = BackupRecord::where('type', 'Automatic')->where('status', 'Completed')->latest()->first();
        $dueAt = match ($settings['backupFrequency']) {
            'Hourly' => $last?->created_at->addHour(),
            'Weekly' => $last?->created_at->addWeek(),
            'Monthly' => $last?->created_at->addMonth(),
            default => $last?->created_at->addDay(),
        };
        if ($dueAt?->isFuture()) {
            return null;
        }

        return $this->create('Automatic', 'System');
    }

    public function delete(BackupRecord $record): void
    {
        Storage::disk('local')->delete($record->path);
        $record->delete();
    }

    private function dumpDatabase(string $outputPath): void
    {
        if (config('database.default') !== 'pgsql') {
            throw new RuntimeException('Automated full backups currently require PostgreSQL.');
        }

        $connection = config('database.connections.pgsql');
        $binary = env('PG_DUMP_PATH') ?: $this->findPgDump();
        $passwordFile = tempnam(sys_get_temp_dir(), 'gcgea-pgpass-');
        if ($passwordFile === false) {
            throw new RuntimeException('Unable to create a temporary PostgreSQL credentials file.');
        }

        $escape = fn (string $value) => str_replace(['\\', ':'], ['\\\\', '\\:'], $value);
        file_put_contents($passwordFile, implode(':', [
            $escape((string) $connection['host']),
            $escape((string) $connection['port']),
            $escape((string) $connection['database']),
            $escape((string) $connection['username']),
            $escape((string) $connection['password']),
        ]).PHP_EOL);

        try {
            $process = new Process([
                $binary,
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--no-password',
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--username='.$connection['username'],
                '--file='.$outputPath,
                $connection['database'],
            ], base_path(), ['PGPASSFILE' => $passwordFile]);
            $process->setTimeout(600);
            $process->mustRun();
        } finally {
            @unlink($passwordFile);
        }
    }

    private function findPgDump(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $matches = glob('C:/Program Files/PostgreSQL/*/bin/pg_dump.exe') ?: [];
            rsort($matches, SORT_NATURAL);
            if ($matches !== []) {
                return $matches[0];
            }
        }

        return 'pg_dump';
    }

    private function zipBackup(string $dumpPath, string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the backup archive.');
        }
        $zip->addFile($dumpPath, str_ends_with($dumpPath, '.json') ? 'database.json' : 'database.dump');
        $publicRoot = Storage::disk('public')->path('');
        if (is_dir($publicRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($publicRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $zip->addFile($file->getPathname(), 'attachments/'.str_replace('\\', '/', substr($file->getPathname(), strlen($publicRoot))));
                }
            }
        }
        $zip->close();
    }

    private function exportPortableDatabase(string $outputPath): void
    {
        $tables = collect(DB::select(
            "select tablename from pg_catalog.pg_tables where schemaname = 'public' order by tablename"
        ))->pluck('tablename');
        $handle = fopen($outputPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create the portable database backup.');
        }

        try {
            fwrite($handle, '{"format":"gcgea-portable-database-v1","createdAt":'.json_encode(now()->toIso8601String()).',"tables":{');
            foreach ($tables as $index => $table) {
                if ($index > 0) {
                    fwrite($handle, ',');
                }
                fwrite($handle, json_encode($table).':[');
                $firstRow = true;
                DB::table($table)->orderByRaw('1')->chunk(500, function ($rows) use ($handle, &$firstRow): void {
                    foreach ($rows as $row) {
                        if (! $firstRow) {
                            fwrite($handle, ',');
                        }
                        fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $firstRow = false;
                    }
                });
                fwrite($handle, ']');
            }
            fwrite($handle, '}}');
        } finally {
            fclose($handle);
        }
    }

    private function prune(int $retentionDays): void
    {
        BackupRecord::where('created_at', '<', now()->subDays($retentionDays))->get()
            ->each(fn (BackupRecord $record) => $this->delete($record));
    }

    private function settings(): array
    {
        return array_replace([
            'automaticBackup' => true,
            'backupFrequency' => 'Daily',
            'retentionDays' => 30,
            'includeAttachments' => false,
        ], SystemSetting::where('section', 'backup')->first()?->value ?? []);
    }
}
