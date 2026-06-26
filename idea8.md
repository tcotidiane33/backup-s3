## Dockerisation complète de la stack

### Structure des fichiers

```
projet/
├── docker/
│   ├── php/
│   │   ├── Dockerfile
│   │   └── php.ini
│   ├── nginx/
│   │   └── default.conf
│   ├── supervisor/
│   │   └── supervisord.conf
│   └── reverb/
│       └── reverb.conf
├── docker-compose.yml
├── docker-compose.prod.yml
└── .dockerignore
```

---

### 1. `docker-compose.yml` (développement)

```yaml
version: '3.9'

services:

  # ── Application PHP-FPM ───────────────────────
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      target: development
    container_name: kondro_app
    restart: unless-stopped
    volumes:
      - .:/var/www/html
      - ./docker/php/php.ini:/usr/local/etc/php/conf.d/custom.ini
    environment:
      - APP_ENV=local
      - APP_DEBUG=true
    depends_on:
      mysql:
        condition: service_healthy
      garage:
        condition: service_started
    networks:
      - kondro_net

  # ── Nginx ─────────────────────────────────────
  nginx:
    image: nginx:1.25-alpine
    container_name: kondro_nginx
    restart: unless-stopped
    ports:
      - "8000:80"
    volumes:
      - .:/var/www/html
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app
    networks:
      - kondro_net

  # ── MySQL ─────────────────────────────────────
  mysql:
    image: mysql:8.0
    container_name: kondro_mysql
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: kondro_db
      MYSQL_USER: kondro
      MYSQL_PASSWORD: secret
    volumes:
      - mysql_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 5s
      retries: 10
    networks:
      - kondro_net

  # ── Redis (cache + queues) ────────────────────
  redis:
    image: redis:7-alpine
    container_name: kondro_redis
    restart: unless-stopped
    command: redis-server --appendonly yes
    volumes:
      - redis_data:/data
    networks:
      - kondro_net

  # ── Laravel Queue Worker ──────────────────────
  worker:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      target: development
    container_name: kondro_worker
    restart: unless-stopped
    command: php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
    volumes:
      - .:/var/www/html
    depends_on:
      - redis
      - mysql
    networks:
      - kondro_net

  # ── Laravel Reverb (WebSocket) ────────────────
  reverb:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      target: development
    container_name: kondro_reverb
    restart: unless-stopped
    command: php artisan reverb:start --host=0.0.0.0 --port=8080
    ports:
      - "8080:8080"
    volumes:
      - .:/var/www/html
    depends_on:
      - redis
    networks:
      - kondro_net

  # ── Garage S3 ─────────────────────────────────
  garage:
    image: dxflrs/garage:v1.0.1
    container_name: kondro_garage
    restart: unless-stopped
    ports:
      - "3900:3900"   # S3 API
      - "3901:3901"   # RPC
      - "3903:3903"   # Admin
    volumes:
      - ./docker/garage/garage.toml:/etc/garage.toml
      - garage_meta:/srv/garage/meta
      - garage_data:/srv/garage/data
    networks:
      - kondro_net

  # ── Mailpit (dev mail catcher) ────────────────
  mailpit:
    image: axllent/mailpit:latest
    container_name: kondro_mailpit
    ports:
      - "8025:8025"   # UI
      - "1025:1025"   # SMTP
    networks:
      - kondro_net

networks:
  kondro_net:
    driver: bridge

volumes:
  mysql_data:
  redis_data:
  garage_meta:
  garage_data:
```

---

### 2. `docker/php/Dockerfile`

```dockerfile
# ── Stage base ────────────────────────────────
FROM php:8.3-fpm-alpine AS base

WORKDIR /var/www/html

# Dépendances système
RUN apk add --no-cache \
    bash curl git zip unzip \
    libpng-dev libjpeg-turbo-dev \
    libzip-dev oniguruma-dev \
    icu-dev linux-headers

# Extensions PHP
RUN docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
    pdo_mysql mbstring zip gd \
    bcmath intl opcache pcntl

# Redis extension
RUN apk add --no-cache $PHPIZE_DEPS \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Permissions
RUN addgroup -g 1000 -S kondro \
 && adduser -u 1000 -S kondro -G kondro \
 && chown -R kondro:kondro /var/www/html

# ── Stage development ─────────────────────────
FROM base AS development

# Xdebug pour le dev
RUN apk add --no-cache $PHPIZE_DEPS \
 && pecl install xdebug \
 && docker-php-ext-enable xdebug \
 && apk del $PHPIZE_DEPS

USER kondro

COPY --chown=kondro:kondro composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader

COPY --chown=kondro:kondro . .
RUN composer dump-autoload --optimize

CMD ["php-fpm"]

# ── Stage production ──────────────────────────
FROM base AS production

ENV APP_ENV=production
ENV APP_DEBUG=false

USER kondro

COPY --chown=kondro:kondro composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --optimize-autoloader

COPY --chown=kondro:kondro . .
RUN composer dump-autoload --optimize --no-dev \
 && php artisan config:cache \
 && php artisan route:cache \
 && php artisan view:cache \
 && php artisan event:cache

CMD ["php-fpm"]
```

---

### 3. `docker/php/php.ini`

```ini
; Performances
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.revalidate_freq=0

; Uploads (fichiers Garage)
upload_max_filesize=512M
post_max_size=512M
max_execution_time=300
memory_limit=512M

; Timezone
date.timezone=Africa/Abidjan
```

