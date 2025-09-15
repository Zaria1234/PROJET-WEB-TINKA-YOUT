<?php
// Affichage des erreurs PHP pour le debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Traitement du formulaire de contact
 * Ce fichier traite les données soumises via le formulaire de contact
 */
require_once 'database.php';
require_once 'functions.php';

// Log pour vérifier que le script est appelé
file_put_contents('debug_contact.log', date('c')." - Script appelé\n", FILE_APPEND);

// Vérifier que la requête est bien en POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    file_put_contents('debug_contact.log', date('c')." - Mauvaise méthode\n", FILE_APPEND);
    jsonResponse(false, 'Méthode non autorisée');
}

try {
    // Récupérer et nettoyer les données du formulaire
    $nom = cleanInput($_POST['nom'] ?? '');
    $email = cleanInput($_POST['email'] ?? '');
    $telephone = cleanInput($_POST['telephone'] ?? '');
    $sujet = cleanInput($_POST['sujet'] ?? '');
    $message = cleanInput($_POST['message'] ?? '');

    // Log les données reçues
    file_put_contents('debug_contact.log', date('c')." - Données reçues: nom=$nom, email=$email, tel=$telephone, sujet=$sujet, message=$message\n", FILE_APPEND);

    // Validation des champs obligatoires
    $errors = [];

    if (empty($nom)) $errors[] = "Le nom est obligatoire";
    if (empty($email)) $errors[] = "L'email est obligatoire";
    if (empty($sujet)) $errors[] = "Le sujet est obligatoire";
    if (empty($message)) $errors[] = "Le message est obligatoire";

    // Validation du format des données
    if (!empty($email) && !validateEmail($email)) {
        $errors[] = "Format d'email invalide";
    }

    if (!empty($telephone) && !validatePhone($telephone)) {
        $errors[] = "Format de téléphone invalide";
    }
    // Si des erreurs sont présentes, les retourner
    if (!empty($errors)) {
        file_put_contents('debug_contact.log', date('c')." - Erreurs: ".implode(', ', $errors)."\n", FILE_APPEND);
        jsonResponse(false, implode(', ', $errors));
    }

    // Connexion à la base de données
    $db = getDatabase();
    $pdo = $db->getConnection();

    if (!$pdo) {
        file_put_contents('debug_contact.log', date('c')." - Connexion PDO échouée\n", FILE_APPEND);
        jsonResponse(false, "Connexion à la base de données impossible");
    }
    //insertion des données dans ma base 
    try {
        // Insérer le message de contact avec du SQL
        $sql = "INSERT INTO contacts (nom, email, telephone, sujet, message, date_creation, statut) 
                VALUES (?, ?, ?, ?, ?, NOW(), 'nouveau')";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $nom,
            $email,
            $telephone,
            $sujet,
            $message
        ]);

        $contactId = $pdo->lastInsertId();
        file_put_contents('debug_contact.log', date('c')." - Insertion OK, id=$contactId\n", FILE_APPEND);

        // Réponse de succès après insertion
        jsonResponse(true, 'Message envoyé avec succès !', [
            'contact_id' => $contactId
        ]);

    } catch (Exception $e) {
        file_put_contents('debug_contact.log', date('c')." - Erreur SQL: ".$e->getMessage()."\n", FILE_APPEND);
        logError("Erreur lors de l'insertion du message de contact : " . $e->getMessage());
        jsonResponse(false, 'Erreur lors de l\'envoi du message: '.$e->getMessage());
    }

} catch (Exception $e) {
    file_put_contents('debug_contact.log', date('c')." - Erreur générale: ".$e->getMessage()."\n", FILE_APPEND);
    logError("Erreur générale lors du traitement du contact : " . $e->getMessage());
    jsonResponse(false, 'Une erreur inattendue s\'est produite: '.$e->getMessage());
}
?>