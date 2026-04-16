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

Ce script crée l'intégralité du schéma relationnel de la base de données `univ_insight` à partir du MLD fait par Chloé (Modèle Logique de Données).

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

Ce fichier contient 8 requêtes SQL d'analyse métier sur les données d'insertion professionnelle fais par Talubna:

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
