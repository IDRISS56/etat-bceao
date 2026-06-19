<?php
// 14-StatPointsServices.php - Statistiques des points de services
// Version conforme à STAT1.xlsx : identification en 3 lignes (Dénomination, ID, Nature)

session_start();

// Configuration BDD
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ============================================================
// PARAMÈTRES (POST uniquement)
// ============================================================
$exercice     = isset($_POST['exercice']) ? (int)$_POST['exercice'] : (isset($_SESSION['stat_exercice']) ? $_SESSION['stat_exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode'] : (isset($_SESSION['stat_type_periode']) ? $_SESSION['stat_type_periode'] : 'annuel');
$mois         = isset($_POST['mois']) ? (int)$_POST['mois'] : (isset($_SESSION['stat_mois']) ? $_SESSION['stat_mois'] : 12);
$trimestre    = isset($_POST['trimestre']) ? (int)$_POST['trimestre'] : (isset($_SESSION['stat_trimestre']) ? $_SESSION['stat_trimestre'] : 4);
$semestre     = isset($_POST['semestre']) ? (int)$_POST['semestre'] : (isset($_SESSION['stat_semestre']) ? $_SESSION['stat_semestre'] : 2);
$format       = isset($_POST['format']) ? $_POST['format'] : 'html';

// Sauvegarde en session (sauf le format)
$_SESSION['stat_exercice'] = $exercice;
$_SESSION['stat_type_periode'] = $type_periode;
$_SESSION['stat_mois'] = $mois;
$_SESSION['stat_trimestre'] = $trimestre;
$_SESSION['stat_semestre'] = $semestre;

// Calcul de la période
switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
    default:          $mois = isset($_POST['mois']) ? (int)$_POST['mois'] : 12;
}

$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$date_debut_exercice = $exercice . '-01-01';

// Libellé période
switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Annee ' . $exercice;
}

// ============================================================
// RÉCUPÉRATION DES AGENCES (Points de services)
// ============================================================
$agences = [];
try {
    $stmt = $pdo->prepare("SELECT agence_id, code_agence, nom_agence FROM agences WHERE statut = 'active' ORDER BY code_agence");
    $stmt->execute();
    $agences = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $agences = []; }

// ============================================================
// FONCTIONS D'EXTRACTION PAR AGENCE
// ============================================================
function getDataByAgence($pdo, $sql, $params = []) {
    $data = [];
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $data[$row['agence_id']] = (float)$row['valeur'];
        }
    } catch (PDOException $e) { /* ignorer */ }
    return $data;
}

// ============================================================
// INDICATEURS PAR AGENCE (tous ceux de l'Excel)
// ============================================================
$indicators = [];

// --- 1. Effectif du personnel (Y01205) ---
$sql = "SELECT agence_id, COUNT(*) as valeur FROM utilisateurs WHERE role != 'Client' AND etat = 'actif' GROUP BY agence_id";
$indicators['Y01205'] = getDataByAgence($pdo, $sql);

// --- 2. Nombre total de clients (Y01101) ---
$sql = "SELECT agence_id, COUNT(*) as valeur FROM clients WHERE statut = 'actif' GROUP BY agence_id";
$indicators['Y01101'] = getDataByAgence($pdo, $sql);

// --- 3. Répartition par genre ---
$sql = "SELECT agence_id, COUNT(*) as valeur FROM clients WHERE statut = 'actif' AND genre = 'Masculin' GROUP BY agence_id";
$indicators['Y01103'] = getDataByAgence($pdo, $sql);
$sql = "SELECT agence_id, COUNT(*) as valeur FROM clients WHERE statut = 'actif' AND genre = 'Feminin' GROUP BY agence_id";
$indicators['Y01104'] = getDataByAgence($pdo, $sql);
$sql = "SELECT agence_id, COUNT(*) as valeur FROM clients WHERE statut = 'actif' AND (categorie IN ('Entreprise','Association') OR genre = 'Morale') GROUP BY agence_id";
$indicators['Y01105'] = getDataByAgence($pdo, $sql);

// --- 4. Répartition par milieu ---
$sql = "SELECT agence_id, COUNT(*) as valeur FROM clients WHERE statut = 'actif' AND milieu = 'Urbain' GROUP BY agence_id";
$indicators['Y01117'] = getDataByAgence($pdo, $sql);
$sql = "SELECT agence_id, COUNT(*) as valeur FROM clients WHERE statut = 'actif' AND milieu = 'Semi-rural' GROUP BY agence_id";
$indicators['Y01118'] = getDataByAgence($pdo, $sql);
$sql = "SELECT agence_id, COUNT(*) as valeur FROM clients WHERE statut = 'actif' AND milieu = 'Rural' GROUP BY agence_id";
$indicators['Y01119'] = getDataByAgence($pdo, $sql);

// --- 5. Encours des dépôts (Y03101) ---
$sql = "SELECT c.agence_id, COALESCE(SUM(c.solde), 0) as valeur FROM comptes c WHERE c.solde > 0 AND c.statut = 'actif' GROUP BY c.agence_id";
$indicators['Y03101'] = getDataByAgence($pdo, $sql);

