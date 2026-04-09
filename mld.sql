/* ====== Creation du MLD a partir du MCD ======*/

/* rappel :

• REGION (id_region, nom, code_insee)

• ETABLISSEMENT (id_etab, nom, type, ville, id_region)

• DISCIPLINE (id_disc, nom, domaine) — ex : Informatique, Droit, Lettres

• DIPLOME (id_diplome, intitule, niveau, id_disc, id_etab)

• ANNEE_ENQUETE (id_annee, annee, delai_mois) — UNIQUE(annee, delai_mois)

• RESULTAT_IP (id_res, taux_emploi, taux_cdi, taux_cadre, salaire_median, delai_emploi, nb_repondants,

id_diplome, id_annee) -> table centrale */

-- Suppression des tables dans l'ordre inverse pour éviter les erreurs de contraintes
DROP TABLE IF EXISTS RESULTAT_IP;
DROP TABLE IF EXISTS DIPLOME;
DROP TABLE IF EXISTS ETABLISSEMENT;
DROP TABLE IF EXISTS ANNEE_ENQUETE;
DROP TABLE IF EXISTS DISCIPLINE;
DROP TABLE IF EXISTS REGION;

-- 1. REGION (Table de référence géographique)
CREATE TABLE REGION (
    id_region BIGSERIAL PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    code_insee VARCHAR(5)
);

-- 2. DISCIPLINE (Table de référence thématique)
CREATE TABLE DISCIPLINE (
    id_disc BIGSERIAL PRIMARY KEY,
    nom VARCHAR(150) NOT NULL, -- ex: Informatique
    domaine VARCHAR(150)       -- ex: Sciences, Technologies, Santé
);

-- 3. ANNEE_ENQUETE (Table de référence temporelle)
CREATE TABLE ANNEE_ENQUETE (
    id_annee BIGSERIAL PRIMARY KEY,
    annee YEAR NOT NULL,
    delai_mois TINYINT NOT NULL, -- 18 ou 30 mois
    CONSTRAINT uq_annee_delai UNIQUE (annee, delai_mois)
);

-- 4. ETABLISSEMENT
CREATE TABLE ETABLISSEMENT (
    id_etab BIGSERIAL PRIMARY KEY,
    nom VARCHAR(200) NOT NULL,
    type VARCHAR(100), -- Université, École d'ingénieurs, etc.
    ville VARCHAR(100),
    id_region INT NOT NULL,
    FOREIGN KEY (id_region) REFERENCES REGION(id_region) ON DELETE RESTRICT
);

-- 5. DIPLOME (Lien entre formation, établissement et discipline)
CREATE TABLE DIPLOME (
    id_diplome BIGSERIAL PRIMARY KEY,
    intitule VARCHAR(255) NOT NULL,
    niveau VARCHAR(50), -- Master, Licence Pro
    id_disc INT NOT NULL,
    id_etab INT NOT NULL,
    FOREIGN KEY (id_disc) REFERENCES DISCIPLINE(id_disc) ON DELETE RESTRICT,
    FOREIGN KEY (id_etab) REFERENCES ETABLISSEMENT(id_etab) ON DELETE CASCADE
);

-- 6. RESULTAT_IP (Table centrale / Table de faits)
CREATE TABLE RESULTAT_IP (
    id_res BIGSERIAL PRIMARY KEY,
    taux_emploi DECIMAL(5,1), -- % avec un chiffre après la virgule
    taux_cdi DECIMAL(5,1),
    taux_cadre DECIMAL(5,1),
    salaire_median INT,
    delai_emploi TINYINT, -- Utilisation de TINYINT car le délai est petit
    nb_repondants INT,
    id_diplome INT NOT NULL,
    id_annee INT NOT NULL,
    
    -- Contraintes de validité (Check Constraints)
    CONSTRAINT chk_taux CHECK (taux_emploi BETWEEN 0 AND 100),
    CONSTRAINT chk_sal CHECK (salaire_median IS NULL OR salaire_median > 0),
    
    -- Unicité : Un seul résultat par diplôme pour une année d'enquête donnée
    CONSTRAINT uq_res UNIQUE (id_diplome, id_annee),
    
    -- Clés étrangères
    FOREIGN KEY (id_diplome) REFERENCES DIPLOME(id_diplome) ON DELETE CASCADE,
    FOREIGN KEY (id_annee) REFERENCES ANNEE_ENQUETE(id_annee) ON DELETE RESTRICT
);

-- Optimisation : Index pour les recherches fréquentes et les jointures
CREATE INDEX idx_res_diplome ON RESULTAT_IP(id_diplome);
CREATE INDEX idx_res_annee ON RESULTAT_IP(id_annee);
CREATE INDEX idx_etab_region ON ETABLISSEMENT(id_region);