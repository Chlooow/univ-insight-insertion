-- Requête SQL 1/8
SELECT
    d.nom AS discipline,
    d.domaine,
    ROUND(AVG(r.taux_cadre),
    2) AS taux_cadre_moyen
FROM
    DISCIPLINE d
JOIN DIPLOME dip ON
    d.id_disc = dip.id_disc
JOIN RESULTAT_IP r ON
    dip.id_diplome = r.id_diplome
JOIN ANNEE_ENQUETE a ON
    r.id_annee = a.id_annee
WHERE
    a.delai_mois = 18
GROUP BY
    d.id_disc,
    d.nom,
    d.domaine
ORDER BY
    taux_cadre_moyen
DESC
    ;

-- Requête SQL 2/8

SET
    @annee_choisie := 2021;

SELECT
    e.nom AS etablissement,
    dip.intitule AS diplome,
    r.salaire_median,
    a.annee
FROM
    RESULTAT_IP r
JOIN DIPLOME dip ON
    r.id_diplome = dip.id_diplome
JOIN ETABLISSEMENT e ON
    dip.id_etab = e.id_etab
JOIN ANNEE_ENQUETE a ON
    r.id_annee = a.id_annee
WHERE
    a.annee = @annee_choisie AND r.salaire_median >(
    SELECT
        AVG(r2.salaire_median)
    FROM
        RESULTAT_IP r2
    JOIN DIPLOME dip2 ON
        r2.id_diplome = dip2.id_diplome
    WHERE
        dip2.id_disc = dip.id_disc AND r2.id_annee = r.id_annee
);

-- Requête SQL 3/8

SELECT
    d.nom AS discipline,
    a.annee,
    ROUND(AVG(r.taux_cdi),
    2) AS taux_cdi_moyen
FROM
    RESULTAT_IP r
JOIN DIPLOME dip ON
    r.id_diplome = dip.id_diplome
JOIN DISCIPLINE d ON
    dip.id_disc = d.id_disc
JOIN ANNEE_ENQUETE a ON
    r.id_annee = a.id_annee
WHERE
    a.annee >=(
    SELECT
        MAX(annee) - 4
    FROM
        ANNEE_ENQUETE
)
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
    -- Récupération dynamique du taux de l'année précédente (T-1)
    (
    SELECT
        AVG(res2.taux_emploi)
    FROM
        DIPLOME dip2
    JOIN RESULTAT_IP res2 ON
        dip2.id_diplome = res2.id_diplome
    JOIN ANNEE_ENQUETE a2 ON
        res2.id_annee = a2.id_annee
    WHERE
        dip2.id_disc = d.id_disc AND a2.annee = a_actuelle.annee - 1
) AS taux_annee_moins_1,
    -- Récupération dynamique du taux de l'année T-2
(
    SELECT
        AVG(res3.taux_emploi)
    FROM
        DIPLOME dip3
    JOIN RESULTAT_IP res3 ON
        dip3.id_diplome = res3.id_diplome
    JOIN ANNEE_ENQUETE a3 ON
        res3.id_annee = a3.id_annee
    WHERE
        dip3.id_disc = d.id_disc AND a3.annee = a_actuelle.annee - 2
) AS taux_annee_moins_2
FROM
    DISCIPLINE d
JOIN ANNEE_ENQUETE a_actuelle ON
    1 = 1
JOIN(
    SELECT dip.id_disc,
        res.id_annee,
        AVG(res.taux_emploi) AS taux_moyen_actuel
    FROM
        DIPLOME dip
    JOIN RESULTAT_IP res ON
        dip.id_diplome = res.id_diplome
    GROUP BY
        dip.id_disc,
        res.id_annee
) r_actuelle
ON
    d.id_disc = r_actuelle.id_disc AND a_actuelle.id_annee = r_actuelle.id_annee
WHERE
    -- Condition : baisse entre T-1 et T
    r_actuelle.taux_moyen_actuel <(
    SELECT
        AVG(res2.taux_emploi)
    FROM
        DIPLOME dip2
    JOIN RESULTAT_IP res2 ON
        dip2.id_diplome = res2.id_diplome
    JOIN ANNEE_ENQUETE a2 ON
        res2.id_annee = a2.id_annee
    WHERE
        dip2.id_disc = d.id_disc AND a2.annee = a_actuelle.annee - 1
) AND
    -- Condition : baisse entre T-2 et T-1 (donc T-1 < T-2)
