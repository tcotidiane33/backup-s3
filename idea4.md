Pas de problème, on va tout couvrir dans l'ordre logique !

---

## Partie 1 — FileUploadService

```php
<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadService
{
    protected string $disk = 'garage';

    /**
     * Upload simple
     */
    public function upload(UploadedFile $file, string $folder = 'uploads'): array
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path     = "{$folder}/{$filename}";

        Storage::disk($this->disk)->putFileAs($folder, $file, $filename);

        return [
            'path'      => $path,
            'filename'  => $filename,
            'size'      => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ];
    }

    /**
     * Upload multipart (fichiers > 100 MB)
     */
    public function uploadMultipart(UploadedFile $file, string $folder = 'uploads'): array
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $key      = "{$folder}/{$filename}";
        $bucket   = config('filesystems.disks.garage.bucket');

        $s3 = $this->getS3Client();

        // Initier le multipart upload
        $multipart = $s3->createMultipartUpload([
            'Bucket'      => $bucket,
            'Key'         => $key,
            'ContentType' => $file->getMimeType(),
        ]);

        $uploadId = $multipart['UploadId'];
        $parts    = [];
        $partSize = 5 * 1024 * 1024; // 5 MB par part (minimum S3)
        $handle   = fopen($file->getRealPath(), 'rb');
        $partNum  = 1;

        while (!feof($handle)) {
            $data = fread($handle, $partSize);

            $result = $s3->uploadPart([
                'Bucket'     => $bucket,
                'Key'        => $key,
                'UploadId'   => $uploadId,
                'PartNumber' => $partNum,
                'Body'       => $data,
            ]);

            $parts[] = [
                'PartNumber' => $partNum,
                'ETag'       => $result['ETag'],
            ];

            $partNum++;
        }

        fclose($handle);

        // Finaliser
        $s3->completeMultipartUpload([
            'Bucket'          => $bucket,
            'Key'             => $key,
            'UploadId'        => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);

        return [
            'path'      => $key,
            'filename'  => $filename,
            'size'      => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ];
    }

    /**
     * Supprimer un fichier
     */
    public function delete(string $path): bool
    {
        return Storage::disk($this->disk)->delete($path);
    }

    /**
     * Vérifier l'existence
     */
    public function exists(string $path): bool
    {
        return Storage::disk($this->disk)->exists($path);
    }

    protected function getS3Client(): \Aws\S3\S3Client
    {
        return new \Aws\S3\S3Client([
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
}
```

---

## Partie 2 — Pre-signed URLs

```php
<?php

namespace App\Services;

use Aws\S3\S3Client;

class PresignedUrlService
{
    protected S3Client $client;
    protected string $bucket;

    public function __construct()
    {
        $this->client = new S3Client([
            'version'                 => 'latest',
            'region'                  => config('filesystems.disks.garage.region'),
            'endpoint'                => config('filesystems.disks.garage.endpoint'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key'    => config('filesystems.disks.garage.key'),
                'secret' => config('filesystems.disks.garage.secret'),
            ],
        ]);

        $this->bucket = config('filesystems.disks.garage.bucket');
    }

    /**
     * URL de téléchargement temporaire (GET)
     */
    public function download(string $key, int $minutes = 60, ?string $filename = null): string
    {
        $params = [
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ];

        // Forcer le nom du fichier au téléchargement
        if ($filename) {
            $params['ResponseContentDisposition'] = "attachment; filename=\"{$filename}\"";
        }

        $cmd = $this->client->getCommand('GetObject', $params);

        return (string) $this->client
            ->createPresignedRequest($cmd, "+{$minutes} minutes")
            ->getUri();
    }

    /**
     * URL d'upload temporaire (PUT) — upload direct depuis le navigateur
     */
    public function upload(string $key, string $mimeType = 'application/octet-stream', int $minutes = 15): string
    {
        $cmd = $this->client->getCommand('PutObject', [
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'ContentType' => $mimeType,
        ]);

        return (string) $this->client
            ->createPresignedRequest($cmd, "+{$minutes} minutes")
            ->getUri();
    }

    /**
     * Générer une paire d'URLs (upload client-side + confirmation)
     */
    public function uploadPair(string $folder, string $extension, string $mimeType): array
    {
        $key = $folder . '/' . \Str::uuid() . '.' . $extension;

        return [
            'key'        => $key,
            'upload_url' => $this->upload($key, $mimeType),
            'read_url'   => $this->download($key, 1440), // 24h
        ];
    }
}
```

Usage dans un contrôleur :

```php
// Générer une URL de téléchargement sécurisé
public function download(string $fileKey)
{
    $url = app(PresignedUrlService::class)->download($fileKey, 30);
    return redirect($url);
}

// Upload direct depuis le front (évite de passer par le serveur Laravel)
public function getUploadUrl(Request $request)
{
    $pair = app(PresignedUrlService::class)->uploadPair(
        'documents',
        $request->extension,
        $request->mime_type
    );

    return response()->json($pair);
}
```

---

## Partie 3 — Versioning (Garage + VMDK)

### 3a. Versioning côté Garage (objets)

