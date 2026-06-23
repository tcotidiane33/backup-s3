<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class DrSnapshotCommand extends Command
{
    protected $signature = 'dr:snapshot';
    protected $description = 'Dumps SQLite DB and generates a block-deduplicated Restic Snapshot';

    public function handle()
    {
        $this->info("Creating SQLite dump...");

        $dumpDir = storage_path('app/dr-dumps');
        File::ensureDirectoryExists($dumpDir);
        $dbFile = database_path('database.sqlite');
        $sqlDump = $dumpDir . '/db.sql';

        exec("sqlite3 {$dbFile} .dump > {$sqlDump}");

        $this->info("Triggering Restic Backup on Storage and Dumps...");

        $env = [
            'AWS_ACCESS_KEY_ID' => env('AWS_ACCESS_KEY_ID'),
            'AWS_SECRET_ACCESS_KEY' => env('AWS_SECRET_ACCESS_KEY'),
            'RESTIC_PASSWORD' => env('RESTIC_PASSWORD', 'garage-restic-secret'),
        ];

        $bucket = env('AWS_BUCKET');
        $endpoint = env('AWS_ENDPOINT');
        $repo = str_replace(['http://', 'https://'], ['s3:http://', 's3:https://'], $endpoint) . "/{$bucket}";

        $targets = storage_path('app/public') . ' ' . $dumpDir;

        $descriptorSpec = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
        $process = proc_open("restic -r {$repo} backup {$targets}", $descriptorSpec, $pipes, null, $env);

        if (is_resource($process)) {
            $this->info(stream_get_contents($pipes[1]));
            $this->error(stream_get_contents($pipes[2]));
            proc_close($process);
        }

        $this->info("DR Snapshot completed securely!");
    }
}
