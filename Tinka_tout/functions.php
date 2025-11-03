<?php
/**
 * Fonctions utilitaires pour le traitement des formulaires
 */

/**
 * Nettoyer et valider les données d'entrée
 */
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Valider une adresse email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Valider un numéro de téléphone
 */
function validatePhone($phone) {
    // Regex pour valider un numéro de téléphone (format flexible)
    return preg_match('/^[\+]?[0-9\s\-\(\)]{8,20}$/', $phone);
}

/**
 * Valider une date
 */
function validateDate($date, $format = 'Y-m-d') {
    $dateTime = DateTime::createFromFormat($format, $date);
    return $dateTime && $dateTime->format($format) === $date;
}

/**
 * Générer une réponse JSON
 */
function jsonResponse($success, $message, $data = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

/**
 * Envoyer un email de notification (fonction basique)
 */
function sendNotificationEmail($to, $subject, $message) {
    $headers = "From: noreply@ecole-tinka-tout.tg\r\n";
    $headers .= "Reply-To: contact@ecole-tinka-tout.tg\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    return mail($to, $subject, $message, $headers);
}

/**
 * Logger les erreurs dans un fichier
 */
function logError($message) {
    $logFile = __DIR__ . '/../logs/error.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message" . PHP_EOL;
    
    // Créer le dossier logs s'il n'existe pas
    if (!file_exists(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}
?>