Garage ne supporte pas nativement le versioning S3 (`PutBucketVersioning`), donc on le gère manuellement avec une convention de nommage :

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;

class VersioningService
{
    protected string $disk = 'garage_backups';

    /**
     * Sauvegarder une nouvelle version d'un fichier
     * Exemple de clé : documents/rapport.pdf
     * Versions :       versions/documents/rapport.pdf/2026-06-26T14-00-00.pdf
     */
    public function saveVersion(string $originalKey, string $content): string
    {
        $timestamp  = Carbon::now()->format('Y-m-d\TH-i-s');
        $versionKey = "versions/{$originalKey}/{$timestamp}";

        Storage::disk($this->disk)->put($versionKey, $content);

        return $versionKey;
    }

    /**
     * Lister toutes les versions d'un fichier
     */
    public function listVersions(string $originalKey): array
    {
        $prefix = "versions/{$originalKey}/";
        $files  = Storage::disk($this->disk)->files($prefix);

        return collect($files)
            ->map(fn($f) => [
                'key'          => $f,
                'timestamp'    => basename($f),
                'size'         => Storage::disk($this->disk)->size($f),
                'last_modified'=> Storage::disk($this->disk)->lastModified($f),
            ])
            ->sortByDesc('timestamp')
            ->values()
            ->toArray();
    }

    /**
     * Restaurer une version spécifique
     */
    public function restore(string $versionKey, string $targetKey): void
    {
        $content = Storage::disk($this->disk)->get($versionKey);
        Storage::disk($this->disk)->put($targetKey, $content);
    }

    /**
     * Nettoyer les vieilles versions (garder N versions max)
     */
    public function prune(string $originalKey, int $keep = 10): int
    {
        $versions = $this->listVersions($originalKey);

        $toDelete = array_slice($versions, $keep);

        foreach ($toDelete as $v) {
            Storage::disk($this->disk)->delete($v['key']);
        }

        return count($toDelete);
    }
}
```

### 3b. Versioning côté VMware (snapshots VMDK coordonnés)

Script bash à lancer avant/après un snapshot VMware :

```bash
#!/bin/bash
# /usr/local/bin/garage-snapshot.sh
# Usage: garage-snapshot.sh [pre|post]

GARAGE_SERVICE="garage"
LOG_FILE="/var/log/garage-snapshot.log"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

pre_snapshot() {
    echo "[${TIMESTAMP}] PRE-SNAPSHOT: flush et arrêt de Garage" >> $LOG_FILE

    # Flush des écritures en cours (LMDB)
    sync

    # Arrêt propre du service
    systemctl stop $GARAGE_SERVICE

    echo "[${TIMESTAMP}] PRE-SNAPSHOT: Garage arrêté — snapshot VMware peut démarrer" >> $LOG_FILE
}

post_snapshot() {
    echo "[${TIMESTAMP}] POST-SNAPSHOT: redémarrage de Garage" >> $LOG_FILE

    systemctl start $GARAGE_SERVICE

    # Attendre que Garage soit prêt
    sleep 3
    systemctl is-active --quiet $GARAGE_SERVICE && \
        echo "[${TIMESTAMP}] POST-SNAPSHOT: Garage démarré avec succès" >> $LOG_FILE || \
        echo "[${TIMESTAMP}] POST-SNAPSHOT: ERREUR au démarrage de Garage" >> $LOG_FILE
}

case "$1" in
    pre)  pre_snapshot ;;
    post) post_snapshot ;;
    *)    echo "Usage: $0 [pre|post]"; exit 1 ;;
esac
```

Intégration dans Laravel pour déclencher le snapshot VMware via API vSphere :

```php
// Dans un Job Laravel planifié
class TriggerVmwareSnapshot implements ShouldQueue
{
    public function handle(): void
    {
        // 1. Préparer Garage
        SSH::run('sudo /usr/local/bin/garage-snapshot.sh pre');

        // 2. Déclencher snapshot via API VMware REST
        Http::withBasicAuth(config('vmware.user'), config('vmware.password'))
            ->post(config('vmware.endpoint') . '/api/vcenter/vm/' . config('vmware.vm_id') . '/snapshot', [
                'name'        => 'garage-auto-' . now()->format('Ymd-His'),
                'description' => 'Snapshot automatique Garage S3',
                'memory'      => false,
                'quiesce'     => true,
            ]);

        // 3. Redémarrer Garage
        SSH::run('sudo /usr/local/bin/garage-snapshot.sh post');
    }
}
```

---

### Récapitulatif de l'architecture complète

```
Laravel App
    │
    ├── FileUploadService     → upload simple / multipart vers Garage
    ├── PresignedUrlService   → URLs temporaires GET/PUT
    ├── VersioningService     → versioning manuel des objets
    └── Spatie Backup         → backup DB + fichiers → bucket garage_backups
                                        │
                              Garage S3 (VM VMware)
                              ├── Bucket: my-bucket       (fichiers app)
                              └── Bucket: my-backups      (backups GFS)
                                        │
                              VMDK snapshots coordonnés
                              (pre/post script bash)
```

Tu veux qu'on câble tout ça avec des migrations et des modèles `FileVersion` en base pour tracker l'historique ?