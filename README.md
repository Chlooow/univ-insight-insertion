# Branche `database` — Scripts SQL & DDL

> **Auteur** : Talubna  
> Cette branche regroupe l'ensemble des scripts SQL réalisés par Talubna pour le projet Univ Insight : le DDL de création de la base de données et les requêtes d'analyse.

---

## Contenu de la branche

```
├── mld.sql          # DDL — Création du schéma relationnel (6 tables)
├── Requête.sql      # Scripts SQL — 8 requêtes d'analyse métier
└── data/            # Données brutes (CSV MESRI) et données Docker PostgreSQL
```

---

## `mld.sql` — Script DDL (Data Definition Language)

Ce script crée l'intégralité du schéma relationnel de la base de données `univ_insight` à partir du MLD (Modèle Logique de Données).

Il définit les **6 tables** suivantes (dans l'ordre de création pour respecter les contraintes de clés étrangères) :

| Table | Rôle |
| :--- | :--- |
| `REGION` | Table de référence géographique (régions françaises avec code INSEE) |
| `DISCIPLINE` | Table de référence thématique (ex : Informatique, Droit, Lettres) |
| `ANNEE_ENQUETE` | Table de référence temporelle (années d'enquête à 18 ou 30 mois) |
| `ETABLISSEMENT` | Universités et grandes écoles, rattachées à une région |
| `DIPLOME` | Formations (Master, Licence Pro…), rattachées à un établissement et une discipline |
| `RESULTAT_IP` | **Table centrale (table de faits)** — indicateurs d'insertion : taux d'emploi, taux CDI, taux cadre, salaire médian, délai d'emploi, nombre de répondants |

Le script inclut également :
- Des **contraintes d'intégrité** (`CHECK`, `UNIQUE`, clés étrangères avec `ON DELETE`)
- Des **index** sur les colonnes les plus interrogées pour optimiser les performances (`id_diplome`, `id_annee`, `id_region`)
- Une suppression préalable des tables (`DROP TABLE IF EXISTS`) pour permettre une réinitialisation propre

---

## `Requête.sql` — Scripts d'analyse (8 requêtes)

Ce fichier contient 8 requêtes SQL d'analyse métier sur les données d'insertion professionnelle :

| N° | Objectif |
| :--- | :--- |
| 1 | Taux de cadre moyen par discipline, pour les enquêtes à **18 mois**, trié du plus élevé au plus bas |
| 2 | Diplômes dont le salaire médian est **supérieur à la médiane nationale** de leur discipline pour une année donnée |
| 3 | Évolution du **taux CDI moyen** par discipline sur les **5 dernières années** |
| 4 | Disciplines en **déclin continu du taux d'emploi** sur 3 années consécutives |
| 5 | Diplômes avec un **taux cadre > 70 %** et un salaire médian au-dessus de la moyenne nationale |
| 6 | **Taux d'emploi moyen par région** pour la dernière année disponible dans les données |
| 7 | Diplômes n'ayant **aucun résultat d'enquête récent** (absents des 3 dernières années) |
| 8 | Comparaison de la **progression du taux d'emploi entre 18 et 30 mois** par discipline |

---

# Univ Insight — Insertion Professionnelle des Diplômés

## Présentation du projet

**Univ Insight** est un observatoire national de l'insertion professionnelle des diplômés, réalisé dans le cadre du module **Modélisation des connaissances** en **M1 Informatique et Big Data**[...]  

Le projet s'appuie sur les données ouvertes du **MESRI** (Ministère de l'Enseignement Supérieur) issues des enquêtes d'insertion professionnelle menées 18 et 30 mois après l'obtention du dipl[...]  
Source des données : [data.enseignementsup-recherche.gouv.fr](https://data.enseignementsup-recherche.gouv.fr)

L'outil permet :  
- Aux **étudiants** de comparer les débouchés selon les formations  
- Aux **établissements** de se comparer à la moyenne nationale  
- Aux **décideurs** d'identifier les disciplines en difficulté

## Stack technique

| Technologie | Usage |
| :--- | :--- |
| **PHP 8.2** | Backend et pages dynamiques (Apache) |
| **MySQL 8.0** | Base de données relationnelle |
| **HTML / CSS / JS** | Interface utilisateur |
| **Docker** | Conteneurisation de l'application |
| **phpMyAdmin** | Administration de la base de données |

## Structure du projet

```
├── client/                  # Pages PHP de l'interface web
│   ├── index.php            # Tableau de bord (indicateurs clés, top 5 disciplines)
│   ├── formation.php        # Fiche formation comparative
│   ├── etablissement.php    # Fiche établissement
│   ├── comparaison.php      # Comparaison côte à côte
│   ├── stats.php            # Requêtes SQL interactives
│   ├── search_api.php       # API de recherche
│   ├── script.js            # Scripts JS
│   └── style.css            # Feuille de styles
├── connexion.php            # Connexion PDO à la base
├── mld.sql                  # Script de création du schéma (DDL)
├── data/
│   ├── univ_insight.sql     # Données d'insertion (DML)
│   ├── correctif_manquants.sql  # Correctifs de données manquantes
│   └── fr-esr-insertion_professionnelle-master.csv  # Données brutes MESRI
├── schema-mcd/              # Schémas MCD (versions 1, 2, brouillon)
├── docker-compose.yml       # Orchestration des services Docker
├── Dockerfile               # Image PHP/Apache
└── projet-09.pdf            # Sujet du projet
```

## Base de données

Le schéma relationnel est composé de 6 tables :

- **REGION** — Régions géographiques
- **ETABLISSEMENT** — Universités et grandes écoles
- **DISCIPLINE** — Grandes disciplines (Informatique, Droit, Lettres…)
- **DIPLOME** — Formations rattachées à un établissement et une discipline
- **ANNEE_ENQUETE** — Années d'enquête avec délai (18 ou 30 mois)
- **RESULTAT_IP** — Table centrale contenant les indicateurs d'insertion (taux d'emploi, taux CDI, taux cadre, salaire médian, etc.)

## Organisation des branches

Chaque branche correspond à une étape / un périmètre du projet :

| Branche | À quoi elle sert |
| :--- | :--- |
| `main` | Branche de référence. Contient la **documentation** (README) et les **fichiers de base** du projet. Elle sert surtout de point d’entrée pour comprendre le dépôt. |
| `database` | Branche dédiée à la **mise en place de la base de données** : scripts SQL (création du schéma / MLD, requêtes), et éléments Docker liés à la BDD. Utile pour travailler uniquement sur la partie modèle + données. |
| `ronic-front` | Branche dédiée à l’**application web complète** (front PHP). Contient l’interface (`client/`) et la configuration **Docker Compose** pour lancer l’ensemble des services (PHP/Apache + MySQL + phpMyAdmin) en local. C’est la branche à utiliser pour exécuter et démontrer le projet. |

## Lancer le projet

### Prérequis

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) installé sur votre machine

