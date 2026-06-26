## Migrations et modèles pour le tracking des versions

### 1. Migrations

**Migration `files` :**

```bash
php artisan make:migration create_files_table
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');        // en bytes
            $table->string('bucket');
            $table->string('current_key');             // clé S3 active
            $table->string('folder')->default('uploads');
            $table->unsignedInteger('versions_count')->default(1);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['uuid', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
```

**Migration `file_versions` :**

```bash
php artisan make:migration create_file_versions_table
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('storage_key');             // clé S3 de cette version
            $table->string('bucket');
            $table->unsignedBigInteger('size');
            $table->string('checksum')->nullable();    // MD5 ou SHA256
            $table->string('snapshot_label')->nullable(); // ex: vmware snapshot ID
            $table->json('metadata')->nullable();      // infos supplémentaires
            $table->boolean('is_current')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['file_id', 'version_number']);
            $table->index(['file_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_versions');
    }
};
```

```bash
php artisan migrate
```

---

### 2. Modèles

**`app/Models/File.php` :**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class File extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'original_name', 'mime_type', 'size',
        'bucket', 'current_key', 'folder',
        'versions_count', 'user_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn($file) => $file->uuid ??= Str::uuid());
    }

    // Relations
    public function versions(): HasMany
    {
        return $this->hasMany(FileVersion::class)->orderByDesc('version_number');
    }

    public function currentVersion(): HasMany
    {
        return $this->hasMany(FileVersion::class)->where('is_current', true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Accesseurs
    public function getSizeHumanAttribute(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size  = $this->size;
        $i     = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    public function getLatestVersionAttribute(): ?FileVersion
    {
        return $this->versions()->first();
    }
}
```

**`app/Models/FileVersion.php` :**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'file_id', 'version_number', 'storage_key',
        'bucket', 'size', 'checksum',
        'snapshot_label', 'metadata', 'is_current', 'created_by',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'is_current' => 'boolean',
        'created_at' => 'datetime',
    ];

    // Relations
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Accesseurs
    public function getSizeHumanAttribute(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size  = $this->size;
        $i     = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }
}
```

---

### 3. FileUploadService mis à jour avec tracking en base

```php
<?php

namespace App\Services;

use App\Models\File;
use App\Models\FileVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadService
{
    protected string $disk   = 'garage';
    protected string $bucket;

    public function __construct()
    {
        $this->bucket = config('filesystems.disks.garage.bucket');
    }

    /**
     * Premier upload — crée le File + version 1
     */
    public function upload(UploadedFile $file, string $folder = 'uploads', ?int $userId = null): File
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $key      = "{$folder}/{$filename}";
        $checksum = md5_file($file->getRealPath());

        Storage::disk($this->disk)->putFileAs($folder, $file, $filename);

        return DB::transaction(function () use ($file, $key, $folder, $checksum, $userId) {
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
    }

    /**
     * Nouvelle version d'un fichier existant
     */
    public function newVersion(File $file, UploadedFile $newFile, ?int $userId = null): FileVersion
    {
        $ext      = $newFile->getClientOriginalExtension();
        $key      = "{$file->folder}/" . Str::uuid() . ".{$ext}";
        $checksum = md5_file($newFile->getRealPath());

        Storage::disk($this->disk)->putFileAs(
            $file->folder,
            $newFile,
            basename($key)
        );

        return DB::transaction(function () use ($file, $newFile, $key, $checksum, $userId) {
            // Marquer l'ancienne version comme non courante
            $file->versions()->update(['is_current' => false]);

            $versionNumber = $file->versions_count + 1;

            $version = FileVersion::create([
                'file_id'        => $file->id,
                'version_number' => $versionNumber,
                'storage_key'    => $key,
                'bucket'         => $this->bucket,
                'size'           => $newFile->getSize(),
                'checksum'       => $checksum,
                'is_current'     => true,
                'created_by'     => $userId,
                'created_at'     => now(),
            ]);

            $file->update([
                'current_key'    => $key,
                'size'           => $newFile->getSize(),
                'versions_count' => $versionNumber,
            ]);

            return $version;
        });
    }

    /**
     * Restaurer une version antérieure
     */
    public function restoreVersion(File $file, FileVersion $version): void
    {
        DB::transaction(function () use ($file, $version) {
            $file->versions()->update(['is_current' => false]);

            $version->update(['is_current' => true]);

            $file->update(['current_key' => $version->storage_key]);
        });
    }

    /**
     * Supprimer les vieilles versions (garder N)
     */
    public function pruneVersions(File $file, int $keep = 5): int
    {
        $toDelete = $file->versions()
            ->where('is_current', false)
            ->skip($keep - 1)
            ->take(PHP_INT_MAX)
            ->get();

        foreach ($toDelete as $version) {
            Storage::disk($this->disk)->delete($version->storage_key);
            $version->delete();
        }

        return $toDelete->count();
    }
}
```

---

### 4. Controller

```php
<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\FileVersion;
use App\Services\FileUploadService;
use App\Services\PresignedUrlService;
use Illuminate\Http\Request;

class FileController extends Controller
{
    public function __construct(
        protected FileUploadService  $uploadService,
        protected PresignedUrlService $presignedService,
    ) {}

    public function upload(Request $request)
    {
        $request->validate(['file' => 'required|file|max:102400']);

        $file = $this->uploadService->upload(
            $request->file('file'),
            'uploads',
            $request->user()?->id
        );

        return response()->json($file->load('versions'), 201);
    }

    public function newVersion(Request $request, File $file)
    {
        $request->validate(['file' => 'required|file|max:102400']);

        $version = $this->uploadService->newVersion(
            $file,
            $request->file('file'),
            $request->user()?->id
        );

        return response()->json($version, 201);
    }

    public function versions(File $file)
    {
        return response()->json($file->versions()->get());
    }

    public function restore(File $file, FileVersion $version)
    {
        $this->uploadService->restoreVersion($file, $version);

        return response()->json(['message' => 'Version restaurée avec succès.']);
    }

    public function download(File $file)
    {
        $url = $this->presignedService->download(
            $file->current_key,
            30,
            $file->original_name
        );

        return redirect($url);
    }
}
```

---

### 5. Routes

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::post  ('files',                          [FileController::class, 'upload']);
    Route::post  ('files/{file}/versions',          [FileController::class, 'newVersion']);
    Route::get   ('files/{file}/versions',          [FileController::class, 'versions']);
    Route::post  ('files/{file}/versions/{version}/restore', [FileController::class, 'restore']);
    Route::get   ('files/{file}/download',          [FileController::class, 'download']);
});
```

---

### Architecture finale en base

```
files
├── id, uuid, original_name, mime_type
├── size, bucket, current_key, folder
├── versions_count, user_id
└── created_at, updated_at, deleted_at

file_versions
├── id, file_id (FK)
├── version_number, storage_key, bucket
├── size, checksum, snapshot_label
├── metadata (JSON), is_current
└── created_by (FK), created_at
```

Tu veux qu'on ajoute une interface Livewire par-dessus pour gérer tout ça depuis le front ?