// --- 6. Dépôts par genre ---
$sql = "SELECT c.agence_id, COALESCE(SUM(c.solde), 0) as valeur FROM comptes c JOIN clients cl ON c.client_id = cl.client_id WHERE c.solde > 0 AND c.statut = 'actif' AND cl.genre = 'Masculin' GROUP BY c.agence_id";
$indicators['Y03103'] = getDataByAgence($pdo, $sql);
$sql = "SELECT c.agence_id, COALESCE(SUM(c.solde), 0) as valeur FROM comptes c JOIN clients cl ON c.client_id = cl.client_id WHERE c.solde > 0 AND c.statut = 'actif' AND cl.genre = 'Feminin' GROUP BY c.agence_id";
$indicators['Y03104'] = getDataByAgence($pdo, $sql);
$sql = "SELECT c.agence_id, COALESCE(SUM(c.solde), 0) as valeur FROM comptes c JOIN clients cl ON c.client_id = cl.client_id WHERE c.solde > 0 AND c.statut = 'actif' AND (cl.categorie IN ('Entreprise','Association') OR cl.genre = 'Morale') GROUP BY c.agence_id";
$indicators['Y03105'] = getDataByAgence($pdo, $sql);

// --- 7. Dépôts par milieu ---
$sql = "SELECT c.agence_id, COALESCE(SUM(c.solde), 0) as valeur FROM comptes c JOIN clients cl ON c.client_id = cl.client_id WHERE c.solde > 0 AND c.statut = 'actif' AND cl.milieu = 'Urbain' GROUP BY c.agence_id";
$indicators['Y03117'] = getDataByAgence($pdo, $sql);
$sql = "SELECT c.agence_id, COALESCE(SUM(c.solde), 0) as valeur FROM comptes c JOIN clients cl ON c.client_id = cl.client_id WHERE c.solde > 0 AND c.statut = 'actif' AND cl.milieu = 'Semi-rural' GROUP BY c.agence_id";
$indicators['Y03118'] = getDataByAgence($pdo, $sql);
$sql = "SELECT c.agence_id, COALESCE(SUM(c.solde), 0) as valeur FROM comptes c JOIN clients cl ON c.client_id = cl.client_id WHERE c.solde > 0 AND c.statut = 'actif' AND cl.milieu = 'Rural' GROUP BY c.agence_id";
$indicators['Y03119'] = getDataByAgence($pdo, $sql);

// --- 8. Nombre de crédits décaissés dans l'année (Y04201) ---
$sql = "SELECT agence_id, COUNT(*) as valeur FROM dossiers WHERE date_octroi BETWEEN :debut AND :fin AND statut IN ('actif','approuve','rembourse') GROUP BY agence_id";
$params = [':debut' => $date_debut_exercice, ':fin' => $date_fin_periode];
$indicators['Y04201'] = getDataByAgence($pdo, $sql, $params);

// --- 9. Crédits décaissés par genre ---
$sql = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND cl.genre = 'Masculin' GROUP BY d.agence_id";
$indicators['Y04203'] = getDataByAgence($pdo, $sql, $params);
$sql = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND cl.genre = 'Feminin' GROUP BY d.agence_id";
$indicators['Y04204'] = getDataByAgence($pdo, $sql, $params);
$sql = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND (cl.categorie IN ('Entreprise','Association') OR cl.genre = 'Morale') GROUP BY d.agence_id";
$indicators['Y04205'] = getDataByAgence($pdo, $sql, $params);

// --- 10. Crédits décaissés par milieu ---
$sql = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND cl.milieu = 'Urbain' GROUP BY d.agence_id";
$indicators['Y04217'] = getDataByAgence($pdo, $sql, $params);
$sql = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND cl.milieu = 'Semi-rural' GROUP BY d.agence_id";
$indicators['Y04218'] = getDataByAgence($pdo, $sql, $params);
$sql = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND cl.milieu = 'Rural' GROUP BY d.agence_id";
$indicators['Y04219'] = getDataByAgence($pdo, $sql, $params);

// --- 11. Montant des crédits décaissés (Y04101) ---
$sql = "SELECT agence_id, COALESCE(SUM(montant), 0) as valeur FROM dossiers WHERE date_octroi BETWEEN :debut AND :fin AND statut IN ('actif','approuve','rembourse') GROUP BY agence_id";
$indicators['Y04101'] = getDataByAgence($pdo, $sql, $params);

// --- 12. Montant des crédits décaissés par genre ---
$sql = "SELECT d.agence_id, COALESCE(SUM(d.montant), 0) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND cl.genre = 'Masculin' GROUP BY d.agence_id";
$indicators['Y04103'] = getDataByAgence($pdo, $sql, $params);
$sql = "SELECT d.agence_id, COALESCE(SUM(d.montant), 0) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND cl.genre = 'Feminin' GROUP BY d.agence_id";
$indicators['Y04104'] = getDataByAgence($pdo, $sql, $params);
$sql = "SELECT d.agence_id, COALESCE(SUM(d.montant), 0) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND (cl.categorie IN ('Entreprise','Association') OR cl.genre = 'Morale') GROUP BY d.agence_id";
$indicators['Y04105'] = getDataByAgence($pdo, $sql, $params);

