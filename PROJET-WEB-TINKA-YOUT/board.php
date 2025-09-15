<?php
/**
 * Tableau de bord administrateur - Tinka-Tout
 * Version corrigée avec gestion améliorée des statuts et filtres
 */

// Traitement AJAX pour la mise à jour du statut
if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json');
    
    try {
        // Inclusion du fichier de base de données
        require_once 'database.php';
        
        // Connexion à la base de données
        $db = getDatabase();
        $pdo = $db->getConnection();
        
        $id = intval($_POST['id']);
        $new_status = trim($_POST['status']);
        
        // Validation des données
        if (empty($id) || empty($new_status)) {
            throw new Exception('Données manquantes : ID ou statut non fourni');
        }
        
        // Valeurs de statut autorisées avec normalisation CORRIGÉE
        $status_mapping = [
            'en_attente' => 'en_attente',
            'pending' => 'en_attente',
            'confirme' => 'accepte',
            'confirmed' => 'accepte',
            'accepte' => 'accepte',
            'refuse' => 'refuse',
            'canceled' => 'refuse',
            'rejected' => 'refuse'
        ];
        
        if (!array_key_exists($new_status, $status_mapping)) {
            throw new Exception('Statut non autorisé : ' . $new_status);
        }
        
        // Normaliser le statut selon la base de données
        $normalized_status = $status_mapping[$new_status];
        
        // Déterminer la table des inscriptions
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $inscription_tables = ['inscriptions', 'inscription', 'enfants', 'registrations', 'eleves'];
        $inscription_table = null;
        
        foreach ($inscription_tables as $table) {
            if (in_array($table, $tables)) {
                $inscription_table = $table;
                break;
            }
        }
        
        if (!$inscription_table) {
            throw new Exception('Aucune table d\'inscriptions trouvée');
        }
        
        // Vérifier que l'enregistrement existe et récupérer le statut actuel
        $check_stmt = $pdo->prepare("SELECT * FROM $inscription_table WHERE id = ?");
        $check_stmt->execute([$id]);
        $current_record = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$current_record) {
            throw new Exception("Inscription avec l'ID $id non trouvée");
        }
        
        // Déterminer la colonne de statut
        $stmt = $pdo->query("DESCRIBE $inscription_table");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $status_col = 'statut';
        if (in_array('statut', $columns)) {
            $status_col = 'statut';
        } elseif (in_array('status', $columns)) {
            $status_col = 'status';
        } elseif (in_array('etat', $columns)) {
            $status_col = 'etat';
        } else {
            throw new Exception("Aucune colonne de statut trouvée dans la table $inscription_table");
        }
        
        $current_status = $current_record[$status_col] ?? '';
        
        if ($current_status === $normalized_status) {
            echo json_encode([
                'success' => true, 
                'message' => 'Le statut était déjà à jour',
                'new_status' => $normalized_status,
                'no_change' => true
            ]);
            exit;
        }
        
        $pdo->beginTransaction();
        
        try {
            $update_stmt = $pdo->prepare("UPDATE $inscription_table SET $status_col = ? WHERE id = ?");
            $success = $update_stmt->execute([$normalized_status, $id]);
            
            if (!$success || $update_stmt->rowCount() === 0) {
                throw new Exception('Aucune ligne n\'a été modifiée');
            }
            
            $verify_stmt = $pdo->prepare("SELECT $status_col FROM $inscription_table WHERE id = ?");
            $verify_stmt->execute([$id]);
            $updated_status = $verify_stmt->fetchColumn();
            
            if ($updated_status !== $normalized_status) {
                throw new Exception('La vérification de la mise à jour a échoué');
            }
            
            $stats_stmt = $pdo->prepare("
                SELECT 
                    $status_col as statut,
                    COUNT(*) as count 
                FROM $inscription_table 
                GROUP BY $status_col
            ");
            $stats_stmt->execute();
            $stats_raw = $stats_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $new_stats = [
                'en_attente' => 0,
                'confirmees' => 0,
                'refusees' => 0
            ];
            
            foreach ($stats_raw as $status => $count) {
                $normalized = strtolower(trim($status));
                switch ($normalized) {
                    case 'en_attente':
                    case 'pending':
                        $new_stats['en_attente'] += $count;
                        break;
                    case 'accepte':
                    case 'confirmed':
                    case 'confirme':
                    case 'valide':
                        $new_stats['confirmees'] += $count;
                        break;
                    case 'refuse':
                    case 'canceled':
                    case 'rejected':
                    case 'annule':
                        $new_stats['refusees'] += $count;
                        break;
                }
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Statut mis à jour avec succès',
                'new_status' => $normalized_status,
                'old_status' => $current_status,
                'table' => $inscription_table,
                'column' => $status_col,
                'stats' => $new_stats,
                'record_id' => $id
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        error_log("Erreur mise à jour statut: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Erreur : ' . $e->getMessage()
        ]);
    }
    
    exit;
}

// Inclusion des fichiers de base de données
require_once 'database.php';

if (file_exists('functions.php')) {
    require_once 'functions.php';
}

// Vérification d'accès basique
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    // Pour le développement, on peut commenter cette ligne
    // header('Location: login.php');
    // exit;
}