### Installation et lancement

1. **Cloner le dépôt** et se placer sur la branche `ronic-front` :
   ```bash
   git clone https://github.com/Chlooow/univ-insight-insertion.git
   cd univ-insight-insertion
   git checkout ronic-front
   ```

2. **Lancer les conteneurs Docker** :
   ```bash
   docker-compose up --build
   ```
   > Au premier lancement, Docker télécharge les images et importe automatiquement la base de données `univ_insight`. Cela peut prendre 1 à 2 minutes.

3. **Accéder à l'application** :

   | Service | URL | Description |
   | :--- | :--- | :--- |
   | **Site Web** | [http://localhost:8080/client](http://localhost:8080/client) | Interface principale du projet |
   | **phpMyAdmin** | [http://localhost:8081](http://localhost:8081) | Gestion de la base de données |

### Connexion à la base de données (logiciel tiers)

Si vous utilisez **DBeaver**, **TablePlus** ou **HeidiSQL** :
- **Hôte** : `localhost`
- **Port** : `3307`
- **Utilisateur** : `root`
- **Mot de passe** : `root`
- **Base de données** : `univ_insight`

### Arrêter le projet

```bash
docker-compose down
```

> En cas de conflit de ports (8080 ou 8081 déjà utilisés), modifiez les ports dans `docker-compose.yml` (les numéros à gauche du `:`).

## Démo Video
https://drive.google.com/file/d/1U-BzMkKuVG_WBXSfzRVjoQs3cZ-DHuvj/view?usp=sharing

##  Contributeurs

- **Chloé Makoundou** — [@Chlooow](https://github.com/Chlooow)
- **Ronic Takougang**
- **Talubna**
- **Adrien**

## 🔗 Liens utiles

- Présentation : [https://canva.link/p1xd0am5q4nkhob](https://canva.link/p1xd0am5q4nkhob)
- Lien du Rapport : 
