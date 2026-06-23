<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DrInitCommand extends Command
{
    protected $signature = 'dr:init';
    protected $description = 'Initializes the Restic S3 repository using Garage env keys';

    public function handle()
    {
        $this->info("Initializing Restic on Garage S3...");

        $env = [
            'AWS_ACCESS_KEY_ID' => env('AWS_ACCESS_KEY_ID'),
            'AWS_SECRET_ACCESS_KEY' => env('AWS_SECRET_ACCESS_KEY'),
            'RESTIC_PASSWORD' => env('RESTIC_PASSWORD', 'garage-restic-secret'),
        ];

        $bucket = env('AWS_BUCKET');
        $endpoint = env('AWS_ENDPOINT');

        // Ensure endpoint handles s3 schema
        $url = str_replace(['http://', 'https://'], ['s3:http://', 's3:https://'], $endpoint);
        $repo = "{$url}/{$bucket}";

        $descriptorSpec = [
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"],  // stderr
        ];

        $process = proc_open("restic -r {$repo} init", $descriptorSpec, $pipes, null, $env);

        if (is_resource($process)) {
            $this->info(stream_get_contents($pipes[1]));
            $this->error(stream_get_contents($pipes[2]));
            proc_close($process);
        }

        $this->info("Restic repository init completed!");
    }
}