---

### 4. `docker/nginx/default.conf`

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php;

    client_max_body_size 512M;

    # Assets statiques
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # Laravel
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass   app:9000;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include        fastcgi_params;
        fastcgi_read_timeout 300;
        fastcgi_buffer_size 16k;
        fastcgi_buffers 4 16k;
    }

    # WebSocket Reverb
    location /app {
        proxy_pass         http://reverb:8080;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade $http_upgrade;
        proxy_set_header   Connection "upgrade";
        proxy_set_header   Host $host;
        proxy_cache_bypass $http_upgrade;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    # Sécurité
    location ~ /\.(?!well-known).* { deny all; }
    location ~ /\.env              { deny all; }
}
```

---

### 5. `docker/garage/garage.toml`

```toml
metadata_dir = "/srv/garage/meta"
data_dir     = "/srv/garage/data"
db_engine    = "lmdb"

replication_factor = 1

[rpc_bind_addr]
bind_addr = "0.0.0.0:3901"

[s3_api]
s3_region    = "garage"
api_bind_addr = "0.0.0.0:3900"

[admin]
api_bind_addr = "0.0.0.0:3903"
```

---

### 6. `docker-compose.prod.yml` (production — override)

```yaml
version: '3.9'

services:

  app:
    build:
      target: production
    volumes: []                          # pas de bind mount en prod
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 1G

  nginx:
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/nginx/prod.conf:/etc/nginx/conf.d/default.conf
      - /etc/letsencrypt:/etc/letsencrypt:ro   # certbot SSL

  worker:
    build:
      target: production
    volumes: []
    deploy:
      replicas: 2                        # 2 workers en prod

  reverb:
    build:
      target: production
    volumes: []
    ports:
      - "8080:8080"

  mysql:
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - /data/mysql:/var/lib/mysql       # volume hôte dédié

  garage:
    volumes:
      - /data/garage/meta:/srv/garage/meta
      - /data/garage/data:/srv/garage/data

  # Pas de mailpit en prod
  mailpit:
    profiles: ["dev"]                    # exclu en prod
```

---

### 7. `.dockerignore`

```
.git
.gitignore
node_modules
vendor
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
.env
.env.*
docker-compose*.yml
*.md
tests/
```

---

### 8. `Makefile` — commandes courantes

```makefile
# Makefile
.PHONY: up down build shell migrate seed fresh logs

# Démarrage
up:
	docker compose up -d

# Arrêt
down:
	docker compose down

# Build complet
build:
	docker compose build --no-cache

# Shell dans le container app
shell:
	docker compose exec app bash

# Migrations
migrate:
	docker compose exec app php artisan migrate

# Seed
seed:
	docker compose exec app php artisan db:seed

# Reset complet BDD
fresh:
	docker compose exec app php artisan migrate:fresh --seed

# Logs en temps réel
logs:
	docker compose logs -f app worker reverb

# Init Garage (à lancer une seule fois)
garage-init:
	@echo "Récupération du node ID Garage..."
	docker compose exec garage garage node id
	@echo "Lancez ensuite : make garage-layout NODE_ID=<id>"

garage-layout:
	docker compose exec garage garage layout assign $(NODE_ID) \
		--zone dc1 --capacity 100G --tag kondro
	docker compose exec garage garage layout apply --version 1

# Créer les buckets Garage
garage-buckets:
	docker compose exec garage garage bucket create kondro-files
	docker compose exec garage garage bucket create kondro-backups
	docker compose exec garage garage key create kondro-app-key
	@echo "Copie les clés dans ton .env"

# Production
up-prod:
	docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

build-prod:
	docker compose -f docker-compose.yml -f docker-compose.prod.yml build --no-cache
```

---

### 9. Premier démarrage

```bash
# 1. Build et démarrage
make build
make up

# 2. Installer les dépendances Laravel
docker compose exec app composer install
docker compose exec app npm install && npm run build

# 3. Setup Laravel
docker compose exec app cp .env.example .env
docker compose exec app php artisan key:generate

# 4. Migrations
make migrate

# 5. Init Garage
make garage-init
# → copier le NODE_ID affiché
make garage-layout NODE_ID=<id_affiché>
make garage-buckets
# → copier les clés Access Key / Secret dans .env

# 6. Vérifier tous les services
docker compose ps
```

---

### Architecture Docker finale

```
┌─────────────────────────────────────────────────┐
│                   kondro_net                    │
│                                                 │
│  ┌──────────┐    ┌──────────┐  ┌─────────────┐  │
│  │  nginx   │───►│   app    │  │   worker    │  │
│  │  :80     │    │ php-fpm  │  │ queue:work  │  │
│  └──────────┘    └────┬─────┘  └──────┬──────┘  │
│        │              │               │         │
│        │         ┌────▼───────────────▼──────┐  │
│        │         │         redis             │  │
│        │         └───────────────────────────┘  │
│        │                                        │
│  ┌─────▼──────┐  ┌──────────┐  ┌─────────────┐  │
│  │   reverb   │  │  mysql   │  │   garage    │  │
│  │  ws :8080  │  │  :3306   │  │  s3 :3900   │  │
│  └────────────┘  └──────────┘  └─────────────┘  │
└─────────────────────────────────────────────────┘
```

Maintenant on configure Nginx en reverse proxy pour la prod avec SSL ? C'est la dernière brique pour avoir une stack 100% production-ready.