(
    SELECT
        AVG(res2.taux_emploi)
    FROM
        DIPLOME dip2
    JOIN RESULTAT_IP res2 ON
        dip2.id_diplome = res2.id_diplome
    JOIN ANNEE_ENQUETE a2 ON
        res2.id_annee = a2.id_annee
    WHERE
        dip2.id_disc = d.id_disc AND a2.annee = a_actuelle.annee - 1
) <(
    SELECT
        AVG(res3.taux_emploi)
    FROM
        DIPLOME dip3
    JOIN RESULTAT_IP res3 ON
        dip3.id_diplome = res3.id_diplome
    JOIN ANNEE_ENQUETE a3 ON
        res3.id_annee = a3.id_annee
    WHERE
        dip3.id_disc = d.id_disc AND a3.annee = a_actuelle.annee - 2
)
ORDER BY
    annee_fin
DESC
LIMIT 0, 25;

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
JOIN DIPLOME dip ON
    r.id_diplome = dip.id_diplome
JOIN ETABLISSEMENT e ON
    dip.id_etab = e.id_etab
JOIN DISCIPLINE disc ON
    dip.id_disc = disc.id_disc
JOIN ANNEE_ENQUETE a ON
    r.id_annee = a.id_annee
WHERE
    a.annee = @annee_choisie -- Année au choix
    AND r.taux_cadre > 70 AND r.salaire_median >(
        /* Sous-requête pour la médiane nationale globale (toutes disciplines) */
    SELECT
        AVG(salaire_median)
    FROM
        RESULTAT_IP r2
    WHERE
        r2.id_annee = r.id_annee
    )
ORDER BY
    r.salaire_median
DESC
    ;
    -- Requête SQL 6/8
SELECT
    reg.nom AS region,
    reg.code_insee,
    ROUND(AVG(r.taux_emploi),
    2) AS taux_emploi_moyen_regional,
    SUM(r.nb_repondants) AS total_repondants_region
FROM
    REGION reg
JOIN ETABLISSEMENT e ON
    reg.id_region = e.id_region
JOIN DIPLOME d ON
    e.id_etab = d.id_etab
JOIN RESULTAT_IP r ON
    d.id_diplome = r.id_diplome
JOIN ANNEE_ENQUETE a ON
    r.id_annee = a.id_annee
WHERE
    a.annee =(
    SELECT
        MAX(annee)
    FROM
        ANNEE_ENQUETE
) -- Filtre sur la dernière année disponible
GROUP BY
    reg.id_region,
    reg.nom,
    reg.code_insee
ORDER BY
    taux_emploi_moyen_regional
DESC
    ;

-- Requête SQL 7/8

SELECT
    d.intitule AS diplome,
    e.nom AS etablissement,
    disc.nom AS discipline
FROM
    DIPLOME d
JOIN ETABLISSEMENT e ON
    d.id_etab = e.id_etab
JOIN DISCIPLINE disc ON
    d.id_disc = disc.id_disc
LEFT JOIN(
    -- On cible les enquêtes des 3 dernières années 
    SELECT
        id_diplome
    FROM
        RESULTAT_IP r
    JOIN ANNEE_ENQUETE a ON
        r.id_annee = a.id_annee
    WHERE
        a.annee >=(
        SELECT
            MAX(annee) - 2
        FROM
            ANNEE_ENQUETE
    )
    ) enquêtes_recentes
ON
    d.id_diplome = enquêtes_recentes.id_diplome
WHERE
    enquêtes_recentes.id_diplome IS NULL;

-- Requête SQL 8/8

SELECT
    d.nom AS discipline,
    ROUND(AVG(r30.taux_emploi),
    2) AS taux_30_mois,
    ROUND(AVG(r18.taux_emploi),
    2) AS taux_18_mois,
    ROUND(
        AVG(r30.taux_emploi) - AVG(r18.taux_emploi),
        2
    ) AS progression
FROM
    DISCIPLINE d
JOIN DIPLOME dip ON
    d.id_disc = dip.id_disc
JOIN RESULTAT_IP r18 ON
    dip.id_diplome = r18.id_diplome
JOIN ANNEE_ENQUETE a18 ON
    r18.id_annee = a18.id_annee AND a18.delai_mois = 18
JOIN RESULTAT_IP r30 ON
    dip.id_diplome = r30.id_diplome
JOIN ANNEE_ENQUETE a30 ON
    r30.id_annee = a30.id_annee AND a30.delai_mois = 30
WHERE
    a18.annee = a30.annee
GROUP BY
    d.id_disc,
    d.nom
ORDER BY
    progression
DESC
    ;