try {
    $db = getDatabase();
    $pdo = $db->getConnection();
    
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    $stats = [
        'total_inscriptions' => 0,
        'total_contacts' => 0,
        'en_attente' => 0,
        'confirmees' => 0,
        'refusees' => 0,
        'nouvelles_inscriptions' => 0,
        'nouveaux_messages' => 0
    ];
    
    $inscription_tables = ['inscriptions', 'inscription', 'enfants', 'registrations'];
    $contact_tables = ['contacts', 'contact', 'messages', 'formulaire_contact'];
    $parent_tables = ['parents', 'parent', 'tuteurs'];
    
    $inscription_table = null;
    $contact_table = null;
    $parent_table = null;
    
    foreach ($inscription_tables as $table) {
        if (in_array($table, $tables)) {
            $inscription_table = $table;
            break;
        }
    }
    
    foreach ($contact_tables as $table) {
        if (in_array($table, $tables)) {
            $contact_table = $table;
            break;
        }
    }
    
    foreach ($parent_tables as $table) {
        if (in_array($table, $tables)) {
            $parent_table = $table;
            break;
        }
    }
    
    // Récupérer les statistiques des inscriptions
    if ($inscription_table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $inscription_table");
        $stats['total_inscriptions'] = $stmt->fetchColumn();
        
        try {
            $stmt = $pdo->query("DESCRIBE $inscription_table");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (in_array('statut', $columns) || in_array('status', $columns)) {
                $status_col = in_array('statut', $columns) ? 'statut' : 'status';
                $stmt = $pdo->prepare("SELECT $status_col, COUNT(*) as count FROM $inscription_table GROUP BY $status_col");
                $stmt->execute();
                $statuts_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                
                foreach ($statuts_raw as $status => $count) {
                    $normalized = strtolower(trim($status));
                    switch ($normalized) {
                        case 'en_attente':
                        case 'pending':
                        case 'attente':
                            $stats['en_attente'] += $count;
                            break;
                        case 'accepte':
                        case 'confirmed':
                        case 'confirme':
                        case 'valide':
                            $stats['confirmees'] += $count;
                            break;
                        case 'refuse':
                        case 'canceled':
                        case 'rejected':
                        case 'annule':
                            $stats['refusees'] += $count;
                            break;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Erreur calcul statistiques: " . $e->getMessage());
        }
        
        try {
            if (in_array('date_inscription', $columns) || in_array('created_at', $columns)) {
                $date_col = in_array('date_inscription', $columns) ? 'date_inscription' : 'created_at';
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM $inscription_table WHERE $date_col >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
                $stmt->execute();
                $stats['nouvelles_inscriptions'] = $stmt->fetchColumn();
            }
        } catch (Exception $e) {
            // Si erreur, continuer
        }
    }
    
    if ($contact_table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $contact_table");
        $stats['total_contacts'] = $stmt->fetchColumn();
        
        try {
            $stmt = $pdo->query("DESCRIBE $contact_table");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (in_array('date_creation', $columns) || in_array('created_at', $columns)) {
                $date_col = in_array('date_creation', $columns) ? 'date_creation' : 'created_at';
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM $contact_table WHERE $date_col >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
                $stmt->execute();
                $stats['nouveaux_messages'] = $stmt->fetchColumn();
            }
        } catch (Exception $e) {
            // Si erreur, continuer
        }
    }
    
    $inscriptions = [];
    if ($inscription_table) {
        try {
            $stmt = $pdo->query("DESCRIBE $inscription_table");
            $inscription_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Construction de la requête améliorée
            $select_parts = ["i.id", "i.statut", "i.status", "i.date_inscription", "i.created_at"];
            $joins = [];
            
            // Ajouter les colonnes directes de la table inscriptions
            $direct_columns = ['nom', 'prenom', 'email', 'telephone', 'nom_pere', 'prenom_pere', 'email_pere', 
                             'nom_mere', 'prenom_mere', 'email_mere', 'nom_parent', 'prenom_parent', 'email_parent',
                             'nom_enfant', 'prenom_enfant', 'classe_enfant', 'age_enfant', 'date_naissance_enfant'];
            
            foreach ($direct_columns as $col) {
                if (in_array($col, $inscription_columns)) {
                    $select_parts[] = "i.$col";
                }
            }
            
            // JOIN avec la table enfants si elle existe et qu'il y a une référence
            if (in_array('enfants', $tables)) {
                $enfant_id_columns = ['enfant_id', 'child_id', 'id_enfant'];
                foreach ($enfant_id_columns as $col) {
                    if (in_array($col, $inscription_columns)) {
                        $select_parts[] = "e.nom as enfant_nom, e.prenom as enfant_prenom, e.date_naissance, e.classe, e.age";
                        $joins[] = "LEFT JOIN enfants e ON i.$col = e.id";
                        break;
                    }
                }
            }
            
            // JOIN avec la table parents si elle existe
            if ($parent_table) {
                $parent_id_columns = ['parent_id', 'parent1_id', 'id_parent', 'pere_id', 'mother_id'];
                foreach ($parent_id_columns as $col) {
                    if (in_array($col, $inscription_columns)) {
                        $select_parts[] = "p.nom as parent_nom, p.prenom as parent_prenom, p.email as parent_email, p.telephone as parent_telephone";
                        $joins[] = "LEFT JOIN $parent_table p ON i.$col = p.id";
                        break;
                    }
                }
            }
            
            $sql = "SELECT " . implode(", ", $select_parts) . " FROM $inscription_table i " . implode(" ", $joins);
            
            // Ordre de tri
            if (in_array('date_inscription', $inscription_columns)) {
                $sql .= " ORDER BY i.date_inscription DESC";
            } elseif (in_array('created_at', $inscription_columns)) {
                $sql .= " ORDER BY i.created_at DESC";
            } elseif (in_array('id', $inscription_columns)) {
                $sql .= " ORDER BY i.id DESC";
            }
            
            $sql .= " LIMIT 50"; // Augmenté pour permettre le filtrage
            
            $stmt = $pdo->query($sql);
            $inscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            // Fallback : requête simple
            $stmt = $pdo->query("SELECT * FROM $inscription_table ORDER BY id DESC LIMIT 50");
            $inscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    $contacts = [];
    if ($contact_table) {
        try {
            $stmt = $pdo->query("DESCRIBE $contact_table");
            $contact_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $select_fields = ["*"];
            
            if (in_array('message', $contact_columns)) {
                $select_fields[] = "LEFT(message, 100) as extrait_message";
            } elseif (in_array('contenu', $contact_columns)) {
                $select_fields[] = "LEFT(contenu, 100) as extrait_message";
            }
            
            $sql = "SELECT " . implode(", ", $select_fields) . " FROM $contact_table";
            
            if (in_array('date_creation', $contact_columns)) {
                $sql .= " ORDER BY date_creation DESC";
            } elseif (in_array('created_at', $contact_columns)) {
                $sql .= " ORDER BY created_at DESC";
            } elseif (in_array('id', $contact_columns)) {
                $sql .= " ORDER BY id DESC";
            }
            
            $sql .= " LIMIT 15";
            
            $stmt = $pdo->query($sql);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $stmt = $pdo->query("SELECT * FROM $contact_table LIMIT 15");
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
} catch (Exception $e) {
    $error_message = "Erreur de connexion à la base de données : " . $e->getMessage();
    error_log($error_message);
    $stats = array_fill_keys(array_keys($stats), 0);
    $inscriptions = [];
    $contacts = [];
}

function getStatusClass($statut) {
    if (empty($statut)) return 'pending';
    $statut = strtolower(trim($statut));
    switch ($statut) {
        case 'accepte':
        case 'confirmed':
        case 'confirme':
        case 'valide':
            return 'confirmed';
        case 'en_attente':
        case 'pending':
        case 'attente':
            return 'pending';
        case 'refuse':
        case 'canceled':
        case 'annule':
        case 'rejete':
        case 'rejected':
            return 'canceled';
        default:
            return 'pending';
    }
}

function formatStatus($statut) {
    if (empty($statut)) return 'En attente';
    $statut = strtolower(trim($statut));
    switch ($statut) {
        case 'accepte':
        case 'confirmed':
        case 'confirme':
        case 'valide':
            return 'Confirmée';
        case 'en_attente':
        case 'pending':
        case 'attente':
            return 'En attente';
        case 'refuse':
        case 'canceled':
        case 'annule':
        case 'rejete':
        case 'rejected':
            return 'Refusée';
        default:
            return ucfirst($statut);
    }
}

function formatDate($date) {
    if (empty($date)) return 'Non renseignée';
    try {
        return date('d/m/Y à H:i', strtotime($date));
    } catch (Exception $e) {
        return $date;
    }
}

function getFieldValue($data, $possible_fields, $default = 'Non renseigné') {
    foreach ($possible_fields as $field) {
        if (isset($data[$field]) && !empty(trim($data[$field]))) {
            return trim($data[$field]);
        }
    }
    return $default;
}

$debug_mode = isset($_GET['debug']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - Admin | Tinka-Tout</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #1a2a6c;
            --secondary: #ff8c00;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #28a745;
            --info: #17a2b8;
            --warning: #ffc107;
            --danger: #dc3545;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --border-radius: 12px;
            --box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --sidebar-width: 280px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: var(--dark);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary) 0%, #2c4aa6 100%);
            color: white;
            padding: 2rem 0;
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transform: translateX(0);
            transition: var(--transition);
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
            opacity: 0;
            transition: var(--transition);
        }

        .sidebar-overlay.active {
            display: block;
            opacity: 1;
        }

        .logo {
            text-align: center;
            padding: 0 1.5rem 2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 2rem;
        }

        .logo h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--secondary);
        }

        .logo p {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-top: 0.5rem;
        }

        .nav-links {
            padding: 0 1.5rem;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
            transition: var(--transition);
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(5px);
        }

        .nav-links i {
            margin-right: 1rem;
            width: 20px;
            text-align: center;
        }

        .menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: var(--primary);
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            cursor: pointer;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
        }

        .menu-toggle:hover {
            transform: scale(1.1);
        }

        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 2rem;
            transition: var(--transition);
            min-height: 100vh;
        }

        .header {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header h1 {
            color: var(--primary);
            font-size: 2rem;
            font-weight: 700;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(26, 42, 108, 0.3);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: var(--gray);
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        }

        .stat-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .stat-card.primary .stat-icon { color: var(--primary); }
        .stat-card.success .stat-icon { color: var(--success); }
        .stat-card.warning .stat-icon { color: var(--warning); }
        .stat-card.danger .stat-icon { color: var(--danger); }

        .stat-info .number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .stat-info .label {
            color: var(--gray);
            font-weight: 500;
            font-size: 1.1rem;
        }

        .data-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .data-header {
            background: var(--light);
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .data-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .data-tab {
            padding: 0.75rem 1.5rem;
            background: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: var(--transition);
            white-space: nowrap;
        }

        .data-tab.active {
            background: var(--primary);
            color: white;
        }

        .data-content {
            padding: 2rem;
            display: none;
        }

        .data-content.active {
            display: block;
        }

        .filters-section {
            background: var(--light);
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--light-gray);
        }

        .filters-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--gray);
        }

        .filter-select {
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            background: white;
            font-size: 0.9rem;
            min-width: 150px;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(26, 42, 108, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .filter-btn.primary {
            background: var(--primary);
            color: white;
        }

        .filter-btn.secondary {
            background: var(--light-gray);
            color: var(--dark);
        }

        .filter-btn:hover {
            transform: translateY(-1px);
        }

        .results-summary {
            background: rgba(26, 42, 108, 0.05);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .results-count {
            font-weight: 600;
            color: var(--primary);
        }

        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            border: 1px solid var(--light-gray);
            max-width: 100%;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            min-width: 800px;
        }

        .table th {
            background: var(--light);
            padding: 1rem 1.5rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid var(--light-gray);
            white-space: nowrap;
        }

        .table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: top;
        }

        .table tr:hover {
            background: rgba(26, 42, 108, 0.02);
        }

        .table tr.hidden {
            display: none;
        }

        .status {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .status.pending {
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
        }

        .status.confirmed {
            background: rgba(40, 167, 69, 0.1);
            color: #155724;
        }

        .status.canceled {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
        }

        .actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: var(--transition);
            white-space: nowrap;
        }

        .action-btn.primary {
            background: var(--primary);
            color: white;
        }

        .action-btn.success {
            background: var(--success);
            color: white;
        }

        .action-btn.info {
            background: var(--info);
            color: white;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            opacity: 0.9;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeIn 0.3s ease forwards;
            padding: 1rem;
        }

        .modal-content {
            background: white;
            border-radius: var(--border-radius);
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease forwards;
        }

        .modal-header {
            padding: 2rem 2rem 1rem;
            border-bottom: 1px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .modal-header h2 {
            color: var(--primary);
            font-size: 1.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .modal-close:hover {
            background: var(--light-gray);
            color: var(--dark);
        }

        .modal-body {
            padding: 2rem;
        }

        .status-form {
            margin-top: 1rem;
        }

        .status-options {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .status-option {
            display: flex;
            align-items: center;
            padding: 1rem;
            border: 2px solid var(--light-gray);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
        }

        .status-option:hover {
            border-color: var(--primary);
            background: rgba(26, 42, 108, 0.05);
        }

        .status-option.selected {
            border-color: var(--primary);
            background: rgba(26, 42, 108, 0.1);
        }

        .status-option input[type="radio"] {
            margin-right: 1rem;
        }

        .status-info {
            flex: 1;
        }

        .status-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .status-description {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-50px) scale(0.95); }
            to { transform: translateY(0) scale(1); }
        }

        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.4); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
        }

        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
            }

            .header {
                padding: 1.5rem;
                margin-top: 4rem;
            }

            .header h1 {
                font-size: 1.5rem;
            }

            .data-header {
                padding: 1rem 1.5rem;
            }

            .data-content {
                padding: 1.5rem;
            }

            .table th,
            .table td {
                padding: 0.75rem 1rem;
            }

            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                width: 100%;
            }

            .filter-select {
                min-width: auto;
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 1.5rem;
            }

            .stat-icon {
                font-size: 2.5rem;
            }

            .stat-info .number {
                font-size: 2rem;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-actions {
                width: 100%;
                justify-content: stretch;
            }

            .btn {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }

            .data-tabs {
                flex-direction: column;
            }

            .data-tab {
                text-align: center;
            }

            .modal-content {
                margin: 1rem;
                width: calc(100% - 2rem);
            }

            .modal-header {
                padding: 1.5rem;
            }

            .modal-body {
                padding: 1.5rem;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.5rem;
            }

            .header {
                padding: 1rem;
                margin-top: 3.5rem;
            }

            .header h1 {
                font-size: 1.25rem;
            }

            .data-header {
                padding: 1rem;
            }

            .data-content {
                padding: 1rem;
            }

            .table th,
            .table td {
                padding: 0.5rem 0.75rem;
                font-size: 0.875rem;
            }

            .actions {
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
            }

            .status-option {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
            }

            .status-option input[type="radio"] {
                margin-right: 0;
                margin-bottom: 0.5rem;
            }

            .filters-section {
                padding: 1rem;
            }
        }
    </style>
