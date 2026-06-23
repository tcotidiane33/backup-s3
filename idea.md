C'est un excellent POC. Cela te permettra de valider une architecture proche de ce qu'utilisent beaucoup de SaaS modernes.

# Projet : Mini SaaS Laravel « DocuVault »

Fonctionnalités :

* Authentification
* Gestion multi-utilisateurs
* Upload de fichiers
* Avatar utilisateur
* Factures PDF
* Statistiques journalières
* Sauvegarde complète vers Garage S3
* Versioning des sauvegardes
* Restauration d'une version antérieure

Architecture cible

```text
Internet
    │
    ▼

Laravel SaaS
    │
    ├── MySQL
    │
    ├── storage/app/private
    │
    ├── avatars
    │
    ├── invoices
    │
    └── exports
            │
            ▼

Garage
(S3 Compatible)

bucket: saas-prod

├── backups
│      ├── 2026-06-23
│      ├── 2026-06-24
│      └── 2026-06-25
│
├── uploads
├── avatars
├── invoices
└── exports
```

---

# Étape 1 : Créer le projet

```bash
composer create-project laravel/laravel saas-backup-poc

cd saas-backup-poc
```

Installer Breeze

```bash
composer require laravel/breeze --dev

php artisan breeze:install

npm install

npm run build
```

Migration

```bash
php artisan migrate
```

---

# Étape 2 : Module Documents

```bash
php artisan make:model Document -m
```

Migration

```php
$table->id();

$table->foreignId('user_id');

$table->string('title');

$table->string('file');

$table->timestamps();
```

Model

```php
protected $fillable=[
'title',
'file'
];
```

Controller

```bash
php artisan make:controller DocumentController
```

Upload

```php
$file = $request->file('document');

$path = $file->store('documents');



Document::create([

'user_id'=>auth()->id(),

'title'=>$request->title,

'file'=>$path

]);
```

---

# Étape 3 : Avatar

users table

```php
$table->string('avatar')->nullable();
```

Upload

```php
$avatar=$request->avatar;


$path=$avatar->store('avatars');
```

---

# Étape 4 : Factures

Installer

```bash
composer require barryvdh/laravel-dompdf
```

Facture

```bash
storage/app/private/invoices/
```

Exemple

```text
invoice_001.pdf

invoice_002.pdf

invoice_003.pdf
```

---

# Étape 5 : Exports CSV

```text
storage/app/private/exports
```

stats.csv

---

# Étape 6 : Activités

Créer

```bash
php artisan make:model Activity -m
```

```php
event
user_id
created_at
```

Exemple

```text
Login

Upload

Delete


Generate Invoice
```

---

# Étape 7 : Installation Garage

Machine dédiée

```bash
garage server
```

Créer bucket

```bash
garage bucket create saas-prod
```

Associer clé

```bash
garage bucket allow \
saas-prod \
--read \
--write \
KEYID
```

---

# Étape 8 : Laravel S3

.env

```env
FILESYSTEM_DISK=s3


AWS_ENDPOINT=http://garage:3900

AWS_BUCKET=saas-prod


AWS_ACCESS_KEY_ID=xxxxx


AWS_SECRET_ACCESS_KEY=yyyyy



AWS_USE_PATH_STYLE_ENDPOINT=true
```

filesystems.php

```php
's3'=>[

'driver'=>'s3',

'endpoint'=>env('AWS_ENDPOINT')

]
```

---

# Étape 9 : Sauvegarde complète

Créer commande

```bash
php artisan make:command BackupSaas
```

Commande

```php
Storage::disk('s3')->put(

'backups/'.$date.'/db.sql',

file_get_contents($dump)

);
```

---

Sauvegarder uploads

```php
Storage::disk('s3')->put(

'backups/'.$date.'/documents.zip',

$zip

);
```

Sauvegarder avatars

```php
Storage::disk('s3')->put(

'backups/'.$date.'/avatars.zip',

$zip
);
```

Factures

```php
Storage::disk('s3')->put(

'backups/'.$date.'/invoices.zip',

$zip
);
```

Exports

```php
Storage::disk('s3')->put(

'backups/'.$date.'/exports.zip',

$zip
);
```

---

Résultat

```text
saas-prod


backups/


2026-06-23/


db.sql


documents.zip


avatars.zip


invoices.zip


exports.zip




2026-06-24/


db.sql


documents.zip


avatars.zip


invoices.zip


exports.zip
```

---

# Étape 10 : Déduplication

Garage ne fait pas nativement de déduplication de sauvegardes comme bs.

Deux approches :

### Option 1 — Versioning S3

Activer le versioning du bucket.

```text
documents.zip

v1
v2
v3
```

Restauration instantanée.

---

### Option 2 — BorgBackup

Sur le serveur Laravel :

```bash
borg create
```

puis

```bash
rclone sync
```

vers Garage.

Déduplication très efficace.

---

### Option 3 — Restic (celle que je recommande)

```bash
restic init


restic backup storage


restic backup mysql
```

Backend S3 Garage

```bash
export AWS_ACCESS_KEY_ID=xxx

export AWS_SECRET_ACCESS_KEY=yyy


restic -r s3:http://garage:3900/saas-prod backup storage/
```

Tu obtiens :

```text
Snapshot 1


Snapshot 2


Snapshot 3


Snapshot 4
```

avec :

* Déduplication par blocs
* Compression
* Chiffrement
* Historique complet
* Restauration sélective

---

# Architecture finale recommandée

```text
Laravel SaaS

├── Code
│    └── GitHub
│
├── MySQL
│
├── Documents
├── Avatars
├── Invoices
├── Exports
│
└── Restic
       │
       ▼

Garage S3

bucket saas-prod


Snapshots

2026-06-23
2026-06-24
2026-06-25


Deduplicated


Encrypted


Versioned
```

Ce POC est particulièrement pertinent car il reproduit une architecture de production moderne : **Laravel + stockage objet compatible S3 + sauvegardes incrémentales dédupliquées avec Restic**, très proche de ce que l'on retrouve chez de nombreux fournisseurs SaaS.
