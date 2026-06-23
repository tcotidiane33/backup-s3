<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DrListCommand extends Command
{
    protected $signature = 'dr:list';
    protected $description = 'Lists all immutable Restic block snapshots in Garage';

    public function handle()
    {
        $env = [
            'AWS_ACCESS_KEY_ID' => env('AWS_ACCESS_KEY_ID'),
            'AWS_SECRET_ACCESS_KEY' => env('AWS_SECRET_ACCESS_KEY'),
            'RESTIC_PASSWORD' => env('RESTIC_PASSWORD', 'garage-restic-secret'),
        ];

        $bucket = env('AWS_BUCKET');
        $endpoint = env('AWS_ENDPOINT');
        $repo = str_replace(['http://', 'https://'], ['s3:http://', 's3:https://'], $endpoint) . "/{$bucket}";

        $descriptorSpec = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
        $process = proc_open("restic -r {$repo} snapshots", $descriptorSpec, $pipes, null, $env);

        if (is_resource($process)) {
            echo stream_get_contents($pipes[1]);
            echo stream_get_contents($pipes[2]);
            proc_close($process);
        }
    }
}
