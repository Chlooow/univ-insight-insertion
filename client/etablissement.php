<?php
require_once '../connexion.php';

// ── Années disponibles ────────────────────────────────────────────────────────
$annees   = $pdo->query("SELECT DISTINCT annee FROM ANNEE_ENQUETE ORDER BY annee DESC")->fetchAll(PDO::FETCH_COLUMN);
$lastYear = (int)$annees[0];
$selectedYear = isset($_GET['annee']) && in_array($_GET['annee'], $annees) ? (int)$_GET['annee'] : $lastYear;

// Récupérer toutes les années pour le délai 18 mois (pour l'axe X complet)
$allYearsStmt = $pdo->prepare("SELECT DISTINCT annee FROM ANNEE_ENQUETE WHERE delai_mois = 18 ORDER BY annee");
$allYearsStmt->execute();
$allYears = $allYearsStmt->fetchAll(PDO::FETCH_COLUMN);

// ── Liste établissements (pour le select) ─────────────────────────────────────
$etabList = $pdo->query("SELECT id_etab, nom FROM ETABLISSEMENT ORDER BY nom")->fetchAll();

$selectedEtabId = null;
if (isset($_GET['etab_id']) && ctype_digit($_GET['etab_id'])) {
    $selectedEtabId = (int)$_GET['etab_id'];
} elseif (!empty($etabList)) {
    $selectedEtabId = (int)$etabList[0]['id_etab'];
}

