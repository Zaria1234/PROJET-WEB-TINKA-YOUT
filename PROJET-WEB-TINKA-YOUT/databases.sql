-- Schéma de base de données pour l'École Tinka-Tout
-- Base de données MySQL pour gérer les inscriptions et les contacts

-- Création de la base de données
CREATE DATABASE IF NOT EXISTS ecole_tinka_tout 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE ecole_tinka_tout;

-- Table des enfants inscrits
CREATE TABLE IF NOT EXISTS enfants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    date_naissance DATE NOT NULL,
    lieu_naissance VARCHAR(255) NOT NULL,
    sexe ENUM('masculin', 'feminin') NOT NULL,
    classe ENUM('maternelle', 'CP1', 'CP2', 'CE1', 'CE2', 'CM1', 'CM2') NOT NULL,
    ancienne_ecole VARCHAR(255),
    besoins_particuliers TEXT,
    date_inscription TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('en_attente', 'accepte', 'refuse') DEFAULT 'en_attente',
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Table des parents
CREATE TABLE IF NOT EXISTS parents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enfant_id INT NOT NULL,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    telephone VARCHAR(20) NOT NULL,
    profession VARCHAR(255),
    type_parent ENUM('parent1', 'parent2') NOT NULL,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (enfant_id) REFERENCES enfants(id) ON DELETE CASCADE,
    INDEX idx_enfant_parent (enfant_id, type_parent),
    INDEX idx_email (email)
) ENGINE=InnoDB;

-- Table des adresses
CREATE TABLE IF NOT EXISTS adresses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enfant_id INT NOT NULL,
    adresse_complete TEXT NOT NULL,
    ville VARCHAR(100) DEFAULT 'Lomé',
    pays VARCHAR(100) DEFAULT 'Togo',
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (enfant_id) REFERENCES enfants(id) ON DELETE CASCADE,
    INDEX idx_enfant_adresse (enfant_id)
) ENGINE=InnoDB;

-- Table des messages de contact
CREATE TABLE IF NOT EXISTS contacts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    telephone VARCHAR(20),
    sujet ENUM('inscription', 'information', 'rendezvous', 'autre') NOT NULL,
    message TEXT NOT NULL,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('nouveau', 'en_cours', 'traite', 'ferme') DEFAULT 'nouveau',
    reponse TEXT,
    date_reponse TIMESTAMP NULL,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_statut (statut),
    INDEX idx_sujet (sujet),
    INDEX idx_date_creation (date_creation)
) ENGINE=InnoDB;

-- Table des logs d'activité (optionnel)
CREATE TABLE IF NOT EXISTS logs_activite (
    id INT PRIMARY KEY AUTO_INCREMENT,
    table_concernee VARCHAR(50) NOT NULL,
    id_enregistrement INT NOT NULL,
    action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    ancien_contenu JSON,
    nouveau_contenu JSON,
    utilisateur VARCHAR(100),
    adresse_ip VARCHAR(45),
    date_action TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_table_id (table_concernee, id_enregistrement),
    INDEX idx_date_action (date_action)
) ENGINE=InnoDB;

-- Vues utiles pour les requêtes
-- Vue complète des inscriptions
CREATE OR REPLACE VIEW vue_inscriptions_completes AS
SELECT 
    e.id as enfant_id,
    e.nom as enfant_nom,
    e.prenom as enfant_prenom,
    e.date_naissance,
    e.lieu_naissance,
    e.sexe,
    e.classe,
    e.ancienne_ecole,
    e.besoins_particuliers,
    e.statut,
    e.date_inscription,
    
    p1.nom as parent1_nom,
    p1.prenom as parent1_prenom,
    p1.email as parent1_email,
    p1.telephone as parent1_telephone,
    p1.profession as parent1_profession,
    
    p2.nom as parent2_nom,
    p2.prenom as parent2_prenom,
    p2.email as parent2_email,
    p2.telephone as parent2_telephone,
    p2.profession as parent2_profession,
    
    a.adresse_complete
    
FROM enfants e
LEFT JOIN parents p1 ON e.id = p1.enfant_id AND p1.type_parent = 'parent1'
LEFT JOIN parents p2 ON e.id = p2.enfant_id AND p2.type_parent = 'parent2'
LEFT JOIN adresses a ON e.id = a.enfant_id;

-- Vue des contacts par statut
CREATE OR REPLACE VIEW vue_contacts_resume AS
SELECT 
    id,
    nom,
    email,
    sujet,
    LEFT(message, 100) as extrait_message,
    statut,
    date_creation,
    CASE 
        WHEN date_reponse IS NOT NULL THEN TIMESTAMPDIFF(HOUR, date_creation, date_reponse)
        ELSE TIMESTAMPDIFF(HOUR, date_creation, NOW())
    END as heures_depuis_creation
FROM contacts
ORDER BY date_creation DESC;

-- Procédure stockée pour obtenir des statistiques
DELIMITER //
CREATE PROCEDURE GetStatistiques()
BEGIN
    SELECT 
        'Total inscriptions' as type,
        COUNT(*) as nombre
    FROM enfants
    
    UNION ALL
    
    SELECT 
        CONCAT('Inscriptions - ', statut) as type,
        COUNT(*) as nombre
    FROM enfants
    GROUP BY statut
    
    UNION ALL
    
    SELECT 
        CONCAT('Contacts - ', statut) as type,
        COUNT(*) as nombre
    FROM contacts
    GROUP BY statut
    
    UNION ALL
    
    SELECT 
        CONCAT('Classe - ', classe) as type,
        COUNT(*) as nombre
    FROM enfants
    GROUP BY classe;
END //
DELIMITER ;

-- Index pour optimiser les performances
CREATE INDEX idx_enfant_classe_statut ON enfants(classe, statut);
CREATE INDEX idx_enfant_date_inscription ON enfants(date_inscription);
CREATE INDEX idx_contact_date_statut ON contacts(date_creation, statut);