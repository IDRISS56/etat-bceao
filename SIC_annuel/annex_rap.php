<?php
// ANNEX_RAPP_AN.php - Annexe au rapport annuel
// Instruction n°018-12-2010 du 29 décembre 2010

session_start();

// Configuration BDD
$host = 'localhost';
$dbname = 'microfinances_dg';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// ============================================================
// PARAMÈTRES AVEC TYPES DE PÉRIODE
// ============================================================
$exercice     = isset($_GET['exercice'])     ? (int)$_GET['exercice']     : date('Y');
$type_periode = isset($_GET['type_periode']) ? $_GET['type_periode']      : 'mensuel';
$mois         = isset($_GET['mois'])         ? (int)$_GET['mois']         : 12;
$trimestre    = isset($_GET['trimestre'])    ? (int)$_GET['trimestre']    : 4;
$semestre     = isset($_GET['semestre'])     ? (int)$_GET['semestre']     : 2;
$format       = isset($_GET['format'])       ? $_GET['format']            : 'html';

// Calcul du mois en fonction du type de période
switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
    default:          $mois = isset($_GET['mois']) ? (int)$_GET['mois'] : 12;
}

$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$date_debut_exercice = $exercice . '-01-01';

// Libellé de la période pour l'affichage
switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Annee ' . $exercice;
}

