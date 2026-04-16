# Univ Insight — Front-end (branche `ronic-front`)

> **README spécifique à la branche `ronic-front`.**  
> Cette branche contient l'**application web complète** développée principalement par **Ronic Takougang** et **Adrien**. C'est la branche à utiliser pour exécuter et démontrer le projet.

---

## Présentation du projet

**Univ Insight** est un observatoire national de l'insertion professionnelle des diplômés, réalisé dans le cadre du module **Modélisation des connaissances** en **M1 Informatique et Big Data**.

L'outil s'appuie sur les données ouvertes du **MESRI** (Ministère de l'Enseignement Supérieur) issues des enquêtes d'insertion professionnelle menées 18 et 30 mois après l'obtention du diplôme.

> Source des données : [data.enseignementsup-recherche.gouv.fr](https://data.enseignementsup-recherche.gouv.fr)

---

## Stack technique

| Technologie | Usage |
| :--- | :--- |
| **PHP 8.2** | Backend et pages dynamiques (Apache) |
| **MySQL 8.0** | Base de données relationnelle |
| **HTML / CSS / JS** | Interface utilisateur |
| **Chart.js 4.4** | Graphiques interactifs (barres, lignes, scatter) |
| **Docker** | Conteneurisation de l'application |
| **phpMyAdmin** | Administration de la base de données |

---

## Architecture de l'application front-end

```
├── client/
│   ├── index.php          # Tableau de bord — KPIs nationaux + Top disciplines/académies
│   ├── formation.php      # Fiche détaillée d'une formation avec comparaison nationale
│   ├── etablissement.php  # Fiche établissement avec évolution temporelle
│   ├── comparaison.php    # Comparaison côte à côte de 2 formations ou établissements
│   ├── stats.php          # 8 requêtes analytiques interactives + indice ICA
│   ├── search_api.php     # API JSON de recherche (établissements + formations)
│   ├── script.js          # Scripts JS (barre de recherche, timer, chart de démo)
│   └── style.css          # Feuille de styles (design system inspiré DSFR)
├── connexion.php          # Connexion PDO à la base MySQL
├── Dockerfile             # Image PHP 8.2 / Apache
├── docker-compose.yml     # Orchestration : PHP/Apache + MySQL 8.0 + phpMyAdmin
└── README_DOCKER.md       # Guide d'installation Docker (rédigé par Ronic)
```

---

## Pages de l'application

### 1. `index.php` — Tableau de bord national

La page d'accueil présente une vue synthétique de l'insertion professionnelle à l'échelle nationale :

- **4 KPIs dynamiques** (taux d'emploi moyen, taux CDI moyen, taux cadre moyen, salaire médian moyen) avec variation par rapport à l'année précédente (flèches tendance ↑↓)
- **Filtre par année** (toutes les années disponibles dans la BDD) et **filtre par domaine** (Sciences, Lettres, Droit…)
- **Top 5 disciplines** par taux d'emploi à 18 mois — affiché sous forme de classement avec jauge de progression
- **Top 5 académies** par taux d'emploi — graphique à barres généré avec Chart.js
- **Barre de recherche intelligente** (live search) qui interroge `search_api.php` et redirige vers la page formation ou établissement

### 2. `formation.php` — Fiche formation

Page dédiée à une formation précise (filtrée par `id_diplome`) :

- Informations de contexte : intitulé, discipline, domaine, établissement, région
- **KPIs de la formation** (taux emploi, CDI, cadre, salaire) sur l'année et le délai choisis (18 ou 30 mois)
- **Comparaison à la moyenne nationale et à la moyenne de la discipline** — mise en valeur des écarts positifs/négatifs
- **Évolution temporelle** sur les 5 dernières années (graphique ligne Chart.js)
- Sélecteur de formation, d'année et de délai d'enquête (18 / 30 mois)

### 3. `etablissement.php` — Fiche établissement

Page dédiée à un établissement (filtrée par `id_etab`) :

- Informations : nom, type, ville, région
- **KPIs agrégés** de l'établissement (moyennes de toutes ses formations)
- **Comparaison à la moyenne nationale** pour l'année sélectionnée
- **Évolution temporelle** du taux d'emploi sur toutes les années disponibles (graphique ligne)
- **Liste des disciplines** couvertes par l'établissement avec leurs indicateurs
- Nombre de formations référencées et total de répondants

### 4. `comparaison.php` — Comparaison côte à côte

Permet de comparer 2 entités (formations ou établissements) sur le même écran :

- **Mode double** : sélecteurs A et B indépendants
- Affichage des indicateurs en colonnes avec **delta coloré** (vert si A > B, rouge sinon)
- Bascule entre le mode **formation** et le mode **établissement** via un toggle
- Filtre par année et délai d'enquête

### 5. `stats.php` — Statistiques analytiques (8 requêtes + ICA)

Page la plus technique du projet — 8 requêtes SQL avancées illustrées avec des graphiques Chart.js :

| N° | Requête | Technique SQL |
| :--- | :--- | :--- |
| **R1** | Taux cadre moyen par discipline | `GROUP BY`, `AVG`, `ORDER BY` |
| **R2** | Établissements au-dessus de la moyenne nationale | **Sous-requête scalaire corrélée** dans `HAVING` |
| **R3** | Évolution du taux CDI par discipline sur 5 ans | `GROUP BY` double (discipline × année), graphique multi-lignes |
| **R4** | Disciplines en déclin continu du taux d'emploi | Comparaison year-over-year, détection de tendance baissière |
| **R5** | Formations avec taux cadre > 70 % ET salaire > médiane | **Double critère** — graphique scatter quadrant |
| **R6** | Classement des régions par insertion | Agrégation multi-niveaux (région → établissement → formation) |
| **R7** | Diplômes sans enquête récente | `LEFT JOIN` + filtre `IS NULL` pour détecter les données manquantes |
| **R8** | Gain d'insertion entre 18 et 30 mois par discipline | Auto-jointure sur `ANNEE_ENQUETE` pour comparer les deux délais |
| **BONUS** | **Indice ICA** (Indice Composite d'Attractivité) | Score pondéré : emploi × 0,4 + CDI × 0,2 + cadre × 0,2 + salaire normalisé × 0,2 |

> Tous les graphiques sont filtrables en temps réel par **année** et **domaine**.

### 6. `search_api.php` — API de recherche

Endpoint JSON interrogeable en `GET ?q=...` :

- Recherche simultanée dans les **établissements** (nom, ville) et les **formations** (intitulé, discipline, nom de l'établissement)
- Tri intelligent : priorité aux résultats commençant par le terme saisi (`LIKE 'terme%'` avant `LIKE '%terme%'`)
- Limites : 5 établissements + 10 formations max par requête
- Gestion des erreurs PDO avec retour JSON `{success: false, error: "..."}`

---

## Design & Interface

Le front-end suit un **design system cohérent** inspiré du DSFR (Système de Design de l'État) avec :

- **Palette France** : bleu institutionnel `#000091`, rouge Marianne `#E1000F`, gris neutres
- **Sidebar de navigation** fixe à gauche avec badge actif et indicateur de sélection bleu
- **Typographie** : DM Sans (Google Fonts) pour une lisibilité moderne
- **Cartes statistiques** avec valeurs grandes polices, labels uppercase, et badges de tendance colorés
- Affichage gracieux des données manquantes (`—`) via les fonctions helper `fmt()` et `fmtSal()`

---

## Contributions de Ronic Takougang

Ronic a été le **principal développeur** de cette branche. Il a créé de zéro l'ensemble de l'application web :

- ✅ Les **6 pages PHP** du dossier `client/` (index, formation, établissement, comparaison, stats, search API)
- ✅ Le fichier `style.css` (900+ lignes) — design system complet, responsive pour la sidebar
- ✅ Le fichier `script.js` — barre de recherche live, gestion du timer, graphique Chart.js de démo
- ✅ Le fichier `connexion.php` — connexion PDO centralisée avec paramètres Docker-aware
- ✅ Le **`Dockerfile`** (image PHP 8.2/Apache avec PDO MySQL)
- ✅ Le **`docker-compose.yml`** — orchestration des 3 services : app PHP, MySQL 8.0, phpMyAdmin
- ✅ Le **`README_DOCKER.md`** — guide d'installation Docker complet
- ✅ Le correctif Apache MPM (`a2dismod mpm_event && a2enmod mpm_prefork`) pour résoudre l'erreur de démarrage du container PHP

## Contributions d'Adrien

Adrien a contribué à la réflexion et au développement de la partie analytique :

- ✅ Conception des **requêtes SQL analytiques** (R1 à R8) présentées dans `stats.php`
- ✅ Définition de l'**indice ICA** (Indice Composite d'Attractivité) — formule pondérée combinant emploi, CDI, cadre et salaire normalisé
- ✅ Participation à la structuration des pages et à la logique de comparaison

---

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

---

## Démo vidéo

[▶ Voir la démo sur Google Drive](https://drive.google.com/file/d/1U-BzMkKuVG_WBXSfzRVjoQs3cZ-DHuvj/view?usp=sharing)

---

## Contributeurs

| Contributeur | Rôle |
| :--- | :--- |
| **Ronic Takougang** | Développement front-end complet, configuration Docker |
| **Adrien** | Requêtes analytiques SQL, indice ICA |
| **Chloé Makoundou** — [@Chlooow](https://github.com/Chlooow) | Architecture BDD, MCD/MLD, coordination |
| **Talubna** | Contribution au projet |

---

## Liens utiles

- Présentation : [https://canva.link/p1xd0am5q4nkhob](https://canva.link/p1xd0am5q4nkhob)
- Rapport : *(lien à compléter)*
- Source des données MESRI : [data.enseignementsup-recherche.gouv.fr](https://data.enseignementsup-recherche.gouv.fr)
