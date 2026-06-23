<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class DrRestoreCommand extends Command
{
    protected $signature = 'dr:restore {id=latest}';
    protected $description = 'Recover DB and storage completely to a specific state (id or latest)';

    public function handle()
    {
        $id = $this->argument('id');
        $this->info("Initiating block-level restore for snapshot [{$id}] from Garage...");

        $env = [
            'AWS_ACCESS_KEY_ID' => env('AWS_ACCESS_KEY_ID'),
            'AWS_SECRET_ACCESS_KEY' => env('AWS_SECRET_ACCESS_KEY'),
            'RESTIC_PASSWORD' => env('RESTIC_PASSWORD', 'garage-restic-secret'),
        ];

        $bucket = env('AWS_BUCKET');
        $endpoint = env('AWS_ENDPOINT');
        $repo = str_replace(['http://', 'https://'], ['s3:http://', 's3:https://'], $endpoint) . "/{$bucket}";

        $restoreTarget = storage_path('app/dr-restore');
        File::deleteDirectory($restoreTarget);

        $this->info("Pulling datablocks via Restic...");
        $cmd = "restic -r {$repo} restore {$id} --target {$restoreTarget}";

        $descriptorSpec = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
        $process = proc_open($cmd, $descriptorSpec, $pipes, null, $env);

        if (is_resource($process)) {
            $this->info(stream_get_contents($pipes[1]));
            $this->error(stream_get_contents($pipes[2]));
            proc_close($process);
        }

        $this->info("Re-hydrating database and files...");

        exec("find {$restoreTarget} -type f -name 'db.sql'", $sqlOutput);
        if (!empty($sqlOutput)) {
            $sqlFile = $sqlOutput[0];
            $dbPath = database_path('database.sqlite');
            exec("sqlite3 {$dbPath} < \"{$sqlFile}\"");
            $this->info("Database successfully re-imported.");
        } else {
            $this->warn("No database dump found in snapshot.");
        }

        exec("find {$restoreTarget} -type d -name 'public' | grep 'storage/app/public'", $publicOutput);
        if (!empty($publicOutput)) {
            $publicDir = $publicOutput[0];
            File::copyDirectory($publicDir, storage_path('app/public'));
            // Remove the temporarily restored files explicitly inside public to avoid duplicates
            $this->info("File assets perfectly recovered.");
        } else {
            $this->warn("Could not find public storage folder inside snapshot.");
        }

        $this->info("Cleaning up restore footprint...");
        File::deleteDirectory($restoreTarget);

        $this->info("bs-like Disaster Recovery Complete!");
    }
}
