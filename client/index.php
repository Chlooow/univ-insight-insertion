<?php
require_once '../connexion.php';

// ── Années disponibles ────────────────────────────────────────────────────────
$annees = $pdo->query("SELECT DISTINCT annee FROM ANNEE_ENQUETE ORDER BY annee DESC")->fetchAll(PDO::FETCH_COLUMN);
$lastYear = $annees[0];
$selectedYear = isset($_GET['annee']) && in_array($_GET['annee'], $annees) ? (int)$_GET['annee'] : (int)$lastYear;

// ── Domaines disponibles ──────────────────────────────────────────────────────
$domainesList = $pdo->query("SELECT DISTINCT domaine FROM DISCIPLINE ORDER BY domaine")->fetchAll(PDO::FETCH_COLUMN);
$selectedDomaine = isset($_GET['domaine']) && in_array($_GET['domaine'], $domainesList) ? $_GET['domaine'] : '';

// ── Helper : affichage avec fallback si null ──────────────────────────────────
function fmt($val, $suffix = '', $fallback = '—') {
    return ($val !== null && $val !== '') ? $val . $suffix : $fallback;
}

// ── Fonction pour récupérer les KPIs d'une année (avec filtre domaine) ────────
function getKPI($pdo, $year, $domaine = null) {
    $sql = "
        SELECT
            ROUND(AVG(r.taux_emploi), 1)    AS taux_emploi_moy,
            ROUND(AVG(r.taux_cdi), 1)        AS taux_cdi_moy,
            ROUND(AVG(r.taux_cadre), 1)      AS taux_cadre_moy,
            ROUND(AVG(r.salaire_median), 0)  AS salaire_moy
        FROM RESULTAT_IP r
        JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome
        JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
        JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
        WHERE r.taux_emploi IS NOT NULL
          AND ae.annee = :y AND ae.delai_mois = 18
    ";
    $params = [':y' => $year];
    if ($domaine) {
        $sql .= " AND dis.domaine = :dom";
        $params[':dom'] = $domaine;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $res = $stmt->fetch();
    if (!$res['taux_emploi_moy']) {
        $sql2 = "
            SELECT ROUND(AVG(r.taux_emploi),1) AS taux_emploi_moy, ROUND(AVG(r.taux_cdi),1) AS taux_cdi_moy,
                   ROUND(AVG(r.taux_cadre),1) AS taux_cadre_moy, ROUND(AVG(r.salaire_median),0) AS salaire_moy
            FROM RESULTAT_IP r JOIN DIPLOME dip ON dip.id_diplome=r.id_diplome
            JOIN ANNEE_ENQUETE ae ON ae.id_annee=r.id_annee
            WHERE r.taux_emploi IS NOT NULL AND ae.delai_mois=18 AND ae.annee = :y
        ";
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute([':y' => $year]);
        $res = $stmt2->fetch();
    }
    return $res;
}

$kpi = getKPI($pdo, $selectedYear, $selectedDomaine);

// ── Nombre d'établissements (avec les mêmes filtres année + domaine) ───────────
$sqlEtab = "
    SELECT COUNT(DISTINCT e.id_etab) AS nb_etab
    FROM ETABLISSEMENT e
    JOIN DIPLOME dip ON dip.id_etab = e.id_etab
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    JOIN RESULTAT_IP r ON r.id_diplome = dip.id_diplome
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE ae.annee = :y AND ae.delai_mois = 18
";
$paramsEtab = [':y' => $selectedYear];
if ($selectedDomaine) {
    $sqlEtab .= " AND dis.domaine = :dom";
    $paramsEtab[':dom'] = $selectedDomaine;
}
$stmtEtab = $pdo->prepare($sqlEtab);
$stmtEtab->execute($paramsEtab);
$nbEtab = $stmtEtab->fetchColumn() ?: 0;

// Récupérer l'année précédente (si existe)
$prevYear = null;
foreach ($annees as $idx => $y) {
    if ($y == $selectedYear && isset($annees[$idx+1])) {
        $prevYear = (int)$annees[$idx+1];
        break;
    }
}
$prevKpi = $prevYear ? getKPI($pdo, $prevYear, $selectedDomaine) : null;

// Calcul des variations
function computeVariation($current, $previous) {
    if ($current === null || $previous === null || $previous == 0) return null;
    return round(($current - $previous) / $previous * 100, 1);
}
$variations = [
    'emploi' => computeVariation($kpi['taux_emploi_moy'], $prevKpi['taux_emploi_moy'] ?? null),
    'cdi'    => computeVariation($kpi['taux_cdi_moy'],    $prevKpi['taux_cdi_moy']    ?? null),
    'cadre'  => computeVariation($kpi['taux_cadre_moy'],  $prevKpi['taux_cadre_moy']  ?? null),
    'salaire'=> computeVariation($kpi['salaire_moy'],     $prevKpi['salaire_moy']     ?? null),
];

// ── Top 5 disciplines ─────────────────────────────────────────────────────────
$top5Sql = "
    SELECT dis.nom AS discipline, dis.domaine,
           ROUND(AVG(r.taux_emploi),1)      AS taux_emploi,
           ROUND(AVG(r.taux_cadre),1)       AS taux_cadre,
           ROUND(AVG(r.salaire_median),0)   AS salaire
    FROM RESULTAT_IP r
    JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE ae.annee = :y AND ae.delai_mois = 18 AND r.taux_emploi IS NOT NULL
" . ($selectedDomaine ? " AND dis.domaine = :dom" : "") . "
    GROUP BY dis.id_disc, dis.nom, dis.domaine
    ORDER BY taux_emploi DESC LIMIT 5
";
$top5Stmt = $pdo->prepare($top5Sql);
$top5Params = [':y' => $selectedYear];
if ($selectedDomaine) $top5Params[':dom'] = $selectedDomaine;
$top5Stmt->execute($top5Params);
$top5 = $top5Stmt->fetchAll();

// ── Évolution nationale ───────────────────────────────────────────────────────
$evolSql = "
    SELECT ae.annee,
           ROUND(AVG(r.taux_emploi),1) AS taux_emploi,
           ROUND(AVG(r.taux_cdi),1)    AS taux_cdi,
           ROUND(AVG(r.taux_cadre),1)  AS taux_cadre
    FROM RESULTAT_IP r
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    WHERE ae.delai_mois = 18
" . ($selectedDomaine ? " AND dis.domaine = :dom" : "") . "
    GROUP BY ae.annee ORDER BY ae.annee
";
$evolParams = $selectedDomaine ? [':dom' => $selectedDomaine] : [];
$evolStmt = $pdo->prepare($evolSql);
$evolStmt->execute($evolParams);
$evol = $evolStmt->fetchAll();

// ── Top 5 académies ───────────────────────────────────────────────────────────
$topAcadSql = "
    SELECT reg.nom AS academie,
           ROUND(AVG(r.taux_emploi),1) AS taux_emploi
    FROM RESULTAT_IP r
    JOIN DIPLOME dip ON dip.id_diplome = r.id_diplome
    JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
    JOIN ETABLISSEMENT e ON e.id_etab = dip.id_etab
    JOIN REGION reg ON reg.id_region = e.id_region
    JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
    WHERE ae.annee = :y AND ae.delai_mois = 18
      AND r.taux_emploi IS NOT NULL
      AND reg.id_region NOT IN (10,11,12,24,30)
" . ($selectedDomaine ? " AND dis.domaine = :dom" : "") . "
    GROUP BY reg.nom
    ORDER BY taux_emploi DESC
    LIMIT 5
";
$topAcadStmt = $pdo->prepare($topAcadSql);
$topAcadStmt->execute($top5Params);
$topAcademies = $topAcadStmt->fetchAll();

$evolJson     = json_encode($evol);
$top5Json     = json_encode($top5);
$anneesJson   = json_encode(array_map('intval', $annees));
$domainesJson = json_encode($domainesList);

// ── Helper : rendu d'une variation (trend) ────────────────────────────────────
function renderTrend($variation, $prevVal, $prevYear, $suffix = '%') {
    if ($prevVal === null) {
        return '<span class="trend-neutral">Données non disponibles</span>';
    }
    if ($variation === null) {
        return '<span class="trend-neutral">— vs année précédente</span>';
    }
    $class  = $variation >= 0 ? 'trend-up' : 'trend-down';
    $arrow  = $variation > 0 ? '▲' : ($variation < 0 ? '▼' : '●');
    $prevFmt = ($suffix === '€')
        ? number_format($prevVal, 0, ',', ' ') . '€'
        : $prevVal . $suffix;
    return sprintf(
        '<span class="%s">%s %s%%</span><span>vs %s (%s)</span>',
        $class, $arrow, abs($variation), $prevYear, $prevFmt
    );
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Univ Insight — Accueil</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── Filter bar ── */
.filter-bar {
  grid-column: 1 / -1;
  display: flex; align-items: center; gap: 10px;
  background: white; border: 1px solid var(--gray-100);
  border-radius: 12px; padding: 10px 16px; flex-wrap: wrap;
}
.filter-bar label { font-size:10px; font-weight:600; color:var(--gray-400); text-transform:uppercase; letter-spacing:.05em; margin-right:4px; }
.filter-select {
  appearance: none; background: var(--gray-50,#f9fafb);
  border: 1px solid var(--gray-200); border-radius: 8px;
  padding: 5px 28px 5px 10px; font-size: 12px;
  font-family: 'DM Sans', sans-serif; color: var(--gray-700); cursor: pointer;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239ca3af'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 8px center; transition: border-color .15s;
}
.filter-select:focus { outline:none; border-color:var(--blue-france); }
.filter-divider { width:1px; height:20px; background:var(--gray-100); margin:0 4px; }
.filter-reset {
  display:flex; align-items:center; gap:5px; font-size:11px; color:var(--gray-400); cursor:pointer;
  background:none; border:none; font-family:'DM Sans',sans-serif;
  padding:4px 8px; border-radius:6px; transition:all .15s;
}
.filter-reset:hover { background:var(--gray-100); color:var(--gray-700); }
.filter-tag {
  display:inline-flex; align-items:center; gap:5px;
  background:var(--blue-pale); color:var(--blue-france);
  border-radius:20px; padding:2px 10px; font-size:10px; font-weight:600;
}

/* ── KPI grid ── */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  grid-column: 1 / -1;
}
.stat-card {
  border-radius:14px; padding:18px; position:relative; overflow:hidden; background:white;
  cursor: default;
}
.stat-card.dark { background:#002B55; color:white; }
.stat-icon { position:absolute; bottom:14px; right:14px; width:32px; height:32px; border-radius:50%; border:1px solid rgba(255,255,255,.15); display:flex; align-items:center; justify-content:center; }
.stat-card:not(.dark) .stat-icon { border-color:var(--gray-200); }
.kpi-label { font-size:10px; font-weight:600; color:var(--gray-400); letter-spacing:.04em; text-transform:uppercase; margin-bottom:6px; }
.stat-card.dark .kpi-label { color:rgba(255,255,255,.5); }
.kpi-value { font-size:30px; font-weight:700; line-height:1; margin-bottom:8px; }
.kpi-value.no-data { font-size:22px; opacity:.4; }
.kpi-sub {
  font-size:10px; display:flex; align-items:center; gap:4px;
  margin-top: 4px;
}
.kpi-sub .trend-up { color:#10b981; }
.kpi-sub .trend-down { color:#ef4444; }
.kpi-sub .trend-neutral { color:var(--gray-400); }
.stat-card.dark .kpi-sub .trend-up,
.stat-card.dark .kpi-sub .trend-down,
.stat-card.dark .kpi-sub .trend-neutral { color:rgba(255,255,255,0.8); }

/* ── Fallback vide ── */
.empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 32px 16px; gap: 8px; text-align: center;
}
.empty-state svg { opacity: .25; }
.empty-state p { font-size: 12px; color: var(--gray-400); margin: 0; }
.empty-state strong { font-size: 13px; color: var(--gray-500); font-weight: 600; }

/* ── 3 colonnes ── */
.three-col-grid {
  grid-column: 1 / -1;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  align-items: stretch;
}
.three-col-grid .card {
  display: flex;
  flex-direction: column;
  height: 100%;
}
.three-col-grid .card .card-title { margin-bottom: 16px; }
.canvas-wrap { height: 190px; }

/* ── Bar chart académies ── */
.bar-analytics-wrap {
  display: flex;
  align-items: flex-end;
  justify-content: space-around;
  height: 140px;
  padding: 0 4px;
  gap: 8px;
  flex: 1;
}
.bar-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  flex: 1;
  height: 100%;
  justify-content: flex-end;
}
.bar-col-value { font-size:10px; font-weight:700; color:var(--gray-700); flex-shrink:0; }
.bar-outer {
  width: 100%;
  max-width: 48px;
  border-radius: 8px 8px 6px 6px;
  overflow: hidden;
  cursor: pointer;
  transition: opacity .15s;
}
.bar-outer:hover { opacity:.85; }
.bar-fill {
  width: 100%;
  height: 100%;
  border-radius: 8px 8px 0 0;
  background-color: #000091;
  background-image: repeating-linear-gradient(
    -45deg,
    rgba(255,255,255,0.15) 0px,
    rgba(255,255,255,0.15) 2px,
    transparent 2px,
    transparent 7px
  );
}
.bar-outer.active .bar-fill { background-color: #002B55; }
.bar-col-label { font-size:9px; font-weight:600; color:var(--gray-400); text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:56px; flex-shrink:0; }

/* Top disciplines */
.top5-table { width:100%; border-collapse:collapse; font-size:11px; }
.top5-table th { font-size:10px; font-weight:600; color:var(--gray-400); text-align:left; padding:0 0 8px; text-transform:uppercase; letter-spacing:.05em; }
.top5-table td { padding:7px 0; border-top:1px solid var(--gray-100); vertical-align:middle; }
.top5-table tr:first-child td { border-top:none; }
.top5-table tbody tr { cursor:pointer; transition: background .12s; }
.top5-table tbody tr:hover td { background: #f8f9ff; }
.rank-badge { width:20px; height:20px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; }
.rank-1 { background:var(--blue-france); color:white; }
.rank-2 { background:var(--gray-200); color:var(--gray-700); }
.rank-3 { background:#fee2e2; color:#dc2626; }
.rank-4,.rank-5 { background:var(--gray-100); color:var(--gray-500); }
.disc-bar { height:4px; border-radius:2px; background:var(--blue-pale); margin-top:3px; overflow:hidden; }
.disc-bar-fill { height:100%; border-radius:2px; background:var(--blue-france); }

.pill-tag { font-size:9px; font-weight:600; padding:2px 7px; border-radius:20px; background:var(--blue-pale); color:var(--blue-france); }
.search-univ { max-width:340px; }

/* Tooltip */
.ui-tooltip {
  position: fixed;
  z-index: 9999;
  pointer-events: none;
  opacity: 0;
  transform: translateY(6px) scale(.97);
  transition: opacity .15s ease, transform .15s ease;
  max-width: 240px;
  min-width: 180px;
}
.ui-tooltip.visible {
  opacity: 1;
  transform: translateY(0) scale(1);
}
.ui-tooltip-inner {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  box-shadow: 0 8px 24px rgba(0,0,0,.10), 0 2px 6px rgba(0,0,0,.06);
  overflow: hidden;
}
.ui-tooltip-header {
  padding: 10px 13px 8px;
  border-bottom: 1px solid #f3f4f6;
  display: flex;
  align-items: flex-start;
  gap: 8px;
}
.ui-tooltip-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
  margin-top: 3px;
}
.ui-tooltip-title {
  font-size: 12px;
  font-weight: 700;
  color: #111827;
  line-height: 1.35;
  font-family: 'DM Sans', sans-serif;
}
.ui-tooltip-subtitle {
  font-size: 10px;
  color: #9ca3af;
  font-family: 'DM Sans', sans-serif;
  margin-top: 2px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: .03em;
}
.ui-tooltip-body {
  padding: 9px 13px 11px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.ui-tooltip-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.ui-tooltip-key {
  font-size: 11px;
  color: #6b7280;
  font-family: 'DM Sans', sans-serif;
  white-space: nowrap;
}
.ui-tooltip-val {
  font-size: 11px;
  font-weight: 700;
  color: #002B55;
  font-family: 'DM Mono', monospace;
  white-space: nowrap;
}
.ui-tooltip-val.na {
  color: #d1d5db;
  font-weight: 400;
  font-style: italic;
}
.ui-tooltip-divider {
  height: 1px;
  background: #f3f4f6;
  margin: 1px 0;
}

/* Topbar */
.main {
  padding-top: 0 !important;
}
.topbar {
  position: sticky;
  top: 0;
  z-index: 100;
  background: #ffffff !important;
  padding: 10px 24px;
  margin: 0;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  align-items: center;
  gap: 12px;
  box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.content {
  padding: 16px 24px 24px !important;
  margin-top: 0 !important;
}
.page-header {
  margin-top: 0 !important;
  padding-top: 0 !important;
  margin-bottom: 8px;
}
.page-header h1 { margin-top: 0; margin-bottom: 4px; }
</style>
</head>
<body>

<!-- Tooltip singleton -->
<div id="ui-tooltip" class="ui-tooltip" role="tooltip" aria-hidden="true">
  <div class="ui-tooltip-inner">
    <div class="ui-tooltip-header">
      <div class="ui-tooltip-dot" id="tt-dot"></div>
      <div>
        <div class="ui-tooltip-title"    id="tt-title"></div>
        <div class="ui-tooltip-subtitle" id="tt-subtitle"></div>
      </div>
    </div>
    <div class="ui-tooltip-body" id="tt-body"></div>
  </div>
</div>

<aside class="sidebar">
  <div class="logo">
    <div class="logo-icon"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12.5v4c0 1.657 2.686 3 6 3s6-1.343 6-3v-4"/><line x1="22" y1="10" x2="22" y2="16"/></svg></div>
    Univ Insight
  </div>

  <div class="nav-section">
    <div class="nav-label">Navigation</div>
    <a href="index.php" class="nav-item active" style="text-decoration:none;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
        <polyline points="9 22 9 12 15 12 15 22"/>
      </svg>Accueil
    </a>
    <a href="formation.php" class="nav-item" style="text-decoration:none;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 3L1 9l11 6 11-6-11-6z"/>
        <path d="M5 13v5c0 2 7 4 7 4s7-2 7-4v-5"/>
      </svg>Formations
    </a>
    <a href="etablissement.php" class="nav-item" style="text-decoration:none;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="4" y="8" width="16" height="13" rx="1"/>
        <path d="M8 8V4h8v4"/>
        <line x1="12" y1="11" x2="12" y2="16"/>
        <line x1="8" y1="11" x2="8" y2="16"/>
        <line x1="16" y1="11" x2="16" y2="16"/>
      </svg>Établissements
      <span class="badge"><?= $nbEtab ?></span>
    </a>
    <a href="comparaison.php" class="nav-item" style="text-decoration:none;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="8" width="6" height="12" rx="1"/>
        <rect x="15" y="4" width="6" height="16" rx="1"/>
        <path d="M12 2v20"/>
        <polyline points="9 5 6 8 9 11"/>
        <polyline points="15 19 18 16 15 13"/>
      </svg>Comparaison
    </a>
    <a href="stats.php" class="nav-item" style="text-decoration:none;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="3 17 7 12 11 15 18 6 21 9"/>
        <line x1="3" y1="20" x2="21" y2="20"/>
      </svg>Statistiques
    </a>
  </div>

  <div class="sidebar-bottom">
    <div class="app-promo"><strong>Données ESR</strong><p>Dernière mise à jour : <?= $lastYear ?></p><button class="promo-btn">En savoir plus</button></div>
  </div>
</aside>

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
    <div class="page-header" style="grid-column:1/-1; margin-bottom:8px;">
      <div>
        <h1>Accueil</h1>
        <p>Indicateurs nationaux d'insertion professionnelle — Masters <?= $selectedYear ?>, enquête à 18 mois<?= $selectedDomaine ? ' · <strong>'.htmlspecialchars($selectedDomaine).'</strong>' : '' ?>.</p>
      </div>
    </div>

    <form method="GET" class="filter-bar" id="filterForm">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      <label>Année</label>
      <select name="annee" class="filter-select" onchange="document.getElementById('filterForm').submit()">
        <?php foreach($annees as $y): ?>
          <option value="<?= $y ?>" <?= $y == $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
      <div class="filter-divider"></div>
      <label>Domaine</label>
      <select name="domaine" class="filter-select" onchange="document.getElementById('filterForm').submit()">
        <option value="">Tous les domaines</option>
        <?php foreach($domainesList as $dom): ?>
          <option value="<?= htmlspecialchars($dom) ?>" <?= $dom === $selectedDomaine ? 'selected' : '' ?>><?= htmlspecialchars($dom) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if($selectedDomaine): ?>
        <div class="filter-divider"></div>
        <span class="filter-tag"><?= htmlspecialchars($selectedDomaine) ?><a href="?annee=<?= $selectedYear ?>" style="color:inherit;text-decoration:none;font-weight:700;">×</a></span>
      <?php endif; ?>
      <div style="margin-left:auto;">
        <?php if($selectedDomaine || $selectedYear != $lastYear): ?>
          <a href="index.php" class="filter-reset">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-4.73L1 10"/></svg>
            Réinitialiser
          </a>
        <?php endif; ?>
      </div>
    </form>

    <!-- KPIs -->
    <div class="kpi-grid">

      <!-- Taux d'emploi -->
      <?php
      $emploiRows = json_encode([
        ['k' => 'Valeur '.$selectedYear,  'v' => fmt($kpi['taux_emploi_moy'], '%'), 'divider' => false],
        ['k' => 'Valeur '.($prevYear ?? '—'), 'v' => fmt($prevKpi['taux_emploi_moy'] ?? null, '%'), 'divider' => false],
        ['k' => 'Variation', 'v' => $variations['emploi'] !== null ? (($variations['emploi'] >= 0 ? '+' : '').$variations['emploi'].'%') : '—', 'divider' => true],
      ]);
      ?>
      <div class="stat-card dark"
           data-tt-title="Taux d'emploi"
           data-tt-subtitle="Enquête à 18 mois après le master"
           data-tt-dot="#6CB4EE"
           data-tt-rows='<?= htmlspecialchars($emploiRows) ?>'>
        <div class="kpi-label">Taux d'emploi moyen</div>
        <div class="kpi-value <?= $kpi['taux_emploi_moy'] === null ? 'no-data' : '' ?>">
          <?= fmt($kpi['taux_emploi_moy'], '%') ?>
        </div>
        <div class="kpi-sub">
          <?php if ($kpi['taux_emploi_moy'] === null): ?>
            <span class="trend-neutral">Données non disponibles</span>
          <?php else: ?>
            <?= renderTrend($variations['emploi'], $prevKpi['taux_emploi_moy'] ?? null, $prevYear) ?>
          <?php endif; ?>
        </div>
        <div class="stat-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
      </div>

      <!-- Taux CDI -->
      <?php
      $cdiRows = json_encode([
        ['k' => 'Valeur '.$selectedYear,  'v' => fmt($kpi['taux_cdi_moy'], '%')],
        ['k' => 'Valeur '.($prevYear ?? '—'), 'v' => fmt($prevKpi['taux_cdi_moy'] ?? null, '%')],
        ['k' => 'Variation', 'v' => $variations['cdi'] !== null ? (($variations['cdi'] >= 0 ? '+' : '').$variations['cdi'].'%') : '—', 'divider' => true],
      ]);
      ?>
      <div class="stat-card"
           data-tt-title="Taux de CDI"
           data-tt-subtitle="Hors fonction publique"
           data-tt-dot="#000091"
           data-tt-rows='<?= htmlspecialchars($cdiRows) ?>'>
        <div class="kpi-label">Taux CDI moyen</div>
        <div class="kpi-value <?= $kpi['taux_cdi_moy'] === null ? 'no-data' : '' ?>">
          <?= fmt($kpi['taux_cdi_moy'], '%') ?>
        </div>
        <div class="kpi-sub">
          <?php if ($kpi['taux_cdi_moy'] === null): ?>
            <span class="trend-neutral">Données non disponibles</span>
          <?php else: ?>
            <?= renderTrend($variations['cdi'], $prevKpi['taux_cdi_moy'] ?? null, $prevYear) ?>
          <?php endif; ?>
        </div>
        <div class="stat-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><path d="M9 12l2 2 4-4"/><rect x="3" y="4" width="18" height="18" rx="2"/></svg></div>
      </div>

      <!-- Taux cadre -->
      <?php
      $cadreRows = json_encode([
        ['k' => 'Valeur '.$selectedYear,  'v' => fmt($kpi['taux_cadre_moy'], '%')],
        ['k' => 'Valeur '.($prevYear ?? '—'), 'v' => fmt($prevKpi['taux_cadre_moy'] ?? null, '%')],
        ['k' => 'Variation', 'v' => $variations['cadre'] !== null ? (($variations['cadre'] >= 0 ? '+' : '').$variations['cadre'].'%') : '—', 'divider' => true],
      ]);
      ?>
      <div class="stat-card"
           data-tt-title="Taux cadre"
           data-tt-subtitle="Cadres et prof. intermédiaires sup."
           data-tt-dot="#002B55"
           data-tt-rows='<?= htmlspecialchars($cadreRows) ?>'>
        <div class="kpi-label">Taux cadre moyen</div>
        <div class="kpi-value <?= $kpi['taux_cadre_moy'] === null ? 'no-data' : '' ?>">
          <?= fmt($kpi['taux_cadre_moy'], '%') ?>
        </div>
        <div class="kpi-sub">
          <?php if ($kpi['taux_cadre_moy'] === null): ?>
            <span class="trend-neutral">Données non disponibles</span>
          <?php else: ?>
            <?= renderTrend($variations['cadre'], $prevKpi['taux_cadre_moy'] ?? null, $prevYear) ?>
          <?php endif; ?>
        </div>
        <div class="stat-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
      </div>

      <!-- Salaire médian -->
      <?php
      $salaireRows = json_encode([
        ['k' => 'Valeur '.$selectedYear,  'v' => $kpi['salaire_moy'] !== null ? number_format($kpi['salaire_moy'],0,',',' ').'€' : '—'],
        ['k' => 'Valeur '.($prevYear ?? '—'), 'v' => isset($prevKpi['salaire_moy']) && $prevKpi['salaire_moy'] !== null ? number_format($prevKpi['salaire_moy'],0,',',' ').'€' : '—'],
        ['k' => 'Variation', 'v' => $variations['salaire'] !== null ? (($variations['salaire'] >= 0 ? '+' : '').$variations['salaire'].'%') : '—', 'divider' => true],
      ]);
      ?>
      <div class="stat-card"
           data-tt-title="Salaire médian net mensuel"
           data-tt-subtitle="Équivalent temps plein · diplômés en emploi"
           data-tt-dot="#4A90D9"
           data-tt-rows='<?= htmlspecialchars($salaireRows) ?>'>
        <div class="kpi-label">Salaire médian</div>
        <div class="kpi-value <?= $kpi['salaire_moy'] === null ? 'no-data' : '' ?>">
          <?= $kpi['salaire_moy'] !== null ? number_format($kpi['salaire_moy'], 0, ',', ' ') . '€' : '—' ?>
        </div>
        <div class="kpi-sub">
          <?php if ($kpi['salaire_moy'] === null): ?>
            <span class="trend-neutral">Données non disponibles</span>
          <?php else: ?>
            <?= renderTrend($variations['salaire'], $prevKpi['salaire_moy'] ?? null, $prevYear, '€') ?>
          <?php endif; ?>
        </div>
        <div class="stat-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
      </div>

    </div><!-- /kpi-grid -->

    <div class="three-col-grid">

      <!-- Graphique évolution -->
      <div class="card">
        <div class="card-title">Évolution nationale — taux d'emploi, CDI et cadre (18 mois)</div>
        <?php if (empty($evol)): ?>
          <div class="empty-state" style="flex:1;">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <strong>Aucune donnée</strong>
            <p>Pas de données d'évolution pour ce filtre.</p>
          </div>
        <?php else: ?>
          <div class="canvas-wrap"><canvas id="chartEvol"></canvas></div>
        <?php endif; ?>
      </div>

      <!-- Top académies -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="card-title" style="margin:0;">Top 5 académies — taux d'emploi</div>
          <span class="pill-tag"><?= $selectedYear ?></span>
        </div>
        <?php if (empty($topAcademies)): ?>
          <div class="empty-state" style="flex:1;">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
            <strong>Aucune académie trouvée</strong>
            <p>Essayez un autre domaine ou une autre année.</p>
          </div>
        <?php else:
          $maxTaux = max(array_column($topAcademies, 'taux_emploi'));
          $maxTaux = max((float)$maxTaux, 70);
          $BAR_MAX_PX = 120;
        ?>
          <div class="bar-analytics-wrap">
            <?php foreach($topAcademies as $i => $ac):
              $barH  = max(round(($ac['taux_emploi'] / $maxTaux) * $BAR_MAX_PX), 14);
              $isTop = ($i === 0);
              $label = mb_strlen($ac['academie']) > 9 ? mb_substr($ac['academie'], 0, 8).'…' : $ac['academie'];
              $ttRows = json_encode([
                ['k' => 'Taux d\'emploi', 'v' => $ac['taux_emploi'].'%'],
                ['k' => 'Enquête', 'v' => '18 mois · '.$selectedYear],
              ]);
            ?>
            <div class="bar-col">
              <div class="bar-col-value"><?= $ac['taux_emploi'] ?>%</div>
              <div class="bar-outer <?= $isTop ? 'active' : '' ?>"
                   style="height:<?= $barH ?>px;"
                   data-tt-title="<?= htmlspecialchars($ac['academie']) ?>"
                   data-tt-subtitle="Académie"
                   data-tt-dot="<?= $isTop ? '#002B55' : '#000091' ?>"
                   data-tt-rows='<?= htmlspecialchars($ttRows) ?>'>
                <div class="bar-fill"></div>
              </div>
              <div class="bar-col-label"><?= htmlspecialchars($label) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:4px 12px;">
            <?php foreach($topAcademies as $i => $ac): ?>
              <div style="display:flex;align-items:center;gap:4px;">
                <div style="width:8px;height:8px;border-radius:2px;background:<?= $i===0?'#002B55':'#000091' ?>;flex-shrink:0;"></div>
                <span style="font-size:9px;color:var(--gray-500);"><strong style="color:var(--gray-700);"><?= htmlspecialchars($ac['academie']) ?></strong> <?= $ac['taux_emploi'] ?>%</span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Top disciplines -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="card-title" style="margin:0;">Top 5 disciplines</div>
          <span class="pill-tag">Taux emploi · <?= $selectedYear ?></span>
        </div>
        <?php if (empty($top5)): ?>
          <div class="empty-state" style="flex:1;">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            <strong>Aucune discipline trouvée</strong>
            <p>Aucun résultat pour ce domaine ou cette année.</p>
          </div>
        <?php else: ?>
          <table class="top5-table">
            <thead>
              <tr>
                <th style="width:24px;">#</th>
                <th>Discipline</th>
                <th style="text-align:right;">Emploi</th>
              </tr>
            </thead>
            <tbody>
            <?php
            $dotColors = ['#000091','#6CB4EE','#002B55','#9cb3d8','#c5d3e8'];
            foreach($top5 as $i => $d):
              $ttRows = json_encode([
                ['k' => 'Taux d\'emploi', 'v' => fmt($d['taux_emploi'], '%')],
                ['k' => 'Taux cadre',     'v' => fmt($d['taux_cadre'],  '%')],
                ['k' => 'Salaire médian', 'v' => $d['salaire'] ? number_format($d['salaire'],0,',',' ').'€' : '—', 'divider' => true],
              ]);
            ?>
             <tr data-tt-title="<?= htmlspecialchars($d['discipline']) ?>"
                 data-tt-subtitle="<?= htmlspecialchars($d['domaine']) ?>"
                 data-tt-dot="<?= $dotColors[$i] ?>"
                 data-tt-rows='<?= htmlspecialchars($ttRows) ?>'>
               <td><span class="rank-badge rank-<?= $i+1 ?>"><?= $i+1 ?></span></td>
               <td>
                 <div style="font-size:11px;font-weight:600;color:var(--gray-900);line-height:1.3;"><?= htmlspecialchars($d['discipline']) ?></div>
                 <div class="disc-bar"><div class="disc-bar-fill" style="width:<?= $d['taux_emploi'] ?>%;"></div></div>
                 <div style="font-size:9px;color:var(--gray-400);margin-top:2px;"><?= htmlspecialchars($d['domaine']) ?></div>
                </td>
               <td style="text-align:right;font-size:13px;font-weight:700;color:var(--blue-france);"><?= $d['taux_emploi'] ?>%</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
           </table>
        <?php endif; ?>
      </div>

    </div><!-- /three-col-grid -->
  </div><!-- /content -->
</div><!-- /main -->

<script>
/* Tooltip */
(function () {
  const tt      = document.getElementById('ui-tooltip');
  const ttDot   = document.getElementById('tt-dot');
  const ttTitle = document.getElementById('tt-title');
  const ttSub   = document.getElementById('tt-subtitle');
  const ttBody  = document.getElementById('tt-body');

  let hideTimer = null;
  let activeEl  = null;

  function buildRows(rows) {
    return rows.map((r, idx) => {
      const isNA    = !r.v || r.v === '—';
      const valCl   = isNA ? 'ui-tooltip-val na' : 'ui-tooltip-val';
      const divider = r.divider && idx < rows.length - 1
        ? '<div class="ui-tooltip-divider"></div>'
        : '';
      return `${divider}<div class="ui-tooltip-row">
        <span class="ui-tooltip-key">${r.k}</span>
        <span class="${valCl}">${r.v || '—'}</span>
      </div>`;
    }).join('');
  }

  function showTooltip(el, e) {
    clearTimeout(hideTimer);
    if (activeEl === el && tt.classList.contains('visible')) {
      positionTooltip(e);
      return;
    }
    activeEl = el;

    const title    = el.dataset.ttTitle    || '';
    const subtitle = el.dataset.ttSubtitle || '';
    const dot      = el.dataset.ttDot      || '#000091';
    let   rows     = [];
    try { rows = JSON.parse(el.dataset.ttRows || '[]'); } catch(_) {}

    ttDot.style.background = dot;
    ttTitle.textContent    = title;
    ttSub.textContent      = subtitle;
    ttSub.style.display    = subtitle ? '' : 'none';
    ttBody.innerHTML       = buildRows(rows);

    tt.style.left = '-9999px';
    tt.style.top  = '-9999px';
    tt.classList.add('visible');
    tt.setAttribute('aria-hidden', 'false');

    requestAnimationFrame(() => positionTooltip(e));
  }

  function positionTooltip(e) {
    const W   = tt.offsetWidth  || 220;
    const H   = tt.offsetHeight || 120;
    const vw  = window.innerWidth;
    const vh  = window.innerHeight;
    const pad = 14;

    let x = e.clientX + 16;
    let y = e.clientY + 16;

    if (x + W + pad > vw) x = e.clientX - W - 12;
    if (y + H + pad > vh) y = e.clientY - H - 12;
    if (x < pad) x = pad;
    if (y < pad) y = pad;

    tt.style.left = x + 'px';
    tt.style.top  = y + 'px';
  }

  function hideTooltip() {
    hideTimer = setTimeout(() => {
      tt.classList.remove('visible');
      tt.setAttribute('aria-hidden', 'true');
      activeEl = null;
    }, 100);
  }

  document.addEventListener('mouseover', function (e) {
    const el = e.target.closest('[data-tt-title]');
    if (el) showTooltip(el, e);
  });
  document.addEventListener('mousemove', function (e) {
    if (tt.classList.contains('visible')) positionTooltip(e);
  });
  document.addEventListener('mouseout', function (e) {
    const el = e.target.closest('[data-tt-title]');
    if (el) hideTooltip();
  });
  document.addEventListener('scroll', hideTooltip, { passive: true });
})();

<?php if (!empty($evol)): ?>
const evolData = <?= $evolJson ?>;

const C_EMPLOI = '#000091';
const C_CDI    = '#6CB4EE';
const C_CADRE  = '#002B55';

Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#9ca3af';

const vertLinePlugin = {
  id: 'vertLine',
  afterDraw(chart) {
    if (chart.tooltip._active && chart.tooltip._active.length) {
      const ctx = chart.ctx;
      const x   = chart.tooltip._active[0].element.x;
      const { top, bottom } = chart.chartArea;
      ctx.save();
      ctx.beginPath();
      ctx.moveTo(x, top);
      ctx.lineTo(x, bottom);
      ctx.lineWidth   = 1;
      ctx.strokeStyle = 'rgba(0,0,145,0.12)';
      ctx.setLineDash([4, 3]);
      ctx.stroke();
      ctx.restore();
    }
  }
};

new Chart(document.getElementById('chartEvol'), {
  type: 'line',
  plugins: [vertLinePlugin],
  data: {
    labels: evolData.map(d => d.annee),
    datasets: [
      {
        label: 'Taux emploi',
        data: evolData.map(d => d.taux_emploi),
        borderColor: C_EMPLOI,
        backgroundColor: 'rgba(0,0,145,0.07)',
        borderWidth: 2.5,
        pointRadius: 4,
        pointHoverRadius: 6,
        pointBackgroundColor: '#fff',
        pointBorderColor: C_EMPLOI,
        pointBorderWidth: 2,
        pointHoverBackgroundColor: C_EMPLOI,
        pointHoverBorderColor: '#fff',
        pointHoverBorderWidth: 2,
        fill: true,
        tension: 0.4,
        spanGaps: true
      },
      {
        label: 'Taux CDI',
        data: evolData.map(d => d.taux_cdi),
        borderColor: C_CDI,
        backgroundColor: 'transparent',
        borderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6,
        pointBackgroundColor: '#fff',
        pointBorderColor: C_CDI,
        pointBorderWidth: 2,
        pointHoverBackgroundColor: C_CDI,
        pointHoverBorderColor: '#fff',
        pointHoverBorderWidth: 2,
        fill: false,
        tension: 0.4,
        spanGaps: true
      },
      {
        label: 'Taux cadre',
        data: evolData.map(d => d.taux_cadre),
        borderColor: C_CADRE,
        backgroundColor: 'transparent',
        borderWidth: 2,
        borderDash: [5, 4],
        pointRadius: 4,
        pointHoverRadius: 6,
        pointBackgroundColor: '#fff',
        pointBorderColor: C_CADRE,
        pointBorderWidth: 2,
        pointHoverBackgroundColor: C_CADRE,
        pointHoverBorderColor: '#fff',
        pointHoverBorderWidth: 2,
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
        labels: {
          boxWidth: 10,
          boxHeight: 10,
          borderRadius: 3,
          useBorderRadius: true,
          padding: 16,
          font: { size: 11 },
          color: '#6b7280'
        }
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
        titleFont:  { family: "'DM Sans', sans-serif", size: 12, weight: '700' },
        bodyFont:   { family: "'DM Sans', sans-serif", size: 11 },
        callbacks: {
          title: items => 'Année ' + items[0].label,
          label: ctx  => {
            const val = ctx.parsed.y;
            return '  ' + ctx.dataset.label + ' : ' + (val !== null ? val + '%' : 'N/A');
          }
        }
      }
    },
    scales: {
      x: {
        grid: { display: false },
        border: { display: false },
        ticks: { font: { size: 10 }, color: '#9ca3af' }
      },
      y: {
        min: 50,
        max: 100,
        grid: { color: '#f3f4f6' },
        border: { display: false },
        ticks: {
          callback: v => v + '%',
          font: { size: 10 },
          color: '#9ca3af',
          stepSize: 10
        }
      }
    }
  }
});
<?php endif; ?>
</script>
<script src="script.js"></script>
</body>
</html>