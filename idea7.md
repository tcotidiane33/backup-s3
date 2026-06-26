## Notifications temps réel avec Laravel Echo + Reverb

### 1. Installation

```bash
# Reverb (WebSocket server officiel Laravel)
php artisan install:broadcasting

# Ou manuellement
composer require laravel/reverb
php artisan reverb:install

# Echo côté front
npm install --save-dev laravel-echo pusher-js
```

---

### 2. Configuration

**`.env` :**

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=my-app-id
REVERB_APP_KEY=my-app-key
REVERB_APP_SECRET=my-app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

**`config/broadcasting.php` :**

```php
'connections' => [
    'reverb' => [
        'driver'  => 'reverb',
        'key'     => env('REVERB_APP_KEY'),
        'secret'  => env('REVERB_APP_SECRET'),
        'app_id'  => env('REVERB_APP_ID'),
        'options' => [
            'host'   => env('REVERB_HOST', 'localhost'),
            'port'   => env('REVERB_PORT', 8080),
            'scheme' => env('REVERB_SCHEME', 'http'),
        ],
    ],
],
```

**`resources/js/echo.js` :**

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster:       'reverb',
    key:               import.meta.env.VITE_REVERB_APP_KEY,
    wsHost:            import.meta.env.VITE_REVERB_HOST,
    wsPort:            import.meta.env.VITE_REVERB_PORT,
    wssPort:           import.meta.env.VITE_REVERB_PORT,
    forceTLS:          import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

**`resources/js/app.js` :**

```javascript
import './echo';
import './bootstrap';
```

---

### 3. Events Laravel

```bash
php artisan make:event FileUploadStarted
php artisan make:event FileUploadProgress
php artisan make:event FileUploadCompleted
php artisan make:event FileUploadFailed
```

**`app/Events/FileUploadStarted.php` :**

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class FileUploadStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int    $userId,
        public readonly string $filename,
        public readonly string $uploadId,   // UUID unique par upload
        public readonly int    $totalSize,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("uploads.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'upload.started';
    }

    public function broadcastWith(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'filename'  => $this->filename,
            'total'     => $this->totalSize,
            'progress'  => 0,
        ];
    }
}
```

**`app/Events/FileUploadProgress.php` :**

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class FileUploadProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int    $userId,
        public readonly string $uploadId,
        public readonly int    $uploadedBytes,
        public readonly int    $totalBytes,
        public readonly int    $part,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("uploads.{$this->userId}")];
    }

    public function broadcastAs(): string { return 'upload.progress'; }

    public function broadcastWith(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'progress'  => (int) round(($this->uploadedBytes / $this->totalBytes) * 100),
            'uploaded'  => $this->uploadedBytes,
            'total'     => $this->totalBytes,
            'part'      => $this->part,
        ];
    }
}
```

**`app/Events/FileUploadCompleted.php` :**

```php
<?php

namespace App\Events;

use App\Models\File;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class FileUploadCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int    $userId,
        public readonly string $uploadId,
        public readonly File   $file,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("uploads.{$this->userId}")];
    }

    public function broadcastAs(): string { return 'upload.completed'; }

    public function broadcastWith(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'file' => [
                'id'            => $this->file->id,
                'original_name' => $this->file->original_name,
                'size_human'    => $this->file->size_human,
                'versions_count'=> $this->file->versions_count,
            ],
        ];
    }
}
```

**`app/Events/FileUploadFailed.php` :**

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class FileUploadFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int    $userId,
        public readonly string $uploadId,
        public readonly string $filename,
        public readonly string $reason,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("uploads.{$this->userId}")];
    }

    public function broadcastAs(): string { return 'upload.failed'; }

    public function broadcastWith(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'filename'  => $this->filename,
            'reason'    => $this->reason,
        ];
    }
}
```

---

### 4. FileUploadService — multipart avec events

```php
<?php

namespace App\Services;

