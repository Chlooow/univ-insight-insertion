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

-- Unicité : Un seul résultat par diplôme pour une année d'enquête donnée
CONSTRAINT uq_res UNIQUE (id_diplome, id_annee),

-- Clés étrangères
FOREIGN KEY (id_diplome) REFERENCES DIPLOME(id_diplome) ON DELETE CASCADE,
FOREIGN KEY (id_annee) REFERENCES ANNEE_ENQUETE(id_annee) ON DELETE RESTRICT

-- Optimisation : Index pour les recherches fréquentes et les jointures
CREATE INDEX idx_res_diplome ON RESULTAT_IP(id_diplome);
CREATE INDEX idx_res_annee ON RESULTAT_IP(id_annee);
CREATE INDEX idx_etab_region ON ETABLISSEMENT(id_region);