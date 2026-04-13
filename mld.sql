/* ====== Creation du MLD a partir du MCD ======*/

/* rappel :

• REGION (id_region, nom, code_insee)

• ETABLISSEMENT (id_etab, nom, type, ville, id_region)

• DISCIPLINE (id_disc, nom, domaine) — ex : Informatique, Droit, Lettres

• DIPLOME (id_diplome, intitule, niveau, id_disc, id_etab)

• ANNEE_ENQUETE (id_annee, annee, delai_mois) — UNIQUE(annee, delai_mois)

• RESULTAT_IP (id_res, taux_emploi, taux_cdi, taux_cadre, salaire_median, delai_emploi, nb_repondants,

id_diplome, id_annee) -> table centrale */
-- Configuration de la base de données
SET client_encoding = 'UTF8';

-- 1. STRUCTURE (Basée sur votre MLD)
DROP TABLE IF EXISTS RESULTAT_IP CASCADE;
DROP TABLE IF EXISTS DIPLOME CASCADE;
DROP TABLE IF EXISTS ETABLISSEMENT CASCADE;
DROP TABLE IF EXISTS ANNEE_ENQUETE CASCADE;
DROP TABLE IF EXISTS DISCIPLINE CASCADE;
DROP TABLE IF EXISTS REGION CASCADE;

CREATE TABLE REGION (
    id_region SERIAL PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    code_insee VARCHAR(5)
);

CREATE TABLE DISCIPLINE (
    id_disc SERIAL PRIMARY KEY,
    nom VARCHAR(150) NOT NULL,
    domaine VARCHAR(150)
);

CREATE TABLE ANNEE_ENQUETE (
    id_annee SERIAL PRIMARY KEY,
    annee INT NOT NULL,
    delai_mois SMALLINT NOT NULL,
    CONSTRAINT uq_annee_delai UNIQUE (annee, delai_mois)
);

CREATE TABLE ETABLISSEMENT (
    id_etab SERIAL PRIMARY KEY,
    nom VARCHAR(200) NOT NULL,
    type VARCHAR(100),
    ville VARCHAR(100),
    id_region INT REFERENCES REGION(id_region)
);

CREATE TABLE DIPLOME (
    id_diplome SERIAL PRIMARY KEY,
    intitule VARCHAR(255) NOT NULL,
    niveau VARCHAR(50),
    id_disc INT REFERENCES DISCIPLINE(id_disc),
    id_etab INT REFERENCES ETABLISSEMENT(id_etab) ON DELETE CASCADE
);

CREATE TABLE RESULTAT_IP (
    id_res SERIAL PRIMARY KEY,
    taux_emploi DECIMAL(5,1),
    taux_cdi DECIMAL(5,1),
    taux_cadre DECIMAL(5,1),
    salaire_median INT,
    delai_emploi SMALLINT,
    nb_repondants INT,
    id_diplome INT REFERENCES DIPLOME(id_diplome) ON DELETE CASCADE,
    id_annee INT REFERENCES ANNEE_ENQUETE(id_annee),
    CONSTRAINT chk_taux CHECK (taux_emploi BETWEEN 0 AND 100),
    CONSTRAINT chk_sal CHECK (salaire_median IS NULL OR salaire_median > 0)
);

-- 2. INSERTION DES DONNÉES (Respect des contraintes)

-- 3 Régions minimum
INSERT INTO REGION (nom, code_insee) VALUES 
('Île-de-France', '11'), ('Auvergne-Rhône-Alpes', '84'), ('Occitanie', '76');

-- 8 Disciplines (Sciences, SHS, DEG, Santé)
INSERT INTO DISCIPLINE (nom, domaine) VALUES 
('Informatique', 'Sciences'), ('Physique', 'Sciences'),
('Sociologie', 'SHS'), ('Psychologie', 'SHS'),
('Droit des affaires', 'DEG'), ('Gestion de production', 'DEG'),
('Médecine', 'Santé'), ('Pharmacie', 'Santé');

