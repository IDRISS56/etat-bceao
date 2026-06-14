<?php
// DIMF_2016.php - État d'affectation du résultat
// Déclaration SICS-BCEAO

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
$date_fin_exercice = $exercice . '-12-31';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        // Création de la table si elle n'existe pas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS affectation_resultat (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                type_affectation VARCHAR(50) NOT NULL,
                montant DECIMAL(15,2) DEFAULT 0,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_exercice_type (exercice, type_affectation)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        if ($_POST['action'] == 'save') {
            // Supprimer les anciennes données pour l'exercice
            $stmtDel = $pdo->prepare("DELETE FROM affectation_resultat WHERE exercice = :exercice");
            $stmtDel->execute([':exercice' => $exercice]);
            
            // Insérer les nouvelles données
            $stmtIns = $pdo->prepare("
                INSERT INTO affectation_resultat (exercice, type_affectation, montant, description)
                VALUES (:exercice, :type_affectation, :montant, :description)
            ");
            
            // Résultat à affecter
            $resultat = (float)($_POST['resultat'] ?? 0);
            $report_anterieur = (float)($_POST['report_anterieur'] ?? 0);
            
            $stmtIns->execute([
                ':exercice' => $exercice,
                ':type_affectation' => 'RESULTAT_A_AFFECTER',
                ':montant' => $resultat,
                ':description' => 'Resultat de l exercice'
            ]);
            
            $stmtIns->execute([
                ':exercice' => $exercice,
                ':type_affectation' => 'REPORT_ANTERIEUR',
                ':montant' => $report_anterieur,
                ':description' => 'Report a nouveau anterieur'
            ]);
            
            // Affectations bénéficiaires
            $reserve_generale = (float)($_POST['reserve_generale'] ?? 0);
            $reserve_facultative = (float)($_POST['reserve_facultative'] ?? 0);
            $autres_reserves = (float)($_POST['autres_reserves'] ?? 0);
            $report_nouveau = (float)($_POST['report_nouveau'] ?? 0);
            $autres_affectations = (float)($_POST['autres_affectations'] ?? 0);
            
            if ($reserve_generale > 0) {
                $stmtIns->execute([':exercice' => $exercice, ':type_affectation' => 'RESERVE_GENERALE', ':montant' => $reserve_generale, ':description' => 'Reserve generale']);
            }
            if ($reserve_facultative > 0) {
                $stmtIns->execute([':exercice' => $exercice, ':type_affectation' => 'RESERVE_FACULTATIVE', ':montant' => $reserve_facultative, ':description' => 'Reserve facultative']);
            }
            if ($autres_reserves > 0) {
                $stmtIns->execute([':exercice' => $exercice, ':type_affectation' => 'AUTRES_RESERVES', ':montant' => $autres_reserves, ':description' => 'Autres reserves']);
            }
            if ($report_nouveau > 0) {
                $stmtIns->execute([':exercice' => $exercice, ':type_affectation' => 'REPORT_NOUVEAU', ':montant' => $report_nouveau, ':description' => 'Report a nouveau beneficiaire']);
            }
            if ($autres_affectations > 0) {
                $stmtIns->execute([':exercice' => $exercice, ':type_affectation' => 'AUTRES_AFFECTATIONS', ':montant' => $autres_affectations, ':description' => 'Autres affectations']);
            }
            
            // Affectations déficitaires
            $prelevement_reserves = (float)($_POST['prelevement_reserves'] ?? 0);
            $report_deficitaire = (float)($_POST['report_deficitaire'] ?? 0);
            
            if ($prelevement_reserves > 0) {
                $stmtIns->execute([':exercice' => $exercice, ':type_affectation' => 'PRELEVEMENT_RESERVES', ':montant' => $prelevement_reserves, ':description' => 'Prelevement sur les reserves']);
            }
            if ($report_deficitaire > 0) {
                $stmtIns->execute([':exercice' => $exercice, ':type_affectation' => 'REPORT_DEFICITAIRE', ':montant' => $report_deficitaire, ':description' => 'Report a nouveau deficit aire']);
            }
            
            $message = "Affectation du resultat enregistree avec succes !";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES COMPTABLES
// ============================================================

// Calcul du résultat de l'exercice
$resultat_exercice = 0;
try {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN pc.classe_compte = '7' THEN e.montant_credit - e.montant_debit ELSE 0 END), 0) as produits,
            COALESCE(SUM(CASE WHEN pc.classe_compte = '6' THEN e.montant_debit - e.montant_credit ELSE 0 END), 0) as charges
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte IN ('6', '7')
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmt->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_periode
    ]);
    $result = $stmt->fetch();
    $resultat_exercice = (float)$result['produits'] - (float)$result['charges'];
} catch (PDOException $e) {
    $resultat_exercice = 0;
}

