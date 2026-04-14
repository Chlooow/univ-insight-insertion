<?php
require_once '../connexion.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt($val, $suffix = '', $fallback = '—') {
    return ($val !== null && $val !== '') ? $val . $suffix : $fallback;
}
function fmtSal($val, $fallback = '—') {
    return ($val !== null && $val !== '') ? number_format((float)$val, 0, ',', ' ') . '€' : $fallback;
}

// ── Listes pour les selects ───────────────────────────────────────────────────
$annees    = $pdo->query("SELECT DISTINCT annee FROM ANNEE_ENQUETE ORDER BY annee DESC")->fetchAll(PDO::FETCH_COLUMN);
$lastYear  = (int)$annees[0];

// Toutes les formations disponibles (intitulé + discipline + établissement)
$formList  = $pdo->query("
    SELECT dip.id_diplome,
           CONCAT(dip.intitule, ' — ', dis.nom, ' (', e.nom, ')') AS label,
           dip.intitule, dis.nom AS discipline, e.nom AS etab_nom
    FROM DIPLOME dip
    JOIN DISCIPLINE    dis ON dis.id_disc = dip.id_disc
    JOIN ETABLISSEMENT e   ON e.id_etab   = dip.id_etab
    ORDER BY dip.intitule, dis.nom, e.nom
")->fetchAll();

// Sélection de la formation
$selectedFormId = null;
if (isset($_GET['form_id']) && ctype_digit($_GET['form_id'])) {
    $selectedFormId = (int)$_GET['form_id'];
} elseif (!empty($formList)) {
    $selectedFormId = (int)$formList[0]['id_diplome'];
}

// Année sélectionnée
$selectedYear = isset($_GET['annee']) && in_array($_GET['annee'], $annees)
    ? (int)$_GET['annee'] : $lastYear;

// Délai sélectionné
$selectedDelai = (isset($_GET['delai']) && in_array((int)$_GET['delai'], [18, 30])) ? (int)$_GET['delai'] : 18;

// ── Info formation ────────────────────────────────────────────────────────────
$formInfo = null;
if ($selectedFormId) {
    $s = $pdo->prepare("
        SELECT dip.id_diplome, dip.intitule, dip.niveau,
               dis.nom AS discipline, dis.domaine,
               e.nom AS etab_nom, e.id_etab,
               reg.nom AS region
        FROM DIPLOME       dip
        JOIN DISCIPLINE    dis ON dis.id_disc  = dip.id_disc
        JOIN ETABLISSEMENT e   ON e.id_etab    = dip.id_etab
        JOIN REGION        reg ON reg.id_region = e.id_region
        WHERE dip.id_diplome = :id
    ");
    $s->execute([':id' => $selectedFormId]);
    $formInfo = $s->fetch();
}

// ── KPIs de la formation (année + délai sélectionnés) ────────────────────────
$kpiStmt = $pdo->prepare("
    SELECT
        r.taux_emploi, r.taux_cdi, r.taux_cadre,
        r.salaire_median AS salaire,
        r.nb_repondants,
        ae.annee, ae.delai_mois
    FROM RESULTAT_IP   r
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE r.id_diplome  = :id
      AND ae.annee      = :y
      AND ae.delai_mois = :d
    LIMIT 1
");
$kpiStmt->execute([':id' => $selectedFormId, ':y' => $selectedYear, ':d' => $selectedDelai]);
$kpi = $kpiStmt->fetch() ?: [];

// ── Moyennes nationales discipline + national ─────────────────────────────────
$moyStmt = $pdo->prepare("
    SELECT
        ROUND(AVG(CASE WHEN dis2.id_disc = dis.id_disc THEN r2.taux_emploi END), 1) AS emp_disc,
        ROUND(AVG(CASE WHEN dis2.id_disc = dis.id_disc THEN r2.taux_cdi    END), 1) AS cdi_disc,
        ROUND(AVG(CASE WHEN dis2.id_disc = dis.id_disc THEN r2.taux_cadre  END), 1) AS cad_disc,
        ROUND(AVG(CASE WHEN dis2.id_disc = dis.id_disc THEN r2.salaire_median END), 0) AS sal_disc,
        ROUND(AVG(r2.taux_emploi), 1)    AS emp_nat,
        ROUND(AVG(r2.taux_cdi),    1)    AS cdi_nat,
        ROUND(AVG(r2.taux_cadre),  1)    AS cad_nat,
        ROUND(AVG(r2.salaire_median), 0) AS sal_nat
    FROM RESULTAT_IP   r2
    JOIN DIPLOME       dip2 ON dip2.id_diplome = r2.id_diplome
    JOIN DISCIPLINE    dis2 ON dis2.id_disc    = dip2.id_disc
    JOIN DIPLOME       dip  ON dip.id_diplome  = :id
    JOIN DISCIPLINE    dis  ON dis.id_disc     = dip.id_disc
    JOIN ANNEE_ENQUETE ae2  ON ae2.id_annee    = r2.id_annee
    WHERE ae2.annee      = :y
      AND ae2.delai_mois = :d
");
$moyStmt->execute([':id' => $selectedFormId, ':y' => $selectedYear, ':d' => $selectedDelai]);
$moy = $moyStmt->fetch() ?: [];

// ── Évolution temporelle de la formation (tous délais confondus → 18 mois) ────
$evolStmt = $pdo->prepare("
    SELECT ae.annee, ae.delai_mois,
           r.taux_emploi, r.taux_cdi, r.taux_cadre, r.salaire_median
    FROM RESULTAT_IP   r
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE r.id_diplome = :id
    ORDER BY ae.annee, ae.delai_mois
");
$evolStmt->execute([':id' => $selectedFormId]);
$evolAll = $evolStmt->fetchAll();

// Séparer 18 et 30 mois
$evol18 = array_values(array_filter($evolAll, fn($r) => $r['delai_mois'] == 18));
$evol30 = array_values(array_filter($evolAll, fn($r) => $r['delai_mois'] == 30));

// ── Rang de la formation dans sa discipline (même année, même délai) ──────────
$rankStmt = $pdo->prepare("
    SELECT ranked.id_diplome, ranked.rnk, ranked.total
    FROM (
        SELECT r2.id_diplome,
               RANK() OVER (ORDER BY r2.taux_emploi DESC) AS rnk,
               COUNT(*) OVER () AS total
        FROM RESULTAT_IP   r2
        JOIN DIPLOME       dip2 ON dip2.id_diplome = r2.id_diplome
        JOIN DISCIPLINE    dis2 ON dis2.id_disc    = dip2.id_disc
        JOIN DIPLOME       dip  ON dip.id_diplome  = :id
        JOIN DISCIPLINE    dis  ON dis.id_disc     = dip.id_disc
        JOIN ANNEE_ENQUETE ae2  ON ae2.id_annee    = r2.id_annee
        WHERE dis2.id_disc = dis.id_disc
          AND ae2.annee      = :y
          AND ae2.delai_mois = :d
          AND r2.taux_emploi IS NOT NULL
    ) ranked
    WHERE ranked.id_diplome = :id2
");
$rankStmt->execute([':id' => $selectedFormId, ':y' => $selectedYear, ':d' => $selectedDelai, ':id2' => $selectedFormId]);
$rankRow = $rankStmt->fetch();
$rangDisc  = $rankRow ? (int)$rankRow['rnk']   : null;
$totalDisc = $rankRow ? (int)$rankRow['total'] : null;

// ── Rang national ─────────────────────────────────────────────────────────────
$rankNatStmt = $pdo->prepare("
    SELECT ranked.id_diplome, ranked.rnk, ranked.total
    FROM (
        SELECT r2.id_diplome,
               RANK() OVER (ORDER BY r2.taux_emploi DESC) AS rnk,
               COUNT(*) OVER () AS total
        FROM RESULTAT_IP   r2
        JOIN ANNEE_ENQUETE ae2 ON ae2.id_annee = r2.id_annee
        WHERE ae2.annee = :y AND ae2.delai_mois = :d AND r2.taux_emploi IS NOT NULL
    ) ranked
    WHERE ranked.id_diplome = :id
");
$rankNatStmt->execute([':y' => $selectedYear, ':d' => $selectedDelai, ':id' => $selectedFormId]);
$rankNatRow  = $rankNatStmt->fetch();
$rangNat     = $rankNatRow ? (int)$rankNatRow['rnk']   : null;
$totalNat    = $rankNatRow ? (int)$rankNatRow['total'] : null;

// ── Radar : cette formation vs discipline vs national ─────────────────────────
// Normalisation min-max sur 5 axes
$statsNorm = $pdo->prepare("
    SELECT
        MIN(r2.taux_emploi) AS min_emp, MAX(r2.taux_emploi) AS max_emp,
        MIN(r2.taux_cdi)    AS min_cdi, MAX(r2.taux_cdi)    AS max_cdi,
        MIN(r2.taux_cadre)  AS min_cad, MAX(r2.taux_cadre)  AS max_cad,
        MIN(r2.salaire_median) AS min_sal, MAX(r2.salaire_median) AS max_sal,
        MIN(r2.nb_repondants)  AS min_rep, MAX(r2.nb_repondants)  AS max_rep
    FROM RESULTAT_IP r2
    JOIN ANNEE_ENQUETE ae2 ON ae2.id_annee = r2.id_annee
    WHERE ae2.annee = :y AND ae2.delai_mois = :d
      AND r2.taux_emploi IS NOT NULL
");
$statsNorm->execute([':y' => $selectedYear, ':d' => $selectedDelai]);
$norm = $statsNorm->fetch();

function normalize($val, $min, $max) {
    if ($val === null || $min === null || $max === null || $max == $min) return null;
    return round(($val - $min) / ($max - $min) * 100, 1);
}

$radarFormation = [
    normalize($kpi['taux_emploi'] ?? null, $norm['min_emp'], $norm['max_emp']),
    normalize($kpi['taux_cdi']    ?? null, $norm['min_cdi'], $norm['max_cdi']),
    normalize($kpi['taux_cadre']  ?? null, $norm['min_cad'], $norm['max_cad']),
    normalize($kpi['salaire']     ?? null, $norm['min_sal'], $norm['max_sal']),
    normalize($kpi['nb_repondants'] ?? null, $norm['min_rep'], $norm['max_rep']),
];
$radarDisc = [
    normalize($moy['emp_disc'] ?? null, $norm['min_emp'], $norm['max_emp']),
    normalize($moy['cdi_disc'] ?? null, $norm['min_cdi'], $norm['max_cdi']),
    normalize($moy['cad_disc'] ?? null, $norm['min_cad'], $norm['max_cad']),
    normalize($moy['sal_disc'] ?? null, $norm['min_sal'], $norm['max_sal']),
    50,
];
$radarNat = [
    normalize($moy['emp_nat'] ?? null, $norm['min_emp'], $norm['max_emp']),
    normalize($moy['cdi_nat'] ?? null, $norm['min_cdi'], $norm['max_cdi']),
    normalize($moy['cad_nat'] ?? null, $norm['min_cad'], $norm['max_cad']),
    normalize($moy['sal_nat'] ?? null, $norm['min_sal'], $norm['max_sal']),
    50,
];

// ── Tendance (3 dernières années, 18 mois) ────────────────────────────────────
$tendance = 'stable';
$lastThree = array_slice(array_filter($evol18, fn($r) => $r['taux_emploi'] !== null), -3);
if (count($lastThree) >= 2) {
    $vals = array_column(array_values($lastThree), 'taux_emploi');
    $last = end($vals); $prev = prev($vals);
    $delta = $last - $prev;
    if ($delta > 1)      $tendance = 'hausse';
    elseif ($delta < -1) $tendance = 'baisse';
}

// ── ICA de la formation ───────────────────────────────────────────────────────
$icaVal = null;
if (!empty($norm) && !empty($kpi)) {
    $e = normalize($kpi['taux_emploi'] ?? null, $norm['min_emp'], $norm['max_emp']) ?? 0;
    $c = normalize($kpi['taux_cdi']    ?? null, $norm['min_cdi'], $norm['max_cdi']) ?? 0;
    $k2= normalize($kpi['taux_cadre']  ?? null, $norm['min_cad'], $norm['max_cad']) ?? 0;
    $s = normalize($kpi['salaire']     ?? null, $norm['min_sal'], $norm['max_sal']) ?? 0;
    $icaVal = round(0.4 * $e + 0.2 * $c + 0.2 * $k2 + 0.2 * $s, 1);
}

// Percentile ICA
$icaPercentile = null;
if ($icaVal !== null) {
    $pctStmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN (
                   0.40 * NULLIF(r2.taux_emploi    - :min_emp, 0) / NULLIF(:max_emp - :min_emp2, 0)
                 + 0.20 * NULLIF(r2.taux_cdi       - :min_cdi, 0) / NULLIF(:max_cdi - :min_cdi2, 0)
                 + 0.20 * NULLIF(r2.taux_cadre     - :min_cad, 0) / NULLIF(:max_cad - :min_cad2, 0)
                 + 0.20 * NULLIF(r2.salaire_median - :min_sal, 0) / NULLIF(:max_sal - :min_sal2, 0)
               ) * 100 < :ica THEN 1 ELSE 0 END) AS below
        FROM RESULTAT_IP r2
        JOIN ANNEE_ENQUETE ae2 ON ae2.id_annee = r2.id_annee
        WHERE ae2.annee = :y AND ae2.delai_mois = :d
          AND r2.taux_emploi IS NOT NULL AND r2.taux_cdi IS NOT NULL
          AND r2.taux_cadre IS NOT NULL  AND r2.salaire_median IS NOT NULL
    ");
    $pctStmt->execute([
        ':min_emp' => $norm['min_emp'], ':max_emp' => $norm['max_emp'], ':min_emp2' => $norm['min_emp'],
        ':min_cdi' => $norm['min_cdi'], ':max_cdi' => $norm['max_cdi'], ':min_cdi2' => $norm['min_cdi'],
        ':min_cad' => $norm['min_cad'], ':max_cad' => $norm['max_cad'], ':min_cad2' => $norm['min_cad'],
        ':min_sal' => $norm['min_sal'], ':max_sal' => $norm['max_sal'], ':min_sal2' => $norm['min_sal'],
        ':ica'     => $icaVal,
        ':y'       => $selectedYear, ':d' => $selectedDelai,
    ]);
    $pctRow = $pctStmt->fetch();
    if ($pctRow && $pctRow['total'] > 0) {
        $icaPercentile = round($pctRow['below'] / $pctRow['total'] * 100);
    }
}

// ── Top formations similaires (même discipline, même année, autres étabs) ─────
$similairesStmt = $pdo->prepare("
    SELECT dip2.id_diplome, dip2.intitule,
           e2.nom AS etab_nom, reg2.nom AS region,
           r2.taux_emploi, r2.taux_cdi, r2.taux_cadre, r2.salaire_median
    FROM RESULTAT_IP   r2
    JOIN DIPLOME       dip2 ON dip2.id_diplome = r2.id_diplome
    JOIN DISCIPLINE    dis2 ON dis2.id_disc    = dip2.id_disc
    JOIN ETABLISSEMENT e2   ON e2.id_etab      = dip2.id_etab
    JOIN REGION        reg2 ON reg2.id_region  = e2.id_region
    JOIN DIPLOME       dip  ON dip.id_diplome  = :id
    JOIN DISCIPLINE    dis  ON dis.id_disc     = dip.id_disc
    JOIN ANNEE_ENQUETE ae2  ON ae2.id_annee    = r2.id_annee
    WHERE dis2.id_disc     = dis.id_disc
      AND ae2.annee        = :y
      AND ae2.delai_mois   = :d
      AND r2.id_diplome   != :id2
      AND r2.taux_emploi  IS NOT NULL
    ORDER BY r2.taux_emploi DESC
    LIMIT 6
");
$similairesStmt->execute([':id' => $selectedFormId, ':y' => $selectedYear, ':d' => $selectedDelai, ':id2' => $selectedFormId]);
$similaires = $similairesStmt->fetchAll();

// ── JSON pour JS ──────────────────────────────────────────────────────────────
$evol18Json   = json_encode($evol18);
$evol30Json   = json_encode($evol30);
$radarJson    = json_encode([
    'formation' => $radarFormation,
    'disc'      => $radarDisc,
    'nat'       => $radarNat,
]);
$similJson    = json_encode($similaires);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Univ Insight — Formation</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── filter bar ── */
.filter-bar{grid-column:1/-1;display:flex;align-items:center;gap:10px;background:white;border:1px solid var(--gray-100);border-radius:12px;padding:10px 16px;flex-wrap:wrap;}
.filter-bar label{font-size:10px;font-weight:600;color:var(--gray-400);text-transform:uppercase;letter-spacing:.05em;margin-right:4px;}
.filter-select{appearance:none;background:var(--gray-50,#f9fafb);border:1px solid var(--gray-200);border-radius:8px;padding:5px 28px 5px 10px;font-size:12px;font-family:'DM Sans',sans-serif;color:var(--gray-700);cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239ca3af'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;transition:border-color .15s;}
.filter-select:focus{outline:none;border-color:var(--blue-france);}
.filter-select.xl{min-width:320px;}
.filter-divider{width:1px;height:20px;background:var(--gray-100);margin:0 4px;}

/* ── delai toggle ── */
.delai-toggle{display:flex;background:var(--gray-100);border-radius:8px;padding:2px;gap:2px;}
.delai-btn{font-size:11px;font-weight:600;padding:4px 12px;border-radius:6px;border:none;background:transparent;color:var(--gray-500);cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s;}
.delai-btn.active{background:white;color:var(--blue-france);box-shadow:0 1px 3px rgba(0,0,0,.08);}

/* ── KPIs ── */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;grid-column:1/-1;}
.stat-card{border-radius:14px;padding:18px;position:relative;overflow:hidden;background:white;}
.stat-card.dark{background:#002B55;color:white;}
.stat-icon{position:absolute;bottom:14px;right:14px;width:32px;height:32px;border-radius:50%;border:1px solid rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;}
.stat-card:not(.dark) .stat-icon{border-color:var(--gray-200);}
.kpi-label{font-size:10px;font-weight:600;color:var(--gray-400);letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px;}
.stat-card.dark .kpi-label{color:rgba(255,255,255,.5);}
.kpi-value{font-size:30px;font-weight:700;line-height:1;margin-bottom:6px;}
.kpi-sub{font-size:10px;display:flex;align-items:center;gap:4px;margin-top:4px;flex-wrap:wrap;}
.vs-up{color:#10b981;font-weight:600;}
.vs-down{color:#ef4444;font-weight:600;}
.vs-eq{color:var(--gray-400);}
.vs-nat{font-size:10px;color:var(--gray-400);}

/* ── tendance badge ── */
.tendance-badge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;}
.tend-hausse{background:#d1fae5;color:#065f46;}
.tend-stable{background:#f3f4f6;color:#6b7280;}
.tend-baisse{background:#fee2e2;color:#991b1b;}

/* ── ICA jauge ── */
.ica-wrap{display:flex;align-items:center;gap:12px;margin-top:8px;}
.ica-track{flex:1;height:8px;background:var(--gray-100);border-radius:4px;overflow:hidden;}
.ica-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#6CB4EE,#000091);transition:width .5s ease;}
.ica-score{font-size:20px;font-weight:700;color:var(--blue-france);width:60px;text-align:right;flex-shrink:0;}
.ica-pct{font-size:10px;color:var(--gray-400);}

/* ── layout ── */
.two-col{grid-column:1/-1;display:grid;grid-template-columns:2fr 1fr;gap:12px;}
.three-col{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
.full-col{grid-column:1/-1;}
.canvas-wrap{height:200px;position:relative;}
.canvas-wrap-md{height:170px;position:relative;}
.card{background:white;border-radius:14px;padding:18px;}
.card-title{font-size:13px;font-weight:600;margin-bottom:16px;}
.pill-tag{font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;background:var(--blue-pale);color:var(--blue-france);}

/* ── similaires table ── */
.simil-table{width:100%;border-collapse:collapse;font-size:11px;}
.simil-table th{font-size:10px;font-weight:600;color:var(--gray-400);text-align:left;padding:0 8px 10px 0;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;}
.simil-table td{padding:8px 8px 8px 0;border-top:1px solid var(--gray-100);vertical-align:middle;}
.simil-table tr:first-child td{border-top:none;}
.simil-table tbody tr{cursor:pointer;transition:background .12s;}
.simil-table tbody tr:hover td{background:#f8f9ff;}
.rank-chip{width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;}

/* ── topbar ── */
.topbar{position:sticky;top:0;z-index:100;background:#fff!important;padding:10px 24px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:12px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.content{padding:16px 24px 24px!important;}
.search-univ{max-width:340px;}

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
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px 16px;gap:8px;text-align:center;}
.empty-state svg{opacity:.25;}
.empty-state p{font-size:12px;color:var(--gray-400);}
.empty-state strong{font-size:13px;color:var(--gray-500);font-weight:600;}
</style>
</head>
<body>

<!-- Tooltip singleton -->
<div id="ui-tooltip" class="ui-tooltip" role="tooltip" aria-hidden="true">
  <div class="ui-tooltip-inner">
    <div class="ui-tooltip-header">
      <div class="ui-tooltip-dot" id="tt-dot"></div>
      <div><div class="ui-tooltip-title" id="tt-title"></div><div class="ui-tooltip-subtitle" id="tt-subtitle"></div></div>
    </div>
    <div class="ui-tooltip-body" id="tt-body"></div>
  </div>
</div>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="logo">
    <div class="logo-icon"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12.5v4c0 1.657 2.686 3 6 3s6-1.343 6-3v-4"/><line x1="22" y1="10" x2="22" y2="16"/></svg></div>
    Univ Insight
  </div>
  <div class="nav-section">
    <div class="nav-label">Navigation</div>
    <a href="index.php" class="nav-item" style="text-decoration:none;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Accueil
    </a>
    <a href="formation.php" class="nav-item active" style="text-decoration:none;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3L1 9l11 6 11-6-11-6z"/><path d="M5 13v5c0 2 7 4 7 4s7-2 7-4v-5"/></svg>Formations
    </a>
    <a href="etablissement.php" class="nav-item" style="text-decoration:none;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="8" width="16" height="13" rx="1"/><path d="M8 8V4h8v4"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="8" y1="11" x2="8" y2="16"/><line x1="16" y1="11" x2="16" y2="16"/></svg>Établissements
    </a>
    <a href="comparaison.php" class="nav-item" style="text-decoration:none;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="8" width="6" height="12" rx="1"/><rect x="15" y="4" width="6" height="16" rx="1"/><path d="M12 2v20"/></svg>Comparaison
    </a>
    <a href="stats.php" class="nav-item" style="text-decoration:none;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 17 7 12 11 15 18 6 21 9"/><line x1="3" y1="20" x2="21" y2="20"/></svg>Statistiques
    </a>
  </div>
  <div class="sidebar-bottom">
    <div class="app-promo"><strong>Données ESR</strong><p>Dernière mise à jour : <?= $lastYear ?></p><button class="promo-btn">En savoir plus</button></div>
  </div>
</aside>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="search-container">
      <div class="search-box search-univ">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" class="search-input-field" placeholder="Rechercher une formation ou un établissement…" autocomplete="off">
        <div class="search-loader" id="searchLoader"></div>
        <span class="shortcut">⌘F</span>
      </div>
      <div class="search-results-dropdown" id="searchResultsDropdown"></div>
    </div>
    <div class="topbar-icons">
      <div class="user-pill"><div class="avatar">UI</div><div class="user-info"><div class="user-name">Univ Insight</div><div class="user-email">Année <?= $selectedYear ?></div></div></div>
    </div>
  </div>

  <div class="content">

    <!-- Header ── -->
    <div class="page-header" style="grid-column:1/-1;margin-bottom:8px;">
      <div>
        <h1 style="font-size:20px;"><?= $formInfo ? htmlspecialchars(mb_strlen($formInfo['intitule'])>60?mb_substr($formInfo['intitule'],0,58).'…':$formInfo['intitule']) : 'Formation' ?></h1>
        <p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <?php if ($formInfo): ?>
            <span style="color:var(--gray-500);"><?= htmlspecialchars($formInfo['discipline']) ?></span>
            <span style="color:var(--gray-300);">·</span>
            <a href="etablissement.php?etab_id=<?= $formInfo['id_etab'] ?>" style="color:var(--blue-france);text-decoration:none;font-weight:500;"><?= htmlspecialchars($formInfo['etab_nom']) ?></a>
            <span style="color:var(--gray-300);">·</span>
            <span style="color:var(--gray-500);"><?= htmlspecialchars($formInfo['region']) ?></span>
          <?php endif; ?>
          <!-- Tendance badge -->
          <?php
          $tendLabels = ['hausse'=>'Tendance haussière','stable'=>'Tendance stable','baisse'=>'Tendance en baisse'];
          $tendIcons  = ['hausse'=>'▲','stable'=>'●','baisse'=>'▼'];
          ?>
          <span class="tendance-badge tend-<?= $tendance ?>">
            <?= $tendIcons[$tendance] ?> <?= $tendLabels[$tendance] ?>
          </span>
        </p>
      </div>
      <div class="header-actions">
        <a href="comparaison.php?form1=<?= $selectedFormId ?>" class="btn-primary" style="text-decoration:none;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="8" width="6" height="12" rx="1"/><rect x="15" y="4" width="6" height="16" rx="1"/><path d="M12 2v20"/></svg>
          Comparer
        </a>
        <a href="etablissement.php?etab_id=<?= $formInfo['id_etab'] ?? '' ?>" class="btn-outline" style="text-decoration:none;">Voir l'établissement</a>
      </div>
    </div>

    <!-- Filtres ── -->
    <form method="GET" class="filter-bar" id="filterForm">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      <label>Formation</label>
      <select name="form_id" class="filter-select xl" onchange="this.form.submit()">
        <?php foreach ($formList as $f): ?>
          <option value="<?= $f['id_diplome'] ?>" <?= $f['id_diplome'] == $selectedFormId ? 'selected' : '' ?>><?= htmlspecialchars(mb_strlen($f['label'])>80?mb_substr($f['label'],0,78).'…':$f['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="filter-divider"></div>
      <label>Année</label>
      <select name="annee" class="filter-select" onchange="this.form.submit()">
        <?php foreach ($annees as $y): ?>
          <option value="<?= $y ?>" <?= $y==$selectedYear?'selected':'' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
      <div class="filter-divider"></div>
      <label>Délai</label>
      <div class="delai-toggle">
        <button type="submit" name="delai" value="18" class="delai-btn <?= $selectedDelai==18?'active':'' ?>">18 mois</button>
        <button type="submit" name="delai" value="30" class="delai-btn <?= $selectedDelai==30?'active':'' ?>">30 mois</button>
      </div>
      <input type="hidden" name="form_id" value="<?= $selectedFormId ?>">
    </form>

    <!-- KPIs ── -->
    <div class="kpi-grid">
    <?php
    $kpiDefs = [
        ['label'=>"Taux d'emploi", 'val'=>$kpi['taux_emploi']??null, 'disc'=>$moy['emp_disc']??null, 'nat'=>$moy['emp_nat']??null, 'suf'=>'%', 'dark'=>true,
         'icon'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
         'tt'=>"Part des diplômés en emploi 18 mois après l'obtention du master."],
        ['label'=>'Taux CDI', 'val'=>$kpi['taux_cdi']??null, 'disc'=>$moy['cdi_disc']??null, 'nat'=>$moy['cdi_nat']??null, 'suf'=>'%', 'dark'=>false,
         'icon'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><path d="M9 12l2 2 4-4"/><rect x="3" y="4" width="18" height="18" rx="2"/></svg>',
         'tt'=>"Part des diplômés en emploi stable (CDI ou fonctionnaire) parmi les salariés en France."],
        ['label'=>'Taux cadre', 'val'=>$kpi['taux_cadre']??null, 'disc'=>$moy['cad_disc']??null, 'nat'=>$moy['cad_nat']??null, 'suf'=>'%', 'dark'=>false,
         'icon'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>',
         'tt'=>"Part des diplômés en poste cadre ou profession intermédiaire supérieure."],
        ['label'=>'Salaire médian', 'val'=>$kpi['salaire']??null, 'disc'=>$moy['sal_disc']??null, 'nat'=>$moy['sal_nat']??null, 'suf'=>'€', 'dark'=>false, 'money'=>true,
         'icon'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
         'tt'=>"Salaire net mensuel médian des diplômés en emploi à temps plein."],
    ];
    foreach ($kpiDefs as $k):
        $val  = $k['val'];
        $disc = $k['disc'];
        $nat  = $k['nat'];
        $isMoney = !empty($k['money']);
        $dDisc = ($val!==null&&$disc!==null)?round($val-$disc,1):null;
        $dNat  = ($val!==null&&$nat!==null)?round($val-$nat,1):null;
        $dispVal = $val!==null?($isMoney?fmtSal($val):$val.'%'):'—';
        $ttRows = json_encode([
            ['k'=>'Cette formation',  'v'=>$val!==null?($isMoney?fmtSal($val):$val.'%'):'—'],
            ['k'=>'Moy. discipline',  'v'=>$disc!==null?($isMoney?fmtSal($disc):$disc.'%'):'—'],
            ['k'=>'Moy. nationale',   'v'=>$nat!==null?($isMoney?fmtSal($nat):$nat.'%'):'—', 'divider'=>true],
            ['k'=>'Écart vs disc.',   'v'=>$dDisc!==null?($dDisc>=0?'+':'').$dDisc.($isMoney?'€':'%'):'—'],
        ]);
    ?>
    <div class="stat-card <?= $k['dark']?'dark':'' ?>"
         data-tt-title="<?= htmlspecialchars($k['label']) ?>"
         data-tt-subtitle="<?= htmlspecialchars($k['tt']) ?>"
         data-tt-dot="<?= $k['dark']?'#6CB4EE':'#000091' ?>"
         data-tt-rows='<?= htmlspecialchars($ttRows) ?>'>
      <div class="kpi-label"><?= $k['label'] ?></div>
      <div class="kpi-value"><?= $dispVal ?></div>
      <div class="kpi-sub">
        <?php if ($dDisc!==null): ?>
          <span class="<?= $dDisc>0?'vs-up':($dDisc<0?'vs-down':'vs-eq') ?>"><?= $dDisc>0?'▲':($dDisc<0?'▼':'●') ?> <?= abs($dDisc).($isMoney?'€':'%') ?></span>
          <span class="vs-nat">vs discipline (<?= $isMoney?fmtSal($disc):$disc.'%' ?>)</span>
        <?php elseif($val!==null): ?>
          <span class="vs-nat">Pas de comparaison disponible</span>
        <?php else: ?>
          <span class="vs-nat">—</span>
        <?php endif; ?>
      </div>
      <div class="stat-icon"><?= $k['icon'] ?></div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- Ligne 1 : évolution + radar ── -->
    <div class="two-col">

      <!-- Évolution temporelle -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
          <div class="card-title" style="margin:0;">Évolution des indicateurs (18 mois)</div>
          <span class="pill-tag"><?= count($evol18) ?> années</span>
        </div>
        <?php if (empty($evol18)): ?>
          <div class="empty-state"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg><strong>Pas de données</strong></div>
        <?php else: ?>
          <div class="canvas-wrap"><canvas id="chartEvol"></canvas></div>
        <?php endif; ?>
      </div>

      <!-- Radar profil -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
          <div class="card-title" style="margin:0;">Profil multi-critères normalisé</div>
          <span class="pill-tag"><?= $selectedYear ?></span>
        </div>
        <?php if (!array_filter($radarFormation)): ?>
          <div class="empty-state"><strong>Pas de données radar</strong></div>
        <?php else: ?>
          <div class="canvas-wrap-md"><canvas id="chartRadar"></canvas></div>
          <div style="display:flex;gap:12px;justify-content:center;margin-top:10px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--gray-500);"><span style="width:12px;height:3px;border-radius:2px;background:#000091;display:inline-block;"></span>Formation</div>
            <div style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--gray-500);"><span style="width:12px;height:3px;border-radius:2px;background:#6CB4EE;display:inline-block;"></span>Discipline</div>
            <div style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--gray-500);"><span style="width:12px;height:3px;border-radius:2px;background:#e5e7eb;display:inline-block;"></span>National</div>
          </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- Ligne 3 : ICA + rangs + formations similaires ── -->
    <div class="three-col">

      <!-- ICA + rangs -->
      <div class="card" style="display:flex;flex-direction:column;gap:14px;">
        <div class="card-title" style="margin:0;">Score ICA & classements</div>

        <!-- ICA -->
        <?php if ($icaVal !== null): ?>
        <div>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
            <div style="font-size:11px;font-weight:600;color:var(--gray-700);">Indice Composite d'Attractivité</div>
            <?php if ($icaPercentile !== null): ?>
              <span style="font-size:9px;font-weight:700;padding:2px 8px;border-radius:20px;background:<?= $icaPercentile>=75?'#d1fae5':($icaPercentile>=50?'#fef3c7':'#fee2e2') ?>;color:<?= $icaPercentile>=75?'#065f46':($icaPercentile>=50?'#92400e':'#991b1b') ?>;">Top <?= 100-$icaPercentile ?>%</span>
            <?php endif; ?>
          </div>
          <div class="ica-wrap"
               data-tt-title="Indice ICA"
               data-tt-subtitle="Emploi 40% · CDI 20% · Cadre 20% · Salaire 20%"
               data-tt-dot="#000091"
               data-tt-rows='<?= htmlspecialchars(json_encode([
                 ['k'=>'Score',      'v'=>$icaVal.'/100'],
                 ['k'=>'Percentile', 'v'=>$icaPercentile!==null?'Top '.(100-$icaPercentile).'%':'—'],
               ])) ?>'>
            <div class="ica-track"><div class="ica-fill" style="width:<?= $icaVal ?>%;"></div></div>
            <div class="ica-score"><?= $icaVal ?></div>
          </div>
          <?php if ($icaPercentile !== null): ?>
          <div class="ica-pct">Cette formation dépasse <?= $icaPercentile ?>% des formations nationales (<?= $selectedYear ?>, <?= $selectedDelai ?> mois)</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div style="border-top:1px solid var(--gray-100);padding-top:14px;display:flex;flex-direction:column;gap:10px;">
          <div style="font-size:11px;font-weight:600;color:var(--gray-700);">Classements</div>

          <!-- Rang dans discipline -->
          <?php if ($rangDisc && $totalDisc): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;"
               data-tt-title="Rang dans la discipline"
               data-tt-subtitle="<?= htmlspecialchars($formInfo['discipline']??'') ?>"
               data-tt-dot="#000091"
               data-tt-rows='<?= htmlspecialchars(json_encode([['k'=>'Rang','v'=>$rangDisc.'/'.$totalDisc],['k'=>'Critère','v'=>"Taux d'emploi"]])) ?>'>
            <div>
              <div style="font-size:10px;color:var(--gray-400);text-transform:uppercase;letter-spacing:.04em;font-weight:600;">Dans la discipline</div>
              <div style="font-size:11px;color:var(--gray-700);margin-top:2px;"><?= htmlspecialchars(mb_strlen($formInfo['discipline']??'')>30?mb_substr($formInfo['discipline']??'',0,28).'…':($formInfo['discipline']??'')) ?></div>
            </div>
            <div style="text-align:right;">
              <div style="font-size:20px;font-weight:700;color:var(--blue-france);line-height:1;"><?= $rangDisc ?><span style="font-size:13px;color:var(--gray-400);">/<?= $totalDisc ?></span></div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Rang national -->
          <?php if ($rangNat && $totalNat): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding-top:10px;border-top:1px solid var(--gray-100);"
               data-tt-title="Rang national"
               data-tt-subtitle="Toutes disciplines confondues"
               data-tt-dot="#002B55"
               data-tt-rows='<?= htmlspecialchars(json_encode([['k'=>'Rang','v'=>$rangNat.'/'.$totalNat],['k'=>'Critère','v'=>"Taux d'emploi"]])) ?>'>
            <div>
              <div style="font-size:10px;color:var(--gray-400);text-transform:uppercase;letter-spacing:.04em;font-weight:600;">National</div>
              <div style="font-size:11px;color:var(--gray-700);margin-top:2px;">Toutes formations</div>
            </div>
            <div style="text-align:right;">
              <div style="font-size:20px;font-weight:700;color:#002B55;line-height:1;"><?= $rangNat ?><span style="font-size:13px;color:var(--gray-400);">/<?= $totalNat ?></span></div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Répondants -->
          <?php if (!empty($kpi['nb_repondants'])): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding-top:10px;border-top:1px solid var(--gray-100);">
            <div>
              <div style="font-size:10px;color:var(--gray-400);text-transform:uppercase;letter-spacing:.04em;font-weight:600;">Répondants</div>
              <div style="font-size:11px;color:var(--gray-700);margin-top:2px;"><?= $selectedYear ?> · <?= $selectedDelai ?> mois</div>
            </div>
            <div style="font-size:20px;font-weight:700;color:var(--gray-700);"><?= (int)$kpi['nb_repondants'] ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Formations similaires (colspan 2) -->
      <div class="card" style="grid-column:span 2;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="card-title" style="margin:0;">Formations similaires — même discipline</div>
          <span class="pill-tag"><?= htmlspecialchars($formInfo['discipline']??'') ?> · <?= $selectedYear ?></span>
        </div>
        <?php if (empty($similaires)): ?>
          <div class="empty-state"><strong>Aucune formation similaire trouvée</strong></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="simil-table">
          <thead><tr>
            <th style="width:22px;">#</th>
            <th>Formation</th>
            <th>Établissement</th>
            <th style="text-align:right;">Emploi</th>
            <th style="text-align:right;">CDI</th>
            <th style="text-align:right;">Salaire</th>
          </tr></thead>
          <tbody>
          <?php
          $chipColors = ['#000091','#0053B3','#6CB4EE','#3a8dd9','#9cb3d8','#c4d8ec'];
          foreach ($similaires as $si => $s):
            $ttR = json_encode([
              ['k'=>"Taux d'emploi",'v'=>fmt($s['taux_emploi'],'%')],
              ['k'=>'Taux CDI',     'v'=>fmt($s['taux_cdi'],'%')],
              ['k'=>'Taux cadre',   'v'=>fmt($s['taux_cadre'],'%')],
              ['k'=>'Salaire',      'v'=>fmtSal($s['salaire_median']),'divider'=>true],
              ['k'=>'Région',       'v'=>$s['region']],
            ]);
          ?>
          <tr data-tt-title="<?= htmlspecialchars($s['intitule']) ?>"
              data-tt-subtitle="<?= htmlspecialchars($s['etab_nom']) ?>"
              data-tt-dot="<?= $chipColors[$si] ?>"
              data-tt-rows='<?= htmlspecialchars($ttR) ?>'
              onclick="window.location='formation.php?form_id=<?= $s['id_diplome'] ?>&annee=<?= $selectedYear ?>&delai=<?= $selectedDelai ?>'">
            <td><span class="rank-chip" style="background:<?= $chipColors[$si] ?>22;color:<?= $chipColors[$si] ?>;"><?= $si+1 ?></span></td>
            <td>
              <div style="font-size:11px;font-weight:600;color:var(--gray-900);"><?= htmlspecialchars(mb_strlen($s['intitule'])>38?mb_substr($s['intitule'],0,36).'…':$s['intitule']) ?></div>
              <div style="font-size:9px;color:var(--gray-400);margin-top:1px;"><?= htmlspecialchars($s['region']) ?></div>
            </td>
            <td style="font-size:11px;color:var(--gray-600);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars(mb_strlen($s['etab_nom'])>22?mb_substr($s['etab_nom'],0,20).'…':$s['etab_nom']) ?></td>
            <td style="text-align:right;font-weight:700;font-size:11px;color:var(--blue-france);"><?= fmt($s['taux_emploi'],'%') ?></td>
            <td style="text-align:right;font-size:11px;color:var(--gray-600);"><?= fmt($s['taux_cdi'],'%') ?></td>
            <td style="text-align:right;font-size:11px;color:var(--gray-600);"><?= fmtSal($s['salaire_median']) ?></td>
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
  function buildRows(rows){return rows.map((r,idx)=>{const isNA=!r.v||r.v==='—';const valCl=isNA?'ui-tooltip-val na':'ui-tooltip-val';const div=r.divider&&idx<rows.length-1?'<div class="ui-tooltip-divider"></div>':'';return`${div}<div class="ui-tooltip-row"><span class="ui-tooltip-key">${r.k}</span><span class="${valCl}">${r.v||'—'}</span></div>`;}).join('');}
  function showTooltip(el,e){clearTimeout(hideTimer);if(activeEl===el&&tt.classList.contains('visible')){positionTooltip(e);return;}activeEl=el;const title=el.dataset.ttTitle||'',subtitle=el.dataset.ttSubtitle||'',dot=el.dataset.ttDot||'#000091';let rows=[];try{rows=JSON.parse(el.dataset.ttRows||'[]');}catch(_){}ttDot.style.background=dot;ttTitle.textContent=title;ttSub.textContent=subtitle;ttSub.style.display=subtitle?'':'none';ttBody.innerHTML=buildRows(rows);tt.style.left='-9999px';tt.style.top='-9999px';tt.classList.add('visible');tt.setAttribute('aria-hidden','false');requestAnimationFrame(()=>positionTooltip(e));}
  function positionTooltip(e){const W=tt.offsetWidth||220,H=tt.offsetHeight||120,vw=window.innerWidth,vh=window.innerHeight,pad=14;let x=e.clientX+16,y=e.clientY+16;if(x+W+pad>vw)x=e.clientX-W-12;if(y+H+pad>vh)y=e.clientY-H-12;if(x<pad)x=pad;if(y<pad)y=pad;tt.style.left=x+'px';tt.style.top=y+'px';}
  function hideTooltip(){hideTimer=setTimeout(()=>{tt.classList.remove('visible');tt.setAttribute('aria-hidden','true');activeEl=null;},100);}
  document.addEventListener('mouseover',e=>{const el=e.target.closest('[data-tt-title]');if(el)showTooltip(el,e);});
  document.addEventListener('mousemove',e=>{if(tt.classList.contains('visible'))positionTooltip(e);});
  document.addEventListener('mouseout',e=>{const el=e.target.closest('[data-tt-title]');if(el)hideTooltip();});
  document.addEventListener('scroll',hideTooltip,{passive:true});
})();

/* ── Chart.js defaults ── */
Chart.defaults.font.family="'DM Sans', sans-serif";
Chart.defaults.font.size=11;
Chart.defaults.color='#9ca3af';

const BLUE='#000091',BLUE_L='#0053B3',BLUE_S='#6CB4EE',DARK='#002B55';

const vertLine={id:'vertLine',afterDraw(c){if(c.tooltip._active?.length){const ctx=c.ctx,x=c.tooltip._active[0].element.x,{top,bottom}=c.chartArea;ctx.save();ctx.beginPath();ctx.moveTo(x,top);ctx.lineTo(x,bottom);ctx.lineWidth=1;ctx.strokeStyle='rgba(0,0,145,0.12)';ctx.setLineDash([4,3]);ctx.stroke();ctx.restore();}}};

/* ── 1. Évolution ── */
<?php if (!empty($evol18)): ?>
const evol18=<?= $evol18Json ?>;
const evol30=<?= $evol30Json ?>;
const annees18=evol18.map(d=>d.annee);
const by30=Object.fromEntries(evol30.map(d=>[d.annee,d.taux_emploi]));
new Chart(document.getElementById('chartEvol'),{
  type:'line',plugins:[vertLine],
  data:{
    labels:annees18,
    datasets:[
      {label:"Taux emploi (18 mois)",data:evol18.map(d=>d.taux_emploi),borderColor:BLUE,backgroundColor:'rgba(0,0,145,0.07)',borderWidth:2.5,pointRadius:4,pointHoverRadius:7,pointBackgroundColor:'#fff',pointBorderColor:BLUE,pointBorderWidth:2,fill:true,tension:0.4,spanGaps:true},
      {label:"Taux CDI (18 mois)",   data:evol18.map(d=>d.taux_cdi),   borderColor:BLUE_S,backgroundColor:'transparent',borderWidth:2,pointRadius:3,pointHoverRadius:5,pointBackgroundColor:'#fff',pointBorderColor:BLUE_S,pointBorderWidth:1.5,fill:false,tension:0.4,spanGaps:true},
      {label:"Taux cadre (18 mois)", data:evol18.map(d=>d.taux_cadre), borderColor:DARK,backgroundColor:'transparent',borderWidth:2,borderDash:[5,4],pointRadius:3,pointHoverRadius:5,pointBackgroundColor:'#fff',pointBorderColor:DARK,pointBorderWidth:1.5,fill:false,tension:0.4,spanGaps:true},
      ...(evol30.length?[{label:"Emploi 30 mois",data:annees18.map(a=>by30[a]??null),borderColor:'#f59e0b',backgroundColor:'transparent',borderWidth:1.5,borderDash:[3,3],pointRadius:3,pointHoverRadius:5,pointBackgroundColor:'#fff',pointBorderColor:'#f59e0b',fill:false,tension:0.4,spanGaps:true}]:[]),
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
    plugins:{
      legend:{position:'top',labels:{boxWidth:10,boxHeight:10,borderRadius:3,useBorderRadius:true,padding:12,font:{size:10},color:'#6b7280'}},
      tooltip:{backgroundColor:'#fff',titleColor:'#111827',bodyColor:'#374151',borderColor:'#e5e7eb',borderWidth:1,padding:12,cornerRadius:10,boxPadding:5,
        titleFont:{family:"'DM Sans',sans-serif",size:12,weight:'700'},bodyFont:{family:"'DM Sans',sans-serif",size:11},
        callbacks:{title:i=>'Année '+i[0].label,label:ctx=>'  '+ctx.dataset.label+' : '+(ctx.parsed.y!==null?ctx.parsed.y+'%':'N/A')}
      }
    },
    scales:{
      x:{grid:{display:false},border:{display:false},ticks:{font:{size:10},color:'#9ca3af'}},
      y:{min:40,max:100,grid:{color:'#f3f4f6'},border:{display:false},ticks:{callback:v=>v+'%',font:{size:10},color:'#9ca3af',stepSize:10}}
    }
  }
});
<?php endif; ?>

/* ── 2. Radar ── */
<?php if (array_filter($radarFormation)): ?>
const radarData=<?= $radarJson ?>;
new Chart(document.getElementById('chartRadar'),{
  type:'radar',
  data:{
    labels:["Emploi","CDI","Cadre","Salaire","Répondants"],
    datasets:[
      {label:'Formation',data:radarData.formation,borderColor:BLUE,backgroundColor:'rgba(0,0,145,0.15)',borderWidth:2.5,pointRadius:4,pointBackgroundColor:BLUE,pointBorderColor:'#fff',pointBorderWidth:2,pointHoverRadius:6,fill:true},
      {label:'Discipline',data:radarData.disc,borderColor:BLUE_S,backgroundColor:'rgba(108,180,238,0.08)',borderWidth:1.5,pointRadius:3,pointBackgroundColor:BLUE_S,fill:true,borderDash:[4,3]},
      {label:'National',data:radarData.nat,borderColor:'#d1d5db',backgroundColor:'transparent',borderWidth:1,pointRadius:2,fill:false,borderDash:[2,4]},
    ]
  },
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{
      legend:{display:false},
      tooltip:{backgroundColor:'#fff',titleColor:'#111827',bodyColor:'#374151',borderColor:'#e5e7eb',borderWidth:1,padding:10,cornerRadius:10,
        callbacks:{label:ctx=>'  '+ctx.dataset.label+' : '+(ctx.parsed.r!==null?ctx.parsed.r.toFixed(1)+'/100':'N/A')}
      }
    },
    scales:{r:{min:0,max:100,ticks:{stepSize:25,font:{size:9},backdropColor:'transparent',color:'#d1d5db'},grid:{color:'#f3f4f6'},angleLines:{color:'#f3f4f6'},pointLabels:{font:{size:10},color:'#6b7280'}}}
  }
});
<?php endif; ?>
</script>
<script src="script.js"></script>
</body>
</html>