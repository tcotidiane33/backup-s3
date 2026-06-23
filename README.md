# DocuVault — Mini SaaS Laravel avec Backup S3 (Garage) & Restic DR

> POC d'architecture de production moderne : **Laravel + Garage S3 + Restic** pour sauvegardes incrémentales dédupliquées, inspiré de Proxmox Backup Server.

# backup-s3
<img width="1866" height="1283" alt="image" src="https://github.com/user-attachments/assets/1d37f80a-0c0b-47d0-9c3f-aa9dc7a33940" />

<img width="1866" height="992" alt="image" src="https://github.com/user-attachments/assets/ac1bc29f-aa9a-43b2-8703-3380c07e5644" />


<img width="1866" height="992" alt="image" src="https://github.com/user-attachments/assets/869630ac-7440-44fb-9f3c-7da2acf9abe2" />

---

## 📋 Table des matières

- [Présentation](#-présentation)
- [Architecture](#-architecture)
- [Prérequis](#-prérequis)
- [Installation](#-installation)
- [Configuration S3 (Garage / MinIO)](#-configuration-s3-garage--minio)
- [Lancer l'application](#-lancer-lapplication)
- [Fonctionnalités](#-fonctionnalités)
- [Commandes Artisan DR](#-commandes-artisan-dr)
- [Interface Kondro Backup Server (GUI)](#-interface-kondro-backup-server-gui)
- [Structure du projet](#-structure-du-projet)
- [Contribuer](#-contribuer)
- [Licence](#-licence)

---

## 🎯 Présentation

**DocuVault** est un mini projet SaaS Laravel conçu pour valider une architecture de sauvegarde et restauration compatible S3 avec déduplication par blocs. Il permet de :

- Uploader des fichiers utilisateur (documents, images…)
- Collecter des statistiques SaaS simulées
- Sauvegarder la base de données + les fichiers vers **Garage** (stockage objet S3-compatible)
- Créer des **snapshots dédupliqués et chiffrés** via **Restic**
- **Restaurer** n'importe quelle version antérieure en un clic depuis une interface web inspirée de PBS

---

## 🏗 Architecture

```text
┌──────────────────────────┐
│     Laravel SaaS App     │
│                          │
│  ├── SQLite Database     │
│  ├── storage/app/public  │
│  │    ├── uploads/       │
│  │    └── avatars/       │
│  └── Restic CLI          │
│         │                │
└─────────┼────────────────┘
          │
          ▼
┌──────────────────────────┐
│   Garage S3 (ou MinIO)   │
│                          │
│  bucket: saas-backups    │
│                          │
│  ├── Restic Repository   │
│  │    ├── Snapshot 1     │
│  │    ├── Snapshot 2     │
│  │    └── Snapshot N     │
│  │    (dédupliqué,       │
│  │     chiffré,          │
│  │     compressé)        │
│  │                       │
│  └── Spatie Backups/     │
│       └── *.zip          │
└──────────────────────────┘
```

---

## ✅ Prérequis

| Outil        | Version minimale | Installation                                      |
|--------------|------------------|----------------------------------------------------|
| **PHP**      | 8.2+             | `sudo apt install php php-cli php-mbstring php-xml php-zip php-curl php-sqlite3` |
| **Composer** | 2.x              | [getcomposer.org](https://getcomposer.org/)        |
| **SQLite3**  | 3.x              | `sudo apt install sqlite3`                         |
| **Node.js**  | 18+ (optionnel)  | `sudo apt install nodejs npm`                      |
| **Restic**   | 0.16+            | `sudo apt install restic`                          |
| **Docker**   | 20+ (optionnel)  | Pour lancer MinIO en local si pas de Garage        |

### Extensions PHP requises

```bash
php -m | grep -E "zip|curl|mbstring|xml|sqlite3|pdo"
```

Vérifiez que ces extensions sont présentes : `curl`, `mbstring`, `xml`, `zip`, `sqlite3`, `pdo_sqlite`.

---

## 🚀 Installation

### 1. Cloner le dépôt

```bash
git clone <url-du-depot> backup-s3
cd backup-s3/saas-backup-app
```

### 2. Installer les dépendances PHP

```bash
composer install
```

### 3. Copier et configurer l'environnement

```bash
cp .env.example .env
php artisan key:generate
```

### 4. Initialiser la base de données SQLite

```bash
touch database/database.sqlite
php artisan migrate
```

### 5. Créer le lien symbolique pour le stockage public

```bash
php artisan storage:link
```

---

## 🔧 Configuration S3 (Garage / MinIO)

### Option A : Garage (production)

Si vous avez un serveur Garage, configurez dans `.env` :

```dotenv
AWS_ACCESS_KEY_ID=votre_cle_garage
AWS_SECRET_ACCESS_KEY=votre_secret_garage
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=saas-backups
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_ENDPOINT=http://votre-serveur-garage:3900
RESTIC_PASSWORD=votre_mot_de_passe_chiffrement
```

### Option B : MinIO via Docker (développement local)

```bash
# Lancer MinIO sur le port 3900
docker run -d -p 3900:9000 --name garage-mock \
  -e "MINIO_ROOT_USER=minioadmin" \
  -e "MINIO_ROOT_PASSWORD=minioadmin" \
  minio/minio server /data

# Créer le bucket
docker exec garage-mock sh -c "mkdir -p /data/saas-backups"
```

Puis dans `.env` :

```dotenv
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=saas-backups
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_ENDPOINT=http://127.0.0.1:3900
RESTIC_PASSWORD=garage-restic-secret
```

---

## ▶️ Lancer l'application

```bash
php artisan serve
```

L'application sera accessible sur :

| Page                 | URL                            | Description                         |
|----------------------|--------------------------------|-------------------------------------|
| **Dashboard SaaS**   | http://127.0.0.1:8000          | Upload fichiers, stats, métriques   |
| **Kondro Backup**    | http://127.0.0.1:8000/bs       | Interface DR style PBS              |

---

## 🔥 Fonctionnalités

### Dashboard SaaS (`/`)

- **Upload de fichiers** : Glissez ou sélectionnez des fichiers (max 10 Mo), stockés dans `storage/app/public/uploads/`
- **Statistiques SaaS** : Métriques journalières générées automatiquement (DAU simulé)
- **Navigation** : Barre de navigation vers le module Kondro Backup

### Kondro Backup Server (`/bs`)

Interface graphique inspirée de **Proxmox Backup Server (PBS)** :

- **Onglet Datastore** :
  - 🟠 **Format/Init** : Initialise le repository Restic chiffré sur S3
  - 🟠 **Backup Now** : Crée un snapshot dédupliqué (DB SQL + fichiers utilisateur)
  - 🔄 **Refresh** : Actualise la liste des snapshots
  - 🔁 **Restore** : Restauration complète d'un snapshot spécifique
  - 📟 **Task Log** : Console temps réel affichant les opérations Restic

- **Onglet Configuration** :
  - Modifier les paramètres S3 (endpoint, clés, bucket) directement via l'interface
  - Tester la connexion sans toucher au fichier `.env`

---

## 🛠 Commandes Artisan DR

Ces commandes encapsulent `restic` pour une utilisation en ligne de commande :

```bash
# Initialiser le repository Restic sur S3
php artisan dr:init

# Créer un snapshot (dump SQLite + fichiers)
php artisan dr:snapshot

# Lister tous les snapshots disponibles
php artisan dr:list

# Restaurer le dernier snapshot
php artisan dr:restore latest

# Restaurer un snapshot spécifique
php artisan dr:restore <snapshot_id>
```

### Backup Spatie (alternative ZIP)

```bash
# Backup complet (ZIP) vers S3
php artisan backup:run

# Lister les backups
php artisan backup:list

# Restaurer le dernier backup ZIP
php artisan backup:restore-latest
```

---

## 📂 Structure du projet

```
saas-backup-app/
├── app/
│   ├── Console/Commands/
│   │   ├── DrInitCommand.php          # Initialise le repo Restic S3
│   │   ├── DrSnapshotCommand.php      # Snapshot dédupliqué
│   │   ├── DrListCommand.php          # Liste les snapshots
│   │   ├── DrRestoreCommand.php       # Restauration complète
│   │   └── RestoreBackup.php          # Restauration ZIP (Spatie)
│   ├── Http/Controllers/
│   │   ├── DashboardController.php    # Dashboard SaaS (uploads, stats)
│   │   └── bsController.php           # API Kondro Backup Server
│   └── Models/
│       ├── User.php                   # Utilisateur Laravel
│       ├── UserUpload.php             # Fichiers uploadés
│       └── SaasStat.php               # Métriques journalières
├── config/
│   ├── backup.php                     # Config Spatie (destination S3)
│   └── filesystems.php                # Disque S3 pour Garage
├── database/
│   ├── database.sqlite                # Base de données locale
│   └── migrations/                    # Schéma des tables
├── resources/views/
│   ├── dashboard.blade.php            # Vue Dashboard SaaS
│   └── bs.blade.php                   # Vue Kondro Backup (PBS UI)
├── routes/
│   └── web.php                        # Routes / et /bs
├── .env                               # Variables d'environnement
└── .env.example                       # Template des variables
```

---

## 🤝 Contribuer

### Prérequis développeur

1. **Fork** le repository
2. Installer les prérequis listés [ci-dessus](#-prérequis)
3. Suivre les étapes d'[installation](#-installation)
4. Lancer MinIO avec Docker pour le développement local

### Workflow de contribution

```bash
# Créer une branche feature
git checkout -b feature/ma-fonctionnalite

# Faire vos modifications
# ...

# Tester que l'application fonctionne
php artisan serve
# Vérifier : http://127.0.0.1:8000 et http://127.0.0.1:8000/bs

# Tester les commandes DR
php artisan dr:init
php artisan dr:snapshot
php artisan dr:list

# Committer et pousser
git add .
git commit -m "feat: description de la fonctionnalité"
git push origin feature/ma-fonctionnalite
```

### Checklist avant PR

- [ ] L'application démarre sans erreur (`php artisan serve`)
- [ ] Les migrations passent (`php artisan migrate:fresh`)
- [ ] Le dashboard affiche les uploads et les stats
- [ ] L'interface Kondro Backup charge sans erreur JS
- [ ] Les commandes `dr:*` fonctionnent avec un backend S3

### Idées de contributions

- 🔐 Ajouter l'authentification (Laravel Breeze / Sanctum)
- 📊 Graphiques de statistiques avec Chart.js
- 📅 Planification automatique des backups (cron / scheduler)
- 🔔 Notifications Slack/Email après backup
- 📦 Support MySQL/PostgreSQL en plus de SQLite
- 🧪 Tests unitaires et d'intégration
- 🐳 Dockerfile et docker-compose pour déploiement

---

## 📄 Licence

Ce projet est un POC éducatif. Libre d'utilisation et de modification.