// Récupération du report à nouveau antérieur
$report_anterieur = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '11%'
          AND e.date_ecriture < :date_debut
    ");
    $stmt->execute([':date_debut' => $date_debut_exercice]);
    $result = $stmt->fetch();
    $report_anterieur = (float)$result['solde'];
} catch (PDOException $e) {
    $report_anterieur = 0;
}

// Récupération des données existantes
$affectations_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM affectation_resultat WHERE exercice = :exercice");
    $stmt->execute([':exercice' => $exercice]);
    $results = $stmt->fetchAll();
    
    foreach ($results as $row) {
        $affectations_data[$row['type_affectation']] = (float)$row['montant'];
    }
} catch (PDOException $e) {
    $affectations_data = [];
}

// Récupération de la réserve générale existante
$reserve_generale_solde = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '106%'
          AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $reserve_generale_solde = (float)$result['solde'];
} catch (PDOException $e) {
    $reserve_generale_solde = 0;
}

// Valeurs par défaut
$default_values = [
    'resultat' => $resultat_exercice,
    'report_anterieur' => $report_anterieur,
    'reserve_generale' => $affectations_data['RESERVE_GENERALE'] ?? 0,
    'reserve_facultative' => $affectations_data['RESERVE_FACULTATIVE'] ?? 0,
    'autres_reserves' => $affectations_data['AUTRES_RESERVES'] ?? 0,
    'report_nouveau' => $affectations_data['REPORT_NOUVEAU'] ?? 0,
    'autres_affectations' => $affectations_data['AUTRES_AFFECTATIONS'] ?? 0,
    'prelevement_reserves' => $affectations_data['PRELEVEMENT_RESERVES'] ?? 0,
    'report_deficitaire' => $affectations_data['REPORT_DEFICITAIRE'] ?? 0
];

// Calcul du résultat à affecter
$resultat_a_affecter = $default_values['resultat'] + $default_values['report_anterieur'];

// Calcul du total des affectations
$total_affectations = $default_values['reserve_generale'] + $default_values['reserve_facultative'] 
                    + $default_values['autres_reserves'] + $default_values['report_nouveau'] 
                    + $default_values['autres_affectations'];

$total_deficit = $default_values['prelevement_reserves'] + $default_values['report_deficitaire'];

// Vérification de l'équilibre
$difference = $resultat_a_affecter - $total_affectations;
$equilibre_ok = ($difference == 0);
$min_reserve_requis = ($resultat_a_affecter > 0) ? $resultat_a_affecter * 0.15 : 0;

// Fonction utilitaire pour formater les montants
function format_montant($val) {
    return number_format((float)$val, 0, ',', ' ') . ' F';
}

// ============================================================
// CLASSE FPDF
// ============================================================
if ($format === 'pdf') {
    // NETTOYER LE BUFFER DE SORTIE
    if (ob_get_length()) {
        ob_end_clean();
    }
    
    // Recherche du fichier FPDF
    $fpdf_path = __DIR__ . '../../fpdf/fpdf.php';
    $alt_fpdf_path = dirname(__DIR__) . '../../fpdf/fpdf.php';
    
    if (file_exists($fpdf_path)) {
        require_once($fpdf_path);
    } elseif (file_exists($alt_fpdf_path)) {
        require_once($alt_fpdf_path);
    } else {
        die("Erreur: La bibliotheque FPDF n'est pas trouvee. Veuillez telecharger FPDF depuis http://www.fpdf.org/ et l'installer dans le dossier 'fpdf/'");
    }
    
    class PDF_DIMF extends FPDF {
        public $codeDimf = 'DIMF_2016';
        public $titreDimf = "Etat d'affectation du resultat";
        public $nomSfd = 'SFD';
        public $periode = '';
        public $exercice = '';

        // Fonction de conversion sans caractères problématiques
        function convert($str) {
            // Supprimer les caractères problématiques
            $str = str_replace(array('é', 'è', 'ê', 'ë', 'à', 'â', 'ä', 'î', 'ï', 'ô', 'ö', 'ù', 'û', 'ü', 'ç', 'É', 'È', 'Ê', 'Ë', 'À', 'Â', 'Ä', 'Î', 'Ï', 'Ô', 'Ö', 'Ù', 'Û', 'Ü', 'Ç'), 
                              array('e', 'e', 'e', 'e', 'a', 'a', 'a', 'i', 'i', 'o', 'o', 'u', 'u', 'u', 'c', 'E', 'E', 'E', 'E', 'A', 'A', 'A', 'I', 'I', 'O', 'O', 'U', 'U', 'U', 'C'), $str);
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
            $this->Cell(0, 7, $this->convert($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, $this->convert('SFD : ' . $this->nomSfd . '   |   Periode : ' . $this->periode . '   |   Exercice : ' . $this->exercice), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }

        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, $this->convert('SICS-BCEAO  •  Genere le ' . date('d/m/Y H:i:s') . '  •  Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
        }

        function SectionTitle($label) {
            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(0, 0, 0);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 7, $this->convert('  ' . strtoupper($label)), 0, 1, 'L', true);
            $this->SetTextColor(0, 0, 0);
            $this->Ln(1);
        }

        function TableRow2Cols($label, $value, $style = '') {
            if ($style == 'total') {
                $this->SetFillColor(240, 253, 244);
                $this->SetFont('Arial', 'B', 9);
                $fill = true;
            } else {
                $this->SetFillColor(255, 255, 255);
                $this->SetFont('Arial', '', 8);
                $fill = false;
            }
            $this->Cell(100, 7, $this->convert($label), 1, 0, 'L', $fill);
            $this->Cell(0, 7, $this->convert($value), 1, 1, 'R', $fill);
        }
        
        function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }

    $pdf = new PDF_DIMF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->nomSfd = isset($_SESSION['nom_sfd']) ? $_SESSION['nom_sfd'] : 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(15, 35, 15);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    // Détermination du résultat à affecter
    $pdf->SectionTitle('DETERMINATION DU RESULTAT A AFFECTER');
    $pdf->TableRow2Cols('L80 - Resultat de l\'exercice (benefice ou deficit)', $pdf->montant($default_values['resultat']));
    $pdf->TableRow2Cols('L70 - Report a nouveau anterieur (benefice ou deficit)', $pdf->montant($default_values['report_anterieur']));
    $pdf->TableRow2Cols('RESULTAT A AFFECTER (L80 + L70)', $pdf->montant($resultat_a_affecter), 'total');
    $pdf->Ln(5);
    
    if ($resultat_a_affecter >= 0) {
        // Affectation du résultat bénéficiaire
        $pdf->SectionTitle('AFFECTATION DU RESULTAT BENEFICIAIRE');
        $pdf->TableRow2Cols('772 - Reserve generale (minimum 15% du benefice)', $pdf->montant($default_values['reserve_generale']));
        $pdf->TableRow2Cols('773 - Reserve facultative', $pdf->montant($default_values['reserve_facultative']));
        $pdf->TableRow2Cols('774 - Autres reserves', $pdf->montant($default_values['autres_reserves']));
        $pdf->TableRow2Cols('776 - Report a nouveau beneficiaire', $pdf->montant($default_values['report_nouveau']));
        $pdf->TableRow2Cols('777 - Autres affectations', $pdf->montant($default_values['autres_affectations']));
        $pdf->TableRow2Cols('TOTAL AFFECTATIONS', $pdf->montant($total_affectations), 'total');
        
        $min_reserve = $resultat_a_affecter * 0.15;
        if ($default_values['reserve_generale'] < $min_reserve) {
            $pdf->Ln(3);
            $pdf->SetFont('Arial', 'I', 8);
            $pdf->SetTextColor(220, 38, 38);
            $pdf->Cell(0, 5, $pdf->convert('Attention : La dotation a la reserve generale (' . $pdf->montant($default_values['reserve_generale']) . ') est inferieure au minimum requis de ' . $pdf->montant($min_reserve) . ' (15%).'), 0, 1);
        }
    } else {
        // Affectation du résultat déficitaire
        $pdf->SectionTitle('AFFECTATION DU RESULTAT DEFICITAIRE');
        $pdf->TableRow2Cols('776 - Report a nouveau deficit aire', $pdf->montant($default_values['report_deficitaire']));
        $pdf->TableRow2Cols('778 - Prelevement sur les reserves', $pdf->montant($default_values['prelevement_reserves']));
        $pdf->TableRow2Cols('TOTAL AFFECTATIONS', $pdf->montant($total_deficit), 'total');
    }
    
    $pdf->Ln(5);
    
    // Vérification équilibre
    $pdf->SectionTitle('VERIFICATION DE L\'EQUILIBRE');
    if ($equilibre_ok) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(22, 163, 74);
        $pdf->Cell(0, 7, $pdf->convert('✓ EQUILIBRE - Le resultat a affecter correspond au total des affectations.'), 0, 1);
    } else {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(220, 38, 38);
        $pdf->Cell(0, 7, $pdf->convert('✗ DESEQUILIBRE - Ecart de ' . $pdf->montant(abs($difference)) . ' FCFA.'), 0, 1);
    }
    
    $pdf->Output('I', 'DIMF_2016_' . $exercice . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2016 - Etat d'affectation du resultat</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; padding: 24px; }
        .dashboard { max-width: 1000px; margin: 0 auto; }
        
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
        .btn-save { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 8px 24px; font-weight: 500; font-size: 0.85rem; cursor: pointer; transition: 0.2s; }
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
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 5px; color: #555; font-size: 0.8rem; }
        .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 0.9rem; text-align: right; font-family: monospace; font-weight: 500; }
        .form-group input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }
        
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .alert { padding: 14px 20px; border-radius: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #ecfdf5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border-left: 4px solid #ef4444; }
        
        .calculated-value { background: #e8f5e9 !important; font-weight: bold; }
        .equilibre-ok { color: #2e7d32; }
        .equilibre-ko { color: #c62828; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
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
            <h1><i class="fas fa-chart-pie"></i> DIMF_2016 - AFFECTATION DU RESULTAT</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Affectation des resultats</div>
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

    <!-- Note d'information -->
    <div class="card">
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Note :</strong> Conformement a la reglementation, les SFD doivent affecter au minimum 15% du benefice a la reserve generale.<br>
                    Reserve generale actuelle : <strong><?= number_format($reserve_generale_solde, 0, ',', ' ') ?> FCFA</strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Formulaire principal -->
    <form method="post" action="">
        <input type="hidden" name="action" value="save">

        <!-- Détermination du résultat à affecter -->
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-line"></i> DETERMINATION DU RESULTAT A AFFECTER</div>
            <div class="card-body">
                <div class="form-group">
                    <label>L80 - Resultat de l'exercice (benefice ou deficit)</label>
                    <small style="display:block; color:#6b7280; margin-bottom:5px;">Calcule automatiquement a partir des produits et charges</small>
                    <input type="number" name="resultat" step="1" id="resultat" class="calculated-value" value="<?= number_format($default_values['resultat'], 0, '', '') ?>">
                </div>
                <div class="form-group">
                    <label>L70 - Report a nouveau anterieur (benefice ou deficit)</label>
                    <small style="display:block; color:#6b7280; margin-bottom:5px;">Solde des comptes de report a nouveau</small>
                    <input type="number" name="report_anterieur" step="1" id="report_anterieur" class="calculated-value" value="<?= number_format($default_values['report_anterieur'], 0, '', '') ?>">
                </div>
                <div class="form-group" style="background:#f0fdf4; padding:12px; border-radius:12px; margin-top:10px;">
                    <label style="font-weight:bold;">RESULTAT A AFFECTER (L80 + L70)</label>
                    <input type="text" id="resultat_a_affecter_display" readonly style="background:#f0fdf4; font-weight:bold; font-size:1.1rem;" value="<?= number_format($resultat_a_affecter, 0, ',', ' ') ?> FCFA">
                </div>
            </div>
        </div>

        <?php if ($resultat_a_affecter >= 0): ?>
        <!-- Affectation du résultat bénéficiaire -->
        <div class="card">
            <div class="card-header"><i class="fas fa-plus-circle"></i> AFFECTATION DU RESULTAT BENEFICIAIRE</div>
            <div class="card-body">
                <div class="form-group">
                    <label>772 - Reserve generale (minimum 15% du benefice)</label>
                    <input type="number" name="reserve_generale" step="1" id="reserve_generale" value="<?= number_format($default_values['reserve_generale'], 0, '', '') ?>">
                </div>
                <div class="form-group">
                    <label>773 - Reserve facultative</label>
                    <input type="number" name="reserve_facultative" step="1" id="reserve_facultative" value="<?= number_format($default_values['reserve_facultative'], 0, '', '') ?>">
                </div>
                <div class="form-group">
                    <label>774 - Autres reserves</label>
                    <input type="number" name="autres_reserves" step="1" id="autres_reserves" value="<?= number_format($default_values['autres_reserves'], 0, '', '') ?>">
                </div>
                <div class="form-group">
                    <label>776 - Report a nouveau beneficiaire</label>
                    <input type="number" name="report_nouveau" step="1" id="report_nouveau" value="<?= number_format($default_values['report_nouveau'], 0, '', '') ?>">
                </div>
                <div class="form-group">
                    <label>777 - Autres affectations</label>
                    <input type="number" name="autres_affectations" step="1" id="autres_affectations" value="<?= number_format($default_values['autres_affectations'], 0, '', '') ?>">
                </div>
                <div class="form-group" style="background:#f0fdf4; padding:12px; border-radius:12px; margin-top:10px;">
                    <label style="font-weight:bold;">TOTAL AFFECTATIONS</label>
                    <input type="text" id="total_affectations_display" readonly style="background:#f0fdf4; font-weight:bold;" value="<?= number_format($total_affectations, 0, ',', ' ') ?> FCFA">
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Affectation du résultat déficitaire -->
        <div class="card">
            <div class="card-header"><i class="fas fa-minus-circle"></i> AFFECTATION DU RESULTAT DEFICITAIRE</div>
            <div class="card-body">
                <div class="form-group">
                    <label>776 - Report a nouveau deficit aire</label>
                    <input type="number" name="report_deficitaire" step="1" id="report_deficitaire" value="<?= number_format($default_values['report_deficitaire'], 0, '', '') ?>">
                </div>
                <div class="form-group">
                    <label>778 - Prelevement sur les reserves</label>
                    <input type="number" name="prelevement_reserves" step="1" id="prelevement_reserves" value="<?= number_format($default_values['prelevement_reserves'], 0, '', '') ?>">
                </div>
                <div class="form-group" style="background:#f0fdf4; padding:12px; border-radius:12px; margin-top:10px;">
                    <label style="font-weight:bold;">TOTAL AFFECTATIONS</label>
                    <input type="text" id="total_affectations_display" readonly style="background:#f0fdf4; font-weight:bold;" value="<?= number_format($total_deficit, 0, ',', ' ') ?> FCFA">
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Vérification et sauvegarde -->
        <div class="card">
            <div class="card-header"><i class="fas fa-check-circle"></i> VERIFICATION ET SAUVEGARDE</div>
            <div class="card-body">
                <div class="info-box" id="verification-box">
                    <i class="fas fa-calculator"></i>
                    <div id="verification-message">Verification de l'equilibre...</div>
                </div>
                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit" class="btn-save" id="btn-submit"><i class="fas fa-save"></i> Enregistrer l'affectation</button>
                </div>
            </div>
        </div>
    </form>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base Mandigo
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
        let url = 'DIMF_2016.php?exercice=' + exercice + '&type_periode=' + type;
        
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        
        window.location.href = url;
    }

    function verifierEquilibre() {
        let resultat = parseFloat(document.getElementById('resultat').value) || 0;
        let reportAnterieur = parseFloat(document.getElementById('report_anterieur').value) || 0;
        let resultatAAffecter = resultat + reportAnterieur;
        
        document.getElementById('resultat_a_affecter_display').value = resultatAAffecter.toLocaleString('fr-FR') + ' FCFA';
        
        let totalAffectations = 0;
        <?php if ($resultat_a_affecter >= 0): ?>
            let reserveGenerale = parseFloat(document.getElementById('reserve_generale').value) || 0;
            let reserveFacultative = parseFloat(document.getElementById('reserve_facultative').value) || 0;
            let autresReserves = parseFloat(document.getElementById('autres_reserves').value) || 0;
            let reportNouveau = parseFloat(document.getElementById('report_nouveau').value) || 0;
            let autresAffectations = parseFloat(document.getElementById('autres_affectations').value) || 0;
            totalAffectations = reserveGenerale + reserveFacultative + autresReserves + reportNouveau + autresAffectations;
            
            let minReserve = resultatAAffecter * 0.15;
            let warningHtml = '';
            if (reserveGenerale < minReserve && resultatAAffecter > 0) {
                warningHtml = '<br><span style="color:#ef6c00;">⚠️ La dotation a la reserve generale (' + reserveGenerale.toLocaleString('fr-FR') + ' FCFA) est inferieure au minimum requis de ' + minReserve.toLocaleString('fr-FR') + ' FCFA (15%).</span>';
            }
        <?php else: ?>
            let reportDeficitaire = parseFloat(document.getElementById('report_deficitaire').value) || 0;
            let prelevementReserves = parseFloat(document.getElementById('prelevement_reserves').value) || 0;
            totalAffectations = reportDeficitaire + prelevementReserves;
            let warningHtml = '';
        <?php endif; ?>
        
        document.getElementById('total_affectations_display').value = totalAffectations.toLocaleString('fr-FR') + ' FCFA';
        
        let difference = resultatAAffecter - totalAffectations;
        let verificationBox = document.getElementById('verification-box');
        let submitBtn = document.getElementById('btn-submit');
        
        if (Math.abs(difference) < 1) {
            verificationBox.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981;"></i><div id="verification-message"><span style="color:#2e7d32;">✓ EQUILIBRE - Le resultat a affecter correspond au total des affectations.</span>' + (typeof warningHtml !== 'undefined' ? warningHtml : '') + '</div>';
            verificationBox.className = 'info-box';
            submitBtn.disabled = false;
        } else {
            verificationBox.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i><div id="verification-message"><span style="color:#c62828;">✗ DESEQUILIBRE - Ecart de ' + Math.abs(difference).toLocaleString('fr-FR') + ' FCFA. Veuillez ajuster les montants.</span>' + (typeof warningHtml !== 'undefined' ? warningHtml : '') + '</div>';
            verificationBox.className = 'alert alert-error';
            submitBtn.disabled = true;
        }
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        
        let data = [
            ['DIMF_2016 - ETAT D\'AFFECTATION DU RESULTAT'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['DETERMINATION DU RESULTAT A AFFECTER', ''],
            ['L80 - Resultat de l\'exercice', <?= $default_values['resultat'] ?>],
            ['L70 - Report a nouveau anterieur', <?= $default_values['report_anterieur'] ?>],
            ['RESULTAT A AFFECTER', <?= $resultat_a_affecter ?>],
            [],
            <?php if ($resultat_a_affecter >= 0): ?>
            ['AFFECTATION DU RESULTAT BENEFICIAIRE', ''],
            ['772 - Reserve generale', <?= $default_values['reserve_generale'] ?>],
            ['773 - Reserve facultative', <?= $default_values['reserve_facultative'] ?>],
            ['774 - Autres reserves', <?= $default_values['autres_reserves'] ?>],
            ['776 - Report a nouveau beneficiaire', <?= $default_values['report_nouveau'] ?>],
            ['777 - Autres affectations', <?= $default_values['autres_affectations'] ?>],
            ['TOTAL AFFECTATIONS', <?= $total_affectations ?>],
            <?php else: ?>
            ['AFFECTATION DU RESULTAT DEFICITAIRE', ''],
            ['776 - Report a nouveau deficit aire', <?= $default_values['report_deficitaire'] ?>],
            ['778 - Prelevement sur les reserves', <?= $default_values['prelevement_reserves'] ?>],
            ['TOTAL AFFECTATIONS', <?= $total_deficit ?>],
            <?php endif; ?>
            [],
            ['VERIFICATION', ''],
            ['Difference', <?= $difference ?>],
            ['Equilibre', '<?= $equilibre_ok ? "OK" : "DESEQUILIBRE" ?>']
        ];
        
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), "AFFECTATION_RESULTAT");
        XLSX.writeFile(wb, 'DIMF_2016_<?= $exercice ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        
        const inputs = ['resultat', 'report_anterieur', 'reserve_generale', 'reserve_facultative', 
                        'autres_reserves', 'report_nouveau', 'autres_affectations', 'report_deficitaire', 
                        'prelevement_reserves'];
        inputs.forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', verifierEquilibre);
                input.addEventListener('change', verifierEquilibre);
            }
        });
        verifierEquilibre();
    });
</script>
</body>
</html>