// --- 13. Montant des crédits décaissés par milieu ---
$sql = "SELECT d.agence_id, COALESCE(SUM(d.montant), 0) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND cl.milieu = 'Urbain' GROUP BY d.agence_id";
$indicators['Y04117'] = getDataByAgence($pdo, $sql, $params);
$sql = "SELECT d.agence_id, COALESCE(SUM(d.montant), 0) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND cl.milieu = 'Semi-rural' GROUP BY d.agence_id";
$indicators['Y04118'] = getDataByAgence($pdo, $sql, $params);
$sql = "SELECT d.agence_id, COALESCE(SUM(d.montant), 0) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND cl.milieu = 'Rural' GROUP BY d.agence_id";
$indicators['Y04119'] = getDataByAgence($pdo, $sql, $params);

// --- 14. Répartition par secteur d'activité (montant des crédits décaissés) ---
$secteurs = [];
try {
    $stmt = $pdo->query("SELECT secteur_id, nom FROM secteurs WHERE statut = 'actif' ORDER BY nom LIMIT 10");
    $secteurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $secteurs = []; }

// Si moins de 10 secteurs, on complète avec des libellés vides pour avoir toujours 10 lignes
while (count($secteurs) < 10) {
    $secteurs[] = ['secteur_id' => 'VIDE_' . (count($secteurs)+1), 'nom' => 'Secteur ' . (count($secteurs)+1)];
}

foreach ($secteurs as $idx => $s) {
    $code = 'Y064' . str_pad($idx + 1, 2, '0', STR_PAD_LEFT);
    if (strpos($s['secteur_id'], 'VIDE') === false) {
        $sql = "SELECT d.agence_id, COALESCE(SUM(d.montant), 0) as valeur 
                FROM dossiers d 
                JOIN comptes c ON d.compte_id = c.compte_id 
                JOIN clients cl ON c.client_id = cl.client_id 
                WHERE d.date_octroi BETWEEN :debut AND :fin 
                  AND d.statut IN ('actif','approuve','rembourse') 
                  AND cl.secteur_id = :secteur_id 
                GROUP BY d.agence_id";
        $indicators[$code] = getDataByAgence($pdo, $sql, array_merge($params, [':secteur_id' => $s['secteur_id']]));
    } else {
        $indicators[$code] = [];
    }
}

// --- 15. Nombre de crédits en cours (Y04501) ---
$sql_encours = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin GROUP BY d.agence_id";
$indicators['Y04501'] = getDataByAgence($pdo, $sql_encours, [':fin' => $date_fin_periode]);

// --- 16. Nombre de crédits en cours par genre ---
$sql = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin AND cl.genre = 'Masculin' GROUP BY d.agence_id";
$indicators['Y04503'] = getDataByAgence($pdo, $sql, [':fin' => $date_fin_periode]);
$sql = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin AND cl.genre = 'Feminin' GROUP BY d.agence_id";
$indicators['Y04504'] = getDataByAgence($pdo, $sql, [':fin' => $date_fin_periode]);
$sql = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin AND (cl.categorie IN ('Entreprise','Association') OR cl.genre = 'Morale') GROUP BY d.agence_id";
$indicators['Y04505'] = getDataByAgence($pdo, $sql, [':fin' => $date_fin_periode]);

// --- 17. Nombre de crédits en cours par milieu ---
$sql = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin AND cl.milieu = 'Urbain' GROUP BY d.agence_id";
$indicators['Y04517'] = getDataByAgence($pdo, $sql, [':fin' => $date_fin_periode]);
$sql = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin AND cl.milieu = 'Semi-rural' GROUP BY d.agence_id";
$indicators['Y04518'] = getDataByAgence($pdo, $sql, [':fin' => $date_fin_periode]);
$sql = "SELECT d.agence_id, COUNT(*) as valeur FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin AND cl.milieu = 'Rural' GROUP BY d.agence_id";
$indicators['Y04519'] = getDataByAgence($pdo, $sql, [':fin' => $date_fin_periode]);

// --- 18. Montant des encours de crédits (Y04401) ---
$sql_encours_montant = "SELECT d.agence_id, COALESCE(SUM(d.montant - COALESCE(r.rembourse, 0)), 0) as valeur 
                        FROM dossiers d 
                        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) r ON d.dossier_id = r.dossier_id 
                        WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin 
                        GROUP BY d.agence_id";
$indicators['Y04401'] = getDataByAgence($pdo, $sql_encours_montant, [':fin' => $date_fin_periode]);

// --- 19. Montant des encours par genre ---
$sql = "SELECT d.agence_id, COALESCE(SUM(d.montant - COALESCE(r.rembourse, 0)), 0) as valeur 
        FROM dossiers d 
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) r ON d.dossier_id = r.dossier_id 
        JOIN comptes c ON d.compte_id = c.compte_id 
        JOIN clients cl ON c.client_id = cl.client_id 
        WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin AND cl.genre = 'Masculin' 
        GROUP BY d.agence_id";
$indicators['Y04403'] = getDataByAgence($pdo, $sql, [':fin' => $date_fin_periode]);
$sql = "SELECT d.agence_id, COALESCE(SUM(d.montant - COALESCE(r.rembourse, 0)), 0) as valeur 
        FROM dossiers d 
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) r ON d.dossier_id = r.dossier_id 
        JOIN comptes c ON d.compte_id = c.compte_id 
        JOIN clients cl ON c.client_id = cl.client_id 
        WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin AND cl.genre = 'Feminin' 
        GROUP BY d.agence_id";
