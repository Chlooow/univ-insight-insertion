<?php
// config.php - Connexion à la base de données MySQL

// Utilisation des variables d'environnement (Docker) ou valeurs par défaut (MAMP)
$host = getenv('DB_HOST') ?: 'localhost';        
$dbname = getenv('DB_NAME') ?: 'univ_insight';        
$username = getenv('DB_USER') ?: 'root';         
$password = getenv('DB_PASS') ?: 'root';             

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    // On lance une exception au lieu de die() pour que l'API puisse renvoyer du JSON
    throw new Exception("Erreur de connexion : " . $e->getMessage());
}
?>