-- 8 Établissements (Universités et Grandes Écoles)
INSERT INTO ETABLISSEMENT (nom, type, ville, id_region) VALUES 
('Sorbonne Université', 'Université', 'Paris', 1), ('Polytechnique', 'Grande École', 'Palaiseau', 1),
('Université Lyon 1', 'Université', 'Lyon', 2), ('EM Lyon', 'Grande École', 'Écully', 2),
('INSA Lyon', 'Grande École', 'Villeurbanne', 2), ('Université de Montpellier', 'Université', 'Montpellier', 3),
('Toulouse Business School', 'Grande École', 'Toulouse', 3), ('Université de Toulouse', 'Université', 'Toulouse', 3);

-- Années d'enquête 2019 à 2023 (18 et 30 mois)
INSERT INTO ANNEE_ENQUETE (annee, delai_mois) VALUES 
(2019, 18), (2020, 18), (2021, 18), (2022, 18), (2023, 18),
(2021, 30), (2022, 30);

-- 15 Diplômes minimum (Lien Discipline x Etablissement)
INSERT INTO DIPLOME (intitule, niveau, id_disc, id_etab) 
SELECT 
    'Master ' || d.nom, 'Master', d.id_disc, e.id_etab
FROM DISCIPLINE d, ETABLISSEMENT e
WHERE (d.id_disc + e.id_etab) % 4 = 0 LIMIT 20;

-- Génération de 150+ lignes dans RESULTAT_IP avec variations réalistes
INSERT INTO RESULTAT_IP (taux_emploi, taux_cdi, taux_cadre, salaire_median, delai_emploi, nb_repondants, id_diplome, id_annee)
SELECT 
    85 + (random() * 14), -- Taux emploi entre 85% et 99%
    70 + (random() * 25), -- Taux CDI
    60 + (random() * 35), -- Taux cadre
    1800 + (floor(random() * 3200)), -- Salaire entre 1800€ et 5000€
    A.delai_mois,
    20 + (floor(random() * 100)),
    D.id_diplome,
    A.id_annee
FROM DIPLOME D, ANNEE_ENQUETE A;
/*
-- Unicité : Un seul résultat par diplôme pour une année d'enquête donnée
CONSTRAINT uq_res UNIQUE (id_diplome, id_annee),

-- Clés étrangères
FOREIGN KEY (id_diplome) REFERENCES DIPLOME(id_diplome) ON DELETE CASCADE,
FOREIGN KEY (id_annee) REFERENCES ANNEE_ENQUETE(id_annee) ON DELETE RESTRICT
*/
-- Optimisation : Index pour les recherches fréquentes et les jointures
CREATE INDEX idx_res_diplome ON RESULTAT_IP(id_diplome);
CREATE INDEX idx_res_annee ON RESULTAT_IP(id_annee);
CREATE INDEX idx_etab_region ON ETABLISSEMENT(id_region);

-- Requête SQL 1/8

SELECT 
    d.nom AS discipline,
    d.domaine,
    ROUND(AVG(r.taux_cadre), 2) AS taux_cadre_moyen
FROM 
    DISCIPLINE d
JOIN 
    DIPLOME dip ON d.id_disc = dip.id_disc
JOIN 
    RESULTAT_IP r ON dip.id_diplome = r.id_diplome
JOIN 
    ANNEE_ENQUETE a ON r.id_annee = a.id_annee
WHERE 
    a.delai_mois = 18
GROUP BY 
    d.id_disc, d.nom, d.domaine
ORDER BY 
    taux_cadre_moyen DESC;

-- Définition de l'année au début du script
\set annee_choisie 2021

-- Requête SQL 2/8

SELECT 
    e.nom AS etablissement,
    dip.intitule AS diplome,
    disc.nom AS discipline,
    r.salaire_median,
    a.annee
FROM 
    RESULTAT_IP r
