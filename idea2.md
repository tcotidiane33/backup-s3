Je vais te créer un artifact React avec une interface web pour gérer Garage S3, intégrant les opérations clés (buckets, clés, upload, etc.).Voici le WebUI Garage S3 — une interface complète pour gérer ton instance depuis le navigateur.C'est un WebUI interactif avec toutes les sections clés :

**Overview** — métriques globales + journal d'activité récente

**Buckets** — liste avec taille/objets, création via modal (nom, clé associée, permissions read/write/owner)

**Access keys** — affichage des clés avec masquage du secret et bouton copier

**Nodes** — état du cluster, adresse RPC, barre d'utilisation disque

**Config** — ton `garage.toml` actif en lecture + tableau des ports exposés

---

Le tout est pré-rempli avec la config de ton installation (buckets `laravel-backups` / `laravel-versions` que tu avais déjà mis en place, IP `192.168.1.50`, region `garage`). Tu veux qu'on adapte quelque chose — par exemple ajouter un onglet pour explorer les objets d'un bucket, ou intégrer ça dans une vraie app Laravel ?