// ── Info établissement ────────────────────────────────────────────────────────
$etabInfo = null;
if ($selectedEtabId) {
    $stmt = $pdo->prepare("
        SELECT e.id_etab, e.nom, e.type, e.ville, r.nom AS region
        FROM ETABLISSEMENT e
        JOIN REGION r ON r.id_region = e.id_region
        WHERE e.id_etab = :id
    ");
    $stmt->execute([':id' => $selectedEtabId]);
    $etabInfo = $stmt->fetch();
}

// ── Helper ────────────────────────────────────────────────────────────────────
function fmt($val, $suffix = '', $fallback = '—') {
    return ($val !== null && $val !== '') ? $val . $suffix : $fallback;
}

// ── KPIs de l'établissement (année sélectionnée, 18 mois) ────────────────────
$kpiStmt = $pdo->prepare("
    SELECT
        ROUND(AVG(r.taux_emploi), 1)    AS taux_emploi,
        ROUND(AVG(r.taux_cdi),    1)    AS taux_cdi,
        ROUND(AVG(r.taux_cadre),  1)    AS taux_cadre,
        ROUND(AVG(r.salaire_median), 0) AS salaire,
        COUNT(DISTINCT dip.id_diplome)  AS nb_formations,
        SUM(r.nb_repondants)            AS nb_repondants
    FROM RESULTAT_IP r
    JOIN DIPLOME       dip ON dip.id_diplome = r.id_diplome
    JOIN ANNEE_ENQUETE ae  ON ae.id_annee    = r.id_annee
    WHERE dip.id_etab    = :id
      AND ae.annee       = :y
      AND ae.delai_mois  = 18
");
$kpiStmt->execute([':id' => $selectedEtabId, ':y' => $selectedYear]);
$kpi = $kpiStmt->fetch();
if (!$kpi) {
    $kpi = ['taux_emploi' => null, 'taux_cdi' => null, 'taux_cadre' => null, 'salaire' => null, 'nb_formations' => 0, 'nb_repondants' => 0];
}

// ── KPIs moyens nationaux (même année, même délai) ───────────────────────────
$natStmt = $pdo->prepare("
    SELECT
        ROUND(AVG(r.taux_emploi), 1)    AS taux_emploi,
        ROUND(AVG(r.taux_cdi),    1)    AS taux_cdi,
        ROUND(AVG(r.taux_cadre),  1)    AS taux_cadre,
        ROUND(AVG(r.salaire_median), 0) AS salaire
    FROM RESULTAT_IP r
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE ae.annee = :y AND ae.delai_mois = 18
");
$natStmt->execute([':y' => $selectedYear]);
$nat = $natStmt->fetch();
if (!$nat) {
    $nat = ['taux_emploi' => null, 'taux_cdi' => null, 'taux_cadre' => null, 'salaire' => null];
}

// ── Rang national de l'établissement ─────────────────────────────────────────
$rankStmt = $pdo->prepare("
    SELECT ranked.id_etab, ranked.rnk
    FROM (
        SELECT dip.id_etab,
               RANK() OVER (ORDER BY AVG(r.taux_emploi) DESC) AS rnk
        FROM RESULTAT_IP r
        JOIN DIPLOME       dip ON dip.id_diplome = r.id_diplome
        JOIN ANNEE_ENQUETE ae  ON ae.id_annee    = r.id_annee
        WHERE ae.annee = :y AND ae.delai_mois = 18
          AND r.taux_emploi IS NOT NULL
        GROUP BY dip.id_etab
    ) ranked
    WHERE ranked.id_etab = :id
");
$rankStmt->execute([':y' => $selectedYear, ':id' => $selectedEtabId]);
$rankRow = $rankStmt->fetch();
$rangNational = $rankRow ? (int)$rankRow['rnk'] : null;
$totalEtab = $pdo->query("SELECT COUNT(*) FROM ETABLISSEMENT")->fetchColumn();

// ── Évolution temporelle de l'établissement (LEFT JOIN pour avoir toutes les années) ──
$evolStmt = $pdo->prepare("
    SELECT y.annee,
           ROUND(AVG(r.taux_emploi), 1) AS taux_emploi,
           ROUND(AVG(r.taux_cdi),    1) AS taux_cdi,
           ROUND(AVG(r.taux_cadre),  1) AS taux_cadre,
           ROUND(AVG(r.salaire_median), 0) AS salaire
    FROM (SELECT DISTINCT annee FROM ANNEE_ENQUETE WHERE delai_mois = 18) y
    LEFT JOIN ANNEE_ENQUETE ae ON ae.annee = y.annee AND ae.delai_mois = 18
    LEFT JOIN RESULTAT_IP r ON r.id_annee = ae.id_annee
    LEFT JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome AND dip.id_etab = :id
    GROUP BY y.annee
    ORDER BY y.annee
");
$evolStmt->execute([':id' => $selectedEtabId]);
$evol = $evolStmt->fetchAll();

// ── Évolution nationale (pour comparaison) ────────────────────────────────────
$evolNatStmt = $pdo->prepare("
    SELECT ae.annee,
           ROUND(AVG(r.taux_emploi), 1) AS taux_emploi
    FROM RESULTAT_IP r
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE ae.delai_mois = 18 AND r.taux_emploi IS NOT NULL
    GROUP BY ae.annee ORDER BY ae.annee
");
$evolNatStmt->execute();
$evolNat = $evolNatStmt->fetchAll();

// ── Récupération des formations (données brutes) ───────────────────────────
$formationsStmt = $pdo->prepare("
    SELECT
        dip.id_diplome,
        dip.intitule,
        dis.nom AS discipline,
        dis.domaine,
        ROUND(r.taux_emploi, 1) AS taux_emploi,
        ROUND(r.taux_cdi, 1)    AS taux_cdi,
        ROUND(r.taux_cadre, 1)  AS taux_cadre,
        r.salaire_median        AS salaire,
        r.nb_repondants
    FROM RESULTAT_IP r
    JOIN DIPLOME       dip ON dip.id_diplome = r.id_diplome
    JOIN DISCIPLINE    dis ON dis.id_disc    = dip.id_disc
    JOIN ANNEE_ENQUETE ae  ON ae.id_annee    = r.id_annee
    WHERE dip.id_etab   = :id
      AND ae.annee      = :y
      AND ae.delai_mois = 18
      AND r.taux_emploi IS NOT NULL
      AND r.taux_cdi IS NOT NULL
      AND r.taux_cadre IS NOT NULL
      AND r.salaire_median IS NOT NULL
    ORDER BY dip.intitule
");
$formationsStmt->execute([':id' => $selectedEtabId, ':y' => $selectedYear]);
$formationsRaw = $formationsStmt->fetchAll();

// ── Calcul des min/max nationaux pour l'ICA (même année, même délai) ─────────
$statsForIca = $pdo->prepare("
    SELECT
        MIN(r.taux_emploi)    AS min_emp,
        MAX(r.taux_emploi)    AS max_emp,
        MIN(r.taux_cdi)       AS min_cdi,
        MAX(r.taux_cdi)       AS max_cdi,
        MIN(r.taux_cadre)     AS min_cad,
        MAX(r.taux_cadre)     AS max_cad,
        MIN(r.salaire_median) AS min_sal,
        MAX(r.salaire_median) AS max_sal
    FROM RESULTAT_IP r
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE ae.annee = :y AND ae.delai_mois = 18
      AND r.taux_emploi IS NOT NULL
      AND r.taux_cdi IS NOT NULL
      AND r.taux_cadre IS NOT NULL
      AND r.salaire_median IS NOT NULL
");
$statsForIca->execute([':y' => $selectedYear]);
$icaStats = $statsForIca->fetch();

// ── Calcul de l'ICA en PHP pour chaque formation ─────────────────────────────
$formations = [];
if ($icaStats && !empty($formationsRaw)) {
    $minEmp = $icaStats['min_emp'];
    $maxEmp = $icaStats['max_emp'];
    $minCdi = $icaStats['min_cdi'];
    $maxCdi = $icaStats['max_cdi'];
    $minCad = $icaStats['min_cad'];
    $maxCad = $icaStats['max_cad'];
    $minSal = $icaStats['min_sal'];
    $maxSal = $icaStats['max_sal'];

    foreach ($formationsRaw as $f) {
        $rangeEmp = ($maxEmp - $minEmp) ?: 1;
        $rangeCdi = ($maxCdi - $minCdi) ?: 1;
        $rangeCad = ($maxCad - $minCad) ?: 1;
        $rangeSal = ($maxSal - $minSal) ?: 1;

        $scoreEmp = ($f['taux_emploi'] - $minEmp) / $rangeEmp * 100;
        $scoreCdi = ($f['taux_cdi']    - $minCdi) / $rangeCdi * 100;
        $scoreCad = ($f['taux_cadre']  - $minCad) / $rangeCad * 100;
        $scoreSal = ($f['salaire']     - $minSal) / $rangeSal * 100;

        $ica = 0.40 * $scoreEmp + 0.20 * $scoreCdi + 0.20 * $scoreCad + 0.20 * $scoreSal;
        $f['ica'] = round($ica, 1);
        $formations[] = $f;
    }
    usort($formations, function($a, $b) {
        return $b['ica'] <=> $a['ica'];
    });
} else {
    $formations = $formationsRaw;
}

// ── Répartition domaines (donut) ──────────────────────────────────────────────
$donutStmt = $pdo->prepare("
    SELECT dis.domaine, COUNT(DISTINCT dip.id_diplome) AS nb
    FROM DIPLOME    dip
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    WHERE dip.id_etab = :id
    GROUP BY dis.domaine
    ORDER BY nb DESC
");
$donutStmt->execute([':id' => $selectedEtabId]);
$donutData = $donutStmt->fetchAll();

// JSON pour JS
$evolJson    = json_encode($evol);
$evolNatJson = json_encode($evolNat);
$allYearsJson = json_encode($allYears);
$donutJson   = json_encode($donutData);
$formJson    = json_encode($formations);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Univ Insight — Établissement</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── filter bar ── */
.filter-bar{grid-column:1/-1;display:flex;align-items:center;gap:10px;background:white;border:1px solid var(--gray-100);border-radius:12px;padding:10px 16px;flex-wrap:wrap;}
.filter-bar label{font-size:10px;font-weight:600;color:var(--gray-400);text-transform:uppercase;letter-spacing:.05em;margin-right:4px;}
.filter-select{appearance:none;background:var(--gray-50,#f9fafb);border:1px solid var(--gray-200);border-radius:8px;padding:5px 28px 5px 10px;font-size:12px;font-family:'DM Sans',sans-serif;color:var(--gray-700);cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239ca3af'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;transition:border-color .15s;}
.filter-select:focus{outline:none;border-color:var(--blue-france);}
.filter-select.large{min-width:240px;}
.filter-divider{width:1px;height:20px;background:var(--gray-100);margin:0 4px;}
.filter-reset{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--gray-400);cursor:pointer;background:none;border:none;font-family:'DM Sans',sans-serif;padding:4px 8px;border-radius:6px;transition:all .15s;}
.filter-reset:hover{background:var(--gray-100);color:var(--gray-700);}

/* ── KPI grid ── */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;grid-column:1/-1;}
.stat-card{border-radius:14px;padding:18px;position:relative;overflow:hidden;background:white;}
.stat-card.dark{background:#002B55;color:white;}
.stat-icon{position:absolute;bottom:14px;right:14px;width:32px;height:32px;border-radius:50%;border:1px solid rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;}
.stat-card:not(.dark) .stat-icon{border-color:var(--gray-200);}
.kpi-label{font-size:10px;font-weight:600;color:var(--gray-400);letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px;}
.stat-card.dark .kpi-label{color:rgba(255,255,255,.5);}
.kpi-value{font-size:30px;font-weight:700;line-height:1;margin-bottom:6px;}
.kpi-sub{font-size:10px;display:flex;align-items:center;gap:4px;margin-top:4px;flex-wrap:wrap;}
.vs-nat{font-size:10px;color:var(--gray-400);}
.vs-up{color:#10b981;font-weight:600;}
.vs-down{color:#ef4444;font-weight:600;}
.vs-eq{color:var(--gray-400);}

/* ── rang badge ── */
.rang-badge{display:inline-flex;align-items:center;gap:6px;background:var(--blue-pale);border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600;color:var(--blue-france);}
.rang-badge strong{font-size:20px;}

/* ── layout rows ── */
.two-col{grid-column:1/-1;display:grid;grid-template-columns:2fr 1fr;gap:12px;}
.full-col{grid-column:1/-1;}
.canvas-wrap{height:200px;position:relative;}

/* ── formations table ── */
.form-table{width:100%;border-collapse:collapse;font-size:11px;}
.form-table th{font-size:10px;font-weight:600;color:var(--gray-400);text-align:left;padding:0 8px 10px 0;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;}
.form-table th:last-child{text-align:right;}
.form-table td{padding:8px 8px 8px 0;border-top:1px solid var(--gray-100);vertical-align:middle;}
.form-table tr:first-child td{border-top:none;}
.form-table tbody tr{cursor:pointer;transition:background .12s;}
.form-table tbody tr:hover td{background:#f8f9ff;}
.ica-bar{height:4px;border-radius:2px;background:var(--blue-pale);margin-top:3px;overflow:hidden;}
.ica-bar-fill{height:100%;border-radius:2px;}
.dom-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;display:inline-block;}
.badge-dom{font-size:9px;padding:2px 7px;border-radius:20px;font-weight:600;white-space:nowrap;}
.pill-tag{font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;background:var(--blue-pale);color:var(--blue-france);}

/* ── donut chart ── */
.donut-container{display:flex;align-items:center;gap:20px;height:170px;}
.donut-canvas-wrap{width:160px;height:160px;flex-shrink:0;position:relative;}
.donut-legend{flex:1;display:flex;flex-direction:column;gap:8px;}
.donut-leg-item{display:flex;align-items:center;gap:8px;font-size:11px;}
.donut-leg-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.donut-leg-label{flex:1;color:var(--gray-700);font-weight:500;}
.donut-leg-val{font-size:11px;font-weight:700;color:var(--gray-900);}

/* ── topbar ── */
.topbar{position:sticky;top:0;z-index:100;background:#fff!important;padding:10px 24px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:12px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.content{padding:16px 24px 24px!important;}
.search-univ{max-width:340px;}

/* ── tooltip ── */
.ui-tooltip{position:fixed;z-index:9999;pointer-events:none;opacity:0;transform:translateY(6px) scale(.97);transition:opacity .15s ease,transform .15s ease;max-width:260px;min-width:190px;}
.ui-tooltip.visible{opacity:1;transform:translateY(0) scale(1);}
.ui-tooltip-inner{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.10),0 2px 6px rgba(0,0,0,.06);overflow:hidden;}
.ui-tooltip-header{padding:10px 13px 8px;border-bottom:1px solid #f3f4f6;display:flex;align-items:flex-start;gap:8px;}
.ui-tooltip-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:3px;}
.ui-tooltip-title{font-size:12px;font-weight:700;color:#111827;line-height:1.35;font-family:'DM Sans',sans-serif;}
.ui-tooltip-subtitle{font-size:10px;color:#9ca3af;font-family:'DM Sans',sans-serif;margin-top:2px;font-weight:500;text-transform:uppercase;letter-spacing:.03em;}
.ui-tooltip-body{padding:9px 13px 11px;display:flex;flex-direction:column;gap:6px;}
.ui-tooltip-row{display:flex;align-items:center;justify-content:space-between;gap:16px;}
.ui-tooltip-key{font-size:11px;color:#6b7280;font-family:'DM Sans',sans-serif;white-space:nowrap;}
.ui-tooltip-val{font-size:11px;font-weight:700;color:#002B55;font-family:'DM Mono',monospace;white-space:nowrap;}
.ui-tooltip-val.na{color:#d1d5db;font-weight:400;font-style:italic;}
.ui-tooltip-divider{height:1px;background:#f3f4f6;margin:1px 0;}

/* ── empty state ── */
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px 16px;gap:8px;text-align:center;}
.empty-state svg{opacity:.25;}
.empty-state p{font-size:12px;color:var(--gray-400);}
.empty-state strong{font-size:13px;color:var(--gray-500);font-weight:600;}
</style>
</head>
<body>

<!-- Tooltip -->
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
    <a href="formation.php" class="nav-item" style="text-decoration:none;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3L1 9l11 6 11-6-11-6z"/><path d="M5 13v5c0 2 7 4 7 4s7-2 7-4v-5"/></svg>Formations
    </a>
    <a href="etablissement.php" class="nav-item active" style="text-decoration:none;">
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

    <!-- Header -->
    <div class="page-header" style="grid-column:1/-1;margin-bottom:8px;">
      <div>
        <h1><?= $etabInfo ? htmlspecialchars($etabInfo['nom']) : 'Établissement' ?></h1>
        <p>
          <?php if ($etabInfo): ?>
            <span style="color:var(--gray-500);"><?= htmlspecialchars($etabInfo['region']) ?></span>
            <?php if ($rangNational): ?>
              &nbsp;·&nbsp;<strong style="color:var(--blue-france);">Rang national <?= $rangNational ?>/<?= $totalEtab ?></strong>
            <?php endif; ?>
          <?php endif; ?>
          &nbsp;·&nbsp;Enquête à 18 mois — <?= $selectedYear ?>
        </p>
      </div>
      <div class="header-actions">
        <a href="comparaison.php?etab1=<?= $selectedEtabId ?>" class="btn-primary" style="text-decoration:none;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="8" width="6" height="12" rx="1"/><rect x="15" y="4" width="6" height="16" rx="1"/><path d="M12 2v20"/></svg>
          Comparer
        </a>
        <a href="index.php" class="btn-outline" style="text-decoration:none;">Accueil</a>
      </div>
    </div>

    <!-- Filtres -->
    <form method="GET" class="filter-bar" id="filterForm">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      <label>Établissement</label>
      <select name="etab_id" class="filter-select large" onchange="document.getElementById('filterForm').submit()">
        <?php foreach ($etabList as $e): ?>
          <option value="<?= $e['id_etab'] ?>" <?= $e['id_etab'] == $selectedEtabId ? 'selected' : '' ?>><?= htmlspecialchars($e['nom']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="filter-divider"></div>
      <label>Année</label>
      <select name="annee" class="filter-select" onchange="document.getElementById('filterForm').submit()">
        <?php foreach ($annees as $y): ?>
          <option value="<?= $y ?>" <?= $y == $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <!-- KPIs -->
    <div class="kpi-grid">
      <?php
      $kpiDefs = [
        ['label'=>"Taux d'emploi", 'val'=>$kpi['taux_emploi'], 'nat'=>$nat['taux_emploi'], 'suf'=>'%', 'dark'=>true,
         'icon'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
         'tt'=>"Taux d'emploi moyen des diplômés 18 mois après le master, toutes formations confondues."],
        ['label'=>'Taux CDI', 'val'=>$kpi['taux_cdi'], 'nat'=>$nat['taux_cdi'], 'suf'=>'%', 'dark'=>false,
         'icon'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><path d="M9 12l2 2 4-4"/><rect x="3" y="4" width="18" height="18" rx="2"/></svg>',
         'tt'=>"Part des diplômés en emploi stable (CDI, fonctionnaire) parmi les salariés en France."],
        ['label'=>'Taux cadre', 'val'=>$kpi['taux_cadre'], 'nat'=>$nat['taux_cadre'], 'suf'=>'%', 'dark'=>false,
         'icon'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>',
         'tt'=>"Part des diplômés occupant un poste cadre ou profession intermédiaire supérieure."],
        ['label'=>'Salaire médian', 'val'=>$kpi['salaire'], 'nat'=>$nat['salaire'], 'suf'=>'€', 'dark'=>false, 'money'=>true,
         'icon'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
         'tt'=>"Salaire net mensuel médian des diplômés en emploi à temps plein."],
      ];
      foreach ($kpiDefs as $k):
        $val     = $k['val'];
        $natVal  = $k['nat'];
        $delta   = ($val !== null && $natVal !== null) ? round($val - $natVal, 1) : null;
        $isMoney = !empty($k['money']);
        $dispVal = $val !== null ? ($isMoney ? number_format($val,0,',',' ').'€' : $val.'%') : '—';
        $ttRows  = json_encode([
          ['k'=>'Cet établissement', 'v'=> $val !== null ? ($isMoney ? number_format($val,0,',',' ').'€' : $val.'%') : '—'],
          ['k'=>'Moyenne nationale',  'v'=> $natVal !== null ? ($isMoney ? number_format($natVal,0,',',' ').'€' : $natVal.'%') : '—'],
          ['k'=>'Écart vs national',  'v'=> $delta !== null ? ($delta>=0?'+':'').$delta.($isMoney?'€':'%') : '—', 'divider'=>true],
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
          <?php if ($delta !== null): ?>
            <span class="<?= $delta>0?'vs-up':($delta<0?'vs-down':'vs-eq') ?>">
              <?= $delta>0?'▲':($delta<0?'▼':'●') ?> <?= abs($delta).($isMoney?'€':'%') ?>
            </span>
            <span class="vs-nat">vs national (<?= $isMoney ? number_format($natVal,0,',',' ').'€' : $natVal.'%' ?>)</span>
          <?php else: ?>
            <span class="vs-nat">—</span>
          <?php endif; ?>
        </div>
        <div class="stat-icon"><?= $k['icon'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Ligne : évolution + donut -->
    <div class="two-col">
      <div class="card">
        <div class="card-title">Évolution de l'établissement vs moyenne nationale (18 mois)</div>
        <?php if (empty($evol)): ?>
          <div class="empty-state"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg><strong>Pas de données</strong></div>
        <?php else: ?>
          <div class="canvas-wrap"><canvas id="chartEvol"></canvas></div>
          <div style="font-size:9px; color:var(--gray-400); margin-top:8px; display:flex; gap:16px; justify-content:center;">
            <span><span style="display:inline-block; width:12px; height:12px; background:rgba(0,0,0,0.04); border:1px dashed #ccc; margin-right:4px; vertical-align:middle;"></span> Zones grisées = années sans données pour l'établissement</span>
          </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-title">Répartition par domaine</div>
        <?php if (empty($donutData)): ?>
          <div class="empty-state"><strong>Pas de données</strong></div>
        <?php else: ?>
          <div class="donut-container">
            <div class="donut-canvas-wrap"><canvas id="chartDonut"></canvas></div>
            <div class="donut-legend">
              <?php
              $donutColors = ['#000091','#0053B3','#6CB4EE','#002B55','#3a8dd9','#9cb3d8'];
              foreach ($donutData as $di => $dd):
              ?>
              <div class="donut-leg-item"
                   data-tt-title="<?= htmlspecialchars($dd['domaine']) ?>"
                   data-tt-subtitle="Domaine de formation"
                   data-tt-dot="<?= $donutColors[$di % count($donutColors)] ?>"
                   data-tt-rows='<?= htmlspecialchars(json_encode([['k'=>'Formations','v'=>$dd['nb']]])) ?>'>
                <div class="donut-leg-dot" style="background:<?= $donutColors[$di % count($donutColors)] ?>;"></div>
                <div class="donut-leg-label"><?= htmlspecialchars(mb_strlen($dd['domaine'])>24?mb_substr($dd['domaine'],0,22).'…':$dd['domaine']) ?></div>
                <div class="donut-leg-val"><?= $dd['nb'] ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Tableau des formations -->
    <div class="full-col">
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="card-title" style="margin:0;">Formations — classement ICA (<?= $selectedYear ?>, 18 mois)</div>
          <div style="display:flex;gap:8px;align-items:center;">
            <span class="pill-tag"><?= count($formations) ?> formation<?= count($formations)>1?'s':'' ?></span>
            <?php if ($rangNational): ?>
            <span style="background:#EEF2FF;color:#000091;font-size:9px;font-weight:600;padding:2px 8px;border-radius:20px;">
              Rang <?= $rangNational ?>e / <?= $totalEtab ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
        <?php if (empty($formations)): ?>
          <div class="empty-state"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2"/></svg><strong>Aucune formation trouvée</strong><p>Aucune donnée disponible pour cet établissement cette année.</p></div>
        <?php else: ?>
          <?php
          $domainColors = ['Droit, économie et gestion'=>'#000091','Sciences, technologies et santé'=>'#10b981','Sciences humaines et sociales'=>'#f59e0b','Lettres, langues, arts'=>'#ef4444','Masters enseignement'=>'#8b5cf6'];
          $domainBg     = ['Droit, économie et gestion'=>'#EEF2FF','Sciences, technologies et santé'=>'#d1fae5','Sciences humaines et sociales'=>'#fef3c7','Lettres, langues, arts'=>'#fee2e2','Masters enseignement'=>'#ede9fe'];
          ?>
          <div style="overflow-x:auto;">
          <table class="form-table">
            <thead><tr>
              <th style="width:28px;">#</th>
              <th>Formation</th>
              <th>Domaine</th>
              <th style="text-align:right;">Emploi</th>
              <th style="text-align:right;">CDI</th>
              <th style="text-align:right;">Cadre</th>
              <th style="text-align:right;">Salaire</th>
              <th style="text-align:right;min-width:80px;">ICA</th>
            </tr></thead>
            <tbody>
            <?php foreach ($formations as $fi => $f):
              $icaVal   = $f['ica'] ?? null;
              $icaColor = $icaVal !== null ? ($icaVal >= 60 ? '#000091' : ($icaVal >= 40 ? '#0053B3' : '#9ca3af')) : '#9ca3af';
              $domColor = $domainColors[$f['domaine']] ?? '#000091';
              $domBg    = $domainBg[$f['domaine']] ?? '#EEF2FF';
              $ttRows   = json_encode([
                ['k'=>"Taux d'emploi", 'v'=>fmt($f['taux_emploi'],'%')],
                ['k'=>'Taux CDI',      'v'=>fmt($f['taux_cdi'],'%')],
                ['k'=>'Taux cadre',    'v'=>fmt($f['taux_cadre'],'%')],
                ['k'=>'Salaire',       'v'=>$f['salaire']?number_format($f['salaire'],0,',',' ').'€':'—', 'divider'=>true],
                ['k'=>'Répondants',    'v'=>fmt($f['nb_repondants'])],
                ['k'=>'Score ICA',     'v'=>$icaVal !== null ? $icaVal.'/100' : '—'],
              ]);
            ?>
            <tr data-tt-title="<?= htmlspecialchars($f['intitule']) ?>"
                data-tt-subtitle="<?= htmlspecialchars($f['discipline']) ?>"
                data-tt-dot="<?= $domColor ?>"
                data-tt-rows='<?= htmlspecialchars($ttRows) ?>'
                onclick="window.location='formation.php?form_id=<?= $f['id_diplome'] ?>&annee=<?= $selectedYear ?>&delai=18'">
              <td style="font-size:10px;color:var(--gray-400);font-weight:600;"><?= $fi+1 ?></td>
              <td>
                <div style="font-size:11px;font-weight:600;color:var(--gray-900);"><?= htmlspecialchars(mb_strlen($f['intitule'])>42?mb_substr($f['intitule'],0,40).'…':$f['intitule']) ?></div>
                <div style="font-size:9px;color:var(--gray-400);margin-top:1px;"><?= htmlspecialchars($f['discipline']) ?></div>
               </div>
              </td>
              <td><span class="badge-dom" style="background:<?= $domBg ?>;color:<?= $domColor ?>;"><?= htmlspecialchars(mb_strlen($f['domaine'])>18?mb_substr($f['domaine'],0,16).'…':$f['domaine']) ?></span></td>
              <td style="text-align:right;font-weight:700;font-size:11px;"><?= fmt($f['taux_emploi'],'%') ?></td>
              <td style="text-align:right;font-size:11px;color:var(--gray-600);"><?= fmt($f['taux_cdi'],'%') ?></td>
              <td style="text-align:right;font-size:11px;color:var(--gray-600);"><?= fmt($f['taux_cadre'],'%') ?></td>
              <td style="text-align:right;font-size:11px;color:var(--gray-600);"><?= $f['salaire']?number_format($f['salaire'],0,',',' ').'€':'—' ?></td>
              <td style="text-align:right;">
                <?php if ($icaVal !== null): ?>
                  <div style="font-size:11px;font-weight:700;color:<?= $icaColor ?>;"><?= $icaVal ?>/100</div>
                  <div class="ica-bar"><div class="ica-bar-fill" style="width:<?= max(0, $icaVal) ?>%;background:<?= $icaColor ?>;"></div></div>
                <?php else: ?>
                  <span style="color:var(--gray-300);font-size:11px;">—</span>
                <?php endif; ?>
               </div>
              </td>
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
  function buildRows(rows){return rows.map((r,idx)=>{const isNA=!r.v||r.v==='—';const valCl=isNA?'ui-tooltip-val na':'ui-tooltip-val';const div=r.divider&&idx<rows.length-1?'<div class="ui-tooltip-divider"></div>':'';return `${div}<div class="ui-tooltip-row"><span class="ui-tooltip-key">${r.k}</span><span class="${valCl}">${r.v||'—'}</span></div>`;}).join('');}
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

const BLUE='#000091',BLUE_S='#6CB4EE';
const vertLine={id:'vertLine',afterDraw(c){if(c.tooltip._active?.length){const ctx=c.ctx,x=c.tooltip._active[0].element.x,{top,bottom}=c.chartArea;ctx.save();ctx.beginPath();ctx.moveTo(x,top);ctx.lineTo(x,bottom);ctx.lineWidth=1;ctx.strokeStyle='rgba(0,0,145,0.12)';ctx.setLineDash([4,3]);ctx.stroke();ctx.restore();}}};

/* ── 1. Évolution ligne (toutes années disponibles) avec zones grisées pour absence de données ── */
<?php if (!empty($evol)): ?>
const evolData = <?= $evolJson ?>;
const evolNat = <?= $evolNatJson ?>;
const allYears = <?= $allYearsJson ?>;
const natByYear = Object.fromEntries(evolNat.map(d => [d.annee, d.taux_emploi]));

// Données pour l'établissement (null si absent)
const tauxEtab = allYears.map(y => {
    const entry = evolData.find(d => d.annee == y);
    return entry ? entry.taux_emploi : null;
});
const tauxNat = allYears.map(y => natByYear[y] ?? null);

// Détection des plages avec données (pour ne pas griser les années où des données existent)
// On va griser les zones où tauxEtab est null.
const missingRanges = [];
let start = null;
for (let i = 0; i < allYears.length; i++) {
    if (tauxEtab[i] === null) {
        if (start === null) start = i;
    } else {
        if (start !== null) {
            missingRanges.push({ start, end: i - 1 });
            start = null;
        }
    }
}
if (start !== null) {
    missingRanges.push({ start, end: allYears.length - 1 });
}

// Plugin pour dessiner les zones grisées
const greyZonesPlugin = {
    id: 'greyZones',
    beforeDraw(chart) {
        const { ctx, chartArea: { top, bottom }, scales: { x } } = chart;
        if (!x || missingRanges.length === 0) return;
        
        ctx.save();
        missingRanges.forEach(range => {
            const startX = x.getPixelForValue(allYears[range.start]);
            // Pour la fin, on prend le début de l'année suivante ou la fin de l'axe
            const endIndex = range.end + 1;
            let endX;
            if (endIndex < allYears.length) {
                endX = x.getPixelForValue(allYears[endIndex]);
            } else {
                endX = chart.chartArea.right;
            }
            
            ctx.fillStyle = 'rgba(0,0,0,0.04)';
            ctx.fillRect(startX, top, endX - startX, bottom - top);
            
            // Optionnel : petits hachures
            ctx.fillStyle = 'rgba(0,0,0,0.02)';
            ctx.fillRect(startX, top, endX - startX, bottom - top);
        });
        ctx.restore();
    }
};

new Chart(document.getElementById('chartEvol'), {
    type: 'line',
    plugins: [vertLine, greyZonesPlugin],
    data: {
        labels: allYears,
        datasets: [
            {
                label: "Cet établissement",
                data: tauxEtab,
                borderColor: BLUE,
                backgroundColor: 'rgba(0,0,145,0.07)',
                borderWidth: 2.5,
                pointRadius: (ctx) => {
                    const value = ctx.raw;
                    return value !== null ? 4 : 0; // pas de point si null
                },
                pointHoverRadius: 7,
                pointBackgroundColor: '#fff',
                pointBorderColor: BLUE,
                pointBorderWidth: 2,
                pointHoverBackgroundColor: BLUE,
                fill: true,
                tension: 0.4,
                spanGaps: false // on ne relie pas à travers les null
            },
            {
                label: "Moyenne nationale",
                data: tauxNat,
                borderColor: BLUE_S,
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 4],
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#fff',
                pointBorderColor: BLUE_S,
                pointBorderWidth: 1.5,
                fill: false,
                tension: 0.4,
                spanGaps: true
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                position: 'top',
                labels: { boxWidth: 10, boxHeight: 10, borderRadius: 3, useBorderRadius: true, padding: 14, font: { size: 11 }, color: '#6b7280' }
            },
            tooltip: {
                backgroundColor: '#fff',
                titleColor: '#111827',
                bodyColor: '#374151',
                borderColor: '#e5e7eb',
                borderWidth: 1,
                padding: 12,
                cornerRadius: 10,
                boxPadding: 5,
                titleFont: { family: "'DM Sans', sans-serif", size: 12, weight: '700' },
                bodyFont: { family: "'DM Sans', sans-serif", size: 11 },
                callbacks: {
                    title: i => 'Année ' + i[0].label,
                    label: ctx => {
                        if (ctx.dataset.label === "Cet établissement" && ctx.raw === null) {
                            return '  Donnée non disponible pour cette année';
                        }
                        return '  ' + ctx.dataset.label + ' : ' + (ctx.parsed.y !== null ? ctx.parsed.y + '%' : 'N/A');
                    }
                }
            }
        },
        scales: {
            x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 10 }, color: '#9ca3af' } },
            y: { min: 50, max: 100, grid: { color: '#f3f4f6' }, border: { display: false }, ticks: { callback: v => v + '%', font: { size: 10 }, color: '#9ca3af', stepSize: 10 } }
        }
    }
});
<?php endif; ?>

/* ── 2. Donut domaines ── */
<?php if (!empty($donutData)): ?>
const donutData=<?= $donutJson ?>;
const donutColors=['#000091','#0053B3','#6CB4EE','#002B55','#3a8dd9','#9cb3d8'];
new Chart(document.getElementById('chartDonut'),{
  type:'doughnut',
  data:{labels:donutData.map(d=>d.domaine),datasets:[{data:donutData.map(d=>+d.nb),backgroundColor:donutColors.slice(0,donutData.length),borderWidth:2,borderColor:'#fff',hoverOffset:6}]},
  options:{responsive:true,maintainAspectRatio:false,cutout:'68%',
    plugins:{
      legend:{display:false},
      tooltip:{backgroundColor:'#fff',titleColor:'#111827',bodyColor:'#374151',borderColor:'#e5e7eb',borderWidth:1,padding:10,cornerRadius:10,boxPadding:4,
        callbacks:{label:ctx=>'  '+ctx.label+' : '+ctx.parsed+' formation'+(ctx.parsed>1?'s':'')}
      }
    }
  }
});
<?php endif; ?>
</script>
<script src="script.js"></script>
</body>
</html>