</head>

<body>
    <?php if ($debug_mode): ?>
    <div style="background: #f0f0f0; padding: 15px; margin: 10px; border: 1px solid #ccc; border-radius: 5px;">
        <strong>🔧 Mode Debug activé</strong><br>
        <strong>Tables trouvées:</strong> <?= implode(', ', $tables ?? []) ?><br>
        <strong>Table inscriptions:</strong> <?= $inscription_table ?? '<span style="color:red;">non trouvée</span>' ?><br>
        <strong>Table contacts:</strong> <?= $contact_table ?? '<span style="color:red;">non trouvée</span>' ?><br>
        <strong>Table parents:</strong> <?= $parent_table ?? '<span style="color:red;">non trouvée</span>' ?><br>
        <strong>Stats calculées:</strong> <?= json_encode($stats) ?><br>
        <?php if (isset($error_message)): ?>
        <strong style="color: red;">Erreur:</strong> <?= htmlspecialchars($error_message) ?>
        <?php endif; ?>
        <?php if (!empty($inscriptions)): ?>
        <strong>Exemple statut inscription:</strong> <?= htmlspecialchars(getFieldValue($inscriptions[0] ?? [], ['statut', 'status', 'etat'], 'aucun')) ?><br>
        <strong>Colonnes disponibles:</strong> <?= implode(', ', array_keys($inscriptions[0] ?? [])) ?><br>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <button class="menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar-overlay" onclick="closeSidebar()"></div>

    <div class="dashboard">
        <aside class="sidebar" id="sidebar">
            <div class="logo">
                <h1>Tinka-Tout</h1>
                <p>Administration</p>
            </div>
            
            <nav class="nav-links">
                <a href="#" class="active" data-section="dashboard">
                    <i class="fas fa-tachometer-alt"></i>
                    Tableau de bord
                </a>
                <a href="#" data-section="inscriptions">
                    <i class="fas fa-user-plus"></i>
                    Inscriptions
                </a>
                <a href="#" data-section="contacts">
                    <i class="fas fa-envelope"></i>
                    Messages
                </a>
                <a href="#" data-section="stats">
                    <i class="fas fa-chart-bar"></i>
                    Statistiques
                </a>
                <a href="#" onclick="refreshData()">
                    <i class="fas fa-sync-alt"></i>
                    Actualiser
                </a>
                <a href="index.html">
                    <i class="fas fa-sign-out-alt"></i>
                    Déconnexion
                </a>
            </nav>
        </aside>

        <main class="main-content">
            <header class="header">
                <div>
                    <h1>Tableau de bord</h1>
                    <p style="color: var(--gray); margin-top: 0.5rem;">
                        Bienvenue dans l'interface d'administration
                    </p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-secondary refresh-btn" onclick="refreshData()">
                        <i class="fas fa-sync-alt"></i> Actualiser
                    </button>
                </div>
            </header>

            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <div class="number" id="stat-total"><?= $stats['total_inscriptions'] ?></div>
                        <div class="label">Total Inscriptions</div>
                    </div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <div class="number" id="stat-pending"><?= $stats['en_attente'] ?></div>
                        <div class="label">En Attente</div>
                    </div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <div class="number" id="stat-confirmed"><?= $stats['confirmees'] ?></div>
                        <div class="label">Confirmées</div>
                    </div>
                </div>

                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-info">
                        <div class="number" id="stat-refused"><?= $stats['refusees'] ?></div>
                        <div class="label">Refusées</div>
                    </div>
                </div>
            </div>

            <div class="data-section">
                <div class="data-header">
                    <div class="data-tabs">
                        <button class="data-tab active" data-tab="inscriptions">
                            <i class="fas fa-user-plus"></i>
                            Inscriptions Récentes
                        </button>
                        <button class="data-tab" data-tab="contacts">
                            <i class="fas fa-envelope"></i>
                            Messages de Contact
                        </button>
                    </div>
                </div>

                <div id="inscriptionsContent" class="data-content active">
                    <div class="filters-section">
                        <div class="filters-title">
                            <i class="fas fa-filter"></i>
                            Filtres
                        </div>
                        <div class="filter-controls">
                            <div class="filter-group">
                                <label>Statut</label>
                                <select class="filter-select" id="statusFilter">
                                    <option value="">Tous les statuts</option>
                                    <option value="pending">En attente</option>
                                    <option value="confirmed">Confirmées</option>
                                    <option value="canceled">Refusées</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Période</label>
                                <select class="filter-select" id="periodFilter">
                                    <option value="">Toutes les périodes</option>
                                    <option value="today">Aujourd'hui</option>
                                    <option value="week">Cette semaine</option>
                                    <option value="month">Ce mois</option>
                                </select>
                            </div>
                            <div class="filter-actions">
                                <button class="filter-btn primary" onclick="applyFilters()">
                                    <i class="fas fa-search"></i>
                                    Filtrer
                                </button>
                                <button class="filter-btn secondary" onclick="clearFilters()">
                                    <i class="fas fa-times"></i>
                                    Effacer
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="results-summary" id="resultsSummary">
                        <div class="results-count" id="resultsCount">
                            Affichage de <?= count($inscriptions) ?> inscription(s)
                        </div>
                        <div id="filterStatus"></div>
                    </div>

                    <?php if (empty($inscriptions)): ?>
                        <p style="text-align: center; color: var(--gray); padding: 3rem;">
                            <i class="fas fa-inbox fa-3x" style="margin-bottom: 1rem; opacity: 0.3;"></i><br>
                            Aucune inscription trouvée
                        </p>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table" id="inscriptionsTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nom&Prenom Enfant</th>
                                        <th>Classe</th>
                                        <th>Email</th>
                                        <th>Date</th>
                                        <th>Statut</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inscriptions as $inscription): ?>
                                    <tr data-inscription-id="<?= $inscription['id'] ?>" data-date="<?= getFieldValue($inscription, ['date_inscription', 'created_at'], '') ?>">
                                        <td><strong>#<?= $inscription['id'] ?></strong></td>
                                        <td>
                                            <?php
                                            $parent_nom = getFieldValue($inscription, ['parent_nom', 'parent1_nom', 'nom_pere', 'nom_parent', 'nom'], '');
                                            $parent_prenom = getFieldValue($inscription, ['parent_prenom', 'parent1_prenom', 'prenom_pere', 'prenom_parent', 'prenom'], '');
                                            
                                            if ($parent_nom === 'Non renseigné' && $parent_prenom === 'Non renseigné') {
                                                echo 'Non renseigné';
                                            } else {
                                                echo htmlspecialchars(($parent_nom !== 'Non renseigné' ? $parent_nom : '') . ' ' . ($parent_prenom !== 'Non renseigné' ? $parent_prenom : ''));
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            $enfant_nom = getFieldValue($inscription, ['enfant_nom', 'nom_enfant'], '');
                                            $enfant_prenom = getFieldValue($inscription, ['enfant_prenom', 'prenom_enfant'], '');
                                            
                                            if ($enfant_nom === 'Non renseigné' && $enfant_prenom === 'Non renseigné') {
                                                echo 'Non renseigné';
                                            } else {
                                                echo htmlspecialchars(($enfant_nom !== 'Non renseigné' ? $enfant_nom : '') . ' ' . ($enfant_prenom !== 'Non renseigné' ? $enfant_prenom : ''));
                                            }
                                            ?>
                                            <?php $classe = getFieldValue($inscription, ['classe'], ''); ?>
                                            <?php if ($classe !== 'Non renseigné'): ?>
                                                <br><small style="color: var(--gray);"><?= htmlspecialchars($classe) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars(getFieldValue($inscription, ['parent_email', 'parent1_email', 'email_pere', 'email_parent', 'email'], 'Non renseigné')) ?>
                                        </td>
                                        <td>
                                            <small><?= formatDate(getFieldValue($inscription, ['date_inscription', 'created_at'], '')) ?></small>
                                        </td>
                                        <td>
                                            <?php $current_status = getFieldValue($inscription, ['statut', 'status', 'etat'], 'en_attente'); ?>
                                            <span class="status <?= getStatusClass($current_status) ?>" data-status="<?= htmlspecialchars($current_status) ?>">
                                                <?= formatStatus($current_status) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <button class="action-btn info" onclick="viewDetails('inscription', <?= $inscription['id'] ?>)" title="Voir les détails">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="action-btn primary" onclick="editInscriptionStatus(<?= $inscription['id'] ?>, '<?= htmlspecialchars($current_status) ?>')" title="Modifier le statut">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="contactsContent" class="data-content">
                    <?php if (empty($contacts)): ?>
                        <p style="text-align: center; color: var(--gray); padding: 3rem;">
                            <i class="fas fa-envelope fa-3x" style="margin-bottom: 1rem; opacity: 0.3;"></i><br>
                            Aucun message de contact trouvé
                        </p>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nom</th>
                                        <th>Email</th>
                                        <th>Sujet</th>
                                        <th>Extrait du message</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contacts as $contact): ?>
                                    <tr>
                                        <td><strong>#<?= $contact['id'] ?></strong></td>
                                        <td><?= htmlspecialchars(getFieldValue($contact, ['nom', 'name'], 'Non renseigné')) ?></td>
                                        <td><?= htmlspecialchars(getFieldValue($contact, ['email'], 'Non renseigné')) ?></td>
                                        <td><?= htmlspecialchars(getFieldValue($contact, ['sujet', 'subject'], 'Non spécifié')) ?></td>
                                        <td>
                                            <small style="color: var(--gray);">
                                                <?= htmlspecialchars(substr(getFieldValue($contact, ['message', 'contenu'], 'Aucun message'), 0, 100)) ?>
                                                <?php if (strlen(getFieldValue($contact, ['message', 'contenu'], '')) > 100): ?>...<?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small><?= formatDate(getFieldValue($contact, ['date_creation', 'created_at'], '')) ?></small>
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <button class="action-btn info" onclick="viewDetails('contact', <?= $contact['id'] ?>)" title="Voir le message complet">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="action-btn success" onclick="replyToMessage(<?= $contact['id'] ?>)" title="Répondre">
                                                    <i class="fas fa-reply"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        const CONFIG = {
            statusMapping: {
                'en_attente': { class: 'pending', text: 'En attente' },
                'pending': { class: 'pending', text: 'En attente' },
                'accepte': { class: 'confirmed', text: 'Confirmée' },
                'confirme': { class: 'confirmed', text: 'Confirmée' },
                'confirmed': { class: 'confirmed', text: 'Confirmée' },
                'refuse': { class: 'canceled', text: 'Refusée' },
                'canceled': { class: 'canceled', text: 'Refusée' },
                'rejected': { class: 'canceled', text: 'Refusée' }
            }
        };

        let currentData = {
            inscriptions: <?= json_encode($inscriptions) ?>,
            contacts: <?= json_encode($contacts) ?>,
            stats: <?= json_encode($stats) ?>
        };

        let filteredRows = [];
        let allRows = [];

        // Initialisation des données pour le filtrage
        function initializeFiltering() {
            const table = document.getElementById('inscriptionsTable');
            if (table) {
                allRows = Array.from(table.querySelectorAll('tbody tr'));
                filteredRows = [...allRows];
                updateResultsCount();
            }
        }

        // Application des filtres
        function applyFilters() {
            const statusFilter = document.getElementById('statusFilter').value;
            const periodFilter = document.getElementById('periodFilter').value;
            
            filteredRows = allRows.filter(row => {
                let showRow = true;

                // Filtre par statut
                if (statusFilter) {
                    const statusElement = row.querySelector('.status');
                    if (statusElement && !statusElement.classList.contains(statusFilter)) {
                        showRow = false;
                    }
                }

                // Filtre par période
                if (periodFilter) {
                    const dateStr = row.getAttribute('data-date');
                    if (dateStr) {
                        const rowDate = new Date(dateStr);
                        const now = new Date();
                        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                        const weekAgo = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000);
                        const monthAgo = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);

                        switch (periodFilter) {
                            case 'today':
                                if (rowDate < today) showRow = false;
                                break;
                            case 'week':
                                if (rowDate < weekAgo) showRow = false;
                                break;
                            case 'month':
                                if (rowDate < monthAgo) showRow = false;
                                break;
                        }
                    }
                }

                return showRow;
            });

            // Masquer/afficher les lignes
            allRows.forEach(row => {
                if (filteredRows.includes(row)) {
                    row.classList.remove('hidden');
                } else {
                    row.classList.add('hidden');
                }
            });

            updateResultsCount();
            updateFilterStatus();
        }

        // Effacer les filtres
        function clearFilters() {
            document.getElementById('statusFilter').value = '';
            document.getElementById('periodFilter').value = '';
            
            filteredRows = [...allRows];
            allRows.forEach(row => row.classList.remove('hidden'));
            
            updateResultsCount();
            updateFilterStatus();
        }

        // Mise à jour du compteur de résultats
        function updateResultsCount() {
            const countElement = document.getElementById('resultsCount');
            if (countElement) {
                const count = filteredRows.length;
                countElement.textContent = `Affichage de ${count} inscription(s)`;
            }
        }

        // Mise à jour du statut des filtres
        function updateFilterStatus() {
            const statusElement = document.getElementById('filterStatus');
            if (statusElement) {
                const statusFilter = document.getElementById('statusFilter').value;
                const periodFilter = document.getElementById('periodFilter').value;
                
                let statusText = '';
                if (statusFilter || periodFilter) {
                    const filters = [];
                    if (statusFilter) {
                        const statusLabels = {
                            'pending': 'En attente',
                            'confirmed': 'Confirmées',
                            'canceled': 'Refusées'
                        };
                        filters.push(`Statut: ${statusLabels[statusFilter]}`);
                    }
                    if (periodFilter) {
                        const periodLabels = {
                            'today': 'Aujourd\'hui',
                            'week': 'Cette semaine',
                            'month': 'Ce mois'
                        };
                        filters.push(`Période: ${periodLabels[periodFilter]}`);
                    }
                    statusText = `Filtres actifs: ${filters.join(', ')}`;
                } else {
                    statusText = 'Aucun filtre actif';
                }
                statusElement.textContent = statusText;
            }
        }

        // Fonctions de gestion de la sidebar mobile
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        }

        // Fermer la sidebar quand on clique sur un lien (mobile)
        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 1024) {
                    closeSidebar();
                }
            });
        });

        function editInscriptionStatus(id, currentStatus) {
            const content = `
                <h3>Modifier le statut de l'inscription #${id}</h3>
                <div class="status-form">
                    <p style="color: var(--gray); margin-bottom: 15px;">
                        Sélectionnez le nouveau statut pour cette inscription :
                    </p>
                    
                    <div class="status-options">
                        <label class="status-option ${(currentStatus === 'en_attente' || currentStatus === 'pending') ? 'selected' : ''}" onclick="selectStatusOption(this)">
                            <input type="radio" name="new_status" value="en_attente" ${(currentStatus === 'en_attente' || currentStatus === 'pending') ? 'checked' : ''}>
                            <div class="status-info">
                                <div class="status-name">En Attente</div>
                                <div class="status-description">L'inscription est en cours de traitement</div>
                            </div>
                        </label>
                        
                        <label class="status-option ${(currentStatus === 'accepte' || currentStatus === 'confirme' || currentStatus === 'confirmed') ? 'selected' : ''}" onclick="selectStatusOption(this)">
                            <input type="radio" name="new_status" value="confirme" ${(currentStatus === 'accepte' || currentStatus === 'confirme' || currentStatus === 'confirmed') ? 'checked' : ''}>
                            <div class="status-info">
                                <div class="status-name">Confirmée</div>
                                <div class="status-description">L'inscription a été acceptée et confirmée</div>
                            </div>
                        </label>
                        
                        <label class="status-option ${(currentStatus === 'refuse' || currentStatus === 'canceled' || currentStatus === 'rejected') ? 'selected' : ''}" onclick="selectStatusOption(this)">
                            <input type="radio" name="new_status" value="refuse" ${(currentStatus === 'refuse' || currentStatus === 'canceled' || currentStatus === 'rejected') ? 'checked' : ''}>
                            <div class="status-info">
                                <div class="status-name">Refusée</div>
                                <div class="status-description">L'inscription a été refusée ou annulée</div>
                            </div>
                        </label>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Annuler
                        </button>
                        <button type="button" class="btn btn-primary" onclick="saveInscriptionStatus(${id})">
                            <i class="fas fa-save"></i> Enregistrer
                        </button>
                    </div>
                </div>
            `;
            
            showModal('Modification du statut', content);
        }

        function saveInscriptionStatus(id) {
            const selectedOption = document.querySelector('input[name="new_status"]:checked');
            if (!selectedOption) {
                alert('Veuillez sélectionner un statut');
                return;
            }
            
            const newStatus = selectedOption.value;
            const modal = document.querySelector('.modal-overlay');
            const saveBtn = modal.querySelector('.btn-primary');
            
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement...';
            saveBtn.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('id', id);
            formData.append('status', newStatus);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('Réponse non-JSON reçue: ' + text.substring(0, 100));
                }
            }))
            .then(data => {
                if (data.success) {
                    updateInscriptionStatusDisplay(id, data.new_status);
                    if (data.stats) {
                        updateStatisticsDisplay(data.stats);
                    } else {
                        recalculateStatistics();
                    }
                    closeModal();
                    showSuccessMessage(data.message || 'Statut mis à jour avec succès !');
                    updateLocalData(id, data.new_status);
                    // Réappliquer les filtres après la mise à jour
                    applyFilters();
                } else {
                    alert('Erreur : ' + (data.message || 'Impossible de mettre à jour le statut'));
                    restoreSaveButton(saveBtn);
                }
            })
            .catch(error => {
                alert('Erreur de connexion: ' + error.message);
                restoreSaveButton(saveBtn);
            });
        }

        function updateInscriptionStatusDisplay(id, newStatus) {
            const row = document.querySelector(`tr[data-inscription-id="${id}"]`);
            if (row) {
                const statusCell = row.querySelector('.status');
                if (statusCell) {
                    statusCell.classList.remove('confirmed', 'pending', 'canceled');
                    
                    let statusConfig;
                    if (newStatus === 'accepte' || newStatus === 'confirme') {
                        statusConfig = { class: 'confirmed', text: 'Confirmée' };
                    } else if (newStatus === 'en_attente' || newStatus === 'pending') {
                        statusConfig = { class: 'pending', text: 'En attente' };
                    } else if (newStatus === 'refuse') {
                        statusConfig = { class: 'canceled', text: 'Refusée' };
                    } else {
                        statusConfig = CONFIG.statusMapping[newStatus] || CONFIG.statusMapping['pending'];
                    }
                    
                    statusCell.classList.add(statusConfig.class);
                    statusCell.textContent = statusConfig.text;
                    statusCell.setAttribute('data-status', newStatus);
                    
                    statusCell.style.animation = 'pulse 0.5s ease-in-out';
                    setTimeout(() => statusCell.style.animation = '', 500);
                }
            }
        }

        function updateStatisticsDisplay(stats) {
            const pendingStat = document.getElementById('stat-pending');
            const confirmedStat = document.getElementById('stat-confirmed');
            const refusedStat = document.getElementById('stat-refused');
            const totalStat = document.getElementById('stat-total');
            
            if (pendingStat && stats.en_attente !== undefined) {
                animateCounter(pendingStat, stats.en_attente);
            }
            if (confirmedStat && stats.confirmees !== undefined) {
                animateCounter(confirmedStat, stats.confirmees);
            }
            if (refusedStat && stats.refusees !== undefined) {
                animateCounter(refusedStat, stats.refusees);
            }
            
            if (totalStat && stats.en_attente !== undefined && stats.confirmees !== undefined && stats.refusees !== undefined) {
                const total = stats.en_attente + stats.confirmees + stats.refusees;
                animateCounter(totalStat, total);
            }
        }

        function recalculateStatistics() {
            const rows = document.querySelectorAll('#inscriptionsContent tr[data-inscription-id]');
            let pending = 0, confirmed = 0, refused = 0;
            
            rows.forEach(row => {
                const statusElement = row.querySelector('.status');
                if (statusElement) {
                    const statusClass = statusElement.classList;
                    if (statusClass.contains('pending')) {
                        pending++;
                    } else if (statusClass.contains('confirmed')) {
                        confirmed++;
                    } else if (statusClass.contains('canceled')) {
                        refused++;
                    }
                }
            });
            
            updateStatisticsDisplay({
                en_attente: pending,
                confirmees: confirmed,
                refusees: refused
            });
        }

        function restoreSaveButton(saveBtn) {
            saveBtn.innerHTML = '<i class="fas fa-save"></i> Enregistrer';
            saveBtn.disabled = false;
        }

        function selectStatusOption(option) {
            document.querySelectorAll('.status-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            option.classList.add('selected');
            const radio = option.querySelector('input[type="radio"]');
            radio.checked = true;
        }

        function updateLocalData(id, newStatus) {
            if (currentData.inscriptions) {
                const inscription = currentData.inscriptions.find(item => item.id == id);
                if (inscription) {
                    inscription.statut = newStatus;
                    inscription.status = newStatus;
                }
            }
        }

        function showSuccessMessage(message) {
            const existingMessages = document.querySelectorAll('.success-message');
            existingMessages.forEach(msg => msg.remove());
            
            const successDiv = document.createElement('div');
            successDiv.className = 'success-message';
            successDiv.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: var(--success);
                color: white;
                padding: 15px 20px;
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
                z-index: 1001;
                display: flex;
                align-items: center;
                gap: 10px;
                animation: slideInRight 0.3s ease;
                max-width: 300px;
            `;
            
            successDiv.innerHTML = `<i class="fas fa-check-circle"></i><span>${message}</span>`;
            document.body.appendChild(successDiv);
            
            setTimeout(() => {
                if (successDiv.parentNode) {
                    successDiv.style.animation = 'slideOutRight 0.3s ease';
                    setTimeout(() => successDiv.remove(), 300);
                }
            }, 4000);
        }

        function viewDetails(type, id) {
            const data = currentData[type + 's'];
            const item = data ? data.find(item => item.id == id) : null;
            
            if (item) {
                let content = '';
                if (type === 'inscription') {
                    content = `
                        <h3>Détails de l'inscription #${id}</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                            <div>
                                <h4>Informations Parent1</h4>
                                <p><strong>Nom:</strong> ${item.parent1_nom || 'Non renseigné'}</p>
                                <p><strong>Prénom:</strong> ${item.parent1_prenom || 'Non renseigné'}</p>
                                <p><strong>Email:</strong> ${item.parent1_email || 'Non renseigné'}</p>
                                <p><strong>Téléphone:</strong> ${item.parent1_telephone || 'Non renseigné'}</p>
                            </div>
                            <div>
                                <h4>Informations Parent2</h4>
                                <p><strong>Nom:</strong> ${item.parent2_nom|| 'Non renseigné'}</p>
                                <p><strong>Prénom:</strong> ${item.parent2_prenom || 'Non renseigné'}</p>
                                <p><strong>Téléphone:</strong> ${item.parent2_telephone || 'Non spécifié'}</p>
                                <p><strong>Email:</strong> ${item.parent2_email || 'Non renseigné'}</p>
                                <p><strong>Profession:</strong> ${item.parent2_profession || 'Non renseigné'}</p>

                            </div>
                        </div>
                        <div style="margin-top: 20px;">
                            <h4>Informations générales</h4>
                            <p><strong>Date d'inscription:</strong> ${item.date_inscription || item.created_at || 'Non renseignée'}</p>
                            <p><strong>Statut:</strong> ${item.statut || item.status || 'En attente'}</p>
                        </div>
                    `;
                } else if (type === 'contact') {
                    content = `
                        <h3>Message de contact #${id}</h3>
                        <div style="margin-top: 20px;">
                            <p><strong>Nom:</strong> ${item.nom || item.name || 'Non renseigné'}</p>
                            <p><strong>Email:</strong> ${item.email || 'Non renseigné'}</p>
                            <p><strong>Sujet:</strong> ${item.sujet || item.subject || 'Non spécifié'}</p>
                            <p><strong>Date:</strong> ${item.date_creation || item.created_at || 'Non renseignée'}</p>
                        </div>
                        <div style="margin-top: 20px;">
                            <h4>Message complet:</h4>
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 10px;">
                                ${item.message || item.contenu || 'Aucun message'}
                            </div>
                        </div>
                    `;
                }
                showModal('Détails', content);
            } else {
                alert('Élément non trouvé');
            }
        }

        function replyToMessage(id) {
            const contact = currentData.contacts ? currentData.contacts.find(item => item.id == id) : null;
            if (contact) {
                showModal('Répondre au message', `
                    <h3>Réponse à ${contact.nom || contact.name || 'Contact'}</h3>
                    <form style="margin-top: 20px;">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">À:</label>
                            <input type="email" value="${contact.email || ''}" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;" readonly>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Sujet:</label>
                            <input type="text" value="RE: ${contact.sujet || contact.subject || 'Votre message'}" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Message:</label>
                            <textarea rows="6" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;" placeholder="Votre réponse..."></textarea>
                        </div>
                        <button type="button" onclick="sendReply()" style="background: var(--primary); color: white; border: none; padding: 12px 25px; border-radius: 5px; cursor: pointer;">
                            <i class="fas fa-paper-plane"></i> Envoyer la réponse
                        </button>
                    </form>
                `);
            }
        }

        function sendReply() {
            alert('Fonctionnalité à développer : envoi d\'email de réponse');
            closeModal();
        }

        function refreshData() {
            const refreshBtn = document.querySelector('.refresh-btn');
            const originalContent = refreshBtn.innerHTML;
            
            refreshBtn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Actualisation...';
            refreshBtn.disabled = true;
            
            setTimeout(() => {
                location.reload();
            }, 1000);
        }

        function showModal(title, content) {
            const existingModals = document.querySelectorAll('.modal-overlay');
            existingModals.forEach(modal => modal.remove());
            
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h2>${title}</h2>
                        <button class="modal-close" onclick="closeModal()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        ${content}
                    </div>
                </div>
            `;
            
            modal.onclick = function(e) {
                if (e.target === modal) closeModal();
            };
            
            document.addEventListener('keydown', function escapeHandler(e) {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escapeHandler);
                }
            });
            
            document.body.appendChild(modal);
        }

        function closeModal() {
            const modals = document.querySelectorAll('.modal-overlay');
            modals.forEach(modal => {
                modal.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => modal.remove(), 300);
            });
        }

        function animateCounter(element, target) {
            const current = parseInt(element.textContent) || 0;
            const increment = (target - current) / 30;
            let currentValue = current;
            
            const timer = setInterval(() => {
                currentValue += increment;
                if ((increment > 0 && currentValue >= target) || (increment < 0 && currentValue <= target)) {
                    currentValue = target;
                    clearInterval(timer);
                }
                element.textContent = Math.floor(currentValue);
            }, 50);
        }

        // Navigation entre les onglets
        const tabs = document.querySelectorAll('.data-tab');
        const contents = document.querySelectorAll('.data-content');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                contents.forEach(content => {
                    content.style.display = 'none';
                });
                
                const contentId = `${tab.dataset.tab}Content`;
                const targetContent = document.getElementById(contentId);
                if (targetContent) {
                    targetContent.style.display = 'block';
                }
            });
        });

        // Navigation sidebar
        const navLinks = document.querySelectorAll('.nav-links a');
        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                navLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
                
                const section = this.dataset.section;
                if (section && section !== 'dashboard' && section !== 'stats') {
                    const tab = document.querySelector(`[data-tab="${section}"]`);
                    if (tab) {
                        tab.click();
                    }
                }
            });
        });

        // Raccourcis clavier
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                refreshData();
            }
            if (e.key === 'Escape') {
                closeModal();
                closeSidebar();
            }
        });

        // Gestion du redimensionnement de fenêtre
        window.addEventListener('resize', function() {
            if (window.innerWidth > 1024) {
                closeSidebar();
            }
        });

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            console.log('✅ Dashboard Tinka-Tout initialisé');
            
            // Initialiser le système de filtrage
            initializeFiltering();
            
            setTimeout(() => {
                const numbers = document.querySelectorAll('.stat-info .number');
                numbers.forEach(number => {
                    const target = parseInt(number.textContent);
                    if (target > 0) {
                        number.textContent = '0';
                        animateCounter(number, target);
                    }
                });
            }, 500);
        });
    </script>
</body>
</html>