JOIN DIPLOME dip ON r.id_diplome = dip.id_diplome
JOIN ETABLISSEMENT e ON dip.id_etab = e.id_etab
JOIN DISCIPLINE disc ON dip.id_disc = disc.id_disc
JOIN ANNEE_ENQUETE a ON r.id_annee = a.id_annee
WHERE 
    a.annee = :annee_choisie  -- Utilisation de la variable ici
    AND r.salaire_median > (
        SELECT AVG(r2.salaire_median)
        FROM RESULTAT_IP r2
        JOIN DIPLOME dip2 ON r2.id_diplome = dip2.id_diplome
        WHERE dip2.id_disc = dip.id_disc
          AND r2.id_annee = r.id_annee
    );

-- Requête SQL 3/8

SELECT 
    d.nom AS discipline,
    a.annee,
    ROUND(AVG(r.taux_cdi), 2) AS taux_cdi_moyen
FROM 
    RESULTAT_IP r
JOIN DIPLOME dip ON r.id_diplome = dip.id_diplome
JOIN DISCIPLINE d ON dip.id_disc = d.id_disc
JOIN ANNEE_ENQUETE a ON r.id_annee = a.id_annee
WHERE 
    a.annee >= (SELECT MAX(annee) - 4 FROM ANNEE_ENQUETE)
GROUP BY 
    d.nom, 
    a.annee
ORDER BY 
    d.nom ASC, 
    a.annee ASC;

-- Requête SQL 4/8

SELECT 
    d.nom AS discipline,
    a_actuelle.annee AS annee_fin,
    r_actuelle.taux_moyen_actuel,
    r_prec.taux_moyen_prec AS taux_annee_moins_1,
    r_double_prec.taux_moyen_double_prec AS taux_annee_moins_2
FROM DISCIPLINE d
JOIN ANNEE_ENQUETE a_actuelle ON 1=1 -- On itère sur les années
JOIN (
    -- Sous-requête corrélée 1 : Taux année T
    SELECT id_disc, id_annee, AVG(taux_emploi) as taux_moyen_actuel
    FROM DIPLOME dip
    JOIN RESULTAT_IP res ON dip.id_diplome = res.id_diplome
    GROUP BY id_disc, id_annee
) r_actuelle ON d.id_disc = r_actuelle.id_disc AND a_actuelle.id_annee = r_actuelle.id_annee

WHERE r_actuelle.taux_moyen_actuel < (
    -- Sous-requête corrélée 2 : Taux année T-1
    SELECT AVG(res2.taux_emploi)
    FROM DIPLOME dip2
    JOIN RESULTAT_IP res2 ON dip2.id_diplome = res2.id_diplome
    JOIN ANNEE_ENQUETE a2 ON res2.id_annee = a2.id_annee
    WHERE dip2.id_disc = d.id_disc 
      AND a2.annee = a_actuelle.annee - 1
)
AND (
    -- Sous-requête corrélée 3 : Taux année T-2
    SELECT AVG(res3.taux_emploi)
    FROM DIPLOME dip3
    JOIN RESULTAT_IP res3 ON dip3.id_diplome = res3.id_diplome
    JOIN ANNEE_ENQUETE a3 ON res3.id_annee = a3.id_annee
    WHERE dip3.id_disc = d.id_disc 
      AND a3.annee = a_actuelle.annee - 2
) > (
    -- Comparaison pour vérifier la deuxième baisse consécutive
    SELECT AVG(res2.taux_emploi)
    FROM DIPLOME dip2
    JOIN RESULTAT_IP res2 ON dip2.id_diplome = res2.id_diplome
    JOIN ANNEE_ENQUETE a2 ON res2.id_annee = a2.id_annee
    WHERE dip2.id_disc = d.id_disc 
      AND a2.annee = a_actuelle.annee - 1
)
ORDER BY annee_fin DESC;

-- Requête SQL 5/8