$indicators['Y04404'] = getDataByAgence($pdo, $sql, [':fin' => $date_fin_periode]);
$sql = "SELECT d.agence_id, COALESCE(SUM(d.montant - COALESCE(r.rembourse, 0)), 0) as valeur 
        FROM dossiers d 
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) r ON d.dossier_id = r.dossier_id 
        JOIN comptes c ON d.compte_id = c.compte_id 
        JOIN clients cl ON c.client_id = cl.client_id 
        WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin AND (cl.categorie IN ('Entreprise','Association') OR cl.genre = 'Morale') 
        GROUP BY d.agence_id";
$indicators['Y04405'] = getDataByAgence($pdo, $sql, [':fin' => $date_fin_periode]);

// --- 20. Montant des encours par milieu ---
$sql = "SELECT d.agence_id, COALESCE(SUM(d.montant - COALESCE(r.rembourse, 0)), 0) as valeur 
        FROM dossiers d 
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) r ON d.dossier_id = r.dossier_id 
        JOIN comptes c ON d.compte_id = c.compte_id 
        JOIN clients cl ON c.client_id = cl.client_id 
        WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin AND cl.milieu = 'Urbain' 
        GROUP BY d.agence_id";
$indicators['Y04417'] = getDataByAgence($pdo, $sql, [':fin' => $date_fin_periode]);
$sql = "SELECT d.agence_id, COALESCE(SUM(d.montant - COALESCE(r.rembourse, 0)), 0) as valeur 
        FROM dossiers d 
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) r ON d.dossier_id = r.dossier_id 
        JOIN comptes c ON d.compte_id = c.compte_id 
        JOIN clients cl ON c.client_id = cl.client_id 
        WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin AND cl.milieu = 'Semi-rural' 
        GROUP BY d.agence_id";
$indicators['Y04418'] = getDataByAgence($pdo, $sql, [':fin' => $date_fin_periode]);
$sql = "SELECT d.agence_id, COALESCE(SUM(d.montant - COALESCE(r.rembourse, 0)), 0) as valeur 
        FROM dossiers d 
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) r ON d.dossier_id = r.dossier_id 
        JOIN comptes c ON d.compte_id = c.compte_id 
        JOIN clients cl ON c.client_id = cl.client_id 
        WHERE d.statut IN ('actif','approuve') AND d.date_octroi <= :fin AND cl.milieu = 'Rural' 
        GROUP BY d.agence_id";
$indicators['Y04419'] = getDataByAgence($pdo, $sql, [':fin' => $date_fin_periode]);

// ============================================================
// CONSTRUCTION DE LA LISTE D'AFFICHAGE (incluant les séparateurs)
// ============================================================
$display_items = [];

// Définition des libellés exacts de l'Excel
$labels = [
    'Y01205' => 'Effectif du personnel',
    'Y01101' => 'Nombre de membres / clients',
    'Y01103' => 'Hommes',
    'Y01104' => 'Femmes',
    'Y01105' => 'Personnes morales',
    'Y01117' => 'Milieu urbain',
    'Y01118' => 'Milieu sémi-rural',
    'Y01119' => 'Milieu rural',
    'Y03101' => 'Encours des dépôts',
    'Y03103' => 'Hommes',
    'Y03104' => 'Femmes',
    'Y03105' => 'Personnes morales',
    'Y03117' => 'Milieu urbain',
    'Y03118' => 'Milieu sémi-rural',
    'Y03119' => 'Milieu rural',
    'Y04201' => 'Nombre de crédits décaissés dans l\'année',
    'Y04203' => 'Hommes',
    'Y04204' => 'Femmes',
    'Y04205' => 'Personnes morales',
    'Y04217' => 'Milieu urbain',
    'Y04218' => 'Milieu sémi-rural',
    'Y04219' => 'Milieu rural',
    'Y04101' => 'Montant des crédits décaissés dans l\'année',
    'Y04103' => 'Hommes',
    'Y04104' => 'Femmes',
    'Y04105' => 'Personnes morales',
    'Y04117' => 'Milieu urbain',
    'Y04118' => 'Milieu sémi-rural',
    'Y04119' => 'Milieu rural',
];

// Secteurs - libellés exacts de l'Excel
$secteur_labels = [
    'Y06401' => 'Agriculture',
    'Y06402' => 'Industries extractives',
    'Y06403' => 'Industries manufacturières',
    'Y06404' => 'Bâtiment et travaux publics',
    'Y06405' => 'Commerce, hôtels et restaurants',
    'Y06406' => 'Electricité, gaz, eau',
    'Y06407' => 'Transports, entrepôts et com.',
    'Y06408' => 'Assurances, services aux entr.',
    'Y06409' => 'Immobilier',
    'Y06410' => 'Autres'
];

