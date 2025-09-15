<?php
// Formulaire d'inscription sécurisé - Version simplifiée
session_start();

// Configuration sécurisée
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Vérification méthode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'message' => 'Méthode non autorisée'));
    exit();
}

// Fonction de nettoyage des données
function nettoyer_donnee($donnee) {
    if (!is_string($donnee)) {
        return '';
    }
    $donnee = trim($donnee);
    $donnee = strip_tags($donnee);
    $donnee = htmlspecialchars($donnee, ENT_QUOTES, 'UTF-8');
    return $donnee;
}

// Fonction de validation email
function valider_email($email) {
    $email = nettoyer_donnee($email);
    if (empty($email)) {
        return false;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Fonction de validation date
function valider_date($date) {
    $date = nettoyer_donnee($date);
    if (empty($date) || strlen($date) !== 10) {
        return false;
    }
    
    $parties = explode('-', $date);
    if (count($parties) !== 3) {
        return false;
    }
    
    $annee = (int)$parties[0];
    $mois = (int)$parties[1];
    $jour = (int)$parties[2];
    
    if ($annee < 1900 || $annee > date('Y')) {
        return false;
    }
    
    return checkdate($mois, $jour, $annee);
}

// Fonction de validation téléphone
function valider_telephone($telephone) {
    $telephone = nettoyer_donnee($telephone);
    $telephone = preg_replace('/[^0-9+\-\s()]/', '', $telephone);
    
    if (strlen($telephone) < 8 || strlen($telephone) > 20) {
        return false;
    }
    
    return $telephone;
}

// Récupération et nettoyage des données
$donnees = array();
$champs_requis = array(
    'nomEnfant' => 'Nom de l\'enfant',
    'prenomEnfant' => 'Prénom de l\'enfant',
    'dateNaissance' => 'Date de naissance',
    'lieuNaissance' => 'Lieu de naissance',
    'sexe' => 'Sexe',
    'classe' => 'Classe',
    'nomParent1' => 'Nom du parent',
    'prenomParent1' => 'Prénom du parent',
    'emailParent1' => 'Email du parent',
    'telephoneParent1' => 'Téléphone du parent',
    'adresse' => 'Adresse'
);

$champs_optionnels = array(
    'ancienneEcole',
    'besoinsParticuliers',
    'nomParent2',
    'prenomParent2',
    'emailParent2',
    'telephoneParent2',
    'professionParent1',
    'professionParent2'
);

$erreurs = array();

// Validation des champs requis
foreach ($champs_requis as $champ => $libelle) {
    $valeur = isset($_POST[$champ]) ? nettoyer_donnee($_POST[$champ]) : '';
    
    if (empty($valeur)) {
        $erreurs[] = $libelle . ' est requis';
        continue;
    }
    
    // Validations spécifiques
    if ($champ === 'dateNaissance') {
        if (!valider_date($valeur)) {
            $erreurs[] = 'Date de naissance invalide';
        } else {
            $donnees[$champ] = $valeur;
        }
    } elseif ($champ === 'emailParent1') {
        $email_valide = valider_email($valeur);
        if ($email_valide === false) {
            $erreurs[] = 'Email du parent invalide';
        } else {
            $donnees[$champ] = $email_valide;
        }
    } elseif ($champ === 'telephoneParent1') {
        $tel_valide = valider_telephone($valeur);
        if ($tel_valide === false) {
            $erreurs[] = 'Numéro de téléphone invalide';
        } else {
            $donnees[$champ] = $tel_valide;
        }
    } elseif ($champ === 'sexe') {
        if ($valeur !== 'M' && $valeur !== 'F') {
            $erreurs[] = 'Sexe invalide';
        } else {
            $donnees[$champ] = $valeur;
        }
    } else {
        $donnees[$champ] = $valeur;
    }
}

// Validation des champs optionnels
foreach ($champs_optionnels as $champ) {
    $valeur = isset($_POST[$champ]) ? nettoyer_donnee($_POST[$champ]) : '';
    
    if (!empty($valeur)) {
        if ($champ === 'emailParent2') {
            $email_valide = valider_email($valeur);
            if ($email_valide === false) {
                $erreurs[] = 'Email du parent 2 invalide';
            } else {
                $donnees[$champ] = $email_valide;
            }
        } elseif ($champ === 'telephoneParent2') {
            $tel_valide = valider_telephone($valeur);
            if ($tel_valide === false) {
                $erreurs[] = 'Téléphone du parent 2 invalide';
            } else {
                $donnees[$champ] = $tel_valide;
            }
        } else {
            $donnees[$champ] = $valeur;
        }
    } else {
        $donnees[$champ] = '';
    }
}

// Si erreurs de validation
if (!empty($erreurs)) {
    http_response_code(400);
    echo json_encode(array(
        'success' => false, 
        'message' => 'Données invalides',
        'errors' => $erreurs
    ));
    exit();
}

// Configuration base de données - À MODIFIER selon vos paramètres
$host = 'localhost';
$dbname = 'votre_base_de_donnees';
$username = 'votre_utilisateur';
$password = 'votre_mot_de_passe';

// Connexion et enregistrement
try {
    $dsn = "mysql:host=" . $host . ";dbname=" . $dbname . ";charset=utf8mb4";
    $options = array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    );
    
    $pdo = new PDO($dsn, $username, $password, $options);
    
    // Début transaction
    $pdo->beginTransaction();
    
    $date_actuelle = date('Y-m-d H:i:s');
    
    // Insertion enfant
    $sql_enfant = "INSERT INTO enfants (nom, prenom, date_naissance, lieu_naissance, sexe, classe, ancienne_ecole, besoins_particuliers, date_inscription) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt_enfant = $pdo->prepare($sql_enfant);
    $resultat_enfant = $stmt_enfant->execute(array(
        $donnees['nomEnfant'],
        $donnees['prenomEnfant'],
        $donnees['dateNaissance'],
        $donnees['lieuNaissance'],
        $donnees['sexe'],
        $donnees['classe'],
        $donnees['ancienneEcole'],
        $donnees['besoinsParticuliers'],
        $date_actuelle
    ));
    
    if (!$resultat_enfant) {
        throw new Exception('Erreur insertion enfant');
    }
    
    $enfant_id = $pdo->lastInsertId();
    
    // Insertion parent 1
    $sql_parent1 = "INSERT INTO parents (enfant_id, nom, prenom, email, telephone, profession, type_parent, date_creation) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt_parent1 = $pdo->prepare($sql_parent1);
    $resultat_parent1 = $stmt_parent1->execute(array(
        $enfant_id,
        $donnees['nomParent1'],
        $donnees['prenomParent1'],
        $donnees['emailParent1'],
        $donnees['telephoneParent1'],
        $donnees['professionParent1'],
        'parent1',
        $date_actuelle
    ));
    
    if (!$resultat_parent1) {
        throw new Exception('Erreur insertion parent 1');
    }
    
    // Insertion parent 2 si données présentes
    if (!empty($donnees['nomParent2']) && !empty($donnees['prenomParent2'])) {
        $sql_parent2 = "INSERT INTO parents (enfant_id, nom, prenom, email, telephone, profession, type_parent, date_creation) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt_parent2 = $pdo->prepare($sql_parent2);
        $resultat_parent2 = $stmt_parent2->execute(array(
            $enfant_id,
            $donnees['nomParent2'],
            $donnees['prenomParent2'],
            $donnees['emailParent2'],
            $donnees['telephoneParent2'],
            $donnees['professionParent2'],
            'parent2',
            $date_actuelle
        ));
        
        if (!$resultat_parent2) {
            throw new Exception('Erreur insertion parent 2');
        }
    }
    
    // Insertion adresse
    $sql_adresse = "INSERT INTO adresses (enfant_id, adresse_complete, date_creation) VALUES (?, ?, ?)";
    
    $stmt_adresse = $pdo->prepare($sql_adresse);
    $resultat_adresse = $stmt_adresse->execute(array(
        $enfant_id,
        $donnees['adresse'],
        $date_actuelle
    ));
    
    if (!$resultat_adresse) {
        throw new Exception('Erreur insertion adresse');
    }
    
    // Validation transaction
    $pdo->commit();
    
    // Réponse succès
    http_response_code(200);
    echo json_encode(array(
        'success' => true,
        'message' => 'Inscription enregistrée avec succès',
        'data' => array(
            'inscription_id' => $enfant_id,
            'enfant' => $donnees['prenomEnfant'] . ' ' . $donnees['nomEnfant']
        )
    ));
    
} catch (Exception $e) {
    // Annulation transaction en cas d'erreur
    if (isset($pdo)) {
        $pdo->rollback();
    }
    
    // Réponse erreur
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'message' => 'Une erreur est survenue lors de l\'enregistrement'
    ));
}
?>