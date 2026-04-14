<?php
require_once '../connexion.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt($val, $suffix = '', $fallback = '—') {
    return ($val !== null && $val !== '') ? $val . $suffix : $fallback;
}
function fmtSal($val, $fallback = '—') {
    return ($val !== null && $val !== '') ? number_format((float)$val, 0, ',', ' ') . '€' : $fallback;
}
function delta($a, $b) {
    if ($a === null || $b === null) return null;
    return round((float)$a - (float)$b, 1);
}

// ── Mode : formations ou établissements ──────────────────────────────────────
$mode = (isset($_GET['mode']) && $_GET['mode'] === 'etab') ? 'etab' : 'form';

// ── Années / délai ─────────────────────────────────────────────────────────────
$annees    = $pdo->query("SELECT DISTINCT annee FROM ANNEE_ENQUETE ORDER BY annee DESC")->fetchAll(PDO::FETCH_COLUMN);
$lastYear  = (int)$annees[0];
$selectedYear  = isset($_GET['annee']) && in_array($_GET['annee'], $annees) ? (int)$_GET['annee'] : $lastYear;
$selectedDelai = (isset($_GET['delai']) && in_array((int)$_GET['delai'], [18, 30])) ? (int)$_GET['delai'] : 18;

// ── Listes formations & établissements ────────────────────────────────────────
$formList = $pdo->query("
    SELECT dip.id_diplome,
           CONCAT(dip.intitule,' — ',dis.nom,' (',e.nom,')') AS label,
           dip.intitule, dis.nom AS discipline, e.nom AS etab_nom, dis.domaine
    FROM DIPLOME dip
    JOIN DISCIPLINE    dis ON dis.id_disc = dip.id_disc
    JOIN ETABLISSEMENT e   ON e.id_etab   = dip.id_etab
    ORDER BY dip.intitule, dis.nom, e.nom
")->fetchAll();

$etabList = $pdo->query("SELECT id_etab, nom FROM ETABLISSEMENT ORDER BY nom")->fetchAll();

// ── Sélections A / B ──────────────────────────────────────────────────────────
function pickId($key, $list, $idCol) {
    if (isset($_GET[$key]) && ctype_digit($_GET[$key])) {
        $v = (int)$_GET[$key];
        foreach ($list as $item) if ($item[$idCol] == $v) return $v;
    }
    return null;
}

if ($mode === 'form') {
    $idA = pickId('a', $formList, 'id_diplome') ?? ((int)($formList[0]['id_diplome'] ?? 0));
    $idB = pickId('b', $formList, 'id_diplome') ?? ((int)($formList[1]['id_diplome'] ?? 0));
} else {
    $idA = pickId('a', $etabList, 'id_etab') ?? ((int)($etabList[0]['id_etab'] ?? 0));
    $idB = pickId('b', $etabList, 'id_etab') ?? ((int)($etabList[1]['id_etab'] ?? 0));
}

// ── Fonction : récupérer les données d'une entité ─────────────────────────────
function getFormData($pdo, $id, $year, $delai) {
    // Info formation
    $s = $pdo->prepare("
        SELECT dip.id_diplome, dip.intitule, dip.niveau,
               dis.nom AS discipline, dis.domaine,
               e.nom AS etab_nom, e.id_etab,
               reg.nom AS region
        FROM DIPLOME dip
        JOIN DISCIPLINE    dis ON dis.id_disc  = dip.id_disc
        JOIN ETABLISSEMENT e   ON e.id_etab    = dip.id_etab
        JOIN REGION        reg ON reg.id_region = e.id_region
        WHERE dip.id_diplome = :id
    ");
    $s->execute([':id' => $id]);
    $info = $s->fetch();

    // KPIs année/délai
    $k = $pdo->prepare("
        SELECT r.taux_emploi, r.taux_cdi, r.taux_cadre, r.salaire_median AS salaire, r.nb_repondants
        FROM RESULTAT_IP r
        JOIN ANNEE_ENQUETE ae ON ae.id_annee = r.id_annee
        WHERE r.id_diplome = :id AND ae.annee = :y AND ae.delai_mois = :d LIMIT 1
    ");
    $k->execute([':id'=>$id,':y'=>$year,':d'=>$delai]);
    $kpi = $k->fetch() ?: [];

    // Évolution (18 mois)
    $e = $pdo->prepare("
        SELECT ae.annee, r.taux_emploi, r.taux_cdi, r.taux_cadre, r.salaire_median
        FROM RESULTAT_IP r JOIN ANNEE_ENQUETE ae ON ae.id_annee=r.id_annee
        WHERE r.id_diplome=:id AND ae.delai_mois=18 AND r.taux_emploi IS NOT NULL
        ORDER BY ae.annee
    ");
    $e->execute([':id'=>$id]);
    $evol = $e->fetchAll();

    // ICA
    $norm = $pdo->prepare("
        SELECT MIN(r2.taux_emploi) mn_emp, MAX(r2.taux_emploi) mx_emp,
               MIN(r2.taux_cdi) mn_cdi, MAX(r2.taux_cdi) mx_cdi,
               MIN(r2.taux_cadre) mn_cad, MAX(r2.taux_cadre) mx_cad,
               MIN(r2.salaire_median) mn_sal, MAX(r2.salaire_median) mx_sal
        FROM RESULTAT_IP r2 JOIN ANNEE_ENQUETE ae2 ON ae2.id_annee=r2.id_annee
        WHERE ae2.annee=:y AND ae2.delai_mois=:d AND r2.taux_emploi IS NOT NULL
    ");
    $norm->execute([':y'=>$year,':d'=>$delai]);
    $n = $norm->fetch();

    $ica = null;
    if ($n && !empty($kpi)) {
        $norm_v = function($v,$mn,$mx){ return ($v!==null&&$mx>$mn)?round(($v-$mn)/($mx-$mn)*100,1):null; };
        $e_ = $norm_v($kpi['taux_emploi']??null,$n['mn_emp'],$n['mx_emp'])??0;
        $c_ = $norm_v($kpi['taux_cdi']??null,$n['mn_cdi'],$n['mx_cdi'])??0;
        $k_ = $norm_v($kpi['taux_cadre']??null,$n['mn_cad'],$n['mx_cad'])??0;
        $s_ = $norm_v($kpi['salaire']??null,$n['mn_sal'],$n['mx_sal'])??0;
        $ica = round(0.4*$e_ + 0.2*$c_ + 0.2*$k_ + 0.2*$s_, 1);
    }

    return compact('info','kpi','evol','ica');
}

function getEtabData($pdo, $id, $year, $delai) {
    $s = $pdo->prepare("
        SELECT e.id_etab, e.nom, reg.nom AS region
        FROM ETABLISSEMENT e JOIN REGION reg ON reg.id_region=e.id_region
        WHERE e.id_etab=:id
    ");
    $s->execute([':id'=>$id]);
    $info = $s->fetch();

    $k = $pdo->prepare("
        SELECT ROUND(AVG(r.taux_emploi),1) AS taux_emploi,
               ROUND(AVG(r.taux_cdi),1)    AS taux_cdi,
               ROUND(AVG(r.taux_cadre),1)  AS taux_cadre,
               ROUND(AVG(r.salaire_median),0) AS salaire,
               COUNT(DISTINCT dip.id_diplome)  AS nb_formations
        FROM RESULTAT_IP r
        JOIN DIPLOME dip ON dip.id_diplome=r.id_diplome
        JOIN ANNEE_ENQUETE ae ON ae.id_annee=r.id_annee
        WHERE dip.id_etab=:id AND ae.annee=:y AND ae.delai_mois=:d
    ");
    $k->execute([':id'=>$id,':y'=>$year,':d'=>$delai]);
    $kpi = $k->fetch() ?: [];

    $e = $pdo->prepare("
        SELECT ae.annee, ROUND(AVG(r.taux_emploi),1) AS taux_emploi,
               ROUND(AVG(r.taux_cdi),1) AS taux_cdi, ROUND(AVG(r.taux_cadre),1) AS taux_cadre,
               ROUND(AVG(r.salaire_median),0) AS salaire_median
        FROM RESULTAT_IP r
        JOIN DIPLOME dip ON dip.id_diplome=r.id_diplome
        JOIN ANNEE_ENQUETE ae ON ae.id_annee=r.id_annee
        WHERE dip.id_etab=:id AND ae.delai_mois=18 AND r.taux_emploi IS NOT NULL
        GROUP BY ae.annee ORDER BY ae.annee
    ");
    $e->execute([':id'=>$id]);
    $evol = $e->fetchAll();

    // ICA moyen
    $norm = $pdo->prepare("
        SELECT MIN(r2.taux_emploi) mn_emp, MAX(r2.taux_emploi) mx_emp,
               MIN(r2.taux_cdi) mn_cdi, MAX(r2.taux_cdi) mx_cdi,
               MIN(r2.taux_cadre) mn_cad, MAX(r2.taux_cadre) mx_cad,
               MIN(r2.salaire_median) mn_sal, MAX(r2.salaire_median) mx_sal
        FROM RESULTAT_IP r2 JOIN ANNEE_ENQUETE ae2 ON ae2.id_annee=r2.id_annee
        WHERE ae2.annee=:y AND ae2.delai_mois=:d AND r2.taux_emploi IS NOT NULL
    ");
    $norm->execute([':y'=>$year,':d'=>$delai]);
    $n = $norm->fetch();

    $ica = null;
    if ($n && !empty($kpi)) {
        $norm_v = function($v,$mn,$mx){ return ($v!==null&&$mx>$mn)?round(($v-$mn)/($mx-$mn)*100,1):null; };
        $e_ = $norm_v($kpi['taux_emploi']??null,$n['mn_emp'],$n['mx_emp'])??0;
        $c_ = $norm_v($kpi['taux_cdi']??null,$n['mn_cdi'],$n['mx_cdi'])??0;
        $k_ = $norm_v($kpi['taux_cadre']??null,$n['mn_cad'],$n['mx_cad'])??0;
        $s_ = $norm_v($kpi['salaire']??null,$n['mn_sal'],$n['mx_sal'])??0;
        $ica = round(0.4*$e_ + 0.2*$c_ + 0.2*$k_ + 0.2*$s_, 1);
    }

    return compact('info','kpi','evol','ica');
}

// ── Charger les deux entités ───────────────────────────────────────────────────
if ($mode === 'form') {
    $dataA = getFormData($pdo, $idA, $selectedYear, $selectedDelai);
    $dataB = getFormData($pdo, $idB, $selectedYear, $selectedDelai);
} else {
    $dataA = getEtabData($pdo, $idA, $selectedYear, $selectedDelai);
    $dataB = getEtabData($pdo, $idB, $selectedYear, $selectedDelai);
}

// ── Verdict automatique ───────────────────────────────────────────────────────
$criteresA = $criteresB = 0;
$metriques = ['taux_emploi','taux_cdi','taux_cadre','salaire'];
foreach ($metriques as $m) {
    $a = $dataA['kpi'][$m] ?? null;
    $b = $dataB['kpi'][$m] ?? null;
    if ($a !== null && $b !== null) {
        if ($a > $b) $criteresA++;
        elseif ($b > $a) $criteresB++;
    }
}
$totalCriteres = $criteresA + $criteresB;

// ── Radar normalisé (même base) ────────────────────────────────────────────────
$normStmt = $pdo->prepare("
    SELECT MIN(r2.taux_emploi) mn_emp, MAX(r2.taux_emploi) mx_emp,
           MIN(r2.taux_cdi) mn_cdi, MAX(r2.taux_cdi) mx_cdi,
           MIN(r2.taux_cadre) mn_cad, MAX(r2.taux_cadre) mx_cad,
           MIN(r2.salaire_median) mn_sal, MAX(r2.salaire_median) mx_sal
    FROM RESULTAT_IP r2 JOIN ANNEE_ENQUETE ae2 ON ae2.id_annee=r2.id_annee
    WHERE ae2.annee=:y AND ae2.delai_mois=:d AND r2.taux_emploi IS NOT NULL
");
$normStmt->execute([':y'=>$selectedYear,':d'=>$selectedDelai]);
$nrm = $normStmt->fetch();

function norm($v, $mn, $mx) {
    if ($v===null||$mn===null||$mx===null||$mx==$mn) return null;
    return round(($v-$mn)/($mx-$mn)*100, 1);
}

function radarFor($kpi, $nrm) {
    return [
        norm($kpi['taux_emploi']??null, $nrm['mn_emp'], $nrm['mx_emp']),
        norm($kpi['taux_cdi']??null,    $nrm['mn_cdi'], $nrm['mx_cdi']),
        norm($kpi['taux_cadre']??null,  $nrm['mn_cad'], $nrm['mx_cad']),
        norm($kpi['salaire']??null,     $nrm['mn_sal'], $nrm['mx_sal']),
    ];
}

$radarA = radarFor($dataA['kpi'], $nrm);
$radarB = radarFor($dataB['kpi'], $nrm);

// ── Nom court d'affichage ─────────────────────────────────────────────────────
function shortName($data, $mode) {
    if ($mode === 'form') {
        return $data['info']['intitule'] ?? '—';
    }
    return $data['info']['nom'] ?? '—';
}
function subName($data, $mode) {
    if ($mode === 'form') {
        return ($data['info']['discipline'] ?? '') . ' · ' . ($data['info']['etab_nom'] ?? '');
    }
    return $data['info']['region'] ?? '';
}

$nameA = shortName($dataA, $mode);
$nameB = shortName($dataB, $mode);
$subA  = subName($dataA, $mode);
$subB  = subName($dataB, $mode);

// ── JSON pour JS ──────────────────────────────────────────────────────────────
$evolAJson  = json_encode($dataA['evol']);
$evolBJson  = json_encode($dataB['evol']);
$radarJson  = json_encode(['A'=>$radarA,'B'=>$radarB]);
$nameAJson  = json_encode(mb_strlen($nameA)>28?mb_substr($nameA,0,26).'…':$nameA);
$nameBJson  = json_encode(mb_strlen($nameB)>28?mb_substr($nameB,0,26).'…':$nameB);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Univ Insight — Comparaison</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── topbar ── */
.topbar{position:sticky;top:0;z-index:100;background:#fff!important;padding:10px 24px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:12px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.content{padding:16px 24px 24px!important;}
.search-univ{max-width:340px;}

/* ── filter bar ── */
.filter-bar{grid-column:1/-1;display:flex;align-items:center;gap:10px;background:white;border:1px solid var(--gray-100);border-radius:12px;padding:10px 16px;flex-wrap:wrap;}
.filter-bar label{font-size:10px;font-weight:600;color:var(--gray-400);text-transform:uppercase;letter-spacing:.05em;margin-right:4px;}
.filter-select{appearance:none;background:var(--gray-50,#f9fafb);border:1px solid var(--gray-200);border-radius:8px;padding:5px 28px 5px 10px;font-size:12px;font-family:'DM Sans',sans-serif;color:var(--gray-700);cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239ca3af'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;}
.filter-select:focus{outline:none;border-color:var(--blue-france);}
.filter-select.xl{min-width:280px;}
.filter-divider{width:1px;height:20px;background:var(--gray-100);margin:0 4px;}
.delai-toggle{display:flex;background:var(--gray-100);border-radius:8px;padding:2px;gap:2px;}
.delai-btn{font-size:11px;font-weight:600;padding:4px 12px;border-radius:6px;border:none;background:transparent;color:var(--gray-500);cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s;}
.delai-btn.active{background:white;color:var(--blue-france);box-shadow:0 1px 3px rgba(0,0,0,.08);}

/* ── mode toggle ── */
.mode-toggle{display:flex;background:var(--gray-100);border-radius:8px;padding:2px;gap:2px;}
.mode-btn{font-size:11px;font-weight:600;padding:5px 14px;border-radius:6px;border:none;background:transparent;color:var(--gray-500);cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s;text-decoration:none;display:inline-block;}
.mode-btn.active{background:white;color:var(--blue-france);box-shadow:0 1px 3px rgba(0,0,0,.08);}

/* ── layout ── */
.pill-tag{font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;background:var(--blue-pale);color:var(--blue-france);}
.full-col{grid-column:1/-1;}
.two-col{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.two-col-3{grid-column:1/-1;display:grid;grid-template-columns:1fr 1.6fr 1fr;gap:12px;}
.card{background:white;border-radius:14px;padding:18px;}
.card-title{font-size:13px;font-weight:600;margin-bottom:16px;}

/* ── entity header cards ── */
.entity-header{border-radius:14px;padding:18px;border:2px solid transparent;transition:border-color .2s;}
.entity-header.A{border-color:#000091;}
.entity-header.B{border-color:#6CB4EE;}
.entity-tag{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;font-size:11px;font-weight:700;color:white;margin-bottom:8px;}
.entity-tag.A{background:#000091;}
.entity-tag.B{background:#6CB4EE;}
.entity-name{font-size:14px;font-weight:700;color:var(--gray-900);line-height:1.3;margin-bottom:4px;}
.entity-sub{font-size:11px;color:var(--gray-400);}
.entity-ica{margin-top:12px;padding-top:12px;border-top:1px solid var(--gray-100);}
.ica-row{display:flex;align-items:center;gap:8px;}
.ica-track{flex:1;height:6px;border-radius:3px;background:var(--gray-100);overflow:hidden;}
.ica-fill{height:100%;border-radius:3px;}
.ica-score-sm{font-size:13px;font-weight:700;min-width:36px;text-align:right;}

/* ── verdict banner ── */
.verdict-banner{grid-column:1/-1;background:white;border-radius:14px;padding:14px 20px;display:flex;align-items:center;gap:16px;border:1px solid var(--gray-100);}
.verdict-entity{flex:1;text-align:center;}
.verdict-score{font-size:28px;font-weight:700;line-height:1;}
.verdict-label{font-size:10px;color:var(--gray-400);text-transform:uppercase;letter-spacing:.04em;margin-top:2px;}
.verdict-vs{width:60px;text-align:center;font-size:13px;font-weight:700;color:var(--gray-300);flex-shrink:0;}
.verdict-badge{padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;}
.verdict-win{background:#d1fae5;color:#065f46;}
.verdict-lose{background:var(--gray-100);color:var(--gray-500);}
.verdict-draw{background:#fef3c7;color:#92400e;}

/* ── diff table ── */
.diff-table{width:100%;border-collapse:collapse;font-size:11px;}
.diff-table th{font-size:10px;font-weight:600;color:var(--gray-400);padding:0 0 10px;text-transform:uppercase;letter-spacing:.05em;}
.diff-table th.center{text-align:center;}
.diff-table td{padding:9px 0;border-top:1px solid var(--gray-100);vertical-align:middle;}
.diff-table tr:first-child td{border-top:none;}
.diff-label{font-size:11px;font-weight:600;color:var(--gray-700);}
.diff-val{font-size:13px;font-weight:700;text-align:right;}
.diff-val.A{color:#000091;}
.diff-val.B{color:#6CB4EE;}
.diff-delta{font-size:11px;font-weight:700;text-align:center;padding:0 12px;}
.diff-delta.pos{color:#10b981;}
.diff-delta.neg{color:#ef4444;}
.diff-delta.zero{color:var(--gray-300);}
.diff-bar-wrap{display:flex;align-items:center;gap:4px;margin-top:4px;}
.diff-bar{height:4px;border-radius:2px;transition:width .4s;}
.diff-bar.A{background:#000091;}
.diff-bar.B{background:#6CB4EE;}

/* ── ICA double gauge ── */
.gauge-wrap{display:flex;flex-direction:column;gap:14px;padding:4px 0;}
.gauge-row{display:flex;align-items:center;gap:12px;}
.gauge-tag{width:22px;height:22px;border-radius:6px;font-size:11px;font-weight:700;color:white;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.gauge-track{flex:1;height:12px;border-radius:6px;background:var(--gray-100);overflow:hidden;position:relative;}
.gauge-fill{height:100%;border-radius:6px;transition:width .5s ease;}
.gauge-val{font-size:14px;font-weight:700;min-width:44px;text-align:right;}
.gauge-label{font-size:10px;color:var(--gray-400);margin-top:2px;margin-left:34px;}

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

/* ── swap btn ── */
.swap-btn{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--gray-500);cursor:pointer;background:var(--gray-100);border:none;border-radius:8px;padding:5px 10px;font-family:'DM Sans',sans-serif;transition:all .15s;}
.swap-btn:hover{background:var(--gray-200);color:var(--gray-700);}

/* ── empty ── */
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
    <a href="index.php"       class="nav-item" style="text-decoration:none;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Accueil</a>
    <a href="formation.php"   class="nav-item" style="text-decoration:none;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3L1 9l11 6 11-6-11-6z"/><path d="M5 13v5c0 2 7 4 7 4s7-2 7-4v-5"/></svg>Formations</a>
    <a href="etablissement.php" class="nav-item" style="text-decoration:none;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="8" width="16" height="13" rx="1"/><path d="M8 8V4h8v4"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="8" y1="11" x2="8" y2="16"/><line x1="16" y1="11" x2="16" y2="16"/></svg>Établissements</a>
    <a href="comparaison.php" class="nav-item active" style="text-decoration:none;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="8" width="6" height="12" rx="1"/><rect x="15" y="4" width="6" height="16" rx="1"/><path d="M12 2v20"/></svg>Comparaison</a>
    <a href="stats.php"       class="nav-item" style="text-decoration:none;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 17 7 12 11 15 18 6 21 9"/><line x1="3" y1="20" x2="21" y2="20"/></svg>Statistiques</a>
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
        <input type="text" class="search-input-field" placeholder="Rechercher…" autocomplete="off">
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
        <h1>Comparaison</h1>
        <p>Comparez deux <?= $mode==='form'?'formations':'établissements' ?> côte à côte — <?= $selectedYear ?> · <?= $selectedDelai ?> mois.</p>
      </div>
      <div class="header-actions">
        <div class="mode-toggle">
          <a href="?mode=form&annee=<?= $selectedYear ?>&delai=<?= $selectedDelai ?>" class="mode-btn <?= $mode==='form'?'active':'' ?>">Formations</a>
          <a href="?mode=etab&annee=<?= $selectedYear ?>&delai=<?= $selectedDelai ?>" class="mode-btn <?= $mode==='etab'?'active':'' ?>">Établissements</a>
        </div>
      </div>
    </div>

    <!-- Filtres -->
    <form method="GET" class="filter-bar" id="filterForm">
      <input type="hidden" name="mode" value="<?= $mode ?>">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>

      <!-- Entité A -->
      <div style="display:flex;align-items:center;gap:6px;">
        <span class="entity-tag A" style="width:18px;height:18px;border-radius:4px;font-size:10px;">A</span>
        <select name="a" class="filter-select xl" onchange="this.form.submit()">
          <?php
          $list = $mode==='form' ? $formList : $etabList;
          $idColL = $mode==='form' ? 'id_diplome' : 'id_etab';
          foreach ($list as $item):
            $lbl = $mode==='form' ? (mb_strlen($item['label'])>70?mb_substr($item['label'],0,68).'…':$item['label']) : $item['nom'];
          ?>
          <option value="<?= $item[$idColL] ?>" <?= $item[$idColL]==$idA?'selected':'' ?>><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Swap -->
      <a href="?mode=<?= $mode ?>&a=<?= $idB ?>&b=<?= $idA ?>&annee=<?= $selectedYear ?>&delai=<?= $selectedDelai ?>" class="swap-btn">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 16V4m0 0L3 8m4-4l4 4"/><path d="M17 8v12m0 0l4-4m-4 4l-4-4"/></svg>
        Inverser
      </a>

      <!-- Entité B -->
      <div style="display:flex;align-items:center;gap:6px;">
        <span class="entity-tag B" style="width:18px;height:18px;border-radius:4px;font-size:10px;">B</span>
        <select name="b" class="filter-select xl" onchange="this.form.submit()">
          <?php foreach ($list as $item):
            $lbl = $mode==='form' ? (mb_strlen($item['label'])>70?mb_substr($item['label'],0,68).'…':$item['label']) : $item['nom'];
          ?>
          <option value="<?= $item[$idColL] ?>" <?= $item[$idColL]==$idB?'selected':'' ?>><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

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
    </form>

    <!-- En-têtes entités A & B -->
    <div class="two-col">
      <?php foreach (['A','B'] as $side):
        $data = $side==='A' ? $dataA : $dataB;
        $name = $side==='A' ? $nameA : $nameB;
        $sub  = $side==='A' ? $subA  : $subB;
        $ica  = $data['ica'];
        $color = $side==='A' ? '#000091' : '#6CB4EE';
        $ttRows = json_encode([
          ["k"=>"Taux emploi","v"=>fmt($data['kpi']['taux_emploi']??null,'%')],
          ["k"=>"Taux CDI",   "v"=>fmt($data['kpi']['taux_cdi']??null,'%')],
          ["k"=>"Taux cadre", "v"=>fmt($data['kpi']['taux_cadre']??null,'%')],
          ["k"=>"Salaire",    "v"=>fmtSal($data['kpi']['salaire']??null),"divider"=>true],
          ["k"=>"Score ICA",  "v"=>$ica!==null?$ica.'/100':'—'],
        ]);
      ?>
      <div class="entity-header card <?= $side ?>"
           data-tt-title="<?= htmlspecialchars($name) ?>"
           data-tt-subtitle="<?= htmlspecialchars($sub) ?>"
           data-tt-dot="<?= $color ?>"
           data-tt-rows='<?= htmlspecialchars($ttRows) ?>'>
        <div class="entity-tag <?= $side ?>"><?= $side ?></div>
        <div class="entity-name"><?= htmlspecialchars(mb_strlen($name)>60?mb_substr($name,0,58).'…':$name) ?></div>
        <div class="entity-sub"><?= htmlspecialchars(mb_strlen($sub)>70?mb_substr($sub,0,68).'…':$sub) ?></div>
        <?php if ($ica !== null): ?>
        <div class="entity-ica">
          <div style="font-size:9px;font-weight:600;color:var(--gray-400);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">Score ICA</div>
          <div class="ica-row">
            <div class="ica-track"><div class="ica-fill" style="width:<?= $ica ?>%;background:<?= $color ?>;"></div></div>
            <div class="ica-score-sm" style="color:<?= $color ?>;"><?= $ica ?></div>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Verdict banner -->
    <div class="verdict-banner">
      <?php
      $winA = $criteresA > $criteresB;
      $winB = $criteresB > $criteresA;
      $draw = $criteresA === $criteresB;
      $shortA = mb_strlen($nameA)>22?mb_substr($nameA,0,20).'…':$nameA;
      $shortB = mb_strlen($nameB)>22?mb_substr($nameB,0,20).'…':$nameB;
      ?>
      <!-- Entité A -->
      <div class="verdict-entity">
        <div class="verdict-score" style="color:#000091;"><?= $criteresA ?></div>
        <div class="verdict-label">critère<?= $criteresA>1?'s':'' ?> gagné<?= $criteresA>1?'s':'' ?></div>
        <div style="margin-top:8px;">
          <span class="verdict-badge <?= $winA?'verdict-win':($draw?'verdict-draw':'verdict-lose') ?>">
            <?= $winA?'✓ Meilleur':($draw?'Égalité':'—') ?>
          </span>
        </div>
        <div style="font-size:11px;font-weight:600;color:var(--gray-700);margin-top:6px;text-align:center;"><?= htmlspecialchars($shortA) ?></div>
      </div>

      <!-- VS central -->
      <div style="display:flex;flex-direction:column;align-items:center;gap:6px;">
        <div class="verdict-vs">VS</div>
        <?php if (!$draw): ?>
        <div style="font-size:10px;color:var(--gray-400);text-align:center;max-width:120px;">
          <?= $winA?htmlspecialchars($shortA):htmlspecialchars($shortB) ?> meilleur sur <?= max($criteresA,$criteresB) ?>/<?= $totalCriteres ?: 4 ?> critères
        </div>
        <?php else: ?>
        <div style="font-size:10px;color:var(--gray-400);">Performance équivalente</div>
        <?php endif; ?>
      </div>

      <!-- Entité B -->
      <div class="verdict-entity">
        <div class="verdict-score" style="color:#6CB4EE;"><?= $criteresB ?></div>
        <div class="verdict-label">critère<?= $criteresB>1?'s':'' ?> gagné<?= $criteresB>1?'s':'' ?></div>
        <div style="margin-top:8px;">
          <span class="verdict-badge <?= $winB?'verdict-win':($draw?'verdict-draw':'verdict-lose') ?>">
            <?= $winB?'✓ Meilleur':($draw?'Égalité':'—') ?>
          </span>
        </div>
        <div style="font-size:11px;font-weight:600;color:var(--gray-700);margin-top:6px;text-align:center;"><?= htmlspecialchars($shortB) ?></div>
      </div>
    </div>

    <!-- Tableau différentiel + ICA double jauge -->
    <div class="two-col-3">

      <!-- Diff table -->
      <div class="card" style="grid-column:span 2;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
          <div class="card-title" style="margin:0;">Tableau différentiel</div>
          <span class="pill-tag"><?= $selectedYear ?> · <?= $selectedDelai ?> mois</span>
        </div>
        <table class="diff-table">
          <thead><tr>
            <th style="width:100px;">Indicateur</th>
            <th style="text-align:right;color:#000091;">A — <?= htmlspecialchars(mb_strlen($nameA)>20?mb_substr($nameA,0,18).'…':$nameA) ?></th>
            <th class="center" style="width:80px;">Écart A–B</th>
            <th style="text-align:right;color:#6CB4EE;">B — <?= htmlspecialchars(mb_strlen($nameB)>20?mb_substr($nameB,0,18).'…':$nameB) ?></th>
          </tr></thead>
          <tbody>
          <?php
          $diffDefs = [
            ['label'=>"Taux d'emploi", 'key'=>'taux_emploi', 'suf'=>'%', 'max'=>100, 'money'=>false,
             'tt'=>"Diplômés en emploi · ".$selectedDelai." mois après le master"],
            ['label'=>'Taux CDI',     'key'=>'taux_cdi',    'suf'=>'%', 'max'=>100, 'money'=>false,
             'tt'=>"Emplois stables (CDI, fonctionnaire)"],
            ['label'=>'Taux cadre',   'key'=>'taux_cadre',  'suf'=>'%', 'max'=>100, 'money'=>false,
             'tt'=>"Cadres et professions intermédiaires supérieures"],
            ['label'=>'Salaire',      'key'=>'salaire',     'suf'=>'€', 'max'=>0,   'money'=>true,
             'tt'=>"Salaire net mensuel médian · temps plein"],
          ];
          foreach ($diffDefs as $d):
            $vA = $dataA['kpi'][$d['key']] ?? null;
            $vB = $dataB['kpi'][$d['key']] ?? null;
            $dl = delta($vA, $vB);
            $dispA = $d['money'] ? fmtSal($vA) : fmt($vA,$d['suf']);
            $dispB = $d['money'] ? fmtSal($vB) : fmt($vB,$d['suf']);
            $dlStr = $dl!==null?(($dl>0?'+':'').$dl.($d['money']?'€':'%')):'—';
            $dlClass = $dl===null?'zero':($dl>0?'pos':($dl<0?'neg':'zero'));
            $maxV = $d['money'] ? max((float)($vA??0),(float)($vB??0),1) : ($d['max']>0?$d['max']:max((float)($vA??0),(float)($vB??0),1));
            $pA = $maxV>0?min(100,round((float)($vA??0)/$maxV*100)):0;
            $pB = $maxV>0?min(100,round((float)($vB??0)/$maxV*100)):0;
            $winnerA = $vA!==null&&$vB!==null&&$vA>=$vB;
            $ttRows = json_encode([
              ['k'=>'A — '.mb_substr($nameA,0,20), 'v'=>$dispA],
              ['k'=>'B — '.mb_substr($nameB,0,20), 'v'=>$dispB],
              ['k'=>'Écart A–B', 'v'=>$dlStr, 'divider'=>true],
            ]);
          ?>
          <tr data-tt-title="<?= htmlspecialchars($d['label']) ?>"
              data-tt-subtitle="<?= htmlspecialchars($d['tt']) ?>"
              data-tt-dot="<?= $winnerA?'#000091':'#6CB4EE' ?>"
              data-tt-rows='<?= htmlspecialchars($ttRows) ?>'>
            <td class="diff-label"><?= $d['label'] ?></td>
            <td>
              <div class="diff-val A" style="<?= ($vA!==null&&$vB!==null&&$vA>$vB)?'font-size:14px;':'' ?>"><?= $dispA ?></div>
              <div class="diff-bar-wrap"><div class="diff-bar A" style="width:<?= $pA ?>%;"></div></div>
            </td>
            <td class="diff-delta <?= $dlClass ?>"><?= $dlStr ?></td>
            <td>
              <div class="diff-val B" style="text-align:right;<?= ($vB!==null&&$vA!==null&&$vB>$vA)?'font-size:14px;':'' ?>"><?= $dispB ?></div>
              <div class="diff-bar-wrap" style="flex-direction:row-reverse;"><div class="diff-bar B" style="width:<?= $pB ?>%;"></div></div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Double jauge ICA -->
      <div class="card">
        <div class="card-title">Indice ICA</div>
        <div class="gauge-wrap">
          <?php foreach (['A','B'] as $side):
            $data = $side==='A' ? $dataA : $dataB;
            $name = $side==='A' ? $nameA : $nameB;
            $ica  = $data['ica'];
            $color = $side==='A' ? '#000091' : '#6CB4EE';
          ?>
          <div>
            <div class="gauge-row"
                 data-tt-title="ICA — <?= htmlspecialchars(mb_substr($name,0,30)) ?>"
                 data-tt-subtitle="Emploi 40% · CDI 20% · Cadre 20% · Salaire 20%"
                 data-tt-dot="<?= $color ?>"
                 data-tt-rows='<?= htmlspecialchars(json_encode([["k"=>"Score ICA","v"=>$ica!==null?$ica."/100":"—"]])) ?>'>
              <div class="gauge-tag" style="background:<?= $color ?>;"><?= $side ?></div>
              <div class="gauge-track"><div class="gauge-fill" style="width:<?= $ica??0 ?>%;background:<?= $color ?>;"></div></div>
              <div class="gauge-val" style="color:<?= $color ?>;"><?= $ica??'—' ?></div>
            </div>
            <div class="gauge-label" style="font-size:10px;color:var(--gray-400);margin-top:2px;"><?= htmlspecialchars(mb_strlen($name)>30?mb_substr($name,0,28).'…':$name) ?></div>
          </div>
          <?php endforeach; ?>
          <div style="border-top:1px solid var(--gray-100);padding-top:12px;margin-top:4px;">
            <div style="font-size:10px;color:var(--gray-400);line-height:1.6;">
              <div>Emploi · 40%</div>
              <div>CDI · 20%</div>
              <div>Cadre · 20%</div>
              <div>Salaire · 20%</div>
            </div>
          </div>
        </div>
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
</script>
<script src="script.js"></script>
</body>
</html>