// Autres labels
$labels['Y04501'] = 'Nombre de crédits en cours';
$labels['Y04503'] = 'Hommes';
$labels['Y04504'] = 'Femmes';
$labels['Y04505'] = 'Personnes morales';
$labels['Y04517'] = 'Milieu urbain';
$labels['Y04518'] = 'Milieu sémi-rural';
$labels['Y04519'] = 'Milieu rural';
$labels['Y04401'] = 'Montant des encours des crédits';
$labels['Y04403'] = 'Hommes';
$labels['Y04404'] = 'Femmes';
$labels['Y04405'] = 'Personnes morales';
$labels['Y04417'] = 'Milieu urbain';
$labels['Y04418'] = 'Milieu sémi-rural';
$labels['Y04419'] = 'Milieu rural';

// Ordre exact des éléments (avec séparateurs)
$order = [
    'Y01205',
    'Y01101',
    'separator_1' => 'Répartition par genre',
    'Y01103',
    'Y01104',
    'Y01105',
    'separator_2' => 'Répartition par milieu',
    'Y01117',
    'Y01118',
    'Y01119',
    'Y03101',
    'separator_3' => 'Répartition par genre',
    'Y03103',
    'Y03104',
    'Y03105',
    'separator_4' => 'Répartition par milieu',
    'Y03117',
    'Y03118',
    'Y03119',
    'Y04201',
    'separator_5' => 'Répartition par genre',
    'Y04203',
    'Y04204',
    'Y04205',
    'separator_6' => 'Répartition par milieu',
    'Y04217',
    'Y04218',
    'Y04219',
    'Y04101',
    'separator_7' => 'Répartition par genre',
    'Y04103',
    'Y04104',
    'Y04105',
    'separator_8' => 'Répartition par milieu',
    'Y04117',
    'Y04118',
    'Y04119',
    'separator_9' => 'Répartition par secteur d\'activité',
    'Y06401', 'Y06402', 'Y06403', 'Y06404', 'Y06405',
    'Y06406', 'Y06407', 'Y06408', 'Y06409', 'Y06410',
    'Y04501',
    'separator_10' => 'Répartition par genre',
    'Y04503',
    'Y04504',
    'Y04505',
    'separator_11' => 'Répartition par milieu',
    'Y04517',
    'Y04518',
    'Y04519',
    'Y04401',
    'separator_12' => 'Répartition par genre',
    'Y04403',
    'Y04404',
    'Y04405',
    'separator_13' => 'Répartition par milieu',
    'Y04417',
    'Y04418',
    'Y04419'
];

// Construction de $table_data avec séparateurs et indicateurs
$table_data = [];
$agence_ids = array_column($agences, 'agence_id');

foreach ($order as $key => $value) {
    if (strpos($key, 'separator_') === 0) {
        // C'est un séparateur
        $table_data[] = [
            'type' => 'separator',
            'label' => $value
        ];
    } else {
        // C'est un indicateur
        $code = $value;
        $label = $labels[$code] ?? $code;
        $row = ['type' => 'indicator', 'code' => $code, 'label' => $label];
        $total = 0;
        foreach ($agences as $a) {
            $val = isset($indicators[$code][$a['agence_id']]) ? $indicators[$code][$a['agence_id']] : 0;
            $row[$a['agence_id']] = $val;
            $total += $val;
        }
        $row['total'] = $total;
        $table_data[] = $row;
    }
}

// ============================================================
// FONCTION FORMATAGE
// ============================================================
function format_montant($val) {
    return number_format((float)$val, 0, ',', ' ');
}