// ============================================================
// TRAITEMENT DU FORMULAIRE
// ============================================================
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {
    try {
        // Création des tables
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS annexe_membres (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                indicateur VARCHAR(20) NOT NULL,
                valeur_effectif INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_exercice_indicateur (exercice, indicateur)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS annexe_personnel (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                indicateur VARCHAR(20) NOT NULL,
                valeur_effectif INT DEFAULT 0,
                valeur_montant DECIMAL(15,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_exercice_indicateur (exercice, indicateur)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS annexe_credits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                indicateur VARCHAR(20) NOT NULL,
                valeur_effectif INT DEFAULT 0,
                valeur_montant DECIMAL(15,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_exercice_indicateur (exercice, indicateur)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS annexe_depots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                indicateur VARCHAR(20) NOT NULL,
                valeur_montant DECIMAL(15,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_exercice_indicateur (exercice, indicateur)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Sauvegarde des données membres
        $stmtMembres = $pdo->prepare("
            INSERT INTO annexe_membres (exercice, indicateur, valeur_effectif)
            VALUES (:exercice, :indicateur, :valeur)
            ON DUPLICATE KEY UPDATE valeur_effectif = VALUES(valeur_effectif)
        ");
        
        $indicateurs_membres = ['Y01101', 'Y01102', 'Y01103', 'Y01104', 'Y01105', 'Y01106', 'Y01107', 'Y01108', 'Y01109'];
        foreach ($indicateurs_membres as $indic) {
            $valeur = (int)($_POST[$indic] ?? 0);
            $stmtMembres->execute([':exercice' => $exercice, ':indicateur' => $indic, ':valeur' => $valeur]);
        }
        
        // Sauvegarde des données personnel
        $stmtPersonnel = $pdo->prepare("
            INSERT INTO annexe_personnel (exercice, indicateur, valeur_effectif)
            VALUES (:exercice, :indicateur, :valeur)
            ON DUPLICATE KEY UPDATE valeur_effectif = VALUES(valeur_effectif)
        ");
        
        $indicateurs_personnel = ['Y01201', 'Y01202', 'Y01203', 'Y01204', 'Y01205', 'Y01206', 'Y01207', 'Y01208', 'Y01209', 'Y01210', 'Y01211', 'Y01212'];
        foreach ($indicateurs_personnel as $indic) {
            $valeur = (int)($_POST[$indic] ?? 0);
            $stmtPersonnel->execute([':exercice' => $exercice, ':indicateur' => $indic, ':valeur' => $valeur]);
        }
        
        // Sauvegarde des données dépôts
        $stmtDepots = $pdo->prepare("
            INSERT INTO annexe_depots (exercice, indicateur, valeur_montant)
            VALUES (:exercice, :indicateur, :valeur)
            ON DUPLICATE KEY UPDATE valeur_montant = VALUES(valeur_montant)
        ");
        
        $indicateurs_depots = ['Y03101', 'Y03102', 'Y03103', 'Y03104', 'Y03105', 'Y03201', 'Y03202', 'Y03203'];
        foreach ($indicateurs_depots as $indic) {
            $valeur = (float)($_POST[$indic] ?? 0);
            $stmtDepots->execute([':exercice' => $exercice, ':indicateur' => $indic, ':valeur' => $valeur]);
        }
        
        // Sauvegarde des données crédits
        $stmtCredits = $pdo->prepare("
            INSERT INTO annexe_credits (exercice, indicateur, valeur_montant, valeur_effectif)
            VALUES (:exercice, :indicateur, :valeur_montant, :valeur_effectif)
            ON DUPLICATE KEY UPDATE 
                valeur_montant = VALUES(valeur_montant),
                valeur_effectif = VALUES(valeur_effectif)
        ");
        
        $indicateurs_credits_montant = ['Y04101', 'Y04102', 'Y04103', 'Y04104', 'Y04105', 'Y04401', 'Y04402', 'Y04403', 'Y04404', 'Y04405'];
        foreach ($indicateurs_credits_montant as $indic) {
            $valeur = (float)($_POST[$indic] ?? 0);
            $stmtCredits->execute([':exercice' => $exercice, ':indicateur' => $indic, ':valeur_montant' => $valeur, ':valeur_effectif' => 0]);
        }
        
        $indicateurs_credits_effectif = ['Y04201', 'Y04202', 'Y04203', 'Y04204', 'Y04205', 'Y04501', 'Y04502', 'Y04503', 'Y04504', 'Y04505'];
        foreach ($indicateurs_credits_effectif as $indic) {
            $valeur = (int)($_POST[$indic] ?? 0);
            $stmtCredits->execute([':exercice' => $exercice, ':indicateur' => $indic, ':valeur_montant' => 0, ':valeur_effectif' => $valeur]);
        }
        
        $message = "Annexe enregistree avec succes !";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES EXISTANTES
// ============================================================
$membres_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM annexe_membres WHERE exercice = :exercice");
    $stmt->execute([':exercice' => $exercice]);
    foreach ($stmt->fetchAll() as $row) { $membres_data[$row['indicateur']] = $row['valeur_effectif']; }
} catch (PDOException $e) { }

$personnel_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM annexe_personnel WHERE exercice = :exercice");
    $stmt->execute([':exercice' => $exercice]);
    foreach ($stmt->fetchAll() as $row) { $personnel_data[$row['indicateur']] = $row['valeur_effectif']; }
} catch (PDOException $e) { }

$depots_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM annexe_depots WHERE exercice = :exercice");
    $stmt->execute([':exercice' => $exercice]);
    foreach ($stmt->fetchAll() as $row) { $depots_data[$row['indicateur']] = $row['valeur_montant']; }
} catch (PDOException $e) { }

$credits_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM annexe_credits WHERE exercice = :exercice");
    $stmt->execute([':exercice' => $exercice]);
    foreach ($stmt->fetchAll() as $row) {
        $credits_data[$row['indicateur']] = ($row['valeur_montant'] > 0) ? $row['valeur_montant'] : $row['valeur_effectif'];
    }
} catch (PDOException $e) { }

// ============================================================
// DONNÉES CALCULÉES AUTOMATIQUEMENT
// ============================================================
$total_clients = 0;
$hommes = 0;
$femmes = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM clients WHERE statut = 'actif'");
    $stmt->execute();
    $total_clients = (int)$stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("SELECT SUM(CASE WHEN genre = 'Masculin' THEN 1 ELSE 0 END) as hommes, SUM(CASE WHEN genre = 'Feminin' THEN 1 ELSE 0 END) as femmes FROM clients WHERE statut = 'actif'");
    $stmt->execute();
    $result = $stmt->fetch();
    $hommes = (int)$result['hommes'];
    $femmes = (int)$result['femmes'];
} catch (PDOException $e) { }

$total_personnel = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM utilisateurs WHERE role != 'Client' AND etat = 'actif'");
    $stmt->execute();
    $total_personnel = (int)$stmt->fetch()['total'];
} catch (PDOException $e) { }

$total_encours_credits = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e 
        ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
    ");
    $stmt->execute();
    $total_encours_credits = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

$total_decaissements = 0;
$nb_decaissements = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as nb, COALESCE(SUM(montant), 0) as total
        FROM dossiers
        WHERE date_octroi BETWEEN :debut AND :fin
          AND statut IN ('actif', 'approuve')
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $nb_decaissements = (int)$result['nb'];
    $total_decaissements = (float)$result['total'];
} catch (PDOException $e) { }

$total_epargne = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde), 0) as total
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0
    ");
    $stmt->execute();
    $total_epargne = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

$montant_moyen_credit = ($nb_decaissements > 0) ? $total_decaissements / $nb_decaissements : 0;

// ============================================================
// CLASSE FPDF
// ============================================================
if ($format === 'pdf') {
    if (ob_get_length()) ob_end_clean();
    
    $fpdf_path = __DIR__ . '/fpdf/fpdf.php';
    $alt_fpdf_path = dirname(__DIR__) . '/fpdf/fpdf.php';
    
    if (file_exists($fpdf_path)) {
        require_once($fpdf_path);
    } elseif (file_exists($alt_fpdf_path)) {
        require_once($alt_fpdf_path);
    } else {
        die("Erreur: La bibliotheque FPDF n'est pas trouvee.");
    }
    
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
            $this->Cell(0, 7, $this->convert('ANNEXE AU RAPPORT ANNUEL'), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, $this->convert('Instruction n°018-12-2010 du 29 decembre 2010'), 0, 1, 'L');
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
            $this->SetFillColor(200, 220, 255);
            $this->Cell(0, 8, $this->convert($label), 0, 1, 'L', true);
            $this->Ln(2);
        }
        
        function TableHeader($cols) {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(240, 240, 240);
            foreach ($cols as $col) {
                $this->Cell($col['w'], 7, $this->convert($col['label']), 1, 0, $col['align'] ?? 'L', true);
            }
            $this->Ln();
        }
        
        function TableRow($cols, $data) {
            $this->SetFont('Arial', '', 8);
            foreach ($cols as $i => $col) {
                $val = $data[$i] ?? '';
                $this->Cell($col['w'], 6, $this->convert($val), 1, 0, $col['align'] ?? 'L');
            }
            $this->Ln();
        }
        
        function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }
    
    $pdf = new PDF_DIMF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(10, 35, 10);
    $pdf->AddPage();
    
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, $pdf->convert('Periode : ' . $lib_periode), 0, 1, 'C');
    $pdf->Ln(5);
    
    // Tableau 1.1
    $pdf->SectionTitle('Tableau n°1.1 - Nombre de membres, beneficiaires ou clients (en unites)');
    $cols = [['w' => 100, 'label' => 'INDICATEUR', 'align' => 'L'], ['w' => 70, 'label' => 'VALEUR', 'align' => 'R']];
    $pdf->TableHeader($cols);
    $pdf->TableRow($cols, ['Y01101 - Nombre total de membres beneficiaires ou clients', $pdf->montant($membres_data['Y01101'] ?? $total_clients)]);
    $pdf->TableRow($cols, ['Y01102 - Nombre de personnes physiques non-membres', number_format($membres_data['Y01102'] ?? 0, 0, ',', ' ')]);
    $pdf->TableRow($cols, ['Y01103 - Hommes', number_format($membres_data['Y01103'] ?? $hommes, 0, ',', ' ')]);
    $pdf->TableRow($cols, ['Y01104 - Femmes', number_format($membres_data['Y01104'] ?? $femmes, 0, ',', ' ')]);
    $pdf->TableRow($cols, ['Y01105 - Nombre de personnes morales', number_format($membres_data['Y01105'] ?? 0, 0, ',', ' ')]);
    $pdf->Ln(5);
    
    // Tableau 1.2
    $pdf->SectionTitle('Tableau n°1.2 - Effectif des dirigeants et du personnel employe (en unites)');
    $pdf->TableHeader($cols);
    $pdf->TableRow($cols, ['Y01205 - Effectifs total des employes', number_format($personnel_data['Y01205'] ?? $total_personnel, 0, ',', ' ')]);
    $pdf->TableRow($cols, ['Y01206 - Dirigeants', number_format($personnel_data['Y01206'] ?? 0, 0, ',', ' ')]);
    $pdf->TableRow($cols, ['Y01210 - Agents permanents', number_format($personnel_data['Y01210'] ?? 0, 0, ',', ' ')]);
    $pdf->TableRow($cols, ['Y01211 - Agents contractuels', number_format($personnel_data['Y01211'] ?? 0, 0, ',', ' ')]);
    $pdf->Ln(5);
    
    // Tableau 3.1
    $pdf->SectionTitle('Tableau n°3.1 - Evolution du montant des depots (en FCFA)');
    $pdf->TableHeader($cols);
    $pdf->TableRow($cols, ['Y03101 - Montant total des depots des membres', $pdf->montant($depots_data['Y03101'] ?? $total_epargne)]);
    $pdf->TableRow($cols, ['Y03103 - Montant des depots des hommes', $pdf->montant($depots_data['Y03103'] ?? 0)]);
    $pdf->TableRow($cols, ['Y03104 - Montant des depots des femmes', $pdf->montant($depots_data['Y03104'] ?? 0)]);
    $pdf->Ln(5);
    
    // Tableau 4.1
    $pdf->SectionTitle('Tableau n°4.1 - Evolution du montant annuel des prets accordes (en FCFA)');
    $pdf->TableHeader($cols);
    $pdf->TableRow($cols, ['Y04101 - Montant des prets accordes', $pdf->montant($credits_data['Y04101'] ?? $total_decaissements)]);
    $pdf->TableRow($cols, ['Y04103 - Montant des prets accordes aux hommes', $pdf->montant($credits_data['Y04103'] ?? 0)]);
    $pdf->TableRow($cols, ['Y04104 - Montant des prets accordes aux femmes', $pdf->montant($credits_data['Y04104'] ?? 0)]);
    $pdf->Ln(5);
    
    // Tableau 4.2
    $pdf->SectionTitle('Tableau n°4.2 - Evolution du nombre de prets accordes dans l\'annee (en unite)');
    $pdf->TableHeader($cols);
    $pdf->TableRow($cols, ['Y04201 - Nombre total des prets accordes', number_format($credits_data['Y04201'] ?? $nb_decaissements, 0, ',', ' ')]);
    $pdf->TableRow($cols, ['Y04203 - Nombre de prets accordes aux hommes', number_format($credits_data['Y04203'] ?? 0, 0, ',', ' ')]);
    $pdf->TableRow($cols, ['Y04204 - Nombre de prets accordes aux femmes', number_format($credits_data['Y04204'] ?? 0, 0, ',', ' ')]);
    $pdf->TableRow($cols, ['Y04206 - Montant moyen des prets accordes', $pdf->montant($montant_moyen_credit)]);
    $pdf->Ln(5);
    
    // Tableau 4.4
    $pdf->SectionTitle('Tableau n°4.4 - Encours de credits au 31 decembre (en FCFA)');
    $pdf->TableHeader($cols);
    $pdf->TableRow($cols, ['Y04401 - Encours total de credits', $pdf->montant($credits_data['Y04401'] ?? $total_encours_credits)]);
    
    $pdf->Output('I', 'ANNEXE_RAPPORT_' . $exercice . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANNEX_RAPP_AN - Annexe au rapport annuel</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; padding: 24px; }
        .dashboard { max-width: 1200px; margin: 0 auto; }
        
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
        .btn-save { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 12px 28px; font-weight: 500; font-size: 0.9rem; cursor: pointer; transition: 0.2s; }
        .btn-save:hover { background: #2563eb; transform: translateY(-1px); }
        
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
        
        .form-section { margin-bottom: 30px; }
        .form-section h3 { font-size: 1rem; font-weight: 600; color: #1e40af; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-weight: 600; font-size: 0.8rem; color: #4b5563; }
        .form-group input { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 12px; font-size: 0.9rem; }
        .form-group input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }
        .form-group .calculated-value { background: #f0fdf4; font-weight: 500; }
        .form-group small { font-size: 0.7rem; color: #16a34a; }
        
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .alert { padding: 14px 20px; border-radius: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #ecfdf5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border-left: 4px solid #ef4444; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            .form-row { grid-template-columns: 1fr; }
        }
        
        @media print {
            body { background: white; padding: 0; }
            .btn-group, .footer, .filters-row, .btn-save, #filtersCard, .alert { display: none !important; }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-file-alt"></i> ANNEXE AU RAPPORT ANNUEL</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">Instruction n°018-12-2010 du 29 decembre 2010</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <?php
            $params = [
                'exercice' => $exercice,
                'type_periode' => $type_periode,
                'mois' => $mois,
                'trimestre' => $trimestre,
                'semestre' => $semestre,
                'format' => 'pdf'
            ];
            ?>
            <a class="btn-pdf" href="?<?= http_build_query($params) ?>" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
    </div>

    <!-- Filtres dynamiques -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
            <div class="filters-row">
                <div class="filter-item">
                    <label>Annee</label>
                    <select id="exerciceSelect">
                        <?php for ($y = 2020; $y <= date('Y')+1; $y++): ?>
                            <option value="<?= $y ?>" <?= $y==$exercice?'selected':'' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Type de periode</label>
                    <select id="typePeriodeSelect">
                        <option value="mensuel"   <?= $type_periode=='mensuel'  ?'selected':'' ?>>Mensuel</option>
                        <option value="trimestre" <?= $type_periode=='trimestre'?'selected':'' ?>>Trimestre</option>
                        <option value="semestre"  <?= $type_periode=='semestre' ?'selected':'' ?>>Semestre</option>
                        <option value="annuel"    <?= $type_periode=='annuel'   ?'selected':'' ?>>Annuel</option>
                    </select>
                </div>
                <div class="filter-item" id="dynamicSelectContainer">
                    <?php
                    if ($type_periode == 'mensuel') {
                        echo '<label>Mois</label><select id="moisSelect">';
                        for ($m=1;$m<=12;$m++) { $s=($m==$mois)?'selected':''; echo "<option value='$m' $s>".str_pad($m,2,'0',STR_PAD_LEFT)." - ".date('F',mktime(0,0,0,$m,1))."</option>"; }
                        echo '</select>';
                    } elseif ($type_periode == 'trimestre') {
                        echo '<label>Trimestre</label><select id="trimestreSelect">';
                        for ($t=1;$t<=4;$t++) { $s=($t==$trimestre)?'selected':''; echo "<option value='$t' $s>$t".($t==1?'er':'eme')." Trimestre</option>"; }
                        echo '</select>';
                    } elseif ($type_periode == 'semestre') {
                        echo '<label>Semestre</label><select id="semestreSelect">';
                        for ($s=1;$s<=2;$s++) { $sel=($s==$semestre)?'selected':''; echo "<option value='$s' $sel>$s".($s==1?'er':'e')." semestre</option>"; }
                        echo '</select>';
                    } else {
                        echo '<label>Periode</label><input type="text" disabled value="Annee complete" style="background:#f3f4f6;cursor:default;">';
                    }
                    ?>
                </div>
                <button class="btn-apply" onclick="appliquerFiltres()"><i class="fas fa-filter"></i> Appliquer</button>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Periode : <?= $lib_periode ?> (arrete au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </div>

    <?php if($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <i class="fas fa-<?= $message_type == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="info-box">
        <i class="fas fa-info-circle"></i>
        <div><strong>Note :</strong> Conformement a l'Instruction n°018-12-2010 du 29 decembre 2010, les SFD doivent fournir les informations complementaires suivantes. Les champs surlignes en vert sont calcules automatiquement.</div>
    </div>

    <form method="post" action="">
        <input type="hidden" name="action" value="save">
        
        <!-- Tableau 1.1 - Membres -->
        <div class="card">
            <div class="card-header"><i class="fas fa-users"></i> Tableau n°1.1 - Nombre de membres, beneficiaires ou clients (en unites)</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Y01101 - Nombre total de membres beneficiaires ou clients</label>
                        <input type="number" name="Y01101" value="<?= $membres_data['Y01101'] ?? $total_clients ?>" class="calculated-value">
                        <small><i class="fas fa-calculator"></i> Calcule automatiquement</small>
                    </div>
                    <div class="form-group">
                        <label>Y01102 - Nombre de personnes physiques non-membres d'un groupement</label>
                        <input type="number" name="Y01102" value="<?= $membres_data['Y01102'] ?? 0 ?>">
                    </div>
                    <div class="form-group">
                        <label>Y01103 - Hommes</label>
                        <input type="number" name="Y01103" value="<?= $membres_data['Y01103'] ?? $hommes ?>" class="calculated-value">
                        <small><i class="fas fa-calculator"></i> Calcule automatiquement</small>
                    </div>
                    <div class="form-group">
                        <label>Y01104 - Femmes</label>
                        <input type="number" name="Y01104" value="<?= $membres_data['Y01104'] ?? $femmes ?>" class="calculated-value">
                        <small><i class="fas fa-calculator"></i> Calcule automatiquement</small>
                    </div>
                    <div class="form-group">
                        <label>Y01105 - Nombre de personnes morales</label>
                        <input type="number" name="Y01105" value="<?= $membres_data['Y01105'] ?? 0 ?>">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tableau 1.2 - Personnel -->
        <div class="card">
            <div class="card-header"><i class="fas fa-briefcase"></i> Tableau n°1.2 - Effectif des dirigeants et du personnel employe (en unites)</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Y01205 - Effectifs total des employes</label>
                        <input type="number" name="Y01205" value="<?= $personnel_data['Y01205'] ?? $total_personnel ?>" class="calculated-value">
                        <small><i class="fas fa-calculator"></i> Calcule automatiquement</small>
                    </div>
                    <div class="form-group">
                        <label>Y01206 - Dirigeants</label>
                        <input type="number" name="Y01206" value="<?= $personnel_data['Y01206'] ?? 0 ?>">
                    </div>
                    <div class="form-group">
                        <label>Y01210 - Agents permanents</label>
                        <input type="number" name="Y01210" value="<?= $personnel_data['Y01210'] ?? 0 ?>">
                    </div>
                    <div class="form-group">
                        <label>Y01211 - Agents contractuels</label>
                        <input type="number" name="Y01211" value="<?= $personnel_data['Y01211'] ?? 0 ?>">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tableau 3.1 - Depots -->
        <div class="card">
            <div class="card-header"><i class="fas fa-piggy-bank"></i> Tableau n°3.1 - Evolution du montant des depots (en FCFA)</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Y03101 - Montant total des depots des membres</label>
                        <input type="number" name="Y03101" value="<?= number_format($depots_data['Y03101'] ?? $total_epargne, 0, '', '') ?>" class="calculated-value">
                        <small><i class="fas fa-calculator"></i> Calcule automatiquement</small>
                    </div>
                    <div class="form-group">
                        <label>Y03103 - Montant des depots des hommes</label>
                        <input type="number" name="Y03103" value="<?= number_format($depots_data['Y03103'] ?? 0, 0, '', '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Y03104 - Montant des depots des femmes</label>
                        <input type="number" name="Y03104" value="<?= number_format($depots_data['Y03104'] ?? 0, 0, '', '') ?>">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tableau 4.1 - Montant des prets -->
        <div class="card">
            <div class="card-header"><i class="fas fa-hand-holding-usd"></i> Tableau n°4.1 - Evolution du montant annuel des prets accordes (en FCFA)</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Y04101 - Montant des prets accordes</label>
                        <input type="number" name="Y04101" value="<?= number_format($credits_data['Y04101'] ?? $total_decaissements, 0, '', '') ?>" class="calculated-value">
                        <small><i class="fas fa-calculator"></i> Calcule automatiquement</small>
                    </div>
                    <div class="form-group">
                        <label>Y04103 - Montant des prets accordes aux hommes</label>
                        <input type="number" name="Y04103" value="<?= number_format($credits_data['Y04103'] ?? 0, 0, '', '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Y04104 - Montant des prets accordes aux femmes</label>
                        <input type="number" name="Y04104" value="<?= number_format($credits_data['Y04104'] ?? 0, 0, '', '') ?>">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tableau 4.2 - Nombre de prets -->
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-line"></i> Tableau n°4.2 - Evolution du nombre de prets accordes dans l'annee (en unite)</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Y04201 - Nombre total des prets accordes</label>
                        <input type="number" name="Y04201" value="<?= $credits_data['Y04201'] ?? $nb_decaissements ?>" class="calculated-value">
                        <small><i class="fas fa-calculator"></i> Calcule automatiquement</small>
                    </div>
                    <div class="form-group">
                        <label>Y04203 - Nombre de prets accordes aux hommes</label>
                        <input type="number" name="Y04203" value="<?= $credits_data['Y04203'] ?? 0 ?>">
                    </div>
                    <div class="form-group">
                        <label>Y04204 - Nombre de prets accordes aux femmes</label>
                        <input type="number" name="Y04204" value="<?= $credits_data['Y04204'] ?? 0 ?>">
                    </div>
                    <div class="form-group">
                        <label>Y04206 - Montant moyen des prets accordes (FCFA)</label>
                        <input type="text" value="<?= number_format($montant_moyen_credit, 0, ',', ' ') ?>" class="calculated-value" readonly>
                        <small><i class="fas fa-calculator"></i> Calcule automatiquement</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tableau 4.4 - Encours -->
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-simple"></i> Tableau n°4.4 - Encours de credits au 31 decembre (en FCFA)</div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Y04401 - Encours total de credits</label>
                        <input type="number" name="Y04401" value="<?= number_format($credits_data['Y04401'] ?? $total_encours_credits, 0, '', '') ?>" class="calculated-value">
                        <small><i class="fas fa-calculator"></i> Calcule automatiquement</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="text-align: center; margin: 20px 0;">
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer l'annexe</button>
        </div>
    </form>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base Mandigo<br>
        Periode : <?= $exercice ?> - <?= $trimestre ?>eme trimestre
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
            html = '<label>Mois</label><select id="moisSelect">';
            for (let m = 1; m <= 12; m++) {
                const s = (m === currentMois) ? 'selected' : '';
                const n = new Date(2000, m-1, 1).toLocaleString('fr', {month:'long'});
                html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select id="trimestreSelect">';
            for (let t = 1; t <= 4; t++) {
                const s = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${s}>${t}${t === 1 ? 'er' : 'eme'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select id="semestreSelect">';
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

    function appliquerFiltres() {
        const exercice = document.getElementById('exerciceSelect').value;
        const type = document.getElementById('typePeriodeSelect').value;
        let url = 'annex_rap.php?exercice=' + exercice + '&type_periode=' + type;
        
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        
        window.location.href = url;
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        
        let data = [
            ['ANNEXE AU RAPPORT ANNUEL'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['Tableau n°1.1 - Nombre de membres, beneficiaires ou clients'],
            ['INDICATEUR', 'VALEUR'],
            ['Y01101 - Nombre total de membres', <?= $membres_data['Y01101'] ?? $total_clients ?>],
            ['Y01102 - Personnes physiques non-membres', <?= $membres_data['Y01102'] ?? 0 ?>],
            ['Y01103 - Hommes', <?= $membres_data['Y01103'] ?? $hommes ?>],
            ['Y01104 - Femmes', <?= $membres_data['Y01104'] ?? $femmes ?>],
            ['Y01105 - Personnes morales', <?= $membres_data['Y01105'] ?? 0 ?>],
            [],
            ['Tableau n°1.2 - Effectif du personnel'],
            ['Y01205 - Effectifs total des employes', <?= $personnel_data['Y01205'] ?? $total_personnel ?>],
            ['Y01206 - Dirigeants', <?= $personnel_data['Y01206'] ?? 0 ?>],
            ['Y01210 - Agents permanents', <?= $personnel_data['Y01210'] ?? 0 ?>],
            ['Y01211 - Agents contractuels', <?= $personnel_data['Y01211'] ?? 0 ?>],
            [],
            ['Tableau n°3.1 - Montant des depots (FCFA)'],
            ['Y03101 - Montant total des depots', <?= $depots_data['Y03101'] ?? $total_epargne ?>],
            ['Y03103 - Depots des hommes', <?= $depots_data['Y03103'] ?? 0 ?>],
            ['Y03104 - Depots des femmes', <?= $depots_data['Y03104'] ?? 0 ?>],
            [],
            ['Tableau n°4.1 - Montant des prets accordes (FCFA)'],
            ['Y04101 - Montant des prets accordes', <?= $credits_data['Y04101'] ?? $total_decaissements ?>],
            ['Y04103 - Prets aux hommes', <?= $credits_data['Y04103'] ?? 0 ?>],
            ['Y04104 - Prets aux femmes', <?= $credits_data['Y04104'] ?? 0 ?>],
            [],
            ['Tableau n°4.2 - Nombre de prets accordes'],
            ['Y04201 - Nombre total des prets', <?= $credits_data['Y04201'] ?? $nb_decaissements ?>],
            ['Y04203 - Prets aux hommes', <?= $credits_data['Y04203'] ?? 0 ?>],
            ['Y04204 - Prets aux femmes', <?= $credits_data['Y04204'] ?? 0 ?>],
            ['Y04206 - Montant moyen des prets', <?= $montant_moyen_credit ?>],
            [],
            ['Tableau n°4.4 - Encours de credits'],
            ['Y04401 - Encours total de credits', <?= $credits_data['Y04401'] ?? $total_encours_credits ?>]
        ];
        
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), "ANNEXE_RAPPORT");
        XLSX.writeFile(wb, 'ANNEXE_RAPPORT_<?= $exercice ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>