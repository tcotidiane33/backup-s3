Voici l'interface Livewire complète pour gérer les fichiers, versions et restaurations.

---

## Interface Livewire — Gestionnaire de fichiers Garage S3

### 1. Composant Livewire principal

```bash
php artisan make:livewire FileManager
```

**`app/Livewire/FileManager.php` :**

```php
<?php

namespace App\Livewire;

use App\Models\File;
use App\Models\FileVersion;
use App\Services\FileUploadService;
use App\Services\PresignedUrlService;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Auth;

class FileManager extends Component
{
    use WithFileUploads;

    // Upload
    public $upload;
    public bool $uploading = false;

    // État UI
    public ?int $selectedFileId   = null;
    public ?int $previewVersionId = null;
    public string $search         = '';
    public string $tab            = 'files'; // files | versions

    // Nouvelle version
    public $newVersionFile;
    public bool $uploadingVersion = false;

    protected $listeners = ['refreshFiles' => '$refresh'];

    public function getFilesProperty()
    {
        return File::with(['versions' => fn($q) => $q->where('is_current', true)])
            ->when($this->search, fn($q) => $q->where('original_name', 'like', "%{$this->search}%"))
            ->where('user_id', Auth::id())
            ->latest()
            ->paginate(10);
    }

    public function getSelectedFileProperty(): ?File
    {
        return $this->selectedFileId
            ? File::with('versions')->find($this->selectedFileId)
            : null;
    }

    // Upload initial
    public function uploadFile(): void
    {
        $this->validate(['upload' => 'required|file|max:102400']);

        $this->uploading = true;

        app(FileUploadService::class)->upload(
            $this->upload,
            'uploads',
            Auth::id()
        );

        $this->reset('upload', 'uploading');
        $this->dispatch('refreshFiles');
        session()->flash('success', 'Fichier uploadé avec succès.');
    }

    // Nouvelle version
    public function uploadNewVersion(): void
    {
        $this->validate(['newVersionFile' => 'required|file|max:102400']);

        $this->uploadingVersion = true;

        $file = File::findOrFail($this->selectedFileId);

        app(FileUploadService::class)->newVersion(
            $file,
            $this->newVersionFile,
            Auth::id()
        );

        $this->reset('newVersionFile', 'uploadingVersion');
        $this->dispatch('refreshFiles');
        session()->flash('success', 'Nouvelle version sauvegardée.');
    }

    // Restaurer une version
    public function restoreVersion(int $versionId): void
    {
        $file    = File::findOrFail($this->selectedFileId);
        $version = FileVersion::findOrFail($versionId);

        app(FileUploadService::class)->restoreVersion($file, $version);

        $this->dispatch('refreshFiles');
        session()->flash('success', "Version #{$version->version_number} restaurée.");
    }

    // Télécharger
    public function download(int $fileId): string
    {
        $file = File::findOrFail($fileId);

        return app(PresignedUrlService::class)->download(
            $file->current_key,
            30,
            $file->original_name
        );
    }

    // Supprimer toutes les vieilles versions
    public function pruneVersions(): void
    {
        $file = File::findOrFail($this->selectedFileId);
        $deleted = app(FileUploadService::class)->pruneVersions($file, 5);

        session()->flash('success', "{$deleted} vieille(s) version(s) supprimée(s).");
        $this->dispatch('refreshFiles');
    }

    public function selectFile(int $id): void
    {
        $this->selectedFileId = ($this->selectedFileId === $id) ? null : $id;
        $this->tab = 'versions';
    }

    public function render()
    {
        return view('livewire.file-manager');
    }
}
```

---

### 2. Vue Livewire

**`resources/views/livewire/file-manager.blade.php` :**

