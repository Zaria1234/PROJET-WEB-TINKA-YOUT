<?php
/**
 * Configuration de la base de données
 * Fichier de connexion à la base de données MySQL
 */

// Paramètres de connexion à la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'ecole_tinka_tout');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Classe pour gérer la connexion à la base de données
 */
class Database {
    private $connection;
    
    public function __construct() {
        $this->connect();
    }
    
    /**
     * Établir la connexion à la base de données
     */
    private function connect() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $this->connection = new PDO($dsn, DB_USER, DB_PASS);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die("Erreur de connexion à la base de données : " . $e->getMessage());
        }
    }
    
    /**
     * Récupérer la connexion PDO
     */
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * Fermer la connexion
     */
    public function close() {
        $this->connection = null;
    }
}

/**
 * Fonction pour obtenir une nouvelle connexion à la base de données
 */
function getDatabase() {
    return new Database();
}
?>