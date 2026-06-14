<?php
// DIMF_2018.php - État de traitement de réévaluation
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
            CREATE TABLE IF NOT EXISTS reevaluations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                bien_libelle VARCHAR(255) NOT NULL,
                date_reevaluation DATE,
                nature_reevaluation VARCHAR(50),
                methode_reevaluation VARCHAR(50),
                valeur_avant DECIMAL(15,2) DEFAULT 0,
                valeur_apres DECIMAL(15,2) DEFAULT 0,
                ecart_reevaluation DECIMAL(15,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        if ($_POST['action'] == 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO reevaluations (
                    exercice, bien_libelle, date_reevaluation, nature_reevaluation,
                    methode_reevaluation, valeur_avant, valeur_apres, ecart_reevaluation
                ) VALUES (
                    :exercice, :bien_libelle, :date_reevaluation, :nature_reevaluation,
                    :methode_reevaluation, :valeur_avant, :valeur_apres, :ecart_reevaluation
                )
            ");
            
            $valeur_avant = (float)($_POST['valeur_avant'] ?? 0);
            $valeur_apres = (float)($_POST['valeur_apres'] ?? 0);
            $ecart = $valeur_apres - $valeur_avant;
            
            $stmt->execute([
                ':exercice' => $exercice,
                ':bien_libelle' => $_POST['bien_libelle'] ?? '',
                ':date_reevaluation' => $_POST['date_reevaluation'] ?? null,
                ':nature_reevaluation' => $_POST['nature_reevaluation'] ?? '',
                ':methode_reevaluation' => $_POST['methode_reevaluation'] ?? '',
                ':valeur_avant' => $valeur_avant,
                ':valeur_apres' => $valeur_apres,
                ':ecart_reevaluation' => $ecart
            ]);
            
            $message = "Reevaluation ajoutee avec succes !";
            $message_type = "success";
        } elseif ($_POST['action'] == 'delete' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("DELETE FROM reevaluations WHERE id = :id AND exercice = :exercice");
            $stmt->execute([':id' => $_POST['id'], ':exercice' => $exercice]);
            $message = "Reevaluation supprimee avec succes !";
            $message_type = "success";
        } elseif ($_POST['action'] == 'update' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("
                UPDATE reevaluations 
                SET bien_libelle = :bien_libelle,
                    date_reevaluation = :date_reevaluation,
                    nature_reevaluation = :nature_reevaluation,
                    methode_reevaluation = :methode_reevaluation,
                    valeur_avant = :valeur_avant,
                    valeur_apres = :valeur_apres,
                    ecart_reevaluation = :ecart_reevaluation
                WHERE id = :id AND exercice = :exercice
            ");
            
            $valeur_avant = (float)($_POST['valeur_avant'] ?? 0);
            $valeur_apres = (float)($_POST['valeur_apres'] ?? 0);
            $ecart = $valeur_apres - $valeur_avant;
            
            $stmt->execute([
                ':id' => $_POST['id'],
                ':exercice' => $exercice,
                ':bien_libelle' => $_POST['bien_libelle'] ?? '',
                ':date_reevaluation' => $_POST['date_reevaluation'] ?? null,
                ':nature_reevaluation' => $_POST['nature_reevaluation'] ?? '',
                ':methode_reevaluation' => $_POST['methode_reevaluation'] ?? '',
                ':valeur_avant' => $valeur_avant,
                ':valeur_apres' => $valeur_apres,
                ':ecart_reevaluation' => $ecart
            ]);
            
            $message = "Reevaluation modifiee avec succes !";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES
// ============================================================
$reevaluations = [];
$total_valeur_avant = 0;
$total_valeur_apres = 0;
$total_ecart = 0;

try {
    $stmt = $pdo->prepare("
        SELECT * FROM reevaluations 
        WHERE exercice = :exercice
        ORDER BY date_reevaluation DESC
    ");
    $stmt->execute([':exercice' => $exercice]);
    $reevaluations = $stmt->fetchAll();
    
    foreach ($reevaluations as $reeval) {
        $total_valeur_avant += (float)$reeval['valeur_avant'];
        $total_valeur_apres += (float)$reeval['valeur_apres'];
        $total_ecart += (float)$reeval['ecart_reevaluation'];
    }
} catch (PDOException $e) {
    $reevaluations = [];
}

// Récupération d'une réévaluation pour édition
$edit_reeval = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM reevaluations WHERE id = :id AND exercice = :exercice");
        $stmt->execute([':id' => $_GET['edit'], ':exercice' => $exercice]);
        $edit_reeval = $stmt->fetch();
    } catch (PDOException $e) {
        $edit_reeval = null;
    }
}

// Types de réévaluation
$natures_reevaluation = [
    'LIBRE' => 'Reevaluation libre',
    'LEGALE' => 'Reevaluation legale'
];

$methodes_reevaluation = [
    'INDICIAIRE' => 'Methode indiciaire',
    'COUTS_ACTUELS' => 'Methode des couts actuels'
];

// Récupération des immobilisations pour suggestion
$immobilisations = [];
try {
    $stmt = $pdo->prepare("
        SELECT libelle, montant_achat FROM immobilisations 
        WHERE statut = 'actif'
        ORDER BY libelle
        LIMIT 50
    ");
    $stmt->execute();
    $immobilisations = $stmt->fetchAll();
} catch (PDOException $e) {
    $immobilisations = [];
}

$total_plus_value = $total_ecart > 0 ? $total_ecart : 0;
$total_moins_value = $total_ecart < 0 ? abs($total_ecart) : 0;

// ============================================================
// CLASSE FPDF
// ============================================================
if ($format === 'pdf') {
    // Nettoyer le buffer de sortie
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
        public $codeDimf = 'DIMF_2018';
        public $titreDimf = "Etat de traitement de reevaluation";
        public $nomSfd = 'SFD';
        public $periode = '';
        public $exercice = '';

        function convert($str) {
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

        function TableHeader($cols) {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(248, 250, 252);
            $this->SetTextColor(30, 41, 59);
            $this->SetDrawColor(226, 232, 240);
            foreach ($cols as $col) {
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 6, $this->convert($col['label']), 1, 0, $align, true);
            }
            $this->Ln();
        }

        function TableRow($cols, $data, $style = '') {
            $fill = false;
            if ($style == 'total') {
                $this->SetFillColor(240, 253, 244);
                $this->SetFont('Arial', 'B', 8.5);
                $fill = true;
            } else {
                $this->SetFont('Arial', '', 7.5);
                $fill = false;
            }
            $this->SetTextColor(15, 23, 42);
            $this->SetDrawColor(226, 232, 240);
            foreach ($cols as $i => $col) {
                $val = isset($data[$i]) ? $data[$i] : '';
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 5.5, $this->convert($val), 1, 0, $align, $fill);
            }
            $this->Ln();
        }
        
        function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }

    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->nomSfd = isset($_SESSION['nom_sfd']) ? $_SESSION['nom_sfd'] : 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    // Tableau des reevaluations
    $cols = [
        ['label' => 'BIEN REEVALUE', 'w' => 60, 'align' => 'L'],
        ['label' => 'DATE', 'w' => 30, 'align' => 'L'],
        ['label' => 'NATURE', 'w' => 40, 'align' => 'L'],
        ['label' => 'METHODE', 'w' => 50, 'align' => 'L'],
        ['label' => 'VALEUR AVANT (FCFA)', 'w' => 45, 'align' => 'R'],
        ['label' => 'VALEUR APRES (FCFA)', 'w' => 45, 'align' => 'R'],
        ['label' => 'ECART (FCFA)', 'w' => 45, 'align' => 'R'],
    ];
    
    $pdf->SectionTitle('LISTE DES REEVALUATIONS');
    $pdf->TableHeader($cols);
    
    foreach ($reevaluations as $reeval) {
        $ecart = (float)$reeval['ecart_reevaluation'];
        $ecart_str = $pdf->montant($ecart);
        if ($ecart >= 0) {
            $ecart_str = '+' . $ecart_str;
        } else {
            $ecart_str = '-' . $pdf->montant(abs($ecart));
        }
        
        $pdf->TableRow($cols, [
            $reeval['bien_libelle'],
            $reeval['date_reevaluation'] ? date('d/m/Y', strtotime($reeval['date_reevaluation'])) : '-',
            $natures_reevaluation[$reeval['nature_reevaluation']] ?? $reeval['nature_reevaluation'] ?? '-',
            $methodes_reevaluation[$reeval['methode_reevaluation']] ?? $reeval['methode_reevaluation'] ?? '-',
            $pdf->montant($reeval['valeur_avant']),
            $pdf->montant($reeval['valeur_apres']),
            $ecart_str
        ]);
    }
    
    if (empty($reevaluations)) {
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 7, $pdf->convert('Aucune reevaluation enregistree pour l\'exercice ' . $exercice), 0, 1, 'C');
    }
    
    $pdf->Ln(5);
    
    // Total
    $pdf->TableRow($cols, [
        'TOTAL',
        '',
        '',
        '',
        $pdf->montant($total_valeur_avant),
        $pdf->montant($total_valeur_apres),
        $pdf->montant($total_ecart)
    ], 'total');
    
    $pdf->Ln(8);
    
    // Recap des ecarts
    $pdf->SectionTitle('RECAPITULATIF DES ECARTS DE REEVALUATION');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(80, 6, $pdf->convert('Ecart total positif (plus-value) :'), 0, 0);
    $pdf->Cell(0, 6, $pdf->montant($total_plus_value), 0, 1);
    $pdf->Cell(80, 6, $pdf->convert('Ecart total negatif (moins-value) :'), 0, 0);
    $pdf->Cell(0, 6, $pdf->montant($total_moins_value), 0, 1);
    $pdf->Cell(80, 6, $pdf->convert('Ecart net :'), 0, 0);
    $pdf->Cell(0, 6, $pdf->montant($total_ecart), 0, 1);
    
    $pdf->Output('I', 'DIMF_2018_' . $exercice . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2018 - Etat de traitement de reevaluation</title>
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
        .btn-save { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 8px 24px; font-weight: 500; font-size: 0.85rem; cursor: pointer; transition: 0.2s; }
        .btn-save:hover { background: #2563eb; transform: translateY(-1px); }
        .btn-warning { background: #f59e0b; color: white; border: none; border-radius: 40px; padding: 6px 16px; font-weight: 500; font-size: 0.75rem; cursor: pointer; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-warning:hover { background: #d97706; }
        .btn-danger { background: #ef4444; color: white; border: none; border-radius: 40px; padding: 6px 16px; font-weight: 500; font-size: 0.75rem; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-danger:hover { background: #dc2626; }
        
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
        .form-group input, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 0.9rem; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }
        .form-group input[type="number"] { text-align: right; font-family: monospace; font-weight: 500; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 12px 16px; background: #f8fafc; font-weight: 600; color: #1e293b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
        .text-right { text-align: right; font-family: 'Courier New', monospace; font-weight: 500; }
        .total-row { background: #f0fdf4; font-weight: 700; border-top: 2px solid #bbf7d0; }
        .positive-ecart { color: #16a34a; }
        .negative-ecart { color: #dc2626; }
        
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .alert { padding: 14px 20px; border-radius: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #ecfdf5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border-left: 4px solid #ef4444; }
        
        .action-buttons { display: flex; gap: 8px; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            th, td { padding: 8px 12px; font-size: 0.75rem; }
            .action-buttons { flex-direction: column; gap: 4px; }
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
            <h1><i class="fas fa-chart-line"></i> DIMF_2018 - TRAITEMENT DE REEVALUATION</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Reevaluation des immobilisations</div>
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
                    <strong>Note :</strong> Cet etat presente les biens reevalues par l'institution, avec les methodes utilisees et les ecarts constates.<br>
                    Les ecarts de reevaluation sont portes au compte 107 "Ecarts de reevaluation des immobilisations".
                </div>
            </div>
        </div>
    </div>

    <!-- Formulaire d'ajout / modification -->
    <div class="card">
        <div class="card-header">
            <?php if($edit_reeval): ?>
                <i class="fas fa-edit"></i> MODIFIER UNE REEVALUATION
            <?php else: ?>
                <i class="fas fa-plus-circle"></i> AJOUTER UNE REEVALUATION
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="post" action="">
                <input type="hidden" name="action" value="<?= $edit_reeval ? 'update' : 'add' ?>">
                <?php if($edit_reeval): ?>
                    <input type="hidden" name="id" value="<?= $edit_reeval['id'] ?>">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Bien reevalue *</label>
                        <input type="text" name="bien_libelle" required 
                               value="<?= $edit_reeval ? htmlspecialchars($edit_reeval['bien_libelle']) : '' ?>"
                               placeholder="Ex: Immeuble commercial, Terrain, Vehicule..." list="immobilisations-list">
                        <datalist id="immobilisations-list">
                            <?php foreach($immobilisations as $immo): ?>
                                <option value="<?= htmlspecialchars($immo['libelle']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label>Date de reevaluation</label>
                        <input type="date" name="date_reevaluation" 
                               value="<?= $edit_reeval && $edit_reeval['date_reevaluation'] ? date('Y-m-d', strtotime($edit_reeval['date_reevaluation'])) : '' ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Nature de reevaluation</label>
                        <select name="nature_reevaluation">
                            <option value="">-- Selectionner --</option>
                            <?php foreach($natures_reevaluation as $key => $value): ?>
                                <option value="<?= $key ?>" <?= ($edit_reeval && $edit_reeval['nature_reevaluation'] == $key) ? 'selected' : '' ?>>
                                    <?= $value ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Methode de reevaluation</label>
                        <select name="methode_reevaluation">
                            <option value="">-- Selectionner --</option>
                            <?php foreach($methodes_reevaluation as $key => $value): ?>
                                <option value="<?= $key ?>" <?= ($edit_reeval && $edit_reeval['methode_reevaluation'] == $key) ? 'selected' : '' ?>>
                                    <?= $value ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Valeur avant reevaluation (VNC) - FCFA</label>
                        <input type="number" name="valeur_avant" step="1" 
                               value="<?= $edit_reeval ? number_format($edit_reeval['valeur_avant'], 0, '', '') : '0' ?>">
                    </div>
                    <div class="form-group">
                        <label>Valeur reevaluee - FCFA</label>
                        <input type="number" name="valeur_apres" step="1" 
                               value="<?= $edit_reeval ? number_format($edit_reeval['valeur_apres'], 0, '', '') : '0' ?>">
                    </div>
                </div>
                
                <div style="margin-top: 20px; text-align: right;">
                    <?php if($edit_reeval): ?>
                        <a href="DIMF_2018.php?exercice=<?= $exercice ?>&type_periode=<?= $type_periode ?>&mois=<?= $mois ?>&trimestre=<?= $trimestre ?>&semestre=<?= $semestre ?>" class="btn-warning"><i class="fas fa-times"></i> Annuler</a>
                    <?php endif; ?>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> <?= $edit_reeval ? 'Mettre a jour' : 'Ajouter la reevaluation' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Liste des réévaluations -->
    <div class="card">
        <div class="card-header"><i class="fas fa-list-ul"></i> LISTE DES REEVALUATIONS</div>
        <div class="card-body">
            <div class="table-wrapper">
                <?php if(empty($reevaluations)): ?>
                    <div class="info-box">Aucune reevaluation enregistree pour l'exercice <?= $exercice ?>.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Bien reevalue</th>
                                <th>Date</th>
                                <th>Nature</th>
                                <th>Methode</th>
                                <th class="text-right">Valeur avant (FCFA)</th>
                                <th class="text-right">Valeur reevaluee (FCFA)</th>
                                <th class="text-right">Ecart de reevaluation (FCFA)</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($reevaluations as $reeval): 
                                $ecart = (float)$reeval['ecart_reevaluation'];
                                $ecart_class = $ecart >= 0 ? 'positive-ecart' : 'negative-ecart';
                                $ecart_sign = $ecart >= 0 ? '+' : '';
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($reeval['bien_libelle']) ?></td>
                                    <td><?= $reeval['date_reevaluation'] ? date('d/m/Y', strtotime($reeval['date_reevaluation'])) : '-' ?></td>
                                    <td><?= $natures_reevaluation[$reeval['nature_reevaluation']] ?? $reeval['nature_reevaluation'] ?? '-' ?></td>
                                    <td><?= $methodes_reevaluation[$reeval['methode_reevaluation']] ?? $reeval['methode_reevaluation'] ?? '-' ?></td>
                                    <td class="text-right"><?= number_format($reeval['valeur_avant'], 0, ',', ' ') ?></td>
                                    <td class="text-right"><?= number_format($reeval['valeur_apres'], 0, ',', ' ') ?></td>
                                    <td class="text-right <?= $ecart_class ?>"><?= $ecart_sign ?><?= number_format(abs($ecart), 0, ',', ' ') ?></td>
                                    <td class="action-buttons">
                                        <a href="?exercice=<?= $exercice ?>&type_periode=<?= $type_periode ?>&mois=<?= $mois ?>&trimestre=<?= $trimestre ?>&semestre=<?= $semestre ?>&edit=<?= $reeval['id'] ?>" class="btn-warning" style="padding: 4px 12px; font-size: 0.7rem;"><i class="fas fa-edit"></i> Modifier</a>
                                        <form method="post" style="display: inline-block;" onsubmit="return confirm('Supprimer cette reevaluation ?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $reeval['id'] ?>">
                                            <button type="submit" class="btn-danger" style="padding: 4px 12px; font-size: 0.7rem;"><i class="fas fa-trash"></i> Supprimer</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td colspan="4"><strong>TOTAL</strong></td>
                                <td class="text-right"><strong><?= number_format($total_valeur_avant, 0, ',', ' ') ?></strong></td>
                                <td class="text-right"><strong><?= number_format($total_valeur_apres, 0, ',', ' ') ?></strong></td>
                                <td class="text-right"><strong><?= number_format($total_ecart, 0, ',', ' ') ?></strong></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Récapitulatif des écarts -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-pie"></i> RECAPITULATIF DES ECARTS DE REEVALUATION</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-calculator"></i>
                <div>
                    <strong>Ecart total positif (plus-value) :</strong> <?= number_format($total_plus_value, 0, ',', ' ') ?> FCFA<br>
                    <strong>Ecart total negatif (moins-value) :</strong> <?= number_format($total_moins_value, 0, ',', ' ') ?> FCFA<br>
                    <strong>Ecart net :</strong> <?= number_format($total_ecart, 0, ',', ' ') ?> FCFA
                </div>
            </div>
        </div>
    </div>

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
        let url = 'DIMF_2018.php?exercice=' + exercice + '&type_periode=' + type;
        
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        
        window.location.href = url;
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        
        let data = [
            ['DIMF_2018 - ETAT DE TRAITEMENT DE REEVALUATION'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['BIEN REEVALUE', 'DATE', 'NATURE', 'METHODE', 'VALEUR AVANT (FCFA)', 'VALEUR APRES (FCFA)', 'ECART (FCFA)']
        ];
        
        <?php foreach($reevaluations as $reeval): ?>
        data.push([
            '<?= addslashes($reeval['bien_libelle']) ?>',
            '<?= $reeval['date_reevaluation'] ? date('d/m/Y', strtotime($reeval['date_reevaluation'])) : '-' ?>',
            '<?= addslashes($natures_reevaluation[$reeval['nature_reevaluation']] ?? $reeval['nature_reevaluation'] ?? '-') ?>',
            '<?= addslashes($methodes_reevaluation[$reeval['methode_reevaluation']] ?? $reeval['methode_reevaluation'] ?? '-') ?>',
            <?= (float)$reeval['valeur_avant'] ?>,
            <?= (float)$reeval['valeur_apres'] ?>,
            <?= (float)$reeval['ecart_reevaluation'] ?>
        ]);
        <?php endforeach; ?>
        
        data.push(['TOTAL', '', '', '', <?= $total_valeur_avant ?>, <?= $total_valeur_apres ?>, <?= $total_ecart ?>]);
        data.push([]);
        data.push(['RECAPITULATIF DES ECARTS', '', '', '', '', '', '']);
        data.push(['Ecart total positif (plus-value)', '', '', '', '', '', <?= $total_plus_value ?>]);
        data.push(['Ecart total negatif (moins-value)', '', '', '', '', '', <?= $total_moins_value ?>]);
        data.push(['Ecart net', '', '', '', '', '', <?= $total_ecart ?>]);
        
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), "REEVALUATIONS");
        XLSX.writeFile(wb, 'DIMF_2018_<?= $exercice ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>