use App\Events\FileUploadCompleted;
use App\Events\FileUploadFailed;
use App\Events\FileUploadProgress;
use App\Events\FileUploadStarted;
use App\Models\File;
use App\Models\FileVersion;
use Aws\S3\S3Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadService
{
    protected string $disk;
    protected string $bucket;
    protected S3Client $s3;

    public function __construct()
    {
        $this->disk   = 'garage';
        $this->bucket = config('filesystems.disks.garage.bucket');
        $this->s3     = new S3Client([
            'version'                 => 'latest',
            'region'                  => config('filesystems.disks.garage.region'),
            'endpoint'                => config('filesystems.disks.garage.endpoint'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key'    => config('filesystems.disks.garage.key'),
                'secret' => config('filesystems.disks.garage.secret'),
            ],
        ]);
    }

    public function upload(UploadedFile $file, string $folder = 'uploads', ?int $userId = null): File
    {
        $uploadId = Str::uuid()->toString();
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $key      = "{$folder}/{$filename}";
        $fileSize = $file->getSize();

        // Seuil multipart : 10 MB
        $useMultipart = $fileSize > 10 * 1024 * 1024;

        try {
            FileUploadStarted::dispatch($userId, $file->getClientOriginalName(), $uploadId, $fileSize);

            if ($useMultipart) {
                $this->multipartUpload($file, $key, $uploadId, $userId, $fileSize);
            } else {
                Storage::disk($this->disk)->putFileAs($folder, $file, $filename);
                FileUploadProgress::dispatch($userId, $uploadId, $fileSize, $fileSize, 1);
            }

            $model = DB::transaction(function () use ($file, $key, $folder, $userId, $filename) {
                $checksum = md5_file($file->getRealPath());

                $model = File::create([
                    'original_name'  => $file->getClientOriginalName(),
                    'mime_type'      => $file->getMimeType(),
                    'size'           => $file->getSize(),
                    'bucket'         => $this->bucket,
                    'current_key'    => $key,
                    'folder'         => $folder,
                    'versions_count' => 1,
                    'user_id'        => $userId,
                ]);

                FileVersion::create([
                    'file_id'        => $model->id,
                    'version_number' => 1,
                    'storage_key'    => $key,
                    'bucket'         => $this->bucket,
                    'size'           => $file->getSize(),
                    'checksum'       => $checksum,
                    'is_current'     => true,
                    'created_by'     => $userId,
                    'created_at'     => now(),
                ]);

                return $model;
            });

            FileUploadCompleted::dispatch($userId, $uploadId, $model);

            return $model;

        } catch (\Throwable $e) {
            FileUploadFailed::dispatch($userId, $uploadId, $file->getClientOriginalName(), $e->getMessage());
            throw $e;
        }
    }

    protected function multipartUpload(
        UploadedFile $file,
        string $key,
        string $uploadId,
        ?int $userId,
        int $totalSize
    ): void {
        $multipart = $this->s3->createMultipartUpload([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'ContentType' => $file->getMimeType(),
        ]);

        $s3UploadId  = $multipart['UploadId'];
        $parts       = [];
        $partSize    = 5 * 1024 * 1024;
        $uploaded    = 0;
        $partNum     = 1;
        $handle      = fopen($file->getRealPath(), 'rb');

        try {
            while (!feof($handle)) {
                $data = fread($handle, $partSize);

                $result = $this->s3->uploadPart([
                    'Bucket'     => $this->bucket,
                    'Key'        => $key,
                    'UploadId'   => $s3UploadId,
                    'PartNumber' => $partNum,
                    'Body'       => $data,
                ]);

                $parts[]   = ['PartNumber' => $partNum, 'ETag' => $result['ETag']];
                $uploaded += strlen($data);

                FileUploadProgress::dispatch($userId, $uploadId, $uploaded, $totalSize, $partNum);

                $partNum++;
            }

            fclose($handle);

            $this->s3->completeMultipartUpload([
                'Bucket'          => $this->bucket,
                'Key'             => $key,
                'UploadId'        => $s3UploadId,
                'MultipartUpload' => ['Parts' => $parts],
            ]);

        } catch (\Throwable $e) {
            fclose($handle);
            $this->s3->abortMultipartUpload([
                'Bucket'   => $this->bucket,
                'Key'      => $key,
                'UploadId' => $s3UploadId,
            ]);
            throw $e;
        }
    }
}
```

---

### 5. Canal privé — autorisation

**`routes/channels.php` :**

```php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('uploads.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
```

---

### 6. Composant Livewire mis à jour

Ajoute la gestion des notifications dans `app/Livewire/FileManager.php` :

```php
// Propriétés à ajouter
public array $activeUploads = [];  // ['upload_id' => ['filename', 'progress', 'status']]

// Méthodes à ajouter
public function getListeners(): array
{
    $userId = Auth::id();
    return [
        "echo-private:uploads.{$userId},.upload.started"   => 'onUploadStarted',
        "echo-private:uploads.{$userId},.upload.progress"  => 'onUploadProgress',
        "echo-private:uploads.{$userId},.upload.completed" => 'onUploadCompleted',
        "echo-private:uploads.{$userId},.upload.failed"    => 'onUploadFailed',
        'refreshFiles' => '$refresh',
    ];
}

public function onUploadStarted(array $event): void
{
    $this->activeUploads[$event['upload_id']] = [
        'filename' => $event['filename'],
        'progress' => 0,
        'status'   => 'uploading',
        'total'    => $event['total'],
    ];
}

public function onUploadProgress(array $event): void
{
    if (isset($this->activeUploads[$event['upload_id']])) {
        $this->activeUploads[$event['upload_id']]['progress'] = $event['progress'];
    }
}

public function onUploadCompleted(array $event): void
{
    if (isset($this->activeUploads[$event['upload_id']])) {
        $this->activeUploads[$event['upload_id']]['status']   = 'completed';
        $this->activeUploads[$event['upload_id']]['progress'] = 100;
    }

    $this->dispatch('refreshFiles');

    // Retirer la notification après 3 secondes
    $this->dispatch('clearUpload', uploadId: $event['upload_id']);
}

public function onUploadFailed(array $event): void
{
    if (isset($this->activeUploads[$event['upload_id']])) {
        $this->activeUploads[$event['upload_id']]['status'] = 'failed';
    }
}

public function clearUpload(string $uploadId): void
{
    unset($this->activeUploads[$uploadId]);
}
```

---

### 7. Toasts dans la vue Livewire

Ajoute ce bloc en haut de `file-manager.blade.php` :

```blade
{{-- Toasts temps réel --}}
@if (count($activeUploads) > 0)
    <div class="fm-toasts" x-data>
        @foreach ($activeUploads as $uploadId => $upload)
            <div class="fm-toast fm-toast--{{ $upload['status'] }}"
                 x-init="
                    @if ($upload['status'] === 'completed')
                        setTimeout(() => $wire.clearUpload('{{ $uploadId }}'), 3000)
                    @endif
                 ">

                <div class="fm-toast__header">
                    @if ($upload['status'] === 'uploading')
                        <span class="fm-toast__spinner"></span>
                    @elseif ($upload['status'] === 'completed')
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                    @endif

                    <span class="fm-toast__name">{{ $upload['filename'] }}</span>
                    <span class="fm-toast__percent">{{ $upload['progress'] }}%</span>
                </div>

                <div class="fm-toast__bar">
                    <div class="fm-toast__fill" style="width: {{ $upload['progress'] }}%"></div>
                </div>
            </div>
        @endforeach
    </div>
@endif
```

---

### 8. CSS pour les toasts

```css
/* Ajouter dans file-manager.css */

.fm-toasts {
    position:      fixed;
    bottom:        1.5rem;
    right:         1.5rem;
    display:       flex;
    flex-direction: column;
    gap:           .75rem;
    z-index:       50;
    width:         320px;
}

.fm-toast {
    background:    var(--fm-surface);
    border:        1px solid var(--fm-border);
    border-radius: var(--fm-radius);
    padding:       .875rem 1rem;
    box-shadow:    0 8px 24px rgba(0,0,0,.4);
    animation:     fm-slide-in .25s ease;
}

.fm-toast--completed { border-color: rgba(52,211,153,.4); }
.fm-toast--failed    { border-color: rgba(239,68,68,.4); }

.fm-toast__header {
    display:     flex;
    align-items: center;
    gap:         .625rem;
    margin-bottom: .625rem;
}

.fm-toast__header svg { width: 16px; height: 16px; flex-shrink: 0; }
.fm-toast--completed .fm-toast__header svg { color: var(--fm-success); }
.fm-toast--failed    .fm-toast__header svg { color: #ef4444; }

.fm-toast__name    { flex: 1; font-size: .8rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fm-toast__percent { font-size: .75rem; color: var(--fm-muted); font-variant-numeric: tabular-nums; }

.fm-toast__bar  { height: 4px; background: var(--fm-border); border-radius: 999px; overflow: hidden; }
.fm-toast__fill { height: 100%; background: var(--fm-accent); border-radius: 999px; transition: width .3s ease; }
.fm-toast--completed .fm-toast__fill { background: var(--fm-success); }
.fm-toast--failed    .fm-toast__fill { background: #ef4444; }

.fm-toast__spinner {
    width:         14px;
    height:        14px;
    border:        2px solid var(--fm-border);
    border-top-color: var(--fm-accent);
    border-radius: 50%;
    animation:     fm-spin .7s linear infinite;
    flex-shrink:   0;
}

@keyframes fm-spin     { to { transform: rotate(360deg); } }
@keyframes fm-slide-in { from { transform: translateX(1rem); opacity: 0; } to { transform: none; opacity: 1; } }
```

---

### 9. Démarrer Reverb

```bash
# Démarrer le serveur WebSocket
php artisan reverb:start --port=8080

# En production, supervisor ou systemd
# /etc/supervisor/conf.d/reverb.conf
[program:reverb]
command=php /var/www/html/artisan reverb:start --port=8080
autostart=true
autorestart=true
stderr_logfile=/var/log/reverb.err.log
stdout_logfile=/var/log/reverb.out.log
```

---

### Architecture complète finale

```
Browser (Livewire + Echo)
    │  WebSocket ws://localhost:8080
    └──────────────────────────────► Reverb
                                         ▲
Laravel Job (multipart upload)           │ broadcast()
    ├── FileUploadStarted  ─────────────►│
    ├── FileUploadProgress ─────────────►│  (par part 5MB)
    ├── FileUploadCompleted ────────────►│
    └── FileUploadFailed   ────────────►│

Garage S3 (VM VMware)
    └── Bucket: my-bucket
            └── uploads/<uuid>.ext  ← fichier final
```

Tu as maintenant un pipeline complet de bout en bout : upload multipart → progress bar temps réel → versioning en base → restauration — tout câblé sur ton Garage S3 dans VMware. Tu veux qu'on dockerise le tout ou qu'on configure Nginx en reverse proxy devant Reverb pour la prod ?