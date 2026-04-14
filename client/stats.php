<?php
require_once '../connexion.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt($val, $suffix = '', $fallback = '—') {
    return ($val !== null && $val !== '') ? $val . $suffix : $fallback;
}
function fmtSal($val, $fallback = '—') {
    return ($val !== null && $val !== '') ? number_format((float)$val, 0, ',', ' ') . '€' : $fallback;
}

// ── Filtres globaux ───────────────────────────────────────────────────────────
$annees      = $pdo->query("SELECT DISTINCT annee FROM ANNEE_ENQUETE ORDER BY annee DESC")->fetchAll(PDO::FETCH_COLUMN);
$lastYear    = (int)$annees[0];
$domainesList = $pdo->query("SELECT DISTINCT domaine FROM DISCIPLINE WHERE domaine IS NOT NULL ORDER BY domaine")->fetchAll(PDO::FETCH_COLUMN);

$selectedYear  = isset($_GET['annee'])   && in_array($_GET['annee'],  $annees)       ? (int)$_GET['annee']  : $lastYear;
$selectedDelai = isset($_GET['delai'])   && in_array((int)$_GET['delai'], [18, 30]) ? (int)$_GET['delai']  : 18;
$selectedDom   = isset($_GET['domaine']) && in_array($_GET['domaine'], $domainesList) ? $_GET['domaine']    : '';

$domFilter     = $selectedDom ? " AND dis.domaine = :dom " : "";
$domParams     = $selectedDom ? [':dom' => $selectedDom] : [];

// ── R1 — Disciplines taux cadre moyen (18 mois) ───────────────────────────────
$r1sql = "
    SELECT dis.nom AS discipline, dis.domaine,
           ROUND(AVG(r.taux_cadre), 1)  AS taux_cadre,
           ROUND(AVG(r.taux_emploi),1)  AS taux_emploi,
           COUNT(*) AS nb
    FROM RESULTAT_IP r
    JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE ae.delai_mois = 18 AND ae.annee = :y AND r.taux_cadre IS NOT NULL
    $domFilter
    GROUP BY dis.id_disc, dis.nom, dis.domaine
    ORDER BY taux_cadre DESC
";
$s = $pdo->prepare($r1sql); $s->execute(array_merge([':y'=>$selectedYear], $domParams)); $r1 = $s->fetchAll();

// ── R2 — Établissements au-dessus de la moyenne ────────────────────────────────
$r2sql = "
    SELECT e.nom AS etablissement, reg.nom AS region,
           ROUND(AVG(r.taux_emploi),1) AS taux_emploi_moy,
           ROUND(
               (SELECT AVG(r2.taux_emploi) FROM RESULTAT_IP r2
                JOIN ANNEE_ENQUETE ae2 ON ae2.id_annee=r2.id_annee
                WHERE ae2.annee=:y2 AND ae2.delai_mois=18 AND r2.taux_emploi IS NOT NULL), 1
           ) AS moyenne_nat
    FROM RESULTAT_IP r
    JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    JOIN ETABLISSEMENT e ON e.id_etab = dip.id_etab
    JOIN REGION reg ON reg.id_region = e.id_region
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE ae.annee = :y AND ae.delai_mois = 18 AND r.taux_emploi IS NOT NULL
    $domFilter
    GROUP BY e.id_etab, e.nom, reg.nom
    HAVING taux_emploi_moy > (
        SELECT AVG(r3.taux_emploi) FROM RESULTAT_IP r3
        JOIN ANNEE_ENQUETE ae3 ON ae3.id_annee=r3.id_annee
        WHERE ae3.annee=:y3 AND ae3.delai_mois=18 AND r3.taux_emploi IS NOT NULL
    )
    ORDER BY taux_emploi_moy DESC LIMIT 15
";
$s = $pdo->prepare($r2sql); $s->execute(array_merge([':y'=>$selectedYear,':y2'=>$selectedYear,':y3'=>$selectedYear], $domParams)); $r2 = $s->fetchAll();
$moyNat = $r2[0]['moyenne_nat'] ?? null;

// ── R3 — Évolution CDI par discipline (5 dernières années) ───────────────────
$r3sql = "
    SELECT dis.nom AS discipline, ae.annee,
           ROUND(AVG(r.taux_cdi),1) AS taux_cdi
    FROM RESULTAT_IP r
    JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE r.taux_cdi IS NOT NULL AND ae.delai_mois = 18
      AND ae.annee >= (SELECT MAX(annee)-4 FROM ANNEE_ENQUETE)
    $domFilter
    GROUP BY dis.id_disc, dis.nom, ae.annee
    ORDER BY dis.nom, ae.annee
";
$s = $pdo->prepare($r3sql); $s->execute($domParams); $r3raw = $s->fetchAll();
// Structurer pour Chart.js
$r3discs = array_values(array_unique(array_column($r3raw,'discipline')));
$r3years = array_values(array_unique(array_column($r3raw,'annee'))); sort($r3years);
$r3matrix = [];
foreach ($r3raw as $row) $r3matrix[$row['discipline']][$row['annee']] = (float)$row['taux_cdi'];

// ── R4 — Disciplines en déclin continu ────────────────────────────────────────
$r4sql = "
    WITH taux_par_annee AS (
        SELECT dis.id_disc, dis.nom AS discipline, ae.annee,
               ROUND(AVG(r.taux_emploi),1) AS taux_moy
        FROM RESULTAT_IP r
        JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome
        JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
        JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
        WHERE r.taux_emploi IS NOT NULL AND ae.delai_mois = 18
        $domFilter
        GROUP BY dis.id_disc, dis.nom, ae.annee
    ),
    avec_lag AS (
        SELECT id_disc, discipline, annee, taux_moy,
               LAG(taux_moy) OVER (PARTITION BY id_disc ORDER BY annee) AS taux_prec,
               LAG(annee)    OVER (PARTITION BY id_disc ORDER BY annee) AS annee_prec
        FROM taux_par_annee
    )
    SELECT DISTINCT a.discipline
    FROM avec_lag a
    WHERE a.taux_moy < a.taux_prec
      AND EXISTS (
          SELECT 1 FROM avec_lag b
          WHERE b.id_disc = a.id_disc AND b.annee = a.annee + 1 AND b.taux_moy < b.taux_prec
      )
    ORDER BY a.discipline
";
$s = $pdo->prepare($r4sql); $s->execute($domParams); $r4disciplines = $s->fetchAll(PDO::FETCH_COLUMN);

