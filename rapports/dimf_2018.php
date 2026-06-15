<?php
// DIMF_2018.php - État de traitement de réévaluation
// Design DIMF_2000 - gestion POST, Bootstrap, FPDF avec mb_convert_encoding

session_start();

// ============================================================
// CONFIGURATION BDD
// ============================================================
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ============================================================
// PARAMÈTRES (POST > GET)
// ============================================================
$exercice     = isset($_POST['exercice'])     ? (int)$_POST['exercice']     : (isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode']      : (isset($_GET['type_periode']) ? $_GET['type_periode'] : 'mensuel');
$mois         = isset($_POST['mois'])         ? (int)$_POST['mois']         : (isset($_GET['mois']) ? (int)$_GET['mois'] : 12);
$trimestre    = isset($_POST['trimestre'])    ? (int)$_POST['trimestre']    : (isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4);
$semestre     = isset($_POST['semestre'])     ? (int)$_POST['semestre']     : (isset($_GET['semestre']) ? (int)$_GET['semestre'] : null);
$format       = isset($_POST['format'])       ? $_POST['format']            : (isset($_GET['format']) ? $_GET['format'] : 'html');

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
}
$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$lib_periode = match($type_periode) {
    'mensuel'   => 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice,
    'trimestre' => $trimestre . 'e Trim. ' . $exercice,
    'semestre'  => $semestre . 'er Sem. ' . $exercice,
    default     => 'Année ' . $exercice,
};

// ============================================================
// TRAITEMENT DU FORMULAIRE (AJOUT / MODIFICATION / SUPPRESSION) – POST
// ============================================================
$message = '';
$message_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS reevaluations (
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
        )");

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
            $message = "Réévaluation ajoutée avec succès !";
            $message_type = "success";
        } elseif ($_POST['action'] == 'delete' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("DELETE FROM reevaluations WHERE id = :id AND exercice = :exercice");
            $stmt->execute([':id' => (int)$_POST['id'], ':exercice' => $exercice]);
            $message = "Réévaluation supprimée avec succès !";
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
                ':id' => (int)$_POST['id'],
                ':exercice' => $exercice,
                ':bien_libelle' => $_POST['bien_libelle'] ?? '',
                ':date_reevaluation' => $_POST['date_reevaluation'] ?? null,
                ':nature_reevaluation' => $_POST['nature_reevaluation'] ?? '',
                ':methode_reevaluation' => $_POST['methode_reevaluation'] ?? '',
                ':valeur_avant' => $valeur_avant,
                ':valeur_apres' => $valeur_apres,
                ':ecart_reevaluation' => $ecart
            ]);
            $message = "Réévaluation modifiée avec succès !";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
    $url = "DIMF_2018.php?exercice=$exercice&type_periode=$type_periode" .
           ($type_periode=='mensuel' ? "&mois=$mois" : ($type_periode=='trimestre' ? "&trimestre=$trimestre" : ($type_periode=='semestre' ? "&semestre=$semestre" : ""))) .
           "&msg=" . urlencode($message) . "&msg_type=$message_type";
    header("Location: $url");
    exit;
}
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $message_type = $_GET['msg_type'] ?? 'success';
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES
// ============================================================
$reevaluations = [];
$total_valeur_avant = 0;
$total_valeur_apres = 0;
$total_ecart = 0;
try {
    $stmt = $pdo->prepare("SELECT * FROM reevaluations WHERE exercice = :exercice ORDER BY date_reevaluation DESC");
    $stmt->execute([':exercice' => $exercice]);
    $reevaluations = $stmt->fetchAll();
    foreach ($reevaluations as $r) {
        $total_valeur_avant += (float)$r['valeur_avant'];
        $total_valeur_apres += (float)$r['valeur_apres'];
        $total_ecart += (float)$r['ecart_reevaluation'];
    }
} catch (PDOException $e) { $reevaluations = []; }

$edit_reeval = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM reevaluations WHERE id = :id AND exercice = :exercice");
        $stmt->execute([':id' => (int)$_GET['edit'], ':exercice' => $exercice]);
        $edit_reeval = $stmt->fetch();
    } catch (PDOException $e) { $edit_reeval = null; }
}

