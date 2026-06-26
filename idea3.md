Voici le guide d'intégration complet entre Garage S3 et ton stack Laravel + Spatie Backup.

---

## Intégration Garage S3 ↔ Laravel + Spatie Backup

### 1. Configuration du filesystem S3 dans Laravel

Dans `config/filesystems.php`, ajoute le disk Garage :

```php
'disks' => [

    // Disk principal (fichiers app)
    'garage' => [
        'driver'   => 's3',
        'key'      => env('GARAGE_ACCESS_KEY_ID'),
        'secret'   => env('GARAGE_SECRET_ACCESS_KEY'),
        'region'   => env('GARAGE_REGION', 'garage'),
        'bucket'   => env('GARAGE_BUCKET'),
        'endpoint' => env('GARAGE_ENDPOINT'),
        'use_path_style_endpoint' => true,  // obligatoire avec Garage
    ],

    // Disk dédié backups (bucket séparé)
    'garage_backups' => [
        'driver'   => 's3',
        'key'      => env('GARAGE_ACCESS_KEY_ID'),
        'secret'   => env('GARAGE_SECRET_ACCESS_KEY'),
        'region'   => env('GARAGE_REGION', 'garage'),
        'bucket'   => env('GARAGE_BACKUP_BUCKET'),
        'endpoint' => env('GARAGE_ENDPOINT'),
        'use_path_style_endpoint' => true,
    ],

],
```

---

### 2. Variables d'environnement `.env`

```env
GARAGE_ACCESS_KEY_ID=GKxxxxxxxxxxxxxxxxxxxx
GARAGE_SECRET_ACCESS_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
GARAGE_REGION=garage
GARAGE_ENDPOINT=http://192.168.x.x:3900   # IP de ta VM VMware
GARAGE_BUCKET=my-bucket
GARAGE_BACKUP_BUCKET=my-backups
```

---

### 3. Configuration Spatie Laravel Backup

Dans `config/backup.php` :

```php
'backup' => [
    'name' => env('APP_NAME', 'laravel-backup'),

    'source' => [
        'files' => [
            'include' => [base_path()],
            'exclude' => [
                base_path('vendor'),
                base_path('node_modules'),
            ],
            'follow_links' => false,
        ],
        'databases' => ['mysql'],  // ou pgsql selon ton stack
    ],

    'destination' => [
        'filename_prefix' => '',
        'disks' => ['garage_backups'],  // ← pointe vers Garage
    ],

    'temporary_directory' => storage_path('app/backup-temp'),
],
```

---

### 4. Politique de rétention GFS (Grandfather-Father-Son)

Toujours dans `config/backup.php` :

```php
'cleanup' => [
    'strategy' => \Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy::class,

    'default_strategy' => [
        'keep_all_backups_for_days'                 => 7,
        'keep_daily_backups_for_days'               => 16,
        'keep_weekly_backups_for_weeks'             => 8,
        'keep_monthly_backups_for_months'           => 4,
        'keep_yearly_backups_for_years'             => 2,
        'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
    ],
],
```

---

### 5. BackupService — upload S3-to-S3 vers Garage

Si tu veux copier des fichiers directement entre buckets Garage (comme tu l'avais fait avec le streaming S3-to-S3) :

```php
<?php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

class GarageBackupService
{
    protected S3Client $client;

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
    }

    /**
     * Copie streaming d'un objet entre deux buckets Garage
     */
    public function copyBetweenBuckets(
        string $sourceBucket,
        string $sourceKey,
        string $destBucket,
        string $destKey
    ): void {
        try {
            // Streaming : récupère l'objet source
            $result = $this->client->getObject([
                'Bucket' => $sourceBucket,
                'Key'    => $sourceKey,
            ]);

            // Upload en streaming vers la destination
            $this->client->putObject([
                'Bucket' => $destBucket,
                'Key'    => $destKey,
                'Body'   => $result['Body'],
                'ContentType' => $result['ContentType'] ?? 'application/octet-stream',
            ]);

            Log::info("Garage: copie réussie {$sourceBucket}/{$sourceKey} → {$destBucket}/{$destKey}");
        } catch (\Exception $e) {
            Log::error("Garage: échec de la copie — " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Génère une pre-signed URL (lecture temporaire)
     */
    public function presignedUrl(string $bucket, string $key, int $expiresInMinutes = 60): string
    {
        $cmd = $this->client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key'    => $key,
        ]);

        $request = $this->client->createPresignedRequest($cmd, "+{$expiresInMinutes} minutes");

        return (string) $request->getUri();
    }
}
```

---

### 6. Créer les buckets côté Garage

```bash
# Sur la VM VMware
garage -c /etc/garage/garage.toml bucket create my-bucket
garage -c /etc/garage/garage.toml bucket create my-backups

# Donner les droits à ta clé
garage -c /etc/garage/garage.toml bucket allow my-bucket \
  --read --write --owner --key my-app-key

garage -c /etc/garage/garage.toml bucket allow my-backups \
  --read --write --owner --key my-app-key
```

---

### 7. Planification des backups (Scheduler)

Dans `app/Console/Kernel.php` :

```php
protected function schedule(Schedule $schedule): void
{
    // Backup quotidien à 2h du matin
    $schedule->command('backup:run')->dailyAt('02:00');

    // Nettoyage selon la politique GFS
    $schedule->command('backup:clean')->dailyAt('03:00');

    // Monitoring de l'état des backups
    $schedule->command('backup:monitor')->dailyAt('04:00');
}
```

---

### 8. Tester

```bash
# Lancer un backup manuellement
php artisan backup:run

# Vérifier la liste des backups dans Garage
aws s3 ls s3://my-backups/ \
  --endpoint-url http://<IP_VM>:3900 \
  --recursive

# Vérifier l'état Spatie
php artisan backup:list
```

---

Tu veux qu'on pousse plus loin sur un point précis — la `FileUploadService`, les pre-signed URLs, ou la gestion du versioning avec les snapshots VMDK ?