SELECT 
    e.nom AS etablissement,
    dip.intitule AS diplome,
    disc.nom AS discipline,
    r.taux_cadre,
    r.salaire_median,
    a.annee
FROM 
    RESULTAT_IP r
JOIN DIPLOME dip ON r.id_diplome = dip.id_diplome
JOIN ETABLISSEMENT e ON dip.id_etab = e.id_etab
JOIN DISCIPLINE disc ON dip.id_disc = disc.id_disc
JOIN ANNEE_ENQUETE a ON r.id_annee = a.id_annee
WHERE 
    a.annee = :annee_choisie -- Année au choix
    AND r.taux_cadre > 70
    AND r.salaire_median > (
        /* Sous-requête pour la médiane nationale globale (toutes disciplines) */
        SELECT AVG(salaire_median) 
        FROM RESULTAT_IP r2
        WHERE r2.id_annee = r.id_annee
    )
ORDER BY 
    r.salaire_median DESC;

-- Requête SQL 6/8

SELECT 
    reg.nom AS region,
    reg.code_insee,
    ROUND(AVG(r.taux_emploi), 2) AS taux_emploi_moyen_regional,
    SUM(r.nb_repondants) AS total_repondants_region
FROM 
    REGION reg
JOIN ETABLISSEMENT e ON reg.id_region = e.id_region
JOIN DIPLOME d ON e.id_etab = d.id_etab
JOIN RESULTAT_IP r ON d.id_diplome = r.id_diplome
JOIN ANNEE_ENQUETE a ON r.id_annee = a.id_annee
WHERE 
    a.annee = (SELECT MAX(annee) FROM ANNEE_ENQUETE) -- Filtre sur la dernière année disponible
GROUP BY 
    reg.id_region, 
    reg.nom, 
    reg.code_insee
ORDER BY 
    taux_emploi_moyen_regional DESC;

-- Requête SQL 7/8

SELECT 
    d.intitule AS diplome,
    e.nom AS etablissement,
    disc.nom AS discipline
FROM 
    DIPLOME d
JOIN ETABLISSEMENT e ON d.id_etab = e.id_etab
JOIN DISCIPLINE disc ON d.id_disc = disc.id_disc
LEFT JOIN (
    -- On cible les enquêtes des 3 dernières années
    SELECT id_diplome 
    FROM RESULTAT_IP r
    JOIN ANNEE_ENQUETE a ON r.id_annee = a.id_annee
    WHERE a.annee >= (SELECT MAX(annee) - 2 FROM ANNEE_ENQUETE)
) enquêtes_recentes ON d.id_diplome = enquêtes_recentes.id_diplome
WHERE 
    enquêtes_recentes.id_diplome IS NULL;

-- Requête SQL 8/8

SELECT 
    d.nom AS discipline,
    ROUND(AVG(r30.taux_emploi), 2) AS taux_30_mois,
    ROUND(AVG(r18.taux_emploi), 2) AS taux_18_mois,
    ROUND(AVG(r30.taux_emploi) - AVG(r18.taux_emploi), 2) AS progression_insertion
FROM 
    DISCIPLINE d
JOIN DIPLOME dip ON d.id_disc = dip.id_disc
-- Jointure pour les données à 18 mois
JOIN RESULTAT_IP r18 ON dip.id_diplome = r18.id_diplome
JOIN ANNEE_ENQUETE a18 ON r18.id_annee = a18.id_annee AND a18.delai_mois = 18
-- Jointure pour les données à 30 mois
JOIN RESULTAT_IP r30 ON dip.id_diplome = r30.id_diplome
JOIN ANNEE_ENQUETE a30 ON r30.id_annee = a30.id_annee AND a30.delai_mois = 30
-- On s'assure de comparer la même période de diplôme (même année de promotion)
WHERE a18.annee = a30.annee 
GROUP BY 
    d.id_disc, d.nom
ORDER BY 
    progression_insertion DESC;