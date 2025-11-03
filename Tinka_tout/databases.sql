-- Schéma de base de données pour l'École Tinka-Tout
-- Base de données MySQL pour gérer les inscriptions et les contacts

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

-- Index pour optimiser les performances
CREATE INDEX idx_enfant_classe_statut ON enfants(classe, statut);
CREATE INDEX idx_enfant_date_inscription ON enfants(date_inscription);
CREATE INDEX idx_contact_date_statut ON contacts(date_creation, statut);
