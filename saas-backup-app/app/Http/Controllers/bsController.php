<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class bsController extends Controller
{
    public function index()
    {
        return view('bs');
    }

    public function getEnv()
    {
        return response()->json([
            'AWS_ENDPOINT' => env('AWS_ENDPOINT'),
            'AWS_BUCKET' => env('AWS_BUCKET'),
            'AWS_ACCESS_KEY_ID' => env('AWS_ACCESS_KEY_ID'),
            'AWS_SECRET_ACCESS_KEY' => env('AWS_SECRET_ACCESS_KEY'),
            'RESTIC_PASSWORD' => env('RESTIC_PASSWORD', 'garage-restic-secret'),
        ]);
    }

    public function saveEnv(Request $request)
    {
        $path = base_path('.env');
        $content = file_get_contents($path);

        $updates = $request->only(['AWS_ENDPOINT', 'AWS_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'RESTIC_PASSWORD']);

        foreach ($updates as $key => $value) {
            if (empty($value))
                continue;
            // Extremely naive environment replace function
            if (preg_match("/^{$key}=.*/m", $content)) {
                $content = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
            } else {
                $content .= "\n{$key}={$value}";
            }
        }

        file_put_contents($path, $content);
        return response()->json(['success' => true]);
    }

    protected function buildResticEnv()
    {
        return [
            'AWS_ACCESS_KEY_ID' => env('AWS_ACCESS_KEY_ID'),
            'AWS_SECRET_ACCESS_KEY' => env('AWS_SECRET_ACCESS_KEY'),
            'RESTIC_PASSWORD' => env('RESTIC_PASSWORD', 'garage-restic-secret'),
        ];
    }

    protected function getRepo()
    {
        $bucket = env('AWS_BUCKET');
        $endpoint = env('AWS_ENDPOINT');
        $url = str_replace(['http://', 'https://'], ['s3:http://', 's3:https://'], $endpoint);
        return "{$url}/{$bucket}";
    }

    protected function runResticCommand($cmdStr)
    {
        $repo = $this->getRepo();
        $env = $this->buildResticEnv();
        $cmd = "restic -r {$repo} {$cmdStr}";

        $descriptorSpec = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
        $process = proc_open($cmd, $descriptorSpec, $pipes, null, $env);

        $stdout = '';
        $stderr = '';
        if (is_resource($process)) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            proc_close($process);
        }

        return ['stdout' => trim($stdout), 'stderr' => trim($stderr)];
    }

    public function initDatastore()
    {
        $res = $this->runResticCommand('init');
        return response()->json($res);
    }

    public function listSnapshots()
    {
        $res = $this->runResticCommand('snapshots --json');
        if (!empty($res['stdout'])) {
            $data = json_decode($res['stdout'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return response()->json(['success' => true, 'snapshots' => $data]);
            }
        }
        return response()->json(['success' => false, 'error' => $res['stderr'] ?: $res['stdout']]);
    }

    public function takeSnapshot()
    {
        // 1. Dump DB
        $dumpDir = storage_path('app/dr-dumps');
        File::ensureDirectoryExists($dumpDir);
        $dbFile = database_path('database.sqlite');
        $sqlDump = $dumpDir . '/db.sql';
        exec("sqlite3 {$dbFile} .dump > {$sqlDump}");

        // 2. Backup
        $targets = storage_path('app/public') . ' ' . $dumpDir;
        $res = $this->runResticCommand("backup {$targets}");
        return response()->json($res);
    }

    public function restoreSnapshot(Request $request)
    {
        $id = $request->input('id', 'latest');
        $restoreTarget = storage_path('app/dr-restore');
        File::deleteDirectory($restoreTarget);

        $res = $this->runResticCommand("restore {$id} --target {$restoreTarget}");

        // Re-hydrate components recursively
        exec("find {$restoreTarget} -type f -name 'db.sql'", $sqlOutput);
        if (!empty($sqlOutput)) {
            $sqlFile = $sqlOutput[0];
            $dbPath = database_path('database.sqlite');
            exec("sqlite3 {$dbPath} < \"{$sqlFile}\"");
        }

        exec("find {$restoreTarget} -type d -name 'public' | grep 'storage/app/public'", $publicOutput);
        if (!empty($publicOutput)) {
            $publicDir = $publicOutput[0];
            File::copyDirectory($publicDir, storage_path('app/public'));
        }

        File::deleteDirectory($restoreTarget);

        return response()->json(['success' => true, 'output' => $res]);
    }

    public function wipeDatastore()
    {
        // Bonus Proxmox utility simulating "Destroy Datastore" allowing complete clean
        return response()->json($this->runResticCommand('destroy --yes'));
    }
}
