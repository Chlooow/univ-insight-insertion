<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    require_once '../connexion.php';

    $q = isset($_GET['q']) ? trim($_GET['q']) : '';

    if (strlen($q) < 2) {
        echo json_encode(['success' => true, 'etablissements' => [], 'formations' => []]);
        exit;
    }

    $searchTerm = '%' . $q . '%';
    $startTerm = $q . '%';

    // Recherche Établissements - Paramètres uniques pour éviter SQLSTATE[HY093]
    $stmtEtab = $pdo->prepare("
        SELECT id_etab, nom, ville 
        FROM ETABLISSEMENT 
        WHERE nom LIKE :q1 OR ville LIKE :q2 
        ORDER BY CASE WHEN nom LIKE :start THEN 1 ELSE 2 END, nom
        LIMIT 5
    ");
    $stmtEtab->execute([
        ':q1' => $searchTerm, 
        ':q2' => $searchTerm, 
        ':start' => $startTerm
    ]);
    $etablissements = $stmtEtab->fetchAll(PDO::FETCH_ASSOC);

    // Recherche Formations - Paramètres uniques
    $stmtForm = $pdo->prepare("
        SELECT dip.id_diplome, dip.intitule, dis.nom AS discipline, e.nom AS etab_nom
        FROM DIPLOME dip
        JOIN DISCIPLINE dis ON dis.id_disc = dip.id_disc
        JOIN ETABLISSEMENT e ON e.id_etab = dip.id_etab
        WHERE dip.intitule LIKE :q1 OR dis.nom LIKE :q2 OR e.nom LIKE :q3
        ORDER BY CASE WHEN dip.intitule LIKE :start THEN 1 ELSE 2 END, dip.intitule
        LIMIT 10
    ");
    $stmtForm->execute([
        ':q1' => $searchTerm, 
        ':q2' => $searchTerm, 
        ':q3' => $searchTerm, 
        ':start' => $startTerm
    ]);
    $formations = $stmtForm->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'etablissements' => $etablissements,
        'formations' => $formations
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