// Récupérer l'évolution pour les disciplines en déclin
$r4evol = [];
if (!empty($r4disciplines)) {
    $in = implode(',', array_fill(0, count($r4disciplines), '?'));
    $s4 = $pdo->prepare("
        SELECT dis.nom AS discipline, ae.annee, ROUND(AVG(r.taux_emploi),1) AS taux_moy
        FROM RESULTAT_IP r
        JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome
        JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
        JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
        WHERE dis.nom IN ($in) AND ae.delai_mois = 18 AND r.taux_emploi IS NOT NULL
        GROUP BY dis.nom, ae.annee ORDER BY dis.nom, ae.annee
    ");
    $s4->execute($r4disciplines);
    $r4raw = $s4->fetchAll();
    foreach ($r4raw as $row) $r4evol[$row['discipline']][$row['annee']] = (float)$row['taux_moy'];
}

// ── R5 — Double critère cadre > 70% ET salaire > médiane ─────────────────────
$r5sql = "
    SELECT dip.intitule, dis.nom AS discipline, e.nom AS etablissement,
           ae.annee, r.taux_cadre, r.salaire_median,
           ROUND(
               (SELECT AVG(r2.salaire_median) FROM RESULTAT_IP r2
                JOIN ANNEE_ENQUETE ae2 ON ae2.id_annee=r2.id_annee
                WHERE r2.salaire_median IS NOT NULL AND ae2.annee=:y2 AND ae2.delai_mois=18),0
           ) AS mediane_nat
    FROM RESULTAT_IP r
    JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    JOIN ETABLISSEMENT e ON e.id_etab = dip.id_etab
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE ae.annee = :y AND ae.delai_mois = :d AND r.taux_cadre > 70
      AND r.salaire_median > (
          SELECT AVG(r3.salaire_median) FROM RESULTAT_IP r3
          JOIN ANNEE_ENQUETE ae3 ON ae3.id_annee=r3.id_annee
          WHERE r3.salaire_median IS NOT NULL AND ae3.annee=:y3 AND ae3.delai_mois=18
      )
    $domFilter
    ORDER BY r.salaire_median DESC, r.taux_cadre DESC LIMIT 15
";
$s = $pdo->prepare($r5sql); $s->execute(array_merge([':y'=>$selectedYear,':y2'=>$selectedYear,':y3'=>$selectedYear,':d'=>$selectedDelai], $domParams)); $r5 = $s->fetchAll();

// ── R6 — Classement régions ───────────────────────────────────────────────────
$r6sql = "
    SELECT reg.nom AS region, ROUND(AVG(r.taux_emploi),1) AS taux_emploi,
           ROUND(AVG(r.salaire_median),0) AS salaire, COUNT(DISTINCT e.id_etab) AS nb_etab
    FROM RESULTAT_IP r
    JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    JOIN ETABLISSEMENT e ON e.id_etab = dip.id_etab
    JOIN REGION reg ON reg.id_region = e.id_region
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE r.taux_emploi IS NOT NULL AND ae.annee = :y AND ae.delai_mois = :d
    $domFilter
    GROUP BY reg.id_region, reg.nom
    ORDER BY taux_emploi DESC
";
$s = $pdo->prepare($r6sql); $s->execute(array_merge([':y'=>$selectedYear,':d'=>$selectedDelai], $domParams)); $r6 = $s->fetchAll();

// ── R7 — Diplômes sans enquête récente ────────────────────────────────────────
$r7sql = "
    SELECT dip.intitule, dis.nom AS discipline, e.nom AS etablissement,
           MAX(ae.annee) AS derniere_enquete
    FROM DIPLOME dip
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    JOIN ETABLISSEMENT e ON e.id_etab = dip.id_etab
    LEFT JOIN RESULTAT_IP r ON r.id_diplome = dip.id_diplome
    LEFT JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    $domFilter
    GROUP BY dip.id_diplome, dip.intitule, dis.nom, e.nom
    HAVING MAX(ae.annee) IS NULL OR MAX(ae.annee) < (SELECT MAX(annee)-2 FROM ANNEE_ENQUETE)
    ORDER BY derniere_enquete ASC, dip.intitule
    LIMIT 20
";
// R7 needs special handling because of LEFT JOIN + optional domaine filter
if ($selectedDom) {
    $s = $pdo->prepare("
        SELECT dip.intitule, dis.nom AS discipline, e.nom AS etablissement,
               MAX(ae.annee) AS derniere_enquete
        FROM DIPLOME dip
        JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc AND dis.domaine = :dom
        JOIN ETABLISSEMENT e ON e.id_etab = dip.id_etab
        LEFT JOIN RESULTAT_IP r ON r.id_diplome = dip.id_diplome
        LEFT JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
        GROUP BY dip.id_diplome, dip.intitule, dis.nom, e.nom
        HAVING MAX(ae.annee) IS NULL OR MAX(ae.annee) < (SELECT MAX(annee)-2 FROM ANNEE_ENQUETE)
        ORDER BY derniere_enquete ASC, dip.intitule LIMIT 20
    ");
    $s->execute([':dom'=>$selectedDom]);
} else {
    $s = $pdo->prepare("
        SELECT dip.intitule, dis.nom AS discipline, e.nom AS etablissement,
               MAX(ae.annee) AS derniere_enquete
        FROM DIPLOME dip
        JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
        JOIN ETABLISSEMENT e ON e.id_etab = dip.id_etab
        LEFT JOIN RESULTAT_IP r ON r.id_diplome = dip.id_diplome
        LEFT JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
        GROUP BY dip.id_diplome, dip.intitule, dis.nom, e.nom
        HAVING MAX(ae.annee) IS NULL OR MAX(ae.annee) < (SELECT MAX(annee)-2 FROM ANNEE_ENQUETE)
        ORDER BY derniere_enquete ASC, dip.intitule LIMIT 20
    ");
    $s->execute();
}
$r7 = $s->fetchAll();

// ── R8 — Gain 18 vs 30 mois par discipline ────────────────────────────────────
$r8sql = "
    SELECT dis.nom AS discipline, ae18.annee,
           ROUND(AVG(r18.taux_emploi),1)                             AS t18,
           ROUND(AVG(r30.taux_emploi),1)                             AS t30,
           ROUND(AVG(r30.taux_emploi)-AVG(r18.taux_emploi),1)       AS gain
    FROM RESULTAT_IP r18
    JOIN RESULTAT_IP r30 ON r30.id_diplome = r18.id_diplome
    JOIN ANNEE_ENQUETE ae18 ON ae18.id_annee = r18.id_annee AND ae18.delai_mois = 18
    JOIN ANNEE_ENQUETE ae30 ON ae30.id_annee = r30.id_annee AND ae30.delai_mois = 30 AND ae30.annee = ae18.annee
    JOIN DIPLOME dip ON dip.id_diplome = r18.id_diplome
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    WHERE r18.taux_emploi IS NOT NULL AND r30.taux_emploi IS NOT NULL
      AND ae18.annee = :y
    $domFilter
    GROUP BY dis.id_disc, dis.nom, ae18.annee
    ORDER BY gain DESC
";
$s = $pdo->prepare($r8sql); $s->execute(array_merge([':y'=>$selectedYear], $domParams)); $r8 = $s->fetchAll();

// ── BONUS ICA — Top 10 formations ─────────────────────────────────────────────
$icaStats = $pdo->prepare("
    SELECT MIN(r.taux_emploi) mn_emp, MAX(r.taux_emploi) mx_emp,
           MIN(r.taux_cdi) mn_cdi, MAX(r.taux_cdi) mx_cdi,
           MIN(r.taux_cadre) mn_cad, MAX(r.taux_cadre) mx_cad,
           MIN(r.salaire_median) mn_sal, MAX(r.salaire_median) mx_sal
    FROM RESULTAT_IP r JOIN ANNEE_ENQUETE ae ON ae.id_annee=r.id_annee
    WHERE ae.annee=:y AND ae.delai_mois=:d
      AND r.taux_emploi IS NOT NULL AND r.taux_cdi IS NOT NULL
      AND r.taux_cadre IS NOT NULL AND r.salaire_median IS NOT NULL
");
$icaStats->execute([':y'=>$selectedYear,':d'=>$selectedDelai]);
$icaN = $icaStats->fetch();

$bonusSql = "
    SELECT dip.intitule, dis.nom AS discipline, e.nom AS etablissement, reg.nom AS region,
           r.taux_emploi, r.taux_cdi, r.taux_cadre, r.salaire_median,
           ROUND(
               0.40 * NULLIF(r.taux_emploi    - :mn_emp,0)/NULLIF(:mx_emp - :mn_emp2,0)*100
             + 0.20 * NULLIF(r.taux_cdi       - :mn_cdi,0)/NULLIF(:mx_cdi - :mn_cdi2,0)*100
             + 0.20 * NULLIF(r.taux_cadre     - :mn_cad,0)/NULLIF(:mx_cad - :mn_cad2,0)*100
             + 0.20 * NULLIF(r.salaire_median - :mn_sal,0)/NULLIF(:mx_sal - :mn_sal2,0)*100
           ,1) AS ica
    FROM RESULTAT_IP r
    JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    JOIN ETABLISSEMENT e ON e.id_etab = dip.id_etab
    JOIN REGION reg ON reg.id_region = e.id_region
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE ae.annee=:y AND ae.delai_mois=:d
      AND r.taux_emploi IS NOT NULL AND r.taux_cdi IS NOT NULL
      AND r.taux_cadre IS NOT NULL AND r.salaire_median IS NOT NULL
    ORDER BY ica DESC LIMIT 10
";
$bonusStmt = $pdo->prepare($bonusSql);
$bonusStmt->execute([
    ':mn_emp'=>$icaN['mn_emp'],':mx_emp'=>$icaN['mx_emp'],':mn_emp2'=>$icaN['mn_emp'],
    ':mn_cdi'=>$icaN['mn_cdi'],':mx_cdi'=>$icaN['mx_cdi'],':mn_cdi2'=>$icaN['mn_cdi'],
    ':mn_cad'=>$icaN['mn_cad'],':mx_cad'=>$icaN['mx_cad'],':mn_cad2'=>$icaN['mn_cad'],
    ':mn_sal'=>$icaN['mn_sal'],':mx_sal'=>$icaN['mx_sal'],':mn_sal2'=>$icaN['mn_sal'],
    ':y'=>$selectedYear,':d'=>$selectedDelai,
]);
$bonusTop10 = $bonusStmt->fetchAll();

// Classement établissements par ICA moyen
$icaEtabSql = "
    SELECT e.nom AS etablissement, reg.nom AS region,
           ROUND(AVG(
               0.40*NULLIF(r.taux_emploi-:mn_emp,0)/NULLIF(:mx_emp-:mn_emp2,0)*100
             + 0.20*NULLIF(r.taux_cdi-:mn_cdi,0)/NULLIF(:mx_cdi-:mn_cdi2,0)*100
             + 0.20*NULLIF(r.taux_cadre-:mn_cad,0)/NULLIF(:mx_cad-:mn_cad2,0)*100
             + 0.20*NULLIF(r.salaire_median-:mn_sal,0)/NULLIF(:mx_sal-:mn_sal2,0)*100
           ),1) AS ica_moy, COUNT(*) AS nb_formations
    FROM RESULTAT_IP r
    JOIN DIPLOME dip ON dip.id_diplome=r.id_diplome
    JOIN ETABLISSEMENT e ON e.id_etab=dip.id_etab
    JOIN REGION reg ON reg.id_region=e.id_region
    JOIN ANNEE_ENQUETE ae ON ae.id_annee=r.id_annee
    WHERE ae.annee=:y AND ae.delai_mois=:d
      AND r.taux_emploi IS NOT NULL AND r.taux_cdi IS NOT NULL
      AND r.taux_cadre IS NOT NULL AND r.salaire_median IS NOT NULL
    GROUP BY e.id_etab, e.nom, reg.nom
    ORDER BY ica_moy DESC LIMIT 10
";
$icaEtabStmt = $pdo->prepare($icaEtabSql);
$icaEtabStmt->execute([
    ':mn_emp'=>$icaN['mn_emp'],':mx_emp'=>$icaN['mx_emp'],':mn_emp2'=>$icaN['mn_emp'],
    ':mn_cdi'=>$icaN['mn_cdi'],':mx_cdi'=>$icaN['mx_cdi'],':mn_cdi2'=>$icaN['mn_cdi'],
    ':mn_cad'=>$icaN['mn_cad'],':mx_cad'=>$icaN['mx_cad'],':mn_cad2'=>$icaN['mn_cad'],
    ':mn_sal'=>$icaN['mn_sal'],':mx_sal'=>$icaN['mx_sal'],':mn_sal2'=>$icaN['mn_sal'],
    ':y'=>$selectedYear,':d'=>$selectedDelai,
]);
$icaEtab = $icaEtabStmt->fetchAll();

// ── JSON pour JS ──────────────────────────────────────────────────────────────
$r1Json    = json_encode($r1);
$r2Json    = json_encode($r2);
$r3Json    = json_encode(['discs'=>$r3discs,'years'=>$r3years,'matrix'=>$r3matrix]);
$r4Json    = json_encode($r4evol);
$r6Json    = json_encode($r6);
$r8Json    = json_encode($r8);
$moyNatJ   = json_encode($moyNat);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Univ Insight — Statistiques</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── topbar ── */
.topbar{position:sticky;top:0;z-index:100;background:#fff!important;padding:10px 24px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:12px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.content{padding:16px 24px 32px!important;}
.search-univ{max-width:340px;}

/* ── filter bar ── */
.filter-bar{grid-column:1/-1;display:flex;align-items:center;gap:10px;background:white;border:1px solid var(--gray-100);border-radius:12px;padding:10px 16px;flex-wrap:wrap;}
.filter-bar label{font-size:10px;font-weight:600;color:var(--gray-400);text-transform:uppercase;letter-spacing:.05em;margin-right:4px;}
.filter-select{appearance:none;background:var(--gray-50,#f9fafb);border:1px solid var(--gray-200);border-radius:8px;padding:5px 28px 5px 10px;font-size:12px;font-family:'DM Sans',sans-serif;color:var(--gray-700);cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239ca3af'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;transition:border-color .15s;}
.filter-select:focus{outline:none;border-color:var(--blue-france);}
.filter-select.lg{min-width:200px;}
.filter-divider{width:1px;height:20px;background:var(--gray-100);margin:0 4px;}
.delai-toggle{display:flex;background:var(--gray-100);border-radius:8px;padding:2px;gap:2px;}
.delai-btn{font-size:11px;font-weight:600;padding:4px 12px;border-radius:6px;border:none;background:transparent;color:var(--gray-500);cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s;}
.delai-btn.active{background:white;color:var(--blue-france);box-shadow:0 1px 3px rgba(0,0,0,.08);}
.filter-reset{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--gray-400);cursor:pointer;background:none;border:none;font-family:'DM Sans',sans-serif;padding:4px 8px;border-radius:6px;transition:all .15s;}
.filter-reset:hover{background:var(--gray-100);color:var(--gray-700);}

/* ── section header ── */
.section-head{grid-column:1/-1;display:flex;align-items:center;gap:12px;padding:8px 0 2px;}
.section-num{width:28px;height:28px;border-radius:8px;background:#002B55;color:white;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.section-num.bonus{background:#E1000F;}
.section-title{font-size:15px;font-weight:700;color:var(--gray-900);}
.section-tech{font-size:10px;font-weight:600;color:var(--gray-400);background:var(--gray-100);padding:2px 8px;border-radius:20px;margin-left:auto;}

/* ── export btn ── */
.export-btn{display:flex;align-items:center;gap:5px;font-size:10px;font-weight:600;color:var(--blue-france);background:var(--blue-pale);border:none;border-radius:7px;padding:4px 10px;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s;}
.export-btn:hover{background:#d4dcff;}

/* ── layout ── */
.full-col{grid-column:1/-1;}
.two-col{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.two-col-31{grid-column:1/-1;display:grid;grid-template-columns:1.8fr 1fr;gap:12px;}
.three-col{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
.card{background:white;border-radius:14px;padding:18px;}
.card-title{font-size:13px;font-weight:600;margin-bottom:14px;}
.pill-tag{font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;background:var(--blue-pale);color:var(--blue-france);}
.canvas-wrap{height:220px;position:relative;}
.canvas-wrap-sm{height:170px;position:relative;}

/* ── stat tables ── */
.stat-tbl{width:100%;border-collapse:collapse;font-size:11px;}
.stat-tbl th{font-size:10px;font-weight:600;color:var(--gray-400);text-align:left;padding:0 8px 10px 0;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;}
.stat-tbl th.r{text-align:right;}
.stat-tbl td{padding:7px 8px 7px 0;border-top:1px solid var(--gray-100);vertical-align:middle;}
.stat-tbl tr:first-child td{border-top:none;}
.stat-tbl tbody tr{cursor:pointer;transition:background .12s;}
.stat-tbl tbody tr:hover td{background:#f8f9ff;}
.rank-n{font-size:10px;font-weight:700;color:var(--gray-400);width:20px;}
.tbl-bar{height:4px;border-radius:2px;background:var(--blue-pale);overflow:hidden;margin-top:3px;}
.tbl-bar-fill{height:100%;border-radius:2px;background:#000091;}

/* ── gain bars ── */
.gain-row{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.gain-label{width:140px;font-size:10px;color:var(--gray-700);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex-shrink:0;}
.gain-track{flex:1;height:6px;border-radius:3px;background:var(--blue-pale);overflow:hidden;}
.gain-fill{height:100%;border-radius:3px;transition:width .4s;}
.gain-val{font-size:10px;font-weight:700;width:44px;text-align:right;flex-shrink:0;}
.gain-val.pos{color:#000091;}
.gain-val.neg{color:#ef4444;}

/* ── sparkline ── */
.sparkline-row{display:flex;align-items:center;gap:10px;padding:6px 0;border-top:1px solid var(--gray-100);}
.sparkline-row:first-child{border-top:none;}
.sparkline-name{width:140px;font-size:11px;font-weight:500;color:var(--gray-700);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex-shrink:0;}
.sparkline-canvas{flex:1;height:28px;}
.sparkline-trend{width:36px;text-align:right;flex-shrink:0;}
.badge-declin{font-size:9px;font-weight:700;padding:2px 6px;border-radius:20px;background:#fee2e2;color:#991b1b;}

/* ── r5 scatter quadrant ── */
.scatter-legend{display:flex;gap:14px;margin-top:8px;flex-wrap:wrap;}
.sc-leg{display:flex;align-items:center;gap:4px;font-size:10px;color:var(--gray-500);}
.sc-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}

/* ── ICA podium ── */
.podium-wrap{display:flex;align-items:flex-end;justify-content:center;gap:8px;height:100px;padding:0 8px;}
.podium-col{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;max-width:70px;}
.podium-block{width:100%;border-radius:6px 6px 0 0;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:white;}
.podium-rank{font-size:9px;font-weight:700;color:var(--gray-400);}
.podium-name{font-size:9px;text-align:center;color:var(--gray-600);line-height:1.2;max-width:60px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;}

/* ── inactivity badge ── */
.inact-badge{font-size:9px;font-weight:700;padding:2px 7px;border-radius:20px;}
.inact-old{background:#fef3c7;color:#92400e;}
.inact-none{background:#fee2e2;color:#991b1b;}

/* ── tooltip ── */
.ui-tooltip{position:fixed;z-index:9999;pointer-events:none;opacity:0;transform:translateY(6px) scale(.97);transition:opacity .15s ease,transform .15s ease;max-width:280px;min-width:200px;}
.ui-tooltip.visible{opacity:1;transform:translateY(0) scale(1);}
.ui-tooltip-inner{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.10),0 2px 6px rgba(0,0,0,.06);overflow:hidden;}
.ui-tooltip-header{padding:10px 13px 8px;border-bottom:1px solid #f3f4f6;display:flex;align-items:flex-start;gap:8px;}
.ui-tooltip-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:3px;}
.ui-tooltip-title{font-size:12px;font-weight:700;color:#111827;line-height:1.35;font-family:'DM Sans',sans-serif;}
.ui-tooltip-subtitle{font-size:10px;color:#9ca3af;font-family:'DM Sans',sans-serif;margin-top:2px;font-weight:500;text-transform:uppercase;letter-spacing:.03em;}
.ui-tooltip-body{padding:9px 13px 11px;display:flex;flex-direction:column;gap:6px;}
.ui-tooltip-row{display:flex;align-items:center;justify-content:space-between;gap:16px;}
.ui-tooltip-key{font-size:11px;color:#6b7280;white-space:nowrap;font-family:'DM Sans',sans-serif;}
.ui-tooltip-val{font-size:11px;font-weight:700;color:#002B55;font-family:'DM Mono',monospace;white-space:nowrap;}
.ui-tooltip-val.na{color:#d1d5db;font-weight:400;font-style:italic;}
.ui-tooltip-divider{height:1px;background:#f3f4f6;margin:1px 0;}

/* ── empty ── */
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:28px 16px;gap:8px;text-align:center;}
.empty-state svg{opacity:.25;}
.empty-state p{font-size:12px;color:var(--gray-400);}
.empty-state strong{font-size:13px;color:var(--gray-500);font-weight:600;}
</style>
</head>
<body>

<div id="ui-tooltip" class="ui-tooltip" role="tooltip" aria-hidden="true">
  <div class="ui-tooltip-inner">
    <div class="ui-tooltip-header"><div class="ui-tooltip-dot" id="tt-dot"></div><div><div class="ui-tooltip-title" id="tt-title"></div><div class="ui-tooltip-subtitle" id="tt-subtitle"></div></div></div>
    <div class="ui-tooltip-body" id="tt-body"></div>
  </div>
</div>

<aside class="sidebar">
  <div class="logo"><div class="logo-icon"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12.5v4c0 1.657 2.686 3 6 3s6-1.343 6-3v-4"/><line x1="22" y1="10" x2="22" y2="16"/></svg></div>Univ Insight</div>
  <div class="nav-section">
    <div class="nav-label">Navigation</div>
    <a href="index.php"         class="nav-item" style="text-decoration:none;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Accueil</a>
    <a href="formation.php"     class="nav-item" style="text-decoration:none;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3L1 9l11 6 11-6-11-6z"/><path d="M5 13v5c0 2 7 4 7 4s7-2 7-4v-5"/></svg>Formations</a>
    <a href="etablissement.php" class="nav-item" style="text-decoration:none;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="8" width="16" height="13" rx="1"/><path d="M8 8V4h8v4"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="8" y1="11" x2="8" y2="16"/><line x1="16" y1="11" x2="16" y2="16"/></svg>Établissements</a>
    <a href="comparaison.php"   class="nav-item" style="text-decoration:none;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="8" width="6" height="12" rx="1"/><rect x="15" y="4" width="6" height="16" rx="1"/><path d="M12 2v20"/></svg>Comparaison</a>
    <a href="stats.php"         class="nav-item active" style="text-decoration:none;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 17 7 12 11 15 18 6 21 9"/><line x1="3" y1="20" x2="21" y2="20"/></svg>Statistiques</a>
  </div>
  <div class="sidebar-bottom"><div class="app-promo"><strong>Données ESR</strong><p>Dernière mise à jour : <?= $lastYear ?></p><button class="promo-btn">En savoir plus</button></div></div>
</aside>

<div class="main">
  <div class="topbar">
    <div class="search-container">
      <div class="search-box search-univ">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" class="search-input-field" placeholder="Rechercher…" autocomplete="off">
        <div class="search-loader" id="searchLoader"></div>
        <span class="shortcut">⌘F</span>
      </div>
      <div class="search-results-dropdown" id="searchResultsDropdown"></div>
    </div>
    <div class="topbar-icons"><div class="user-pill"><div class="avatar">UI</div><div class="user-info"><div class="user-name">Univ Insight</div><div class="user-email">Statistiques</div></div></div></div>
  </div>

  <div class="content">

    <div class="page-header" style="grid-column:1/-1;margin-bottom:8px;">
      <div><h1>Statistiques analytiques</h1><p>8 requêtes interactives + indice ICA — filtres globaux appliqués à tous les indicateurs.</p></div>
    </div>

    <!-- Filtres globaux -->
    <form method="GET" class="filter-bar" id="filterForm">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      <label>Année</label>
      <select name="annee" class="filter-select" onchange="this.form.submit()">
        <?php foreach ($annees as $y): ?><option value="<?= $y ?>" <?= $y==$selectedYear?'selected':'' ?>><?= $y ?></option><?php endforeach; ?>
      </select>
      <div class="filter-divider"></div>
      <label>Domaine</label>
      <select name="domaine" class="filter-select lg" onchange="this.form.submit()">
        <option value="">Tous les domaines</option>
        <?php foreach ($domainesList as $d): ?><option value="<?= htmlspecialchars($d) ?>" <?= $d===$selectedDom?'selected':'' ?>><?= htmlspecialchars($d) ?></option><?php endforeach; ?>
      </select>
      <div class="filter-divider"></div>
      <label>Délai</label>
      <div class="delai-toggle">
        <button type="submit" name="delai" value="18" class="delai-btn <?= $selectedDelai==18?'active':'' ?>">18 mois</button>
        <button type="submit" name="delai" value="30" class="delai-btn <?= $selectedDelai==30?'active':'' ?>">30 mois</button>
      </div>
      <?php if ($selectedDom || $selectedYear!=$lastYear): ?>
      <a href="stats.php" class="filter-reset" style="margin-left:auto;text-decoration:none;">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-4.73L1 10"/></svg>Réinitialiser
      </a>
      <?php endif; ?>
    </form>

    <!-- ═══════════════════════════════════════════════════════════
         R1 — Taux cadre par discipline
    ═══════════════════════════════════════════════════════════════ -->
    <div class="section-head">
      <div class="section-num">R1</div>
      <div><div class="section-title">Disciplines avec le meilleur taux cadre</div><div style="font-size:11px;color:var(--gray-400);margin-top:1px;">Classement agrégé toutes années — 18 mois</div></div>
      <span class="section-tech">Agrégation + ORDER BY</span>
      <button class="export-btn" onclick="exportCSV('r1-data','r1_taux_cadre')">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>CSV
      </button>
    </div>
    <div class="two-col-31">
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="card-title" style="margin:0;">Barres horizontales — taux cadre moyen</div>
          <span class="pill-tag"><?= $selectedYear ?></span>
        </div>
        <?php if (empty($r1)): ?>
          <div class="empty-state"><strong>Aucune donnée</strong></div>
        <?php else: ?>
          <div class="canvas-wrap"><canvas id="chartR1"></canvas></div>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="card-title">Données détaillées</div>
        <div style="overflow-y:auto;max-height:240px;" id="r1-data">
        <table class="stat-tbl">
          <thead><tr><th>#</th><th>Discipline</th><th class="r">Cadre</th><th class="r">Emploi</th></tr></thead>
          <tbody>
          <?php foreach ($r1 as $i => $row):
            $ttR = json_encode([['k'=>'Taux cadre','v'=>fmt($row['taux_cadre'],'%')],['k'=>"Taux d'emploi",'v'=>fmt($row['taux_emploi'],'%')],['k'=>'Observations','v'=>$row['nb'],'divider'=>true]]);
          ?>
          <tr data-tt-title="<?= htmlspecialchars($row['discipline']) ?>" data-tt-subtitle="<?= htmlspecialchars($row['domaine']) ?>" data-tt-dot="#000091" data-tt-rows='<?= htmlspecialchars($ttR) ?>'>
            <td class="rank-n"><?= $i+1 ?></td>
            <td><div style="font-size:11px;font-weight:600;"><?= htmlspecialchars(mb_strlen($row['discipline'])>28?mb_substr($row['discipline'],0,26).'…':$row['discipline']) ?></div><div class="tbl-bar"><div class="tbl-bar-fill" style="width:<?= $row['taux_cadre'] ?>%;"></div></div></td>
            <td style="text-align:right;font-weight:700;color:#000091;"><?= fmt($row['taux_cadre'],'%') ?></td>
            <td style="text-align:right;color:var(--gray-500);"><?= fmt($row['taux_emploi'],'%') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- ═══ R2 — Établissements au-dessus de la moyenne ═══ -->
    <div class="section-head">
      <div class="section-num">R2</div>
      <div><div class="section-title">Établissements au-dessus de la moyenne nationale</div><div style="font-size:11px;color:var(--gray-400);margin-top:1px;">Sous-requête scalaire corrélée</div></div>
      <span class="section-tech">Sous-requête corrélée</span>
      <button class="export-btn" onclick="exportCSV('r2-data','r2_etab_au_dessus')">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>CSV
      </button>
    </div>
    <div class="full-col card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div class="card-title" style="margin:0;">Scatter — taux d'emploi vs moyenne nationale</div>
        <?php if ($moyNat): ?><span style="background:#EEF2FF;color:#000091;font-size:10px;font-weight:600;padding:3px 10px;border-radius:20px;">Moy. nationale : <?= $moyNat ?>%</span><?php endif; ?>
      </div>
      <?php if (empty($r2)): ?>
        <div class="empty-state"><strong>Aucun établissement au-dessus de la moyenne</strong></div>
      <?php else: ?>
        <div class="canvas-wrap"><canvas id="chartR2"></canvas></div>
      <?php endif; ?>
    </div>

    <!-- ═══ R3 — Évolution CDI par discipline ═══ -->
    <div class="section-head">
      <div class="section-num">R3</div>
      <div><div class="section-title">Évolution du taux CDI par discipline</div><div style="font-size:11px;color:var(--gray-400);margin-top:1px;">5 dernières années — 18 mois</div></div>
      <span class="section-tech">GROUP BY + ORDER BY annee</span>
      <button class="export-btn" onclick="exportCSV('r3-data','r3_evolution_cdi')">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>CSV
      </button>
    </div>
    <div class="full-col card" id="r3-data">
      <div class="card-title">Courbes multi-séries — taux CDI par discipline</div>
      <?php if (empty($r3raw)): ?>
        <div class="empty-state"><strong>Aucune donnée</strong></div>
      <?php else: ?>
        <div class="canvas-wrap"><canvas id="chartR3"></canvas></div>
      <?php endif; ?>
    </div>

    <!-- ═══ R4 — Disciplines en déclin continu ═══ -->
    <div class="section-head">
      <div class="section-num">R4</div>
      <div><div class="section-title">Disciplines en déclin continu du taux d'emploi</div><div style="font-size:11px;color:var(--gray-400);margin-top:1px;">Deux années consécutives de baisse détectées</div></div>
      <span class="section-tech">LAG() + EXISTS</span>
    </div>
    <div class="full-col card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div class="card-title" style="margin:0;">Sparklines — disciplines en déclin</div>
        <?php if (empty($r4disciplines)): ?>
          <span style="font-size:10px;color:#10b981;font-weight:600;">✓ Aucun déclin détecté</span>
        <?php else: ?>
          <span style="font-size:10px;color:#ef4444;font-weight:600;"><?= count($r4disciplines) ?> discipline<?= count($r4disciplines)>1?'s':'' ?> en déclin</span>
        <?php endif; ?>
      </div>
      <?php if (empty($r4disciplines)): ?>
        <div class="empty-state" style="padding:20px;"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg><strong>Aucun déclin continu détecté</strong><p>Pour ce filtre, aucune discipline ne présente de baisse deux années consécutives.</p></div>
      <?php else: ?>
        <div id="sparklineWrap"></div>
      <?php endif; ?>
    </div>

    <!-- ═══ R5 — Double critère cadre + salaire ═══ -->
    <div class="section-head">
      <div class="section-num">R5</div>
      <div><div class="section-title">Formations avec taux cadre > 70% ET salaire > médiane nationale</div><div style="font-size:11px;color:var(--gray-400);margin-top:1px;"><?= $selectedYear ?> · <?= $selectedDelai ?> mois</div></div>
      <span class="section-tech">Jointure + multi-critères</span>
      <button class="export-btn" onclick="exportCSV('r5-tbl','r5_double_critere')">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>CSV
      </button>
    </div>
    <div class="two-col">
      <div class="card">
        <div class="card-title">Quadrant cadre vs salaire</div>
        <?php if (empty($r5)): ?>
          <div class="empty-state"><strong>Aucune formation ne remplit les deux critères</strong></div>
        <?php else: ?>
          <div class="canvas-wrap"><canvas id="chartR5"></canvas></div>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="card-title">Formations qualifiées</div>
        <div style="overflow-y:auto;max-height:260px;" id="r5-tbl">
        <?php if (empty($r5)): ?>
          <div class="empty-state" style="padding:20px;"><strong>Aucun résultat</strong></div>
        <?php else: ?>
        <table class="stat-tbl">
          <thead><tr><th>#</th><th>Formation</th><th class="r">Cadre</th><th class="r">Salaire</th></tr></thead>
          <tbody>
          <?php foreach ($r5 as $i => $row):
            $ttR = json_encode([['k'=>'Taux cadre','v'=>fmt($row['taux_cadre'],'%')],['k'=>'Salaire','v'=>fmtSal($row['salaire_median'])],['k'=>'Médiane nat.','v'=>fmtSal($row['mediane_nat']),'divider'=>true],['k'=>'Établissement','v'=>$row['etablissement']]]);
          ?>
          <tr data-tt-title="<?= htmlspecialchars(mb_strlen($row['intitule'])>36?mb_substr($row['intitule'],0,34).'…':$row['intitule']) ?>" data-tt-subtitle="<?= htmlspecialchars($row['discipline']) ?>" data-tt-dot="#000091" data-tt-rows='<?= htmlspecialchars($ttR) ?>'>
            <td class="rank-n"><?= $i+1 ?></td>
            <td><div style="font-size:11px;font-weight:600;"><?= htmlspecialchars(mb_strlen($row['intitule'])>28?mb_substr($row['intitule'],0,26).'…':$row['intitule']) ?></div><div style="font-size:9px;color:var(--gray-400);"><?= htmlspecialchars($row['etablissement']) ?></div></td>
            <td style="text-align:right;font-weight:700;color:#000091;"><?= fmt($row['taux_cadre'],'%') ?></td>
            <td style="text-align:right;font-weight:700;color:#10b981;"><?= fmtSal($row['salaire_median']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ═══ R6 — Classement régions ═══ -->
    <div class="section-head">
      <div class="section-num">R6</div>
      <div><div class="section-title">Classement des régions par taux d'emploi</div><div style="font-size:11px;color:var(--gray-400);margin-top:1px;"><?= $selectedYear ?> · <?= $selectedDelai ?> mois</div></div>
      <span class="section-tech">GROUP BY région</span>
      <button class="export-btn" onclick="exportCSV('r6-data','r6_regions')">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>CSV
      </button>
    </div>
    <div class="two-col-31">
      <div class="card">
        <div class="card-title">Barres horizontales — taux d'emploi par région</div>
        <?php if (empty($r6)): ?>
          <div class="empty-state"><strong>Aucune donnée</strong></div>
        <?php else: ?>
          <div class="canvas-wrap"><canvas id="chartR6"></canvas></div>
        <?php endif; ?>
      </div>
      <div class="card" id="r6-data">
        <div class="card-title">Classement</div>
        <div style="overflow-y:auto;max-height:240px;">
        <table class="stat-tbl">
          <thead><tr><th>#</th><th>Région</th><th class="r">Emploi</th><th class="r">Étabs</th></tr></thead>
          <tbody>
          <?php foreach ($r6 as $i => $row):
            $ttR = json_encode([["k"=>"Taux d'emploi","v"=>fmt($row['taux_emploi'],'%')],['k'=>'Salaire moy.','v'=>fmtSal($row['salaire']),'divider'=>true],['k'=>'Établissements','v'=>$row['nb_etab']]]);
          ?>
          <tr data-tt-title="<?= htmlspecialchars($row['region']) ?>" data-tt-subtitle="Région" data-tt-dot="#000091" data-tt-rows='<?= htmlspecialchars($ttR) ?>'>
            <td class="rank-n"><?= $i+1 ?></td>
            <td style="font-size:11px;font-weight:600;"><?= htmlspecialchars($row['region']) ?></td>
            <td style="text-align:right;font-weight:700;color:#000091;"><?= fmt($row['taux_emploi'],'%') ?></td>
            <td style="text-align:right;color:var(--gray-400);"><?= $row['nb_etab'] ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- ═══ R7 — Diplômes sans enquête récente ═══ -->
    <div class="section-head">
      <div class="section-num">R7</div>
      <div><div class="section-title">Diplômes sans enquête récente</div><div style="font-size:11px;color:var(--gray-400);margin-top:1px;">Aucune donnée depuis plus de 2 ans</div></div>
      <span class="section-tech">LEFT JOIN + IS NULL</span>
      <button class="export-btn" onclick="exportCSV('r7-tbl','r7_sans_enquete')">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>CSV
      </button>
    </div>
    <div class="full-col card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div class="card-title" style="margin:0;">Tableau — formations inactives</div>
        <span class="pill-tag"><?= count($r7) ?> formation<?= count($r7)>1?'s':'' ?></span>
      </div>
      <?php if (empty($r7)): ?>
        <div class="empty-state"><strong>Toutes les formations ont une enquête récente</strong></div>
      <?php else: ?>
      <div style="overflow-x:auto;" id="r7-tbl">
      <table class="stat-tbl">
        <thead><tr><th>Formation</th><th>Discipline</th><th>Établissement</th><th class="r">Dernière enquête</th><th class="r">Statut</th></tr></thead>
        <tbody>
        <?php foreach ($r7 as $row):
          $dern = $row['derniere_enquete'];
          $gap  = $dern ? ($lastYear - (int)$dern) : null;
          $badgeClass = $dern===null?'inact-none':'inact-old';
          $badgeTxt   = $dern===null?'Jamais enquêté':'Inactif '.($gap>=1?$gap.' an'.($gap>1?'s':''):'');
          $ttR = json_encode([['k'=>'Dernière enquête','v'=>$dern??'—'],['k'=>'Inactivité','v'=>$gap?$gap.' an'.($gap>1?'s':''):'—']]);
        ?>
        <tr data-tt-title="<?= htmlspecialchars(mb_strlen($row['intitule'])>40?mb_substr($row['intitule'],0,38).'…':$row['intitule']) ?>" data-tt-subtitle="<?= htmlspecialchars($row['etablissement']) ?>" data-tt-dot="<?= $dern===null?'#ef4444':'#f59e0b' ?>" data-tt-rows='<?= htmlspecialchars($ttR) ?>'>
          <td style="font-size:11px;font-weight:600;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars(mb_strlen($row['intitule'])>36?mb_substr($row['intitule'],0,34).'…':$row['intitule']) ?></td>
          <td style="font-size:11px;color:var(--gray-600);"><?= htmlspecialchars(mb_strlen($row['discipline'])>22?mb_substr($row['discipline'],0,20).'…':$row['discipline']) ?></td>
          <td style="font-size:11px;color:var(--gray-500);"><?= htmlspecialchars(mb_strlen($row['etablissement'])>22?mb_substr($row['etablissement'],0,20).'…':$row['etablissement']) ?></td>
          <td style="text-align:right;font-family:'DM Mono',monospace;font-size:11px;"><?= $dern??'—' ?></td>
          <td style="text-align:right;"><span class="inact-badge <?= $badgeClass ?>"><?= $badgeTxt ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- ═══ R8 — Gain 18 vs 30 mois ═══ -->
    <div class="section-head">
      <div class="section-num">R8</div>
      <div><div class="section-title">Gain d'insertion 18 → 30 mois par discipline</div><div style="font-size:11px;color:var(--gray-400);margin-top:1px;"><?= $selectedYear ?> — vitesse d'insertion</div></div>
      <span class="section-tech">Auto-jointure + écart</span>
      <button class="export-btn" onclick="exportCSV('r8-data','r8_gain_insertion')">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>CSV
      </button>
    </div>
    <div class="two-col">
      <div class="card">
        <div class="card-title">Barres groupées — 18 mois vs 30 mois</div>
        <?php if (empty($r8)): ?>
          <div class="empty-state"><strong>Données insuffisantes pour <?= $selectedYear ?></strong><p>Cette année ne dispose pas des deux délais d'enquête.</p></div>
        <?php else: ?>
          <div class="canvas-wrap"><canvas id="chartR8"></canvas></div>
        <?php endif; ?>
      </div>
      <div class="card" id="r8-data">
        <div class="card-title">Gain net par discipline</div>
        <?php if (empty($r8)): ?>
          <div class="empty-state" style="padding:20px;"><strong>Aucune donnée</strong></div>
        <?php else:
          $maxGain = max(array_merge(array_column($r8,'gain'),[1]));
        ?>
        <?php foreach ($r8 as $row):
          $g = (float)$row['gain'];
          $pct = $maxGain>0?min(100,round(abs($g)/$maxGain*100)):0;
          $col = $g>=0?'#000091':'#ef4444';
          $ttR = json_encode([['k'=>'18 mois','v'=>fmt($row['t18'],'%')],['k'=>'30 mois','v'=>fmt($row['t30'],'%')],['k'=>'Gain','v'=>($g>=0?'+':'').$g.'%','divider'=>true]]);
        ?>
        <div class="gain-row" data-tt-title="<?= htmlspecialchars($row['discipline']) ?>" data-tt-subtitle="Gain 18 → 30 mois" data-tt-dot="<?= $col ?>" data-tt-rows='<?= htmlspecialchars($ttR) ?>'>
          <div class="gain-label"><?= htmlspecialchars(mb_strlen($row['discipline'])>20?mb_substr($row['discipline'],0,18).'…':$row['discipline']) ?></div>
          <div class="gain-track"><div class="gain-fill" style="width:<?= $pct ?>%;background:<?= $col ?>;"></div></div>
          <div class="gain-val <?= $g>=0?'pos':'neg' ?>"><?= $g>=0?'+':'' ?><?= $g ?>%</div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ═══ BONUS ICA ═══ -->
    <div class="section-head">
      <div class="section-num bonus">★</div>
      <div><div class="section-title">Bonus — Indice Composite d'Attractivité (ICA)</div><div style="font-size:11px;color:var(--gray-400);margin-top:1px;">Emploi 40% · CDI 20% · Cadre 20% · Salaire 20% — <?= $selectedYear ?> · <?= $selectedDelai ?> mois</div></div>
      <span class="section-tech" style="background:#fde8e8;color:#b91c1c;">+2 pts</span>
    </div>
    <div class="two-col">

      <!-- Top 10 formations -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="card-title" style="margin:0;">Top 10 formations</div>
          <span class="pill-tag"><?= $selectedYear ?></span>
        </div>
        <?php if (empty($bonusTop10)): ?>
          <div class="empty-state"><strong>Données insuffisantes</strong></div>
        <?php else: ?>
          <!-- Podium top 3 -->
          <div class="podium-wrap">
          <?php
          $podiumOrder = [1,0,2]; // affichage : 2e, 1er, 3e
          $podiumH = [60,90,48];
          $podiumColors = ['#0053B3','#000091','#6CB4EE'];
          foreach ($podiumOrder as $pi => $ri):
            if (!isset($bonusTop10[$ri])) continue;
            $f = $bonusTop10[$ri];
            $pname = mb_strlen($f['etablissement'])>10?mb_substr($f['etablissement'],0,8).'…':$f['etablissement'];
          ?>
          <div class="podium-col">
            <div class="podium-rank"><?= $ri+1 ?></div>
            <div class="podium-block" style="height:<?= $podiumH[$pi] ?>px;background:<?= $podiumColors[$pi] ?>;"><?= round($f['ica']) ?></div>
            <div class="podium-name" title="<?= htmlspecialchars($f['intitule']) ?>"><?= htmlspecialchars($pname) ?></div>
          </div>
          <?php endforeach; ?>
          </div>

          <!-- Liste complète -->
          <div style="margin-top:12px;overflow-y:auto;max-height:180px;">
          <table class="stat-tbl">
            <thead><tr><th>#</th><th>Formation</th><th class="r">ICA</th></tr></thead>
            <tbody>
            <?php foreach ($bonusTop10 as $i => $row):
              $ttR = json_encode([["k"=>"Taux d'emploi",'v'=>fmt($row['taux_emploi'],'%')],['k'=>'Taux CDI','v'=>fmt($row['taux_cdi'],'%')],['k'=>'Taux cadre','v'=>fmt($row['taux_cadre'],'%')],['k'=>'Salaire','v'=>fmtSal($row['salaire_median']),'divider'=>true],['k'=>'ICA','v'=>$row['ica'].'/100']]);
            ?>
            <tr data-tt-title="<?= htmlspecialchars(mb_strlen($row['intitule'])>36?mb_substr($row['intitule'],0,34).'…':$row['intitule']) ?>" data-tt-subtitle="<?= htmlspecialchars($row['etablissement']) ?>" data-tt-dot="#000091" data-tt-rows='<?= htmlspecialchars($ttR) ?>'>
              <td class="rank-n" style="color:<?= $i<3?'#000091':'' ?>;"><?= $i+1 ?></td>
              <td><div style="font-size:11px;font-weight:600;"><?= htmlspecialchars(mb_strlen($row['intitule'])>28?mb_substr($row['intitule'],0,26).'…':$row['intitule']) ?></div><div style="font-size:9px;color:var(--gray-400);"><?= htmlspecialchars(mb_strlen($row['etablissement'])>28?mb_substr($row['etablissement'],0,26).'…':$row['etablissement']) ?></div></td>
              <td style="text-align:right;">
                <div style="font-size:12px;font-weight:700;color:#000091;"><?= $row['ica'] ?></div>
                <div class="tbl-bar"><div class="tbl-bar-fill" style="width:<?= $row['ica'] ?>%;"></div></div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Classement établissements ICA + bar chart -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="card-title" style="margin:0;">Top 10 établissements par ICA moyen</div>
          <span class="pill-tag"><?= $selectedYear ?></span>
        </div>
        <?php if (empty($icaEtab)): ?>
          <div class="empty-state"><strong>Données insuffisantes</strong></div>
        <?php else: ?>
          <div class="canvas-wrap-sm"><canvas id="chartIcaEtab"></canvas></div>
          <div style="overflow-y:auto;max-height:120px;margin-top:10px;">
          <table class="stat-tbl">
            <thead><tr><th>#</th><th>Établissement</th><th class="r">ICA moy.</th><th class="r">Formations</th></tr></thead>
            <tbody>
            <?php foreach ($icaEtab as $i => $row):
              $ttR = json_encode([['k'=>'ICA moyen','v'=>$row['ica_moy'].'/100'],['k'=>'Formations','v'=>$row['nb_formations']],['k'=>'Région','v'=>$row['region']]]);
            ?>
            <tr data-tt-title="<?= htmlspecialchars($row['etablissement']) ?>" data-tt-subtitle="<?= htmlspecialchars($row['region']) ?>" data-tt-dot="#000091" data-tt-rows='<?= htmlspecialchars($ttR) ?>'>
              <td class="rank-n" style="color:<?= $i<3?'#000091':'' ?>;"><?= $i+1 ?></td>
              <td style="font-size:11px;font-weight:600;"><?= htmlspecialchars(mb_strlen($row['etablissement'])>24?mb_substr($row['etablissement'],0,22).'…':$row['etablissement']) ?></td>
              <td style="text-align:right;font-weight:700;color:#000091;"><?= $row['ica_moy'] ?></td>
              <td style="text-align:right;color:var(--gray-400);"><?= $row['nb_formations'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<script>
/* ── Tooltip ── */
(function(){
  const tt=document.getElementById('ui-tooltip'),ttDot=document.getElementById('tt-dot'),ttTitle=document.getElementById('tt-title'),ttSub=document.getElementById('tt-subtitle'),ttBody=document.getElementById('tt-body');
  let hideTimer=null,activeEl=null;
  function buildRows(rows){return rows.map((r,idx)=>{const isNA=!r.v||r.v==='—';const cl=isNA?'ui-tooltip-val na':'ui-tooltip-val';const div=r.divider&&idx<rows.length-1?'<div class="ui-tooltip-divider"></div>':'';return`${div}<div class="ui-tooltip-row"><span class="ui-tooltip-key">${r.k}</span><span class="${cl}">${r.v||'—'}</span></div>`;}).join('');}
  function show(el,e){clearTimeout(hideTimer);if(activeEl===el&&tt.classList.contains('visible')){pos(e);return;}activeEl=el;const title=el.dataset.ttTitle||'',sub=el.dataset.ttSubtitle||'',dot=el.dataset.ttDot||'#000091';let rows=[];try{rows=JSON.parse(el.dataset.ttRows||'[]');}catch(_){}ttDot.style.background=dot;ttTitle.textContent=title;ttSub.textContent=sub;ttSub.style.display=sub?'':'none';ttBody.innerHTML=buildRows(rows);tt.style.left='-9999px';tt.style.top='-9999px';tt.classList.add('visible');tt.setAttribute('aria-hidden','false');requestAnimationFrame(()=>pos(e));}
  function pos(e){const W=tt.offsetWidth||220,H=tt.offsetHeight||120,vw=window.innerWidth,vh=window.innerHeight,pad=14;let x=e.clientX+16,y=e.clientY+16;if(x+W+pad>vw)x=e.clientX-W-12;if(y+H+pad>vh)y=e.clientY-H-12;if(x<pad)x=pad;if(y<pad)y=pad;tt.style.left=x+'px';tt.style.top=y+'px';}
  function hide(){hideTimer=setTimeout(()=>{tt.classList.remove('visible');tt.setAttribute('aria-hidden','true');activeEl=null;},100);}
  document.addEventListener('mouseover',e=>{const el=e.target.closest('[data-tt-title]');if(el)show(el,e);});
  document.addEventListener('mousemove',e=>{if(tt.classList.contains('visible'))pos(e);});
  document.addEventListener('mouseout',e=>{const el=e.target.closest('[data-tt-title]');if(el)hide();});
  document.addEventListener('scroll',hide,{passive:true});
})();

/* ── CSV export ── */
function exportCSV(elId, filename) {
  const el = document.getElementById(elId);
  if (!el) return;
  const tbl = el.tagName==='TABLE' ? el : el.querySelector('table');
  if (!tbl) { alert('Pas de tableau à exporter.'); return; }
  const rows = [...tbl.querySelectorAll('tr')].map(tr =>
    [...tr.querySelectorAll('th,td')].map(td => '"'+td.innerText.replace(/"/g,'""').trim()+'"').join(',')
  );
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,\uFEFF' + encodeURIComponent(rows.join('\n'));
  a.download = filename + '_<?= $selectedYear ?>.csv';
  a.click();
}

/* ── Chart.js ── */
Chart.defaults.font.family="'DM Sans', sans-serif";
Chart.defaults.font.size=11;
Chart.defaults.color='#9ca3af';

const BLUE='#000091',BLUE_L='#0053B3',BLUE_S='#6CB4EE',DARK='#002B55';
const PALETTE=['#000091','#0053B3','#6CB4EE','#002B55','#3a8dd9','#9cb3d8','#185FA5','#1a6fc4','#4a90d9','#c4d8ec'];

const sharedTT={backgroundColor:'#fff',titleColor:'#111827',bodyColor:'#374151',borderColor:'#e5e7eb',borderWidth:1,padding:12,cornerRadius:10,boxPadding:5,titleFont:{family:"'DM Sans',sans-serif",size:12,weight:'700'},bodyFont:{family:"'DM Sans',sans-serif",size:11}};

const vertLine={id:'vl',afterDraw(c){if(c.tooltip._active?.length){const ctx=c.ctx,x=c.tooltip._active[0].element.x,{top,bottom}=c.chartArea;ctx.save();ctx.beginPath();ctx.moveTo(x,top);ctx.lineTo(x,bottom);ctx.lineWidth=1;ctx.strokeStyle='rgba(0,0,145,.1)';ctx.setLineDash([4,3]);ctx.stroke();ctx.restore();}}};

/* R1 — barres horizontales taux cadre */
<?php if (!empty($r1)): ?>
const r1=<?= $r1Json ?>;
new Chart(document.getElementById('chartR1'),{
  type:'bar',
  data:{labels:r1.map(d=>d.discipline.length>22?d.discipline.slice(0,20)+'…':d.discipline),datasets:[{label:'Taux cadre (%)',data:r1.map(d=>d.taux_cadre),backgroundColor:r1.map((_,i)=>i===0?BLUE:BLUE_L+'BB'),borderRadius:4,borderSkipped:false}]},
  options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{...sharedTT,callbacks:{label:ctx=>'  Taux cadre : '+ctx.parsed.x+'%'}}},scales:{x:{min:0,max:100,grid:{color:'#f3f4f6'},border:{display:false},ticks:{callback:v=>v+'%',font:{size:10}}},y:{grid:{display:false},border:{display:false},ticks:{font:{size:10}}}}}
});
<?php endif; ?>

/* R2 — scatter établissements */
<?php if (!empty($r2)): ?>
const r2=<?= $r2Json ?>;
const moyNat=<?= $moyNatJ ?>;
new Chart(document.getElementById('chartR2'),{
  type:'scatter',
  data:{datasets:[{label:"Établissements",data:r2.map((d,i)=>({x:i+1,y:+d.taux_emploi_moy,label:d.etablissement,region:d.region})),backgroundColor:r2.map((_,i)=>i===0?BLUE:BLUE_L+'CC'),borderColor:r2.map((_,i)=>i===0?BLUE:BLUE_L),borderWidth:1.5,pointRadius:7,pointHoverRadius:10},{label:'Moy. nationale',data:r2.map((_,i)=>({x:i+1,y:moyNat})),type:'line',borderColor:'#E1000F',borderWidth:1.5,borderDash:[5,4],pointRadius:0,fill:false}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{...sharedTT,filter:item=>item.datasetIndex===0,callbacks:{title:items=>{const d=r2[items[0].dataIndex];return d.etablissement;},label:ctx=>{const d=r2[ctx.dataIndex];return['  Taux emploi : '+d.taux_emploi_moy+'%','  Région : '+d.region,'  Vs national : +'+(Math.round((+d.taux_emploi_moy-moyNat)*10)/10)+'%'];}}}},scales:{x:{display:false},y:{min:Math.max(0,moyNat-5),grid:{color:'#f3f4f6'},border:{display:false},ticks:{callback:v=>v+'%',font:{size:10}}}}}
});
<?php endif; ?>

/* R3 — multi-lignes CDI */
<?php if (!empty($r3raw)): ?>
const r3=<?= $r3Json ?>;
new Chart(document.getElementById('chartR3'),{
  type:'line',plugins:[vertLine],
  data:{
    labels:r3.years,
    datasets:r3.discs.map((disc,i)=>({
      label:disc.length>20?disc.slice(0,18)+'…':disc,
      data:r3.years.map(y=>r3.matrix[disc]?.[y]??null),
      borderColor:PALETTE[i%PALETTE.length],
      backgroundColor:'transparent',
      borderWidth:2,pointRadius:3,pointHoverRadius:5,
      pointBackgroundColor:'#fff',pointBorderColor:PALETTE[i%PALETTE.length],
      tension:0.4,spanGaps:true,fill:false
    }))
  },
  options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
    plugins:{legend:{position:'top',labels:{boxWidth:10,boxHeight:10,borderRadius:3,useBorderRadius:true,padding:12,font:{size:10}}},tooltip:{...sharedTT,callbacks:{title:i=>'Année '+i[0].label,label:ctx=>'  '+ctx.dataset.label+' : '+(ctx.parsed.y!==null?ctx.parsed.y+'%':'N/A')}}},
    scales:{x:{grid:{display:false},border:{display:false},ticks:{font:{size:10}}},y:{min:40,max:100,grid:{color:'#f3f4f6'},border:{display:false},ticks:{callback:v=>v+'%',font:{size:10},stepSize:10}}}
  }
});
<?php endif; ?>

/* R4 — sparklines disciplines en déclin */
<?php if (!empty($r4disciplines)): ?>
(function(){
  const r4=<?= $r4Json ?>;
  const wrap=document.getElementById('sparklineWrap');
  const discs=Object.keys(r4);
  discs.forEach(disc=>{
    const years=Object.keys(r4[disc]).map(Number).sort();
    const vals=years.map(y=>r4[disc][y]);
    const lastV=vals[vals.length-1],prevV=vals[vals.length-2];
    const trend=lastV&&prevV?(lastV<prevV?'down':'up'):'';
    const row=document.createElement('div');row.className='sparkline-row';
    row.innerHTML=`<div class="sparkline-name" title="${disc}">${disc.length>22?disc.slice(0,20)+'…':disc}</div><canvas class="sparkline-canvas" id="sp_${disc.replace(/[^a-z0-9]/gi,'_')}"></canvas><div class="sparkline-trend"><span class="badge-declin">▼</span></div>`;
    wrap.appendChild(row);
    const ctx=row.querySelector('canvas').getContext('2d');
    new Chart(ctx,{type:'line',data:{labels:years,datasets:[{data:vals,borderColor:'#ef4444',backgroundColor:'rgba(239,68,68,0.06)',borderWidth:1.5,pointRadius:2,fill:true,tension:0.4,spanGaps:true}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{...sharedTT,callbacks:{title:i=>'Année '+i[0].label,label:ctx=>'  Taux emploi : '+ctx.parsed.y+'%'}}},scales:{x:{display:false},y:{display:false,min:v=>v.min-2,max:v=>v.max+2}}}});
  });
})();
<?php endif; ?>

/* R5 — scatter quadrant cadre vs salaire */
<?php if (!empty($r5)): ?>
const r5=<?= json_encode($r5) ?>;
const medNat=<?= json_encode($r5[0]['mediane_nat'] ?? null) ?>;
new Chart(document.getElementById('chartR5'),{
  type:'scatter',
  data:{datasets:[{label:'Formations',data:r5.map(d=>({x:+d.taux_cadre,y:+d.salaire_median,label:d.intitule,etab:d.etablissement})),backgroundColor:BLUE+'CC',borderColor:BLUE,borderWidth:1.5,pointRadius:7,pointHoverRadius:9}]},
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:false},tooltip:{...sharedTT,callbacks:{title:items=>{const d=r5[items[0].dataIndex];return d.label.length>36?d.label.slice(0,34)+'…':d.label;},label:ctx=>{const d=r5[ctx.dataIndex];return['  Taux cadre : '+d.x+'%','  Salaire : '+d.y.toLocaleString('fr-FR')+'€','  Établissement : '+d.etab];}}}},
    scales:{
      x:{title:{display:true,text:'Taux cadre (%)',font:{size:10},color:'#9ca3af'},min:70,max:105,grid:{color:'#f3f4f6'},border:{display:false},ticks:{callback:v=>v+'%',font:{size:10}}},
      y:{title:{display:true,text:'Salaire médian (€)',font:{size:10},color:'#9ca3af'},grid:{color:'#f3f4f6'},border:{display:false},ticks:{callback:v=>v.toLocaleString('fr-FR')+'€',font:{size:10}}}
    },
    plugins:{
      legend:{display:false},
      annotation:{annotations:{line1:{type:'line',yMin:medNat,yMax:medNat,borderColor:'#E1000F',borderWidth:1.5,borderDash:[5,4],label:{content:'Médiane nat.',enabled:true,position:'end',font:{size:9}}}}}
    }
  }
});
<?php endif; ?>

/* R6 — barres régions */
<?php if (!empty($r6)): ?>
const r6=<?= $r6Json ?>;
new Chart(document.getElementById('chartR6'),{
  type:'bar',
  data:{labels:r6.map(d=>d.region.length>16?d.region.slice(0,14)+'…':d.region),datasets:[{label:"Taux d'emploi",data:r6.map(d=>d.taux_emploi),backgroundColor:r6.map((_,i)=>i===0?BLUE:(i<3?BLUE_L+'CC':BLUE_S+'99')),borderRadius:4,borderSkipped:false}]},
  options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{...sharedTT,callbacks:{label:ctx=>'  '+r6[ctx.dataIndex].region+' : '+ctx.parsed.x+'%'}}},scales:{x:{min:60,max:100,grid:{color:'#f3f4f6'},border:{display:false},ticks:{callback:v=>v+'%',font:{size:10}}},y:{grid:{display:false},border:{display:false},ticks:{font:{size:10}}}}}
});
<?php endif; ?>

/* R8 — barres groupées gain */
<?php if (!empty($r8)): ?>
const r8=<?= $r8Json ?>;
new Chart(document.getElementById('chartR8'),{
  type:'bar',
  data:{
    labels:r8.map(d=>d.discipline.length>20?d.discipline.slice(0,18)+'…':d.discipline),
    datasets:[
      {label:'18 mois',data:r8.map(d=>d.t18),backgroundColor:BLUE_S+'CC',borderRadius:3,borderSkipped:false},
      {label:'30 mois',data:r8.map(d=>d.t30),backgroundColor:BLUE+'CC',borderRadius:3,borderSkipped:false},
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
    plugins:{legend:{position:'top',labels:{boxWidth:10,boxHeight:10,borderRadius:3,useBorderRadius:true,padding:12,font:{size:10}}},tooltip:{...sharedTT,callbacks:{title:i=>r8[i[0].dataIndex].discipline,label:ctx=>'  '+ctx.dataset.label+' : '+ctx.parsed.y+'%',afterBody:items=>{const d=r8[items[0].dataIndex];return d.gain!==null?['','  Gain : +('+d.gain+'%)']:[];}}}},
    scales:{x:{grid:{display:false},border:{display:false},ticks:{font:{size:9}}},y:{min:50,max:100,grid:{color:'#f3f4f6'},border:{display:false},ticks:{callback:v=>v+'%',font:{size:10}}}}
  }
});
<?php endif; ?>

/* ICA établissements barres */
<?php if (!empty($icaEtab)): ?>
const icaEtabData=<?= json_encode($icaEtab) ?>;
new Chart(document.getElementById('chartIcaEtab'),{
  type:'bar',
  data:{labels:icaEtabData.map(d=>d.etablissement.length>18?d.etablissement.slice(0,16)+'…':d.etablissement),datasets:[{label:'ICA moyen',data:icaEtabData.map(d=>d.ica_moy),backgroundColor:icaEtabData.map((_,i)=>i===0?BLUE:(i<3?BLUE_L+'CC':BLUE_S+'99')),borderRadius:4,borderSkipped:false}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{...sharedTT,callbacks:{label:ctx=>'  ICA : '+ctx.parsed.y+'/100'}}},scales:{x:{grid:{display:false},border:{display:false},ticks:{font:{size:9}}},y:{min:0,max:100,grid:{color:'#f3f4f6'},border:{display:false},ticks:{callback:v=>v+'/100',font:{size:10}}}}}
});
<?php endif; ?>
</script>
<script src="script.js"></script>
</body>
</html>