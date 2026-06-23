<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use Illuminate\Support\Facades\File;

class RestoreBackup extends Command
{
    protected $signature = 'backup:restore-latest';
    protected $description = 'Restores the latest backup from the S3 disk';

    public function handle()
    {
        $this->info("Fetching latest backup from Garage S3...");
        $disk = Storage::disk('s3');

        $appName = env('APP_NAME', 'Laravel');
        // Try exact config name or fallback mapping
        $directories = $disk->directories();
        // Just pull all files, it's a test bucket anyway
        $files = $disk->allFiles('');

        $zipFiles = collect($files)->filter(fn($f) => str_ends_with($f, '.zip'))->values();

        if ($zipFiles->isEmpty()) {
            $this->error("No backups found in Garage S3 bucket.");
            return;
        }

        // Get latest file by sorting (spatie uses timestamps in filenames usually)
        $latestFile = $zipFiles->last();

        $this->info("Downloading {$latestFile}...");
        $tempZip = storage_path('app/backup-temp.zip');
        file_put_contents($tempZip, $disk->get($latestFile));

        $this->info("Extracting archive...");
        $extractTo = storage_path('app/backup-extract');
        File::deleteDirectory($extractTo);
        File::makeDirectory($extractTo, 0755, true);

        $zip = new ZipArchive;
        if ($zip->open($tempZip) === TRUE) {
            $zip->extractTo($extractTo);
            $zip->close();
        } else {
            $this->error("Failed to extract zip.");
            return;
        }

        // Restore DB (SQLite)
        $this->info("Restoring database from SQL dump...");
        $dbDump = collect(File::allFiles($extractTo . '/db-dumps'))->first();
        if ($dbDump) {
            $dbPath = database_path('database.sqlite');
            // Execute sqlite3 to import the SQL dump
            $dumpFile = $dbDump->getRealPath();
            exec("sqlite3 {$dbPath} < \"{$dumpFile}\"");
            $this->info("Database restored from {$dbDump->getFilename()}!");
        } else {
            $this->warn("No database dump found in the archive.");
        }

        // Restore public files
        $this->info("Restoring public files...");
        exec("find {$extractTo} -type d -name 'public' | grep 'storage/app/public'", $output);
        if (!empty($output)) {
            $srcPublic = $output[0];
            File::copyDirectory($srcPublic, storage_path('app/public'));
            $this->info("Public user uploads restored!");
        } else {
            $this->warn("Could not easily locate storage/app/public inside zip. Skipping files.");
        }

        // Cleanup
        $this->info("Cleaning up temporary files...");
        File::delete($tempZip);
        File::deleteDirectory($extractTo);

        $this->info('Restore completed successfully. Your Garage backup is live.');
    }
}
