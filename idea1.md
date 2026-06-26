Voici un guide complet pour installer et configurer **Garage S3** dans une VM sur VMware.

---

## Garage S3 sur VM VMware — Guide d'installation

### Prérequis VM

| Ressource | Recommandé |
|-----------|-----------|
| OS | Ubuntu 22.04 / Debian 12 |
| CPU | 2 vCPU minimum |
| RAM | 2 GB minimum |
| Disque système | 20 GB |
| Disque(s) données | 1+ disques dédiés (VMDK) |
| Réseau | IP fixe recommandée |

---

### 1. Ajouter un disque de données dans VMware

Dans VMware (vSphere ou Workstation), ajoute un VMDK dédié au stockage Garage avant de démarrer la VM. Depuis la VM :

```bash
# Identifier le nouveau disque
lsblk

# Partitionner et formater (ex: /dev/sdb)
sudo fdisk /dev/sdb   # → nouvelle partition primaire
sudo mkfs.ext4 /dev/sdb1

# Monter
sudo mkdir -p /srv/garage/data
sudo mount /dev/sdb1 /srv/garage/data

# Rendre le montage persistant
echo '/dev/sdb1 /srv/garage/data ext4 defaults 0 2' | sudo tee -a /etc/fstab
```

---

### 2. Installer Garage

```bash
# Télécharger le binaire (vérifier la dernière version sur garagehq.deuxfleurs.fr)
GARAGE_VERSION="v1.0.1"
ARCH="x86_64-unknown-linux-musl"

wget https://garagehq.deuxfleurs.fr/_releases/${GARAGE_VERSION}/${ARCH}/garage
chmod +x garage
sudo mv garage /usr/local/bin/garage

# Vérifier
garage --version
```

---

### 3. Configurer Garage

```bash
sudo mkdir -p /etc/garage
sudo nano /etc/garage/garage.toml
```

**Contenu de `/etc/garage/garage.toml` :**

```toml
metadata_dir = "/srv/garage/meta"
data_dir = "/srv/garage/data"

db_engine = "lmdb"

replication_factor = 1   # 1 nœud = pas de réplication

[rpc_bind_addr]
# Adresse d'écoute interne (communication entre nœuds)
bind_addr = "0.0.0.0:3901"

[s3_api]
s3_region = "garage"
api_bind_addr = "0.0.0.0:3900"
root_domain = ".s3.local"

[s3_web]
bind_addr = "0.0.0.0:3902"
root_domain = ".web.garage.local"
index = "index.html"
error_document = "error/404.html"

[admin]
api_bind_addr = "0.0.0.0:3903"
```

```bash
sudo mkdir -p /srv/garage/meta
```

---

### 4. Créer le service systemd

```bash
sudo nano /etc/systemd/system/garage.service
```

```ini
[Unit]
Description=Garage S3-compatible object storage
After=network.target

[Service]
ExecStart=/usr/local/bin/garage -c /etc/garage/garage.toml server
Restart=on-failure
RestartSec=5s
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now garage
sudo systemctl status garage
```

---

### 5. Initialiser le cluster (nœud unique)

```bash
# Récupérer l'ID du nœud
garage -c /etc/garage/garage.toml node id

# Exemple de sortie :
# <NODE_ID>@<IP>:3901

# Assigner le nœud à une zone (layout)
garage -c /etc/garage/garage.toml layout assign \
  <NODE_ID> \
  --zone dc1 \
  --capacity 100G \
  --tag "vmware-node"

# Appliquer le layout
garage -c /etc/garage/garage.toml layout apply --version 1
```

---

### 6. Créer une clé d'accès et un bucket

```bash
# Créer une clé
garage -c /etc/garage/garage.toml key create my-app-key

# Noter l'Access Key ID et Secret Access Key affichés

# Créer un bucket
garage -c /etc/garage/garage.toml bucket create my-bucket

# Donner accès à la clé sur ce bucket
garage -c /etc/garage/garage.toml bucket allow my-bucket \
  --read --write --owner \
  --key my-app-key
```

---

### 7. Tester avec AWS CLI

```bash
# Installer aws cli
sudo apt install awscli -y

# Configurer
aws configure
# AWS Access Key ID: <ton access key>
# AWS Secret Access Key: <ton secret key>
# Default region name: garage
# Default output format: json

# Tester un upload (path-style endpoint)
aws s3 cp ./test.txt s3://my-bucket/ \
  --endpoint-url http://<IP_VM>:3900
```

---

### 8. Points VMware spécifiques

**Snapshot-safe** — Garage utilise LMDB, évite de faire des snapshots VMware à chaud sans arrêter le service d'abord :
```bash
sudo systemctl stop garage
# → faire le snapshot VMware
sudo systemctl start garage
```

**Réseau** — Si tu veux exposer Garage hors de la VM, pense à configurer le pare-feu VMware/NSX pour autoriser les ports `3900` (S3 API) et `3903` (Admin).

**Multi-disques** — Pour la production, tu peux passer `data_dir` en tableau dans le TOML :
```toml
data_dir = [
  { path = "/srv/garage/data1", capacity = "500G" },
  { path = "/srv/garage/data2", capacity = "500G" }
]
```

---

Tu veux qu'on intègre ça avec ton projet Laravel (Spatie Backup) que tu avais déjà configuré, ou tu veux d'abord valider l'install de base ?