// ============================================================
// EXPORT PDF
// ============================================================
if ($format === 'pdf') {
    if (ob_get_length()) ob_end_clean();
    
    class PDF_DIMF extends FPDF {
        function convert($str) {
            $str = str_replace(array('é','è','ê','ë','à','â','ä','î','ï','ô','ö','ù','û','ü','ç','É','È','Ê','Ë','À','Â','Ä','Î','Ï','Ô','Ö','Ù','Û','Ü','Ç'), 
                              array('e','e','e','e','a','a','a','i','i','o','o','u','u','u','c','E','E','E','E','A','A','A','I','I','O','O','U','U','U','C'), $str);
            return $str;
        }
        function Header() {
            $this->SetFillColor(156, 163, 175);
            $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(8, 3);
            $this->Cell(0, 4, $this->convert('Republique de Cote d\'Ivoire  •  Ministere de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
            $this->SetFont('Arial', 'B', 13);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 7, $this->convert('14 - STATISTIQUES DES POINTS DE SERVICES'), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, $this->convert('Donnees statistiques des agences et points de service'), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }
        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, $this->convert('Page ' . $this->PageNo() . '/{nb} - Genere le ' . date('d/m/Y H:i:s')), 0, 0, 'C');
        }
        function SectionTitle($label) {
            $this->SetFont('Arial', 'B', 10);
            $this->SetFillColor(0, 0, 0);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 8, $this->convert($label), 0, 1, 'L', true);
            $this->SetTextColor(0, 0, 0);
            $this->Ln(2);
        }
        function TableHeader($cols) {
            $this->SetFont('Arial', 'B', 7);
            $this->SetFillColor(240, 240, 240);
            $this->SetTextColor(0, 0, 0);
            foreach ($cols as $col) {
                $this->Cell($col['w'], 6, $this->convert($col['label']), 1, 0, $col['align'] ?? 'L', true);
            }
            $this->Ln();
        }
        function TableRow($cols, $data, $style = '') {
            if ($style == 'total') {
                $this->SetFont('Arial', 'B', 7);
                $this->SetFillColor(240, 253, 244);
                $fill = true;
            } elseif ($style == 'separator') {
                $this->SetFont('Arial', 'I', 7);
                $this->SetFillColor(245, 245, 245);
                $fill = true;
            } else {
                $this->SetFont('Arial', '', 7);
                $fill = false;
            }
            $this->SetTextColor(0, 0, 0);
            foreach ($cols as $i => $col) {
                $val = isset($data[$i]) ? $data[$i] : '';
                $this->Cell($col['w'], 5, $this->convert($val), 1, 0, $col['align'] ?? 'L', $fill);
            }
            $this->Ln();
        }
        function montant($val) { return number_format((float)$val, 0, ',', ' '); }
    }
    
    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(8, 35, 8);
    $pdf->AddPage();
    
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, $pdf->convert('Periode : ' . $lib_periode), 0, 1, 'C');
    $pdf->Ln(3);
    
    // --- Nouvelle section IDENTIFICATION (3 lignes) ---
    $pdf->SectionTitle('IDENTIFICATION DES POINTS DE SERVICES');
    $cols = [];
    $cols[] = ['label' => 'Attribut', 'w' => 50, 'align' => 'L'];
    foreach ($agences as $a) {
        $cols[] = ['label' => $a['code_agence'], 'w' => 25, 'align' => 'L'];
    }
    $pdf->TableHeader($cols);
    
    // Dénomination
    $row = ['Dénomination du point de services:'];
    foreach ($agences as $a) {
        $row[] = $a['nom_agence'];
    }
    $pdf->TableRow($cols, $row);
    
    // ID
    $row = ['ID Point de service:'];
    foreach ($agences as $a) {
        $row[] = $a['code_agence'];
    }
    $pdf->TableRow($cols, $row);
    
    // Nature
    $row = ['Nature du point de services:'];
    foreach ($agences as $a) {
        $row[] = 'Agence'; // Nature fixe (peut être adaptée)
    }
    $pdf->TableRow($cols, $row);
    
    // --- Données statistiques ---
    $pdf->Ln(5);
    $pdf->SectionTitle('DONNEES STATISTIQUES DE L\'EXERCICE DES POINTS DE SERVICES');
    $cols_stats = [];
    $cols_stats[] = ['label' => 'Code', 'w' => 25, 'align' => 'L'];
    $cols_stats[] = ['label' => 'Indicateurs', 'w' => 60, 'align' => 'L'];
    foreach ($agences as $a) {
        $cols_stats[] = ['label' => $a['code_agence'], 'w' => 25, 'align' => 'R'];
    }
    $cols_stats[] = ['label' => 'Total', 'w' => 30, 'align' => 'R'];
    $pdf->TableHeader($cols_stats);
    
    foreach ($table_data as $row) {
        if ($row['type'] == 'separator') {
            $data_row = ['', $row['label']];
            foreach ($agences as $a) {
                $data_row[] = '';
            }
            $data_row[] = '';
            $pdf->TableRow($cols_stats, $data_row, 'separator');
        } else {
            $data_row = [$row['code'], $row['label']];
            foreach ($agences as $a) {
                $val = $row[$a['agence_id']];
                if (strpos($row['code'], 'Y031') === 0 || strpos($row['code'], 'Y041') === 0 || strpos($row['code'], 'Y044') === 0) {
                    $data_row[] = $pdf->montant($val);
                } else {
                    $data_row[] = number_format((int)$val, 0, ',', ' ');
                }
            }
            $data_row[] = (strpos($row['code'], 'Y031') === 0 || strpos($row['code'], 'Y041') === 0 || strpos($row['code'], 'Y044') === 0) ? $pdf->montant($row['total']) : number_format((int)$row['total'], 0, ',', ' ');
            $pdf->TableRow($cols_stats, $data_row);
        }
    }
    
    $pdf->Output('I', '14_STAT_POINTS_SERVICES_' . $exercice . '.pdf');
    exit;
}