$natures_reevaluation = ['LIBRE' => 'Réévaluation libre', 'LEGALE' => 'Réévaluation légale'];
$methodes_reevaluation = ['INDICIAIRE' => 'Méthode indiciaire', 'COUTS_ACTUELS' => 'Méthode des coûts actuels'];

$immobilisations = [];
try {
    $stmt = $pdo->prepare("SELECT libelle, montant_achat FROM immobilisations WHERE statut = 'actif' ORDER BY libelle LIMIT 50");
    $stmt->execute();
    $immobilisations = $stmt->fetchAll();
} catch (PDOException $e) { $immobilisations = []; }

$total_plus_value = $total_ecart > 0 ? $total_ecart : 0;
$total_moins_value = $total_ecart < 0 ? abs($total_ecart) : 0;

// ============================================================
// CLASSE PDF (mb_convert_encoding)
// ============================================================
if ($format === 'pdf') {

    class PDF_DIMF extends FPDF {
        public $codeDimf = 'DIMF_2018';
        public $titreDimf = "État de traitement de réévaluation";
        public $nomSfd = 'SFD';
        public $periode = '';
        public $exercice = '';

        static function u($str) {
            return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
        }

        function Header() {
            $this->SetFillColor(156, 163, 175);
            $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(8, 3);
            $this->Cell(0, 4, self::u('République de Côte d\'Ivoire  •  Ministère de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
            $this->SetFont('Arial', 'B', 13);
            $this->SetX(8);
            $this->Cell(0, 7, self::u($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetX(8);
            $this->Cell(0, 5, self::u('SFD : ' . $this->nomSfd . '   |   Période : ' . $this->periode . '   |   Exercice : ' . $this->exercice . '   |   Arrêté au : ' . date('d/m/Y')), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }

        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, self::u('SICS-BCEAO  •  Généré le ' . date('d/m/Y H:i:s') . '  •  Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
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
    $pdf->nomSfd = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'BIEN RÉÉVALUÉ', 'w' => 60],
        ['label' => 'DATE', 'w' => 30],
        ['label' => 'NATURE', 'w' => 40],
        ['label' => 'MÉTHODE', 'w' => 50],
        ['label' => 'VALEUR AVANT (FCFA)', 'w' => 45, 'align' => 'R'],
        ['label' => 'VALEUR APRÈS (FCFA)', 'w' => 45, 'align' => 'R'],
        ['label' => 'ÉCART (FCFA)', 'w' => 45, 'align' => 'R']
    ];
    $pdf->SectionTitle('LISTE DES RÉÉVALUATIONS');
    $pdf->TableHeader($cols);
    foreach ($reevaluations as $r) {
        $ecart = (float)$r['ecart_reevaluation'];
        $ecart_str = PDF_DIMF::montant($ecart);
        if ($ecart >= 0) $ecart_str = '+' . $ecart_str;
        else $ecart_str = '-' . PDF_DIMF::montant(abs($ecart));
        $pdf->TableRow($cols, [
            $r['bien_libelle'],
            $r['date_reevaluation'] ? date('d/m/Y', strtotime($r['date_reevaluation'])) : '-',
            $natures_reevaluation[$r['nature_reevaluation']] ?? $r['nature_reevaluation'] ?? '-',
            $methodes_reevaluation[$r['methode_reevaluation']] ?? $r['methode_reevaluation'] ?? '-',
            PDF_DIMF::montant($r['valeur_avant']),
            PDF_DIMF::montant($r['valeur_apres']),
            $ecart_str
        ]);
    }
    if (empty($reevaluations)) {
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 7, PDF_DIMF::u('Aucune réévaluation enregistrée pour l\'exercice ' . $exercice), 0, 1, 'C');
    }
    $pdf->TableRow($cols, [
        'TOTAL', '', '', '',
        PDF_DIMF::montant($total_valeur_avant),
        PDF_DIMF::montant($total_valeur_apres),
        PDF_DIMF::montant($total_ecart)
    ], 'total');
    $pdf->Ln(8);
    $pdf->SectionTitle('RÉCAPITULATIF DES ÉCARTS DE RÉÉVALUATION');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(80, 6, PDF_DIMF::u('Écart total positif (plus-value) :'), 0, 0);
    $pdf->Cell(0, 6, PDF_DIMF::montant($total_plus_value), 0, 1);
    $pdf->Cell(80, 6, PDF_DIMF::u('Écart total négatif (moins-value) :'), 0, 0);
    $pdf->Cell(0, 6, PDF_DIMF::montant($total_moins_value), 0, 1);
    $pdf->Cell(80, 6, PDF_DIMF::u('Écart net :'), 0, 0);
    $pdf->Cell(0, 6, PDF_DIMF::montant($total_ecart), 0, 1);
    $pdf->Output('I', 'DIMF_2018_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL (POST)
// ============================================================
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="DIMF_2018_' . $exercice . '.xls"');
    echo '<html><head><meta charset="UTF-8"><style> body { font-family: Arial; } td { text-align: right; } .text-left { text-align: left; } </style></head><body>';
    echo '<h2>DIMF_2018 - État de traitement de réévaluation</h2>';
    echo '<p>Période : ' . htmlspecialchars($lib_periode) . '</p>';
    echo '<table border="1"><thead><tr><th>Bien réévalué</th><th>Date</th><th>Nature</th><th>Méthode</th><th>Valeur avant (FCFA)</th><th>Valeur après (FCFA)</th><th>Écart (FCFA)</th></tr></thead><tbody>';
    foreach ($reevaluations as $r) {
        $ecart = (float)$r['ecart_reevaluation'];
        $ecart_str = number_format($ecart,0,',',' ');
        if ($ecart >= 0) $ecart_str = '+' . $ecart_str;
        else $ecart_str = '-' . number_format(abs($ecart),0,',',' ');
        echo '<tr>';
        echo '<td class="text-left">' . htmlspecialchars($r['bien_libelle']) . '</td>';
        echo '<td class="text-left">' . ($r['date_reevaluation'] ? date('d/m/Y', strtotime($r['date_reevaluation'])) : '-') . '</td>';
        echo '<td class="text-left">' . htmlspecialchars($natures_reevaluation[$r['nature_reevaluation']] ?? $r['nature_reevaluation'] ?? '-') . '</td>';
        echo '<td class="text-left">' . htmlspecialchars($methodes_reevaluation[$r['methode_reevaluation']] ?? $r['methode_reevaluation'] ?? '-') . '</td>';
        echo '<td>' . number_format($r['valeur_avant'],0,',',' ') . '</td>';
        echo '<td>' . number_format($r['valeur_apres'],0,',',' ') . '</td>';
        echo '<td>' . $ecart_str . '</td>';
        echo '</tr>';
    }
    echo '<tr style="background:#e8f5e9;"><td colspan="4"><strong>TOTAL</strong></td>';
    echo '<td><strong>' . number_format($total_valeur_avant,0,',',' ') . '</strong></td>';
    echo '<td><strong>' . number_format($total_valeur_apres,0,',',' ') . '</strong></td>';
    echo '<td><strong>' . number_format($total_ecart,0,',',' ') . '</strong></td></tr>';
    echo '</tbody></table><br/>';
    echo '<h3>Récapitulatif des écarts</h3>';
    echo '<table border="1"><tr><td>Écart total positif (plus-value)</td><td>' . number_format($total_plus_value,0,',',' ') . ' FCFA</td></tr>';
    echo '<tr><td>Écart total négatif (moins-value)</td><td>' . number_format($total_moins_value,0,',',' ') . ' FCFA</td></tr>';
    echo '<tr><td>Écart net</td><td>' . number_format($total_ecart,0,',',' ') . ' FCFA</td></tr></table>';
    echo '</body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2018 - Traitement de réévaluation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter', sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; display:flex; align-items:center; gap:10px; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; display:inline-block; margin-top:8px; }
        .btn-group { display:flex; gap:12px; }
        .btn-excel, .btn-pdf { display:inline-flex; align-items:center; gap:8px; padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; text-decoration:none; }
        .btn-excel { background:#10b981; color:white; }
        .btn-excel:hover { background:#059669; }
        .btn-pdf { background:#ef4444; color:white; }
        .btn-pdf:hover { background:#dc2626; }
        .btn-save { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; font-weight:500; cursor:pointer; }
        .btn-save:hover { background:#2563eb; }
        .btn-warning { background:#f59e0b; color:white; border:none; border-radius:40px; padding:6px 16px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-size:0.75rem; }
        .btn-danger { background:#ef4444; color:white; border:none; border-radius:40px; padding:6px 16px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-size:0.75rem; }
        .card { background:white; border-radius:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px; overflow:hidden; }
        .card-header { display:flex; align-items:center; gap:10px; padding:16px 24px; background:#f8fafc; border-bottom:1px solid #eef2f6; font-weight:600; color:#1e40af; }
        .card-body { padding:20px 24px; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select { background:white; border:1px solid #d1d5db; border-radius:12px; padding:8px 14px; font-size:0.85rem; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .form-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:20px; }
        .form-group { margin-bottom:15px; }
        .form-group label { display:block; font-weight:600; margin-bottom:5px; color:#555; font-size:0.8rem; }
        .form-group input, .form-group select { width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:8px; font-size:0.9rem; }
        .form-group input:focus, .form-group select:focus { outline:none; border-color:#3b82f6; }
        .form-group input[type="number"] { text-align:right; font-family:monospace; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        th { text-align:left; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
        td { padding:10px 16px; border-bottom:1px solid #f1f5f9; }
        .text-right { text-align:right; font-family:'Courier New',monospace; }
        .total-row { background:#f0fdf4; font-weight:700; }
        .positive-ecart { color:#16a34a; }
        .negative-ecart { color:#dc2626; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px; border-radius:16px; display:flex; align-items:center; gap:14px; margin-bottom:20px; }
        .alert { padding:14px 20px; border-radius:16px; margin-bottom:20px; display:flex; align-items:center; gap:12px; }
        .alert-success { background:#ecfdf5; color:#065f46; border-left:4px solid #10b981; }
        .alert-error { background:#fef2f2; color:#991b1b; border-left:4px solid #ef4444; }
        .action-buttons { display:flex; gap:8px; flex-wrap:wrap; }
        .footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; padding:16px; }
        @media (max-width:768px) { body { padding:12px; } .filters-row { flex-direction:column; } .btn-group { flex-wrap:wrap; } .action-buttons { flex-direction:column; } }
        @media print { .btn-group, .footer, .filters-row, .btn-save, #filtersCard, .alert { display:none !important; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-line"></i> DIMF_2018 - TRAITEMENT DE RÉÉVALUATION</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Réévaluation des immobilisations</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <form method="post" class="card" id="filtersForm">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
            <div class="filters-row">
                <div class="filter-item">
                    <label>Année</label>
                    <select name="exercice" id="exerciceSelect">
                        <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                            <option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Type de période</label>
                    <select name="type_periode" id="typePeriodeSelect">
                        <option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option>
                        <option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option>
                        <option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option>
                        <option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option>
                    </select>
                </div>
                <div class="filter-item" id="dynamicSelectContainer">
                    <?php
                    if ($type_periode == 'mensuel') {
                        echo '<label>Mois</label><select name="mois" id="moisSelect">';
                        for ($m=1;$m<=12;$m++) echo '<option value="'.$m.'" '.($m==$mois?'selected':'').'>'.str_pad($m,2,'0',STR_PAD_LEFT).' - '.date('F',mktime(0,0,0,$m,1)).'</option>';
                        echo '</select>';
                    } elseif ($type_periode == 'trimestre') {
                        echo '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
                        for ($t=1;$t<=4;$t++) echo '<option value="'.$t.'" '.($t==$trimestre?'selected':'').'>'.$t.($t==1?'er':'ème').' Trimestre</option>';
                        echo '</select>';
                    } elseif ($type_periode == 'semestre') {
                        echo '<label>Semestre</label><select name="semestre" id="semestreSelect">';
                        for ($s=1;$s<=2;$s++) echo '<option value="'.$s.'" '.($s==$semestre?'selected':'').'>'.$s.($s==1?'er':'e').' semestre</option>';
                        echo '</select>';
                    } else {
                        echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
                    }
                    ?>
                </div>
                <div class="filter-item">
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Période : <?= $lib_periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </form>

    <?php if($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <i class="fas fa-<?= $message_type=='success'?'check-circle':'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Note :</strong> Cet état présente les biens réévalués par l'institution, avec les méthodes utilisées et les écarts constatés.<br>
                    Les écarts de réévaluation sont portés au compte 107 "Écarts de réévaluation des immobilisations".
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <?php if($edit_reeval): ?>
                <i class="fas fa-edit"></i> MODIFIER UNE RÉÉVALUATION
            <?php else: ?>
                <i class="fas fa-plus-circle"></i> AJOUTER UNE RÉÉVALUATION
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="post" action="">
                <input type="hidden" name="action" value="<?= $edit_reeval ? 'update' : 'add' ?>">
                <?php if($edit_reeval): ?><input type="hidden" name="id" value="<?= $edit_reeval['id'] ?>"><?php endif; ?>
                <div class="form-row">
                    <div class="form-group">
                        <label>Bien réévalué *</label>
                        <input type="text" name="bien_libelle" required value="<?= $edit_reeval ? htmlspecialchars($edit_reeval['bien_libelle']) : '' ?>" placeholder="Ex: Immeuble commercial, Terrain..." list="immobilisations-list">
                        <datalist id="immobilisations-list">
                            <?php foreach($immobilisations as $immo): ?>
                                <option value="<?= htmlspecialchars($immo['libelle']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label>Date de réévaluation</label>
                        <input type="date" name="date_reevaluation" value="<?= $edit_reeval && $edit_reeval['date_reevaluation'] ? date('Y-m-d', strtotime($edit_reeval['date_reevaluation'])) : '' ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Nature de réévaluation</label>
                        <select name="nature_reevaluation">
                            <option value="">-- Sélectionner --</option>
                            <?php foreach($natures_reevaluation as $k=>$v): ?>
                                <option value="<?= $k ?>" <?= ($edit_reeval && $edit_reeval['nature_reevaluation']==$k)?'selected':'' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Méthode de réévaluation</label>
                        <select name="methode_reevaluation">
                            <option value="">-- Sélectionner --</option>
                            <?php foreach($methodes_reevaluation as $k=>$v): ?>
                                <option value="<?= $k ?>" <?= ($edit_reeval && $edit_reeval['methode_reevaluation']==$k)?'selected':'' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Valeur avant réévaluation (VNC) - FCFA</label>
                        <input type="number" name="valeur_avant" step="1" value="<?= $edit_reeval ? number_format($edit_reeval['valeur_avant'],0,'','') : '0' ?>">
                    </div>
                    <div class="form-group">
                        <label>Valeur réévaluée - FCFA</label>
                        <input type="number" name="valeur_apres" step="1" value="<?= $edit_reeval ? number_format($edit_reeval['valeur_apres'],0,'','') : '0' ?>">
                    </div>
                </div>
                <div style="margin-top:20px; text-align:right;">
                    <?php if($edit_reeval): ?>
                        <a href="DIMF_2018.php?exercice=<?= $exercice ?>&type_periode=<?= $type_periode ?><?= $type_periode=='mensuel'?"&mois=$mois":($type_periode=='trimestre'?"&trimestre=$trimestre":($type_periode=='semestre'?"&semestre=$semestre":"")) ?>" class="btn-warning"><i class="fas fa-times"></i> Annuler</a>
                    <?php endif; ?>
                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> <?= $edit_reeval ? 'Mettre à jour' : 'Ajouter la réévaluation' ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-list-ul"></i> LISTE DES RÉÉVALUATIONS</div>
        <div class="card-body">
            <div class="table-wrapper">
                <?php if(empty($reevaluations)): ?>
                    <div class="info-box">Aucune réévaluation enregistrée pour l'exercice <?= $exercice ?>.</div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr><th>Bien réévalué</th><th>Date</th><th>Nature</th><th>Méthode</th><th class="text-right">Valeur avant (FCFA)</th><th class="text-right">Valeur après (FCFA)</th><th class="text-right">Écart (FCFA)</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($reevaluations as $r): 
                                $ecart = (float)$r['ecart_reevaluation'];
                                $ecart_class = $ecart >= 0 ? 'positive-ecart' : 'negative-ecart';
                                $ecart_sign = $ecart >= 0 ? '+' : '';
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['bien_libelle']) ?></td>
                                    <td><?= $r['date_reevaluation'] ? date('d/m/Y', strtotime($r['date_reevaluation'])) : '-' ?></td>
                                    <td><?= $natures_reevaluation[$r['nature_reevaluation']] ?? $r['nature_reevaluation'] ?? '-' ?></td>
                                    <td><?= $methodes_reevaluation[$r['methode_reevaluation']] ?? $r['methode_reevaluation'] ?? '-' ?></td>
                                    <td class="text-right"><?= number_format($r['valeur_avant'],0,',',' ') ?></td>
                                    <td class="text-right"><?= number_format($r['valeur_apres'],0,',',' ') ?></td>
                                    <td class="text-right <?= $ecart_class ?>"><?= $ecart_sign ?><?= number_format(abs($ecart),0,',',' ') ?></td>
                                    <td class="action-buttons">
                                        <a href="?exercice=<?= $exercice ?>&type_periode=<?= $type_periode ?><?= $type_periode=='mensuel'?"&mois=$mois":($type_periode=='trimestre'?"&trimestre=$trimestre":($type_periode=='semestre'?"&semestre=$semestre":"")) ?>&edit=<?= $r['id'] ?>" class="btn-warning" style="padding:4px 12px;"><i class="fas fa-edit"></i> Modifier</a>
                                        <form method="post" style="display:inline-block;" onsubmit="return confirm('Supprimer cette réévaluation ?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="btn-danger" style="padding:4px 12px;"><i class="fas fa-trash"></i> Supprimer</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td colspan="4"><strong>TOTAL</strong></td>
                                <td class="text-right"><strong><?= number_format($total_valeur_avant,0,',',' ') ?></strong></td>
                                <td class="text-right"><strong><?= number_format($total_valeur_apres,0,',',' ') ?></strong></td>
                                <td class="text-right"><strong><?= number_format($total_ecart,0,',',' ') ?></strong></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-chart-pie"></i> RÉCAPITULATIF DES ÉCARTS DE RÉÉVALUATION</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-calculator"></i>
                <div>
                    <strong>Écart total positif (plus-value) :</strong> <?= number_format($total_plus_value,0,',',' ') ?> FCFA<br>
                    <strong>Écart total négatif (moins-value) :</strong> <?= number_format($total_moins_value,0,',',' ') ?> FCFA<br>
                    <strong>Écart net :</strong> <?= number_format($total_ecart,0,',',' ') ?> FCFA
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> – Données extraites de la base Mandigo
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const currentMois = <?= $mois ?>;
        const currentTrimestre = <?= $trimestre ?>;
        const currentSemestre = <?= json_encode($semestre) ?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois" id="moisSelect">';
            for (let m=1;m<=12;m++) { const s=(m===currentMois)?'selected':''; const n=new Date(2000,m-1,1).toLocaleString('fr',{month:'long'}); html+=`<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`; }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
            for (let t=1;t<=4;t++) { const s=(t===currentTrimestre)?'selected':''; html+=`<option value="${t}" ${s}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
            for (let s=1;s<=2;s++) { const sel=(s===currentSemestre)?'selected':''; html+=`<option value="${s}" ${sel}>${s}${s===1?'er':'e'} semestre</option>`; }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
        }
        container.innerHTML = html;
    }

    function exporterPDF() {
        const form = document.getElementById('filtersForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'format';
        input.value = 'pdf';
        form.appendChild(input);
        form.target = '_blank';
        form.submit();
        form.target = '';
        form.removeChild(input);
    }

    function exporterExcel() {
        const form = document.getElementById('filtersForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'format';
        input.value = 'excel';
        form.appendChild(input);
        form.submit();
        form.removeChild(input);
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);
    });
</script>
</body>
</html>