```blade
<div class="fm-root">

    {{-- Flash messages --}}
    @if (session('success'))
        <div class="fm-flash fm-flash--success">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
            {{ session('success') }}
        </div>
    @endif

    <div class="fm-layout">

        {{-- ═══════════════════════════════════════
             Colonne gauche — Liste des fichiers
        ═══════════════════════════════════════ --}}
        <div class="fm-panel fm-panel--left">

            {{-- Header + upload --}}
            <div class="fm-panel__header">
                <h2 class="fm-panel__title">Fichiers</h2>

                <div class="fm-upload-zone" x-data="{ drag: false }"
                    @dragover.prevent="drag = true"
                    @dragleave="drag = false"
                    @drop.prevent="drag = false">
                    <label class="fm-upload-label" :class="{ 'fm-upload-label--drag': drag }">
                        <input type="file" wire:model="upload" class="sr-only">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                        <span>Glisser un fichier ou <u>parcourir</u></span>
                    </label>
                </div>

                @if ($upload)
                    <button wire:click="uploadFile" wire:loading.attr="disabled" class="fm-btn fm-btn--primary">
                        <span wire:loading.remove wire:target="uploadFile">Uploader</span>
                        <span wire:loading wire:target="uploadFile">Upload en cours…</span>
                    </button>
                @endif
            </div>

            {{-- Recherche --}}
            <div class="fm-search">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" /></svg>
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Rechercher un fichier…" class="fm-search__input">
            </div>

            {{-- Liste --}}
            <div class="fm-file-list">
                @forelse ($this->files as $file)
                    <div class="fm-file-item {{ $selectedFileId === $file->id ? 'fm-file-item--active' : '' }}"
                         wire:click="selectFile({{ $file->id }})">

                        <div class="fm-file-item__icon">
                            @if (str_contains($file->mime_type, 'image'))
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M1.5 6a2.25 2.25 0 012.25-2.25h16.5A2.25 2.25 0 0122.5 6v12a2.25 2.25 0 01-2.25 2.25H3.75A2.25 2.25 0 011.5 18V6zM3 16.06V18c0 .414.336.75.75.75h16.5A.75.75 0 0021 18v-1.94l-2.69-2.689a1.5 1.5 0 00-2.12 0l-.88.879.97.97a.75.75 0 11-1.06 1.06l-5.16-5.159a1.5 1.5 0 00-2.12 0L3 16.061zm10.125-7.81a1.125 1.125 0 112.25 0 1.125 1.125 0 01-2.25 0z" clip-rule="evenodd" /></svg>
                            @elseif (str_contains($file->mime_type, 'pdf'))
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M5.625 1.5H9a3.75 3.75 0 013.75 3.75v1.875c0 1.036.84 1.875 1.875 1.875H16.5a3.75 3.75 0 013.75 3.75v7.875c0 1.035-.84 1.875-1.875 1.875H5.625a1.875 1.875 0 01-1.875-1.875V3.375c0-1.036.84-1.875 1.875-1.875z" clip-rule="evenodd" /></svg>
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0016.5 9h-1.875a1.875 1.875 0 01-1.875-1.875V5.25A3.75 3.75 0 009 1.5H5.625z" /><path d="M12.971 1.816A5.23 5.23 0 0114.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0 013.434 1.279 9.768 9.768 0 00-6.963-6.963z" /></svg>
                            @endif
                        </div>

                        <div class="fm-file-item__info">
                            <p class="fm-file-item__name">{{ $file->original_name }}</p>
                            <p class="fm-file-item__meta">
                                {{ $file->size_human }} &middot;
                                {{ $file->versions_count }} version{{ $file->versions_count > 1 ? 's' : '' }} &middot;
                                {{ $file->created_at->diffForHumans() }}
                            </p>
                        </div>

                        <a href="{{ $this->download($file->id) }}" target="_blank"
                           class="fm-file-item__download" wire:click.stop>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 2.75a.75.75 0 00-1.5 0v8.614L6.295 8.235a.75.75 0 10-1.09 1.03l4.25 4.5a.75.75 0 001.09 0l4.25-4.5a.75.75 0 00-1.09-1.03l-2.955 3.129V2.75z" /><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z" /></svg>
                        </a>
                    </div>
                @empty
                    <div class="fm-empty">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
                        <p>Aucun fichier trouvé</p>
                        <span>Uploadez votre premier fichier ci-dessus</span>
                    </div>
                @endforelse
            </div>

            {{ $this->files->links() }}
        </div>

        {{-- ═══════════════════════════════════════
             Colonne droite — Détail & Versions
        ═══════════════════════════════════════ --}}
        <div class="fm-panel fm-panel--right">

            @if ($this->selectedFile)
                @php $file = $this->selectedFile; @endphp

                <div class="fm-panel__header">
                    <div>
                        <h2 class="fm-panel__title">{{ $file->original_name }}</h2>
                        <p class="fm-panel__subtitle">{{ $file->size_human }} &middot; {{ $file->mime_type }}</p>
                    </div>
                    <span class="fm-badge">{{ $file->versions_count }} versions</span>
                </div>

                {{-- Upload nouvelle version --}}
                <div class="fm-new-version">
                    <label class="fm-new-version__label">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M9.25 13.25a.75.75 0 001.5 0V4.636l2.955 3.129a.75.75 0 001.09-1.03l-4.25-4.5a.75.75 0 00-1.09 0l-4.25 4.5a.75.75 0 101.09 1.03L9.25 4.636v8.614z" /><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z" /></svg>
                        Uploader une nouvelle version
                        <input type="file" wire:model="newVersionFile" class="sr-only">
                    </label>

                    @if ($newVersionFile)
                        <button wire:click="uploadNewVersion" wire:loading.attr="disabled" class="fm-btn fm-btn--primary fm-btn--sm">
                            <span wire:loading.remove wire:target="uploadNewVersion">Sauvegarder</span>
                            <span wire:loading wire:target="uploadNewVersion">Upload…</span>
                        </button>
                    @endif
                </div>

                {{-- Historique des versions --}}
                <div class="fm-versions">
                    <div class="fm-versions__header">
                        <h3 class="fm-versions__title">Historique des versions</h3>
                        <button wire:click="pruneVersions" wire:confirm="Supprimer les anciennes versions (garder 5) ?"
                                class="fm-btn fm-btn--ghost fm-btn--sm">
                            Nettoyer
                        </button>
                    </div>

                    <div class="fm-version-list">
                        @foreach ($file->versions as $version)
                            <div class="fm-version-item {{ $version->is_current ? 'fm-version-item--current' : '' }}">

                                <div class="fm-version-item__dot {{ $version->is_current ? 'fm-version-item__dot--current' : '' }}"></div>

                                <div class="fm-version-item__body">
                                    <div class="fm-version-item__header">
                                        <span class="fm-version-item__label">
                                            Version {{ $version->version_number }}
                                            @if ($version->is_current)
                                                <span class="fm-tag fm-tag--green">Actuelle</span>
                                            @endif
                                        </span>
                                        <span class="fm-version-item__date">
                                            {{ \Carbon\Carbon::parse($version->created_at)->format('d/m/Y H:i') }}
                                        </span>
                                    </div>

                                    <div class="fm-version-item__meta">
                                        {{ number_format($version->size / 1024, 1) }} KB
                                        @if ($version->checksum)
                                            &middot; MD5 : <code>{{ substr($version->checksum, 0, 8) }}…</code>
                                        @endif
                                    </div>

                                    @if (!$version->is_current)
                                        <button wire:click="restoreVersion({{ $version->id }})"
                                                wire:confirm="Restaurer la version {{ $version->version_number }} ?"
                                                class="fm-btn fm-btn--outline fm-btn--sm">
                                            Restaurer cette version
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

            @else
                <div class="fm-empty fm-empty--panel">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" /></svg>
                    <p>Sélectionne un fichier</p>
                    <span>pour voir son historique de versions</span>
                </div>
            @endif
        </div>

    </div>
</div>
```

---

### 3. CSS dédié

**`resources/css/file-manager.css` :**

```css
/* ── Tokens ─────────────────────────────────── */
.fm-root {
    --fm-bg:        #0f1117;
    --fm-surface:   #1a1d27;
    --fm-border:    #2a2d3e;
    --fm-accent:    #6c8aff;
    --fm-accent-2:  #a78bfa;
    --fm-success:   #34d399;
    --fm-text:      #e2e8f0;
    --fm-muted:     #64748b;
    --fm-radius:    10px;
    --fm-font:      'Inter', system-ui, sans-serif;

    font-family: var(--fm-font);
    background:  var(--fm-bg);
    color:       var(--fm-text);
    min-height:  100vh;
    padding:     2rem;
}

/* ── Layout ──────────────────────────────────── */
.fm-layout {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 1.5rem;
    max-width: 1200px;
    margin: 0 auto;
}

/* ── Panels ──────────────────────────────────── */
.fm-panel {
    background:    var(--fm-surface);
    border:        1px solid var(--fm-border);
    border-radius: var(--fm-radius);
    padding:       1.5rem;
    display:       flex;
    flex-direction: column;
    gap:           1.25rem;
}

.fm-panel__header {
    display:         flex;
    align-items:     flex-start;
    justify-content: space-between;
    gap:             1rem;
}

.fm-panel__title    { font-size: 1.1rem; font-weight: 600; margin: 0; }
.fm-panel__subtitle { font-size: 0.8rem; color: var(--fm-muted); margin-top: .2rem; }

/* ── Upload zone ─────────────────────────────── */
.fm-upload-zone { width: 100%; }

.fm-upload-label {
    display:        flex;
    flex-direction: column;
    align-items:    center;
    gap:            .5rem;
    padding:        1.25rem;
    border:         1.5px dashed var(--fm-border);
    border-radius:  var(--fm-radius);
    cursor:         pointer;
    font-size:      .85rem;
    color:          var(--fm-muted);
    transition:     border-color .2s, background .2s;
}

.fm-upload-label svg { width: 28px; height: 28px; }
.fm-upload-label:hover,
.fm-upload-label--drag {
    border-color: var(--fm-accent);
    background:   rgba(108, 138, 255, .06);
    color:        var(--fm-accent);
}

/* ── Search ──────────────────────────────────── */
.fm-search {
    position: relative;
    display:  flex;
    align-items: center;
}

.fm-search svg {
    position: absolute;
    left:     .75rem;
    width:    16px;
    height:   16px;
    color:    var(--fm-muted);
    pointer-events: none;
}

.fm-search__input {
    width:         100%;
    background:    var(--fm-bg);
    border:        1px solid var(--fm-border);
    border-radius: 8px;
    padding:       .55rem .75rem .55rem 2.25rem;
    color:         var(--fm-text);
    font-size:     .875rem;
    outline:       none;
    transition:    border-color .2s;
}
.fm-search__input:focus { border-color: var(--fm-accent); }

/* ── File list ───────────────────────────────── */
.fm-file-list { display: flex; flex-direction: column; gap: .5rem; }

.fm-file-item {
    display:       flex;
    align-items:   center;
    gap:           .875rem;
    padding:       .75rem 1rem;
    border-radius: 8px;
    border:        1px solid transparent;
    cursor:        pointer;
    transition:    background .15s, border-color .15s;
}

.fm-file-item:hover         { background: rgba(255,255,255,.04); }
.fm-file-item--active       { background: rgba(108,138,255,.1); border-color: rgba(108,138,255,.35); }

.fm-file-item__icon svg     { width: 28px; height: 28px; color: var(--fm-accent); }
.fm-file-item__info         { flex: 1; min-width: 0; }
.fm-file-item__name         { font-size: .875rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin: 0; }
.fm-file-item__meta         { font-size: .75rem; color: var(--fm-muted); margin: .15rem 0 0; }
.fm-file-item__download     { color: var(--fm-muted); transition: color .15s; }
.fm-file-item__download:hover { color: var(--fm-accent); }
.fm-file-item__download svg { width: 18px; height: 18px; }

/* ── Versions ────────────────────────────────── */
.fm-new-version {
    display:       flex;
    align-items:   center;
    gap:           .75rem;
    padding:       .875rem 1rem;
    background:    var(--fm-bg);
    border:        1px solid var(--fm-border);
    border-radius: 8px;
}

.fm-new-version__label {
    display:     flex;
    align-items: center;
    gap:         .5rem;
    font-size:   .875rem;
    color:       var(--fm-muted);
    cursor:      pointer;
    flex:        1;
}
.fm-new-version__label svg { width: 18px; height: 18px; }
.fm-new-version__label:hover { color: var(--fm-accent); }

.fm-versions__header {
    display:         flex;
    align-items:     center;
    justify-content: space-between;
}
.fm-versions__title { font-size: .95rem; font-weight: 600; margin: 0; }

/* Version timeline */
.fm-version-list { display: flex; flex-direction: column; gap: 0; }

.fm-version-item {
    display:  flex;
    gap:      1rem;
    padding:  1rem 0;
    position: relative;
}

.fm-version-item:not(:last-child)::after {
    content:    '';
    position:   absolute;
    left:       7px;
    top:        2rem;
    bottom:     0;
    width:      1px;
    background: var(--fm-border);
}

.fm-version-item__dot {
    width:         15px;
    height:        15px;
    border-radius: 50%;
    background:    var(--fm-border);
    flex-shrink:   0;
    margin-top:    3px;
    position:      relative;
    z-index:       1;
}
.fm-version-item__dot--current {
    background:  var(--fm-accent);
    box-shadow:  0 0 0 3px rgba(108,138,255,.25);
}

.fm-version-item__body  { flex: 1; }
.fm-version-item__header{ display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
.fm-version-item__label { font-size: .875rem; font-weight: 500; display: flex; align-items: center; gap: .5rem; }
.fm-version-item__date  { font-size: .75rem; color: var(--fm-muted); }
.fm-version-item__meta  { font-size: .75rem; color: var(--fm-muted); margin: .25rem 0 .5rem; }
.fm-version-item__meta code { background: rgba(255,255,255,.06); padding: .1rem .3rem; border-radius: 4px; font-size: .7rem; }

/* ── Buttons ─────────────────────────────────── */
.fm-btn {
    display:       inline-flex;
    align-items:   center;
    gap:           .375rem;
    padding:       .55rem 1.1rem;
    border-radius: 8px;
    font-size:     .875rem;
    font-weight:   500;
    cursor:        pointer;
    border:        none;
    transition:    background .15s, opacity .15s;
    white-space:   nowrap;
}
.fm-btn--sm      { padding: .35rem .75rem; font-size: .8rem; }
.fm-btn--primary { background: var(--fm-accent); color: #fff; }
.fm-btn--primary:hover { background: #5a79f0; }
.fm-btn--outline { background: transparent; border: 1px solid var(--fm-border); color: var(--fm-text); }
.fm-btn--outline:hover { border-color: var(--fm-accent); color: var(--fm-accent); }
.fm-btn--ghost   { background: transparent; color: var(--fm-muted); }
.fm-btn--ghost:hover { color: var(--fm-text); }
.fm-btn:disabled { opacity: .5; cursor: not-allowed; }

/* ── Tags & badges ───────────────────────────── */
.fm-badge { background: rgba(108,138,255,.15); color: var(--fm-accent); border-radius: 999px; padding: .2rem .7rem; font-size: .75rem; font-weight: 600; white-space: nowrap; }
.fm-tag   { display: inline-flex; align-items: center; border-radius: 999px; padding: .1rem .5rem; font-size: .7rem; font-weight: 600; }
.fm-tag--green { background: rgba(52,211,153,.15); color: var(--fm-success); }

/* ── Flash ───────────────────────────────────── */
.fm-flash {
    display:       flex;
    align-items:   center;
    gap:           .625rem;
    padding:       .75rem 1rem;
    border-radius: 8px;
    font-size:     .875rem;
    margin-bottom: 1rem;
    max-width:     1200px;
    margin-left:   auto;
    margin-right:  auto;
}
.fm-flash svg          { width: 18px; height: 18px; flex-shrink: 0; }
.fm-flash--success     { background: rgba(52,211,153,.1); color: var(--fm-success); border: 1px solid rgba(52,211,153,.25); }

/* ── Empty states ────────────────────────────── */
.fm-empty {
    display:        flex;
    flex-direction: column;
    align-items:    center;
    gap:            .5rem;
    padding:        2.5rem 1rem;
    color:          var(--fm-muted);
    text-align:     center;
}
.fm-empty svg    { width: 40px; height: 40px; opacity: .4; }
.fm-empty p      { font-weight: 500; color: var(--fm-text); margin: 0; }
.fm-empty span   { font-size: .8rem; }
.fm-empty--panel { padding: 4rem 2rem; }

/* ── Responsive ──────────────────────────────── */
@media (max-width: 768px) {
    .fm-layout { grid-template-columns: 1fr; }
}
```

---

### 4. Intégration dans le layout

```blade
{{-- resources/views/layouts/app.blade.php --}}
<head>
    @vite(['resources/css/app.css', 'resources/css/file-manager.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    {{ $slot }}
    @livewireScripts
</body>
```

```blade
{{-- resources/views/files/index.blade.php --}}
<x-layouts.app>
    <livewire:file-manager />
</x-layouts.app>
```

```php
// routes/web.php
Route::middleware('auth')->get('/files', fn() => view('files.index'))->name('files.index');
```

---

L'interface est prête — tu as une liste de fichiers à gauche, un panneau de versions à droite avec la timeline, upload drag & drop, et restauration en un clic. Tu veux qu'on ajoute des notifications temps réel avec Laravel Echo + Reverb pour les uploads longs ?