// ============================================================
// AFFICHAGE WEB
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>14 - Statistiques des points de services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; padding: 24px; }
        .dashboard { max-width: 1400px; margin: 0 auto; }
        .page-header { background: linear-gradient(135deg, #3b82f6, #60a5fa); border-radius: 24px; padding: 20px 28px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 6px -2px rgba(0,0,0,0.05); }
        .header-left h1 { font-size: 1.6rem; font-weight: 600; color: white; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
        .subtitle { font-size: 0.8rem; color: #e0f2fe; line-height: 1.4; }
        .badge { display: inline-block; background: #2563eb; color: white; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 500; margin-top: 8px; }
        .btn-group { display: flex; gap: 12px; }
        .btn-excel, .btn-pdf { display: inline-flex; align-items: center; gap: 8px; padding: 8px 20px; border-radius: 40px; font-weight: 500; font-size: 0.85rem; border: none; cursor: pointer; transition: 0.2s; text-decoration: none; }
        .btn-excel { background: #10b981; color: white; }
        .btn-excel:hover { background: #059669; transform: translateY(-1px); }
        .btn-pdf { background: #ef4444; color: white; }
        .btn-pdf:hover { background: #dc2626; transform: translateY(-1px); }
        .card { background: white; border-radius: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 8px 16px -4px rgba(0,0,0,0.05); margin-bottom: 24px; overflow: hidden; }
        .card-header { display: flex; align-items: center; gap: 10px; padding: 16px 24px; background: #f8fafc; border-bottom: 1px solid #eef2f6; font-weight: 600; font-size: 1rem; color: #1e40af; }
        .card-header i { color: #3b82f6; }
        .card-body { padding: 20px 24px; }
        .filters-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 20px; }
        .filter-item { display: flex; flex-direction: column; gap: 6px; }
        .filter-item label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #4b5563; }
        .filter-item select { background: white; border: 1px solid #d1d5db; border-radius: 12px; padding: 8px 14px; font-size: 0.85rem; color: #111827; cursor: pointer; }
        .filter-item select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.2); }
        .btn-apply { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 8px 24px; font-weight: 500; font-size: 0.85rem; cursor: pointer; transition: 0.2s; }
        .btn-apply:hover { background: #2563eb; transform: translateY(-1px); }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.75rem; }
        th { text-align: left; padding: 8px 10px; background: #f8fafc; font-weight: 600; color: #1e293b; border: 1px solid #d1d5db; }
        td { padding: 6px 10px; border: 1px solid #d1d5db; color: #0f172a; }
        .text-right { text-align: right; font-family: 'Courier New', monospace; font-weight: 500; }
        .separator-row td { background: #f3f4f6; font-style: italic; font-weight: 600; color: #4b5563; text-align: center; }
        .separator-row td:first-child { border-left: 1px solid #d1d5db; }
        .separator-row td:last-child { border-right: 1px solid #d1d5db; }
        .section-title { background: #e2e8f0; font-weight: 700; padding: 8px 12px; border: 1px solid #d1d5db; margin-top: 20px; border-bottom: 2px solid #94a3b8; }
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            th, td { padding: 4px 6px; font-size: 0.65rem; }
        }
        @media print {
            body { background: white; padding: 0; }
            .btn-group, .footer, .filters-row, #filtersCard { display: none !important; }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-map-marker-alt"></i> 14 - Statistiques des points de services</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">Donnees statistiques des agences et points de service</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <form method="POST" action="" style="display:inline-block">
                <input type="hidden" name="exercice" value="<?= $exercice ?>">
                <input type="hidden" name="type_periode" value="<?= $type_periode ?>">
                <input type="hidden" name="mois" value="<?= $mois ?>">
                <input type="hidden" name="trimestre" value="<?= $trimestre ?>">
                <input type="hidden" name="semestre" value="<?= $semestre ?>">
                <input type="hidden" name="format" value="pdf">
                <button type="submit" class="btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
            </form>
        </div>
    </div>

    <!-- Filtres -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
            <form method="POST" action="">
                <div class="filters-row">
                    <div class="filter-item">
                        <label>Annee</label>
                        <select name="exercice" id="exerciceSelect">
                            <?php for ($y = 2020; $y <= date('Y')+1; $y++): ?>
                                <option value="<?= $y ?>" <?= $y==$exercice?'selected':'' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label>Type de periode</label>
                        <select name="type_periode" id="typePeriodeSelect">
                            <option value="mensuel"   <?= $type_periode=='mensuel'  ?'selected':'' ?>>Mensuel</option>
                            <option value="trimestre" <?= $type_periode=='trimestre'?'selected':'' ?>>Trimestre</option>
                            <option value="semestre"  <?= $type_periode=='semestre' ?'selected':'' ?>>Semestre</option>
                            <option value="annuel"    <?= $type_periode=='annuel'   ?'selected':'' ?>>Annuel</option>
                        </select>
                    </div>
                    <div class="filter-item" id="dynamicSelectContainer">
                        <?php
                        if ($type_periode == 'mensuel') {
                            echo '<label>Mois</label><select name="mois">';
                            for ($m=1;$m<=12;$m++) { $s=($m==$mois)?'selected':''; echo "<option value='$m' $s>".str_pad($m,2,'0',STR_PAD_LEFT)." - ".date('F',mktime(0,0,0,$m,1))."</option>"; }
                            echo '</select>';
                        } elseif ($type_periode == 'trimestre') {
                            echo '<label>Trimestre</label><select name="trimestre">';
                            for ($t=1;$t<=4;$t++) { $s=($t==$trimestre)?'selected':''; echo "<option value='$t' $s>$t".($t==1?'er':'eme')." Trimestre</option>"; }
                            echo '</select>';
                        } elseif ($type_periode == 'semestre') {
                            echo '<label>Semestre</label><select name="semestre">';
                            for ($s=1;$s<=2;$s++) { $sel=($s==$semestre)?'selected':''; echo "<option value='$s' $sel>$s".($s==1?'er':'e')." semestre</option>"; }
                            echo '</select>';
                        } else {
                            echo '<label>Periode</label><input type="text" disabled value="Annee complete" style="background:#f3f4f6;cursor:default;">';
                        }
                        ?>
                    </div>
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
            </form>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Periode : <?= $lib_periode ?> (arrete au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- IDENTIFICATION DES POINTS DE SERVICES (3 lignes) -->
    <!-- ============================================================ -->
    <div class="card">
        <div class="card-header"><i class="fas fa-id-card"></i> IDENTIFICATION DES POINTS DE SERVICES</div>
        <div class="card-body">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Attribut</th>
                            <?php foreach ($agences as $a): ?>
                                <th><?= htmlspecialchars($a['code_agence']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Dénomination du point de services:</td>
                            <?php foreach ($agences as $a): ?>
                                <td><?= htmlspecialchars($a['nom_agence']) ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td>ID Point de service:</td>
                            <?php foreach ($agences as $a): ?>
                                <td><?= htmlspecialchars($a['code_agence']) ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td>Nature du point de services:</td>
                            <?php foreach ($agences as $a): ?>
                                <td>Agence</td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- DONNEES STATISTIQUES DE L'EXERCICE DES POINTS DE SERVICES -->
    <!-- ============================================================ -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> DONNEES STATISTIQUES DE L'EXERCICE DES POINTS DE SERVICES</div>
        <div class="card-body">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Indicateurs</th>
                            <?php foreach ($agences as $a): ?>
                                <th class="text-right"><?= htmlspecialchars($a['code_agence']) ?></th>
                            <?php endforeach; ?>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($table_data as $row): ?>
                            <?php if ($row['type'] == 'separator'): ?>
                                <tr class="separator-row">
                                    <td colspan="<?= 2 + count($agences) + 1 ?>"><?= htmlspecialchars($row['label']) ?></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td><?= $row['code'] ?></td>
                                    <td><?= htmlspecialchars($row['label']) ?></td>
                                    <?php foreach ($agences as $a): ?>
                                        <td class="text-right">
                                            <?php
                                            $val = $row[$a['agence_id']];
                                            if (strpos($row['code'], 'Y031') === 0 || strpos($row['code'], 'Y041') === 0 || strpos($row['code'], 'Y044') === 0) {
                                                echo number_format($val, 0, ',', ' ');
                                            } else {
                                                echo number_format((int)$val, 0, ',', ' ');
                                            }
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-right">
                                        <?php
                                        $total = $row['total'];
                                        if (strpos($row['code'], 'Y031') === 0 || strpos($row['code'], 'Y041') === 0 || strpos($row['code'], 'Y044') === 0) {
                                            echo number_format($total, 0, ',', ' ');
                                        } else {
                                            echo number_format((int)$total, 0, ',', ' ');
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base<br>
        Periode : <?= $lib_periode ?>
    </div>
</div>

<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const currentMois = <?= $mois ?>;
        const currentTrimestre = <?= $trimestre ?>;
        const currentSemestre = <?= json_encode($semestre) ?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois">';
            for (let m = 1; m <= 12; m++) {
                const s = (m === currentMois) ? 'selected' : '';
                const n = new Date(2000, m-1, 1).toLocaleString('fr', {month:'long'});
                html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre">';
            for (let t = 1; t <= 4; t++) {
                const s = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${s}>${t}${t === 1 ? 'er' : 'eme'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre">';
            for (let s = 1; s <= 2; s++) {
                const sel = (s === currentSemestre) ? 'selected' : '';
                html += `<option value="${s}" ${sel}>${s}${s === 1 ? 'er' : 'e'} semestre</option>`;
            }
            html += '</select>';
        } else {
            html = '<label>Periode</label><input type="text" disabled value="Annee complete" style="background:#f3f4f6;cursor:default;">';
        }
        container.innerHTML = html;
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        let data = [
            ['14 - STATISTIQUES DES POINTS DE SERVICES'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['IDENTIFICATION DES POINTS DE SERVICES'],
            ['Attribut', <?php foreach ($agences as $a) { echo "'".addslashes($a['code_agence'])."', "; } ?>],
            ['Dénomination du point de services:', <?php foreach ($agences as $a) { echo "'".addslashes($a['nom_agence'])."', "; } ?>],
            ['ID Point de service:', <?php foreach ($agences as $a) { echo "'".addslashes($a['code_agence'])."', "; } ?>],
            ['Nature du point de services:', <?php foreach ($agences as $a) { echo "'Agence', "; } ?>],
            [],
            ['DONNEES STATISTIQUES DE L\'EXERCICE DES POINTS DE SERVICES'],
            ['Code', 'Indicateurs', <?php foreach ($agences as $a) { echo "'".addslashes($a['code_agence'])."', "; } ?> 'Total']
        ];
        <?php foreach ($table_data as $row): ?>
            <?php if ($row['type'] == 'separator'): ?>
                data.push(['', '<?= addslashes($row['label']) ?>', <?php foreach ($agences as $a) { echo " '', "; } ?> '']);
            <?php else: ?>
                data.push([
                    '<?= $row['code'] ?>',
                    '<?= addslashes($row['label']) ?>',
                    <?php foreach ($agences as $a): ?>
                        <?= $row[$a['agence_id']] ?>,
                    <?php endforeach; ?>
                    <?= $row['total'] ?>
                ]);
            <?php endif; ?>
        <?php endforeach; ?>
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), "STAT_POINTS_SERVICES");
        XLSX.writeFile(wb, '14_STAT_POINTS_SERVICES_<?= $exercice ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>