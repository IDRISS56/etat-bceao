<?php
// DIMF_2014.php - Ressources affectées et crédits consentis
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
    default:          $lib_periode = 'Année ' . $exercice;
}

// Traitement du formulaire
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        // Création des tables
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ressources_affectees (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                libelle VARCHAR(200) NOT NULL,
                court_terme DECIMAL(15,2) DEFAULT 0,
                moyen_terme DECIMAL(15,2) DEFAULT 0,
                long_terme DECIMAL(15,2) DEFAULT 0,
                total DECIMAL(15,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS credits_ressources_affectees (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                type_credit VARCHAR(50) NOT NULL,
                montant DECIMAL(15,2) DEFAULT 0,
                en_souffrance DECIMAL(15,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        if ($_POST['action'] == 'save_ressources') {
            $stmtDel = $pdo->prepare("DELETE FROM ressources_affectees WHERE exercice = :exercice");
            $stmtDel->execute([':exercice' => $exercice]);
            
            $stmtIns = $pdo->prepare("
                INSERT INTO ressources_affectees (exercice, libelle, court_terme, moyen_terme, long_terme, total)
                VALUES (:exercice, :libelle, :court_terme, :moyen_terme, :long_terme, :total)
            ");
            
            $ressources = $_POST['ressources'] ?? [];
            foreach ($ressources as $libelle => $values) {
                $court = (float)($values['court_terme'] ?? 0);
                $moyen = (float)($values['moyen_terme'] ?? 0);
                $long = (float)($values['long_terme'] ?? 0);
                $total = $court + $moyen + $long;
                
                $stmtIns->execute([
                    ':exercice' => $exercice,
                    ':libelle' => $libelle,
                    ':court_terme' => $court,
                    ':moyen_terme' => $moyen,
                    ':long_terme' => $long,
                    ':total' => $total
                ]);
            }
            
            $message = "Ressources affectées enregistrées avec succès !";
            $message_type = "success";
        } elseif ($_POST['action'] == 'save_credits') {
            $stmtDel = $pdo->prepare("DELETE FROM credits_ressources_affectees WHERE exercice = :exercice");
            $stmtDel->execute([':exercice' => $exercice]);
            
            $stmtIns = $pdo->prepare("
                INSERT INTO credits_ressources_affectees (exercice, type_credit, montant, en_souffrance)
                VALUES (:exercice, :type_credit, :montant, :en_souffrance)
            ");
            
            $credits_total = (float)($_POST['credits_total'] ?? 0);
            $credits_souffrance = (float)($_POST['credits_souffrance'] ?? 0);
            
            $stmtIns->execute([
                ':exercice' => $exercice,
                ':type_credit' => 'TOTAL',
                ':montant' => $credits_total,
                ':en_souffrance' => $credits_souffrance
            ]);
            
            $message = "Crédits consentis enregistrés avec succès !";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
}

// Récupération des données existantes
$ressources_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM ressources_affectees WHERE exercice = :exercice ORDER BY id");
    $stmt->execute([':exercice' => $exercice]);
    $ressources_data = $stmt->fetchAll();
} catch (PDOException $e) {
    $ressources_data = [];
}

$credits_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM credits_ressources_affectees WHERE exercice = :exercice AND type_credit = 'TOTAL'");
    $stmt->execute([':exercice' => $exercice]);
    $credits_data = $stmt->fetch();
} catch (PDOException $e) {
    $credits_data = [];
}

$total_ressources = 0;
$total_credits = isset($credits_data['montant']) ? (float)$credits_data['montant'] : 0;
$credits_souffrance = isset($credits_data['en_souffrance']) ? (float)$credits_data['en_souffrance'] : 0;

foreach ($ressources_data as $ressource) {
    $total_ressources += (float)$ressource['total'];
}

// Liste des ressources par défaut
$default_ressources = [
    'RESSOURCES AFFECTEES' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => true],
    'Subventions d\'investissement' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Fonds de garantie' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Lignes de crédit (BCEAO)' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Lignes de crédit (Banques locales)' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Apports en capital' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Subventions d\'équipement' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Dons et legs' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Fonds de réserve' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Autres ressources' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false]
];

foreach ($ressources_data as $ressource) {
    if (isset($default_ressources[$ressource['libelle']])) {
        $default_ressources[$ressource['libelle']] = [
            'court_terme' => (float)$ressource['court_terme'],
            'moyen_terme' => (float)$ressource['moyen_terme'],
            'long_terme' => (float)$ressource['long_terme'],
            'is_title' => ($ressource['libelle'] == 'RESSOURCES AFFECTEES')
        ];
    }
}

// Emprunts réels
$emprunts_reels = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM capital WHERE statut = 'valide' AND mode_paiement = 'BANQUE' AND date_creation <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $emprunts_reels = (float)$result['total'];
} catch (PDOException $e) {
    $emprunts_reels = 0;
}

// Construction du tableau
$tableau_ressources = [];
$total_court = $total_moyen = $total_long = $total_global = 0;

foreach ($default_ressources as $libelle => $values) {
    $court = (float)$values['court_terme'];
    $moyen = (float)$values['moyen_terme'];
    $long = (float)$values['long_terme'];
    $total_ligne = $court + $moyen + $long;
    
    $total_court += $court;
    $total_moyen += $moyen;
    $total_long += $long;
    $total_global += $total_ligne;
    
    $tableau_ressources[] = [
        'libelle' => $libelle,
        'court_terme' => $court,
        'moyen_terme' => $moyen,
        'long_terme' => $long,
        'total' => $total_ligne,
        'is_title' => $values['is_title']
    ];
}

$taux_utilisation = ($total_ressources > 0) ? ($total_credits / $total_ressources) * 100 : 0;
$taux_souffrance = ($total_credits > 0) ? ($credits_souffrance / $total_credits) * 100 : 0;

// ============================================================
// CLASSE FPDF (définie ici pour ne pas dépendre d'un fichier externe)
// ============================================================
if ($format === 'pdf') {
    // Vérifier si FPDF est disponible, sinon afficher une erreur
    $fpdf_path = __DIR__ . '../../fpdf/fpdf.php';
    $alt_fpdf_path = dirname(__DIR__) . '../../fpdf/fpdf.php';
    
    if (file_exists($fpdf_path)) {
        require_once($fpdf_path);
    } elseif (file_exists($alt_fpdf_path)) {
        require_once($alt_fpdf_path);
    } else {
        die("Erreur: La bibliothèque FPDF n'est pas trouvée. Veuillez télécharger FPDF depuis http://www.fpdf.org/ et l'installer dans le dossier 'fpdf/'");
    }
    
    class PDF_DIMF extends FPDF {
        public $codeDimf = 'DIMF_2014';
        public $titreDimf = 'Ressources affectées et crédits consentis';
        public $nomSfd = 'SFD';
        public $periode = '';
        public $exercice = '';

        static function u($str) {
            return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str);
        }

        function Header() {
            $this->SetFillColor(156, 163, 175);
            $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(8, 3);
            $this->Cell(0, 4, self::u('Republique de Cote d\'Ivoire  •  Ministere de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
            $this->SetFont('Arial', 'B', 13);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 7, self::u($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, self::u('SFD : ' . $this->nomSfd . '   |   Periode : ' . $this->periode . '   |   Exercice : ' . $this->exercice), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }

        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, self::u('SICS-BCEAO  •  Genere le ' . date('d/m/Y H:i:s') . '  •  Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
        }

        function SectionTitle($label) {
            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(0, 0, 0);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 7, self::u('  ' . strtoupper($label)), 0, 1, 'L', true);
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
                $this->Cell($col['w'], 6, self::u($col['label']), 1, 0, $align, true);
            }
            $this->Ln();
        }

        function TableRow($cols, $data, $style = '') {
            $fill = false;
            if ($style == 'subtotal') {
                $this->SetFillColor(248, 250, 252);
                $this->SetFont('Arial', 'B', 8);
                $fill = true;
            } elseif ($style == 'total') {
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
                $this->Cell($col['w'], 5.5, self::u($val), 1, 0, $align, $fill);
            }
            $this->Ln();
        }

        static function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }

    if (ob_get_length()) ob_end_clean();
    
    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->nomSfd = isset($_SESSION['nom_sfd']) ? $_SESSION['nom_sfd'] : 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    // Tableau ressources
    $cols = [
        ['label' => 'LIBELLES', 'w' => 90, 'align' => 'L'],
        ['label' => 'COURT TERME (FCFA)', 'w' => 50, 'align' => 'R'],
        ['label' => 'MOYEN TERME (FCFA)', 'w' => 50, 'align' => 'R'],
        ['label' => 'LONG TERME (FCFA)', 'w' => 50, 'align' => 'R'],
        ['label' => 'TOTAL (FCFA)', 'w' => 50, 'align' => 'R'],
    ];
    
    $pdf->SectionTitle('RESSOURCES AFFECTEES');
    $pdf->TableHeader($cols);
    
    foreach ($tableau_ressources as $item) {
        $style = $item['is_title'] ? 'subtotal' : '';
        $pdf->TableRow($cols, [
            $item['libelle'],
            PDF_DIMF::montant($item['court_terme']),
            PDF_DIMF::montant($item['moyen_terme']),
            PDF_DIMF::montant($item['long_terme']),
            PDF_DIMF::montant($item['total'])
        ], $style);
    }
    
    $pdf->TableRow($cols, [
        'TOTAL RESSOURCES AFFECTEES',
        PDF_DIMF::montant($total_court),
        PDF_DIMF::montant($total_moyen),
        PDF_DIMF::montant($total_long),
        PDF_DIMF::montant($total_global)
    ], 'total');
    
    $pdf->Ln(8);
    
    // Crédits consentis
    $cols2 = [
        ['label' => 'LIBELLE', 'w' => 150, 'align' => 'L'],
        ['label' => 'MONTANT (FCFA)', 'w' => 70, 'align' => 'R'],
        ['label' => 'EN SOUFFRANCE (FCFA)', 'w' => 70, 'align' => 'R'],
    ];
    
    $pdf->SectionTitle('CREDITS CONSENTIS SUR RESSOURCES AFFECTEES');
    $pdf->TableHeader($cols2);
    $pdf->TableRow($cols2, ['Crédits consentis', PDF_DIMF::montant($total_credits), PDF_DIMF::montant($credits_souffrance)]);
    $pdf->TableRow($cols2, ['TOTAL', PDF_DIMF::montant($total_credits), PDF_DIMF::montant($credits_souffrance)], 'total');
    
    $pdf->Ln(8);
    
    // Synthèse
    $pdf->SectionTitle('SYNTHESE');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(70, 6, 'Total ressources affectees :', 0, 0);
    $pdf->Cell(0, 6, PDF_DIMF::montant($total_ressources), 0, 1);
    $pdf->Cell(70, 6, 'Credits consentis :', 0, 0);
    $pdf->Cell(0, 6, PDF_DIMF::montant($total_credits), 0, 1);
    $pdf->Cell(70, 6, "Taux d'utilisation :", 0, 0);
    $pdf->Cell(0, 6, number_format($taux_utilisation, 2) . '%', 0, 1);
    $pdf->Cell(70, 6, 'Credits en souffrance :', 0, 0);
    $pdf->Cell(0, 6, PDF_DIMF::montant($credits_souffrance), 0, 1);
    $pdf->Cell(70, 6, 'Taux de souffrance :', 0, 0);
    $pdf->Cell(0, 6, number_format($taux_souffrance, 2) . '%', 0, 1);
    $pdf->Cell(70, 6, 'Emprunts reels :', 0, 0);
    $pdf->Cell(0, 6, PDF_DIMF::montant($emprunts_reels), 0, 1);
    
    $pdf->Output('I', 'DIMF_2014_' . $exercice . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2014 - Ressources affectées et crédits consentis</title>
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
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 12px 16px; background: #f8fafc; font-weight: 600; color: #1e293b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
        .text-right { text-align: right; font-family: 'Courier New', monospace; font-weight: 500; }
        .subtotal-row { background: #f8fafc; font-weight: 600; }
        .total-row { background: #f0fdf4; font-weight: 700; border-top: 2px solid #bbf7d0; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 5px; color: #555; font-size: 0.8rem; }
        .form-group input { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 0.9rem; text-align: right; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        
        .ressources-table input { min-width: 100px; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 8px; text-align: right; font-family: monospace; }
        .ressources-table input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }
        
        .alert { padding: 14px 20px; border-radius: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #ecfdf5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border-left: 4px solid #ef4444; }
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            th, td { padding: 8px 12px; font-size: 0.75rem; }
            .ressources-table input { min-width: 70px; }
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
            <h1><i class="fas fa-chart-line"></i> DIMF_2014 - RESSOURCES AFFECTÉES</h1>
            <div class="subtitle">République de Côte d'Ivoire / Ministère de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Fonds spéciaux et lignes de crédit</div>
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
                    <label>Année</label>
                    <select id="exerciceSelect">
                        <?php for ($y = 2020; $y <= date('Y')+1; $y++): ?>
                            <option value="<?= $y ?>" <?= $y==$exercice?'selected':'' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Type de période</label>
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
                        for ($t=1;$t<=4;$t++) { $s=($t==$trimestre)?'selected':''; echo "<option value='$t' $s>$t".($t==1?'er':'ème')." Trimestre</option>"; }
                        echo '</select>';
                    } elseif ($type_periode == 'semestre') {
                        echo '<label>Semestre</label><select id="semestreSelect">';
                        for ($s=1;$s<=2;$s++) { $sel=($s==$semestre)?'selected':''; echo "<option value='$s' $sel>$s".($s==1?'er':'e')." semestre</option>"; }
                        echo '</select>';
                    } else {
                        echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;cursor:default;">';
                    }
                    ?>
                </div>
                <button class="btn-apply" onclick="appliquerFiltres()"><i class="fas fa-filter"></i> Appliquer</button>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Période : <?= $lib_periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </div>

    <?php if($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <i class="fas fa-<?= $message_type == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Formulaire Ressources affectées -->
    <form method="post" action="">
        <input type="hidden" name="action" value="save_ressources">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-money-bill-wave"></i> RESSOURCES AFFECTÉES
                <button type="submit" class="btn-save" style="margin-left: auto; padding: 6px 16px; font-size: 0.75rem;"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table class="ressources-table">
                        <thead>
                            <tr><th>LIBELLÉS</th><th class="text-right">COURT TERME (FCFA)</th><th class="text-right">MOYEN TERME (FCFA)</th><th class="text-right">LONG TERME (FCFA)</th><th class="text-right">TOTAL (FCFA)</th></tr></thead>
                        <tbody>
                            <?php foreach ($tableau_ressources as $item): ?>
                            <tr <?= $item['is_title'] ? 'class="subtotal-row"' : '' ?>>
                                <td>
                                    <?php if ($item['is_title']): ?>
                                        <strong><?= htmlspecialchars($item['libelle']) ?></strong>
                                        <input type="hidden" name="ressources[<?= htmlspecialchars($item['libelle']) ?>][court_terme]" value="0">
                                        <input type="hidden" name="ressources[<?= htmlspecialchars($item['libelle']) ?>][moyen_terme]" value="0">
                                        <input type="hidden" name="ressources[<?= htmlspecialchars($item['libelle']) ?>][long_terme]" value="0">
                                    <?php else: ?>
                                        <input type="text" name="ressources[<?= htmlspecialchars($item['libelle']) ?>][libelle]" value="<?= htmlspecialchars($item['libelle']) ?>" style="width:100%; border:none; background:transparent;">
                                    <?php endif; ?>
                                </td>
                                <td class="text-right"><input type="number" name="ressources[<?= htmlspecialchars($item['libelle']) ?>][court_terme]" step="1" class="court-input" value="<?= number_format($item['court_terme'], 0, '', '') ?>"></td>
                                <td class="text-right"><input type="number" name="ressources[<?= htmlspecialchars($item['libelle']) ?>][moyen_terme]" step="1" class="moyen-input" value="<?= number_format($item['moyen_terme'], 0, '', '') ?>"></td>
                                <td class="text-right"><input type="number" name="ressources[<?= htmlspecialchars($item['libelle']) ?>][long_terme]" step="1" class="long-input" value="<?= number_format($item['long_terme'], 0, '', '') ?>"></td>
                                <td class="text-right total-cell" style="background:#f0fdf4; font-weight:bold;"><?= number_format($item['total'], 0, ',', ' ') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td><strong>TOTAL RESSOURCES AFFECTÉES</strong></td>
                                <td class="text-right" id="total_court"><?= number_format($total_court, 0, ',', ' ') ?></td>
                                <td class="text-right" id="total_moyen"><?= number_format($total_moyen, 0, ',', ' ') ?></td>
                                <td class="text-right" id="total_long"><?= number_format($total_long, 0, ',', ' ') ?></td>
                                <td class="text-right" id="total_ressources"><?= number_format($total_global, 0, ',', ' ') ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </form>

    <!-- Formulaire Crédits consentis -->
    <form method="post" action="">
        <input type="hidden" name="action" value="save_credits">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-hand-holding-usd"></i> CRÉDITS CONSENTIS SUR RESSOURCES AFFECTÉES
                <button type="submit" class="btn-save" style="margin-left: auto; padding: 6px 16px; font-size: 0.75rem;"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Montant total des crédits consentis (FCFA)</label>
                        <input type="number" name="credits_total" step="1" id="credits_total" value="<?= number_format($total_credits, 0, '', '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Dont crédits en souffrance (FCFA)</label>
                        <input type="number" name="credits_souffrance" step="1" id="credits_souffrance" value="<?= number_format($credits_souffrance, 0, '', '') ?>">
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- Emprunts réels -->
    <div class="card">
        <div class="card-header"><i class="fas fa-building-columns"></i> EMPRUNTS RÉELS (HORS RESSOURCES AFFECTÉES)</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-chart-simple"></i>
                <div><strong>Emprunts bancaires / institutionnels :</strong> <?= number_format($emprunts_reels, 0, ',', ' ') ?> FCFA</div>
            </div>
        </div>
    </div>

    <!-- Synthèse -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-pie"></i> SYNTHÈSE</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-calculator"></i>
                <div>
                    <strong>Total ressources affectées :</strong> <?= number_format($total_ressources, 0, ',', ' ') ?> FCFA<br>
                    <strong>Crédits consentis :</strong> <?= number_format($total_credits, 0, ',', ' ') ?> FCFA<br>
                    <strong>Taux d'utilisation :</strong> <?= number_format($taux_utilisation, 2) ?>%<br>
                    <strong>Crédits en souffrance :</strong> <?= number_format($credits_souffrance, 0, ',', ' ') ?> FCFA<br>
                    <strong>Taux de souffrance :</strong> <?= number_format($taux_souffrance, 2) ?>%
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base Mandigo
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
                html += `<option value="${t}" ${s}>${t}${t === 1 ? 'er' : 'ème'} Trimestre</option>`;
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
            html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;cursor:default;">';
        }
        container.innerHTML = html;
    }

    function appliquerFiltres() {
        const exercice = document.getElementById('exerciceSelect').value;
        const type = document.getElementById('typePeriodeSelect').value;
        let url = 'DIMF_2014.php?exercice=' + exercice + '&type_periode=' + type;
        
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        
        window.location.href = url;
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        
        let dataRessources = [
            ['DIMF_2014 - RESSOURCES AFFECTEES ET CREDITS CONSENTIS'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['LIBELLES', 'COURT TERME (FCFA)', 'MOYEN TERME (FCFA)', 'LONG TERME (FCFA)', 'TOTAL (FCFA)']
        ];
        
        <?php foreach ($tableau_ressources as $item): ?>
        dataRessources.push([
            '<?= addslashes($item['libelle']) ?>',
            <?= $item['court_terme'] ?>,
            <?= $item['moyen_terme'] ?>,
            <?= $item['long_terme'] ?>,
            <?= $item['total'] ?>
        ]);
        <?php endforeach; ?>
        
        dataRessources.push(['TOTAL RESSOURCES AFFECTEES', <?= $total_court ?>, <?= $total_moyen ?>, <?= $total_long ?>, <?= $total_global ?>]);
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(dataRessources), "RESSOURCES_AFFECTEES");
        
        let dataCredits = [
            ['DIMF_2014 - CREDITS CONSENTIS'],
            [],
            ['LIBELLE', 'MONTANT (FCFA)', 'EN SOUFFRANCE (FCFA)'],
            ['Crédits consentis', <?= $total_credits ?>, <?= $credits_souffrance ?>],
            ['TOTAL', <?= $total_credits ?>, <?= $credits_souffrance ?>]
        ];
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(dataCredits), "CREDITS_CONSENTIS");
        
        let dataSynth = [
            ['DIMF_2014 - SYNTHESE'],
            [],
            ['INDICATEUR', 'VALEUR'],
            ['Total ressources affectees', <?= $total_ressources ?>],
            ['Credits consentis', <?= $total_credits ?>],
            ["Taux d'utilisation", <?= $taux_utilisation ?>],
            ['Credits en souffrance', <?= $credits_souffrance ?>],
            ['Taux de souffrance', <?= $taux_souffrance ?>],
            ['Emprunts reels', <?= $emprunts_reels ?>]
        ];
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(dataSynth), "SYNTHESE");
        
        XLSX.writeFile(wb, 'DIMF_2014_<?= $exercice ?>.xlsx');
    }

    function calculerTotaux() {
        let totalCourt = 0, totalMoyen = 0, totalLong = 0, totalGlobal = 0;
        const lignes = document.querySelectorAll('.ressources-table tbody tr');
        
        lignes.forEach(function(row) {
            if (row.classList.contains('total-row')) return;
            const courtInput = row.querySelector('input[name$="[court_terme]"]');
            const moyenInput = row.querySelector('input[name$="[moyen_terme]"]');
            const longInput = row.querySelector('input[name$="[long_terme]"]');
            const totalCell = row.querySelector('.total-cell');
            
            const court = courtInput ? parseFloat(courtInput.value) || 0 : 0;
            const moyen = moyenInput ? parseFloat(moyenInput.value) || 0 : 0;
            const long = longInput ? parseFloat(longInput.value) || 0 : 0;
            const total = court + moyen + long;
            
            totalCourt += court;
            totalMoyen += moyen;
            totalLong += long;
            totalGlobal += total;
            if (totalCell) totalCell.innerText = total.toLocaleString('fr-FR');
        });
        
        document.getElementById('total_court').innerText = totalCourt.toLocaleString('fr-FR');
        document.getElementById('total_moyen').innerText = totalMoyen.toLocaleString('fr-FR');
        document.getElementById('total_long').innerText = totalLong.toLocaleString('fr-FR');
        document.getElementById('total_ressources').innerText = totalGlobal.toLocaleString('fr-FR');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        
        const inputs = document.querySelectorAll('.ressources-table input[type="number"]');
        inputs.forEach(input => {
            input.addEventListener('input', calculerTotaux);
            input.addEventListener('change', calculerTotaux);
        });
        calculerTotaux();
    });
</script>
</body>
</html>