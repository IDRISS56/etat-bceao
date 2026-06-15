<?php
// DIMF_2016.php - État d'affectation du résultat
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
$date_debut_exercice = $exercice . '-01-01';
$lib_periode = match($type_periode) {
    'mensuel'   => 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice,
    'trimestre' => $trimestre . 'e Trim. ' . $exercice,
    'semestre'  => $semestre . 'er Sem. ' . $exercice,
    default     => 'Année ' . $exercice,
};

// ============================================================
// TRAITEMENT DU FORMULAIRE (SAUVEGARDE EN POST)
// ============================================================
$message = '';
$message_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS affectation_resultat (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exercice INT NOT NULL,
            type_affectation VARCHAR(50) NOT NULL,
            montant DECIMAL(15,2) DEFAULT 0,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_exercice_type (exercice, type_affectation)
        )");

        $stmtDel = $pdo->prepare("DELETE FROM affectation_resultat WHERE exercice = :exercice");
        $stmtDel->execute([':exercice' => $exercice]);

        $stmtIns = $pdo->prepare("INSERT INTO affectation_resultat (exercice, type_affectation, montant, description) VALUES (:exercice, :type, :montant, :desc)");

        $resultat = (float)($_POST['resultat'] ?? 0);
        $report_anterieur = (float)($_POST['report_anterieur'] ?? 0);
        $stmtIns->execute([':exercice' => $exercice, ':type' => 'RESULTAT_A_AFFECTER', ':montant' => $resultat, ':desc' => 'Résultat de l\'exercice']);
        $stmtIns->execute([':exercice' => $exercice, ':type' => 'REPORT_ANTERIEUR', ':montant' => $report_anterieur, ':desc' => 'Report à nouveau antérieur']);

        $reserve_generale = (float)($_POST['reserve_generale'] ?? 0);
        $reserve_facultative = (float)($_POST['reserve_facultative'] ?? 0);
        $autres_reserves = (float)($_POST['autres_reserves'] ?? 0);
        $report_nouveau = (float)($_POST['report_nouveau'] ?? 0);
        $autres_affectations = (float)($_POST['autres_affectations'] ?? 0);
        if ($reserve_generale > 0) $stmtIns->execute([':exercice' => $exercice, ':type' => 'RESERVE_GENERALE', ':montant' => $reserve_generale, ':desc' => 'Réserve générale']);
        if ($reserve_facultative > 0) $stmtIns->execute([':exercice' => $exercice, ':type' => 'RESERVE_FACULTATIVE', ':montant' => $reserve_facultative, ':desc' => 'Réserve facultative']);
        if ($autres_reserves > 0) $stmtIns->execute([':exercice' => $exercice, ':type' => 'AUTRES_RESERVES', ':montant' => $autres_reserves, ':desc' => 'Autres réserves']);
        if ($report_nouveau > 0) $stmtIns->execute([':exercice' => $exercice, ':type' => 'REPORT_NOUVEAU', ':montant' => $report_nouveau, ':desc' => 'Report à nouveau bénéficiaire']);
        if ($autres_affectations > 0) $stmtIns->execute([':exercice' => $exercice, ':type' => 'AUTRES_AFFECTATIONS', ':montant' => $autres_affectations, ':desc' => 'Autres affectations']);

        $prelevement_reserves = (float)($_POST['prelevement_reserves'] ?? 0);
        $report_deficitaire = (float)($_POST['report_deficitaire'] ?? 0);
        if ($prelevement_reserves > 0) $stmtIns->execute([':exercice' => $exercice, ':type' => 'PRELEVEMENT_RESERVES', ':montant' => $prelevement_reserves, ':desc' => 'Prélèvement sur les réserves']);
        if ($report_deficitaire > 0) $stmtIns->execute([':exercice' => $exercice, ':type' => 'REPORT_DEFICITAIRE', ':montant' => $report_deficitaire, ':desc' => 'Report à nouveau déficitaire']);

        $message = "Affectation du résultat enregistrée avec succès !";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
    $url = "DIMF_2016.php?exercice=$exercice&type_periode=$type_periode" .
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
// RÉCUPÉRATION DES DONNÉES COMPTABLES
// ============================================================
$resultat_exercice = 0;
try {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN pc.classe_compte = '7' THEN e.montant_credit - e.montant_debit ELSE 0 END), 0) as produits,
            COALESCE(SUM(CASE WHEN pc.classe_compte = '6' THEN e.montant_debit - e.montant_credit ELSE 0 END), 0) as charges
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte IN ('6', '7')
          AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $r = $stmt->fetch();
    $resultat_exercice = (float)$r['produits'] - (float)$r['charges'];
} catch (PDOException $e) { $resultat_exercice = 0; }

$report_anterieur = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '11%'
          AND e.date_ecriture < :debut
    ");
    $stmt->execute([':debut' => $date_debut_exercice]);
    $report_anterieur = (float)$stmt->fetch()['solde'];
} catch (PDOException $e) { $report_anterieur = 0; }

$affectations_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM affectation_resultat WHERE exercice = :exercice");
    $stmt->execute([':exercice' => $exercice]);
    foreach ($stmt->fetchAll() as $row) {
        $affectations_data[$row['type_affectation']] = (float)$row['montant'];
    }
} catch (PDOException $e) { $affectations_data = []; }

$reserve_generale_solde = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '106%'
          AND e.date_ecriture <= :fin
    ");
    $stmt->execute([':fin' => $date_fin_periode]);
    $reserve_generale_solde = (float)$stmt->fetch()['solde'];
} catch (PDOException $e) { $reserve_generale_solde = 0; }

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

$resultat_a_affecter = $default_values['resultat'] + $default_values['report_anterieur'];
$total_affectations = $default_values['reserve_generale'] + $default_values['reserve_facultative'] 
                    + $default_values['autres_reserves'] + $default_values['report_nouveau'] 
                    + $default_values['autres_affectations'];
$total_deficit = $default_values['prelevement_reserves'] + $default_values['report_deficitaire'];
$difference = $resultat_a_affecter - ($resultat_a_affecter >= 0 ? $total_affectations : $total_deficit);
$equilibre_ok = (abs($difference) < 1);
$min_reserve_requis = ($resultat_a_affecter > 0) ? $resultat_a_affecter * 0.15 : 0;

function format_montant($val) {
    return number_format((float)$val, 0, ',', ' ') . ' F';
}

// ============================================================
// CLASSE PDF (mb_convert_encoding)
// ============================================================
if ($format === 'pdf') {

    class PDF_DIMF extends FPDF {
        public $codeDimf = 'DIMF_2016';
        public $titreDimf = "Etat d'affectation du résultat";
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
            $this->Cell(100, 7, self::u($label), 1, 0, 'L', $fill);
            $this->Cell(0, 7, self::u($value), 1, 1, 'R', $fill);
        }

        static function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }

    if (ob_get_length()) ob_end_clean();

    $pdf = new PDF_DIMF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->nomSfd = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(15, 35, 15);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $pdf->SectionTitle('DÉTERMINATION DU RÉSULTAT À AFFECTER');
    $pdf->TableRow2Cols('L80 - Résultat de l\'exercice (bénéfice ou déficit)', PDF_DIMF::montant($default_values['resultat']));
    $pdf->TableRow2Cols('L70 - Report à nouveau antérieur (bénéfice ou déficit)', PDF_DIMF::montant($default_values['report_anterieur']));
    $pdf->TableRow2Cols('RÉSULTAT À AFFECTER (L80 + L70)', PDF_DIMF::montant($resultat_a_affecter), 'total');
    $pdf->Ln(5);

    if ($resultat_a_affecter >= 0) {
        $pdf->SectionTitle('AFFECTATION DU RÉSULTAT BÉNÉFICIAIRE');
        $pdf->TableRow2Cols('772 - Réserve générale (minimum 15% du bénéfice)', PDF_DIMF::montant($default_values['reserve_generale']));
        $pdf->TableRow2Cols('773 - Réserve facultative', PDF_DIMF::montant($default_values['reserve_facultative']));
        $pdf->TableRow2Cols('774 - Autres réserves', PDF_DIMF::montant($default_values['autres_reserves']));
        $pdf->TableRow2Cols('776 - Report à nouveau bénéficiaire', PDF_DIMF::montant($default_values['report_nouveau']));
        $pdf->TableRow2Cols('777 - Autres affectations', PDF_DIMF::montant($default_values['autres_affectations']));
        $pdf->TableRow2Cols('TOTAL AFFECTATIONS', PDF_DIMF::montant($total_affectations), 'total');
        if ($default_values['reserve_generale'] < $min_reserve_requis) {
            $pdf->Ln(3);
            $pdf->SetFont('Arial', 'I', 8);
            $pdf->SetTextColor(220, 38, 38);
            $pdf->Cell(0, 5, PDF_DIMF::u("Attention : La dotation à la réserve générale (" . PDF_DIMF::montant($default_values['reserve_generale']) . ") est inférieure au minimum requis de " . PDF_DIMF::montant($min_reserve_requis) . " (15%)."), 0, 1);
        }
    } else {
        $pdf->SectionTitle('AFFECTATION DU RÉSULTAT DÉFICITAIRE');
        $pdf->TableRow2Cols('776 - Report à nouveau déficitaire', PDF_DIMF::montant($default_values['report_deficitaire']));
        $pdf->TableRow2Cols('778 - Prélèvement sur les réserves', PDF_DIMF::montant($default_values['prelevement_reserves']));
        $pdf->TableRow2Cols('TOTAL AFFECTATIONS', PDF_DIMF::montant($total_deficit), 'total');
    }

    $pdf->Ln(5);
    $pdf->SectionTitle('VÉRIFICATION DE L\'ÉQUILIBRE');
    if ($equilibre_ok) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(22, 163, 74);
        $pdf->Cell(0, 7, PDF_DIMF::u('✓ ÉQUILIBRE - Le résultat à affecter correspond au total des affectations.'), 0, 1);
    } else {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(220, 38, 38);
        $pdf->Cell(0, 7, PDF_DIMF::u('✗ DÉSÉQUILIBRE - Écart de ' . PDF_DIMF::montant(abs($difference)) . ' FCFA.'), 0, 1);
    }

    $pdf->Output('I', 'DIMF_2016_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL (POST)
// ============================================================
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="DIMF_2016_' . $exercice . '.xls"');
    echo '<html><head><meta charset="UTF-8"><style> body { font-family: Arial; } td { text-align: right; } .text-left { text-align: left; } </style></head><body>';
    echo '<h2>DIMF_2016 - État d\'affectation du résultat</h2>';
    echo '<p>Période : ' . htmlspecialchars($lib_periode) . '</p>';
    echo '<table border="1"><thead>';
    echo '<tr><th>Poste</th><th>Montant (FCFA)</th></tr>';
    echo '</thead><tbody>';
    echo '<tr><td>L80 - Résultat de l\'exercice</td><td>' . number_format($default_values['resultat'],0,',',' ') . '</td></tr>';
    echo '<tr><td>L70 - Report à nouveau antérieur</td><td>' . number_format($default_values['report_anterieur'],0,',',' ') . '</td></tr>';
    echo '<tr style="background:#e8f5e9;"><td><strong>RÉSULTAT À AFFECTER</strong></td><td><strong>' . number_format($resultat_a_affecter,0,',',' ') . '</strong></td></tr>';
    echo '<tr><td colspan="2">&nbsp;</td></tr>';
    if ($resultat_a_affecter >= 0) {
        echo '<tr><th colspan="2">AFFECTATION DU RÉSULTAT BÉNÉFICIAIRE</th></tr>';
        echo '<tr><td>772 - Réserve générale</td><td>' . number_format($default_values['reserve_generale'],0,',',' ') . '</td></tr>';
        echo '<tr><td>773 - Réserve facultative</td><td>' . number_format($default_values['reserve_facultative'],0,',',' ') . '</td></tr>';
        echo '<tr><td>774 - Autres réserves</td><td>' . number_format($default_values['autres_reserves'],0,',',' ') . '</td></tr>';
        echo '<tr><td>776 - Report à nouveau bénéficiaire</td><td>' . number_format($default_values['report_nouveau'],0,',',' ') . '</td></tr>';
        echo '<tr><td>777 - Autres affectations</td><td>' . number_format($default_values['autres_affectations'],0,',',' ') . '</td></tr>';
        echo '<tr style="background:#e8f5e9;"><td><strong>TOTAL AFFECTATIONS</strong></td><td><strong>' . number_format($total_affectations,0,',',' ') . '</strong></td></tr>';
    } else {
        echo '<tr><th colspan="2">AFFECTATION DU RÉSULTAT DÉFICITAIRE</th></tr>';
        echo '<tr><td>776 - Report à nouveau déficitaire</td><td>' . number_format($default_values['report_deficitaire'],0,',',' ') . '</td></tr>';
        echo '<tr><td>778 - Prélèvement sur les réserves</td><td>' . number_format($default_values['prelevement_reserves'],0,',',' ') . '</td></tr>';
        echo '<tr style="background:#e8f5e9;"><td><strong>TOTAL AFFECTATIONS</strong></td><td><strong>' . number_format($total_deficit,0,',',' ') . '</strong></td></tr>';
    }
    echo '<tr><td colspan="2">&nbsp;</td></tr>';
    echo '<tr><th colspan="2">VÉRIFICATION DE L\'ÉQUILIBRE</th></tr>';
    if ($equilibre_ok) {
        echo '<tr><td colspan="2" style="color:#2e7d32;">✓ ÉQUILIBRE - Le résultat à affecter correspond au total des affectations.</td></tr>';
    } else {
        echo '<tr><td colspan="2" style="color:#c62828;">✗ DÉSÉQUILIBRE - Écart de ' . number_format(abs($difference),0,',',' ') . ' FCFA.</td></tr>';
    }
    echo '</tbody></table>';
    echo '</body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2016 - Affectation du résultat</title>
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
        .card { background:white; border-radius:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px; overflow:hidden; }
        .card-header { display:flex; align-items:center; gap:10px; padding:16px 24px; background:#f8fafc; border-bottom:1px solid #eef2f6; font-weight:600; color:#1e40af; }
        .card-body { padding:20px 24px; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select { background:white; border:1px solid #d1d5db; border-radius:12px; padding:8px 14px; font-size:0.85rem; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .form-group { margin-bottom:15px; }
        .form-group label { display:block; font-weight:600; margin-bottom:5px; color:#555; font-size:0.8rem; }
        .form-group input { width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:8px; font-size:0.9rem; text-align:right; font-family:monospace; }
        .form-group input:focus { outline:none; border-color:#3b82f6; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px; border-radius:16px; display:flex; align-items:center; gap:14px; margin-bottom:20px; }
        .alert { padding:14px 20px; border-radius:16px; margin-bottom:20px; display:flex; align-items:center; gap:12px; }
        .alert-success { background:#ecfdf5; color:#065f46; border-left:4px solid #10b981; }
        .alert-error { background:#fef2f2; color:#991b1b; border-left:4px solid #ef4444; }
        .calculated-value { background:#e8f5e9; font-weight:bold; }
        .footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; padding:16px; }
        @media (max-width:768px) { body { padding:12px; } .filters-row { flex-direction:column; } .btn-group { flex-wrap:wrap; } }
        @media print { .btn-group, .footer, .filters-row, .btn-save, #filtersCard, .alert { display:none !important; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-pie"></i> DIMF_2016 - AFFECTATION DU RÉSULTAT</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Affectation des résultats</div>
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
                    <strong>Note :</strong> Conformément à la réglementation, les SFD doivent affecter au minimum 15% du bénéfice à la réserve générale.<br>
                    Réserve générale actuelle : <strong><?= number_format($reserve_generale_solde,0,',',' ') ?> FCFA</strong>
                </div>
            </div>
        </div>
    </div>

    <form method="post" action="">
        <input type="hidden" name="action" value="save">

        <div class="card">
            <div class="card-header"><i class="fas fa-chart-line"></i> DÉTERMINATION DU RÉSULTAT À AFFECTER</div>
            <div class="card-body">
                <div class="form-group">
                    <label>L80 - Résultat de l'exercice (bénéfice ou déficit)</label>
                    <small style="display:block; color:#6b7280;">Calculé automatiquement à partir des produits et charges</small>
                    <input type="number" name="resultat" step="1" id="resultat" class="calculated-value" value="<?= number_format($default_values['resultat'],0,'','') ?>">
                </div>
                <div class="form-group">
                    <label>L70 - Report à nouveau antérieur (bénéfice ou déficit)</label>
                    <small style="display:block; color:#6b7280;">Solde des comptes de report à nouveau</small>
                    <input type="number" name="report_anterieur" step="1" id="report_anterieur" class="calculated-value" value="<?= number_format($default_values['report_anterieur'],0,'','') ?>">
                </div>
                <div class="form-group" style="background:#f0fdf4; padding:12px; border-radius:12px;">
                    <label style="font-weight:bold;">RÉSULTAT À AFFECTER (L80 + L70)</label>
                    <input type="text" id="resultat_a_affecter_display" readonly style="background:#f0fdf4; font-weight:bold; font-size:1.1rem;" value="<?= number_format($resultat_a_affecter,0,',',' ') ?> FCFA">
                </div>
            </div>
        </div>

        <?php if ($resultat_a_affecter >= 0): ?>
        <div class="card">
            <div class="card-header"><i class="fas fa-plus-circle"></i> AFFECTATION DU RÉSULTAT BÉNÉFICIAIRE</div>
            <div class="card-body">
                <div class="form-group">
                    <label>772 - Réserve générale (minimum 15% du bénéfice)</label>
                    <input type="number" name="reserve_generale" step="1" id="reserve_generale" value="<?= number_format($default_values['reserve_generale'],0,'','') ?>">
                </div>
                <div class="form-group">
                    <label>773 - Réserve facultative</label>
                    <input type="number" name="reserve_facultative" step="1" id="reserve_facultative" value="<?= number_format($default_values['reserve_facultative'],0,'','') ?>">
                </div>
                <div class="form-group">
                    <label>774 - Autres réserves</label>
                    <input type="number" name="autres_reserves" step="1" id="autres_reserves" value="<?= number_format($default_values['autres_reserves'],0,'','') ?>">
                </div>
                <div class="form-group">
                    <label>776 - Report à nouveau bénéficiaire</label>
                    <input type="number" name="report_nouveau" step="1" id="report_nouveau" value="<?= number_format($default_values['report_nouveau'],0,'','') ?>">
                </div>
                <div class="form-group">
                    <label>777 - Autres affectations</label>
                    <input type="number" name="autres_affectations" step="1" id="autres_affectations" value="<?= number_format($default_values['autres_affectations'],0,'','') ?>">
                </div>
                <div class="form-group" style="background:#f0fdf4; padding:12px; border-radius:12px;">
                    <label style="font-weight:bold;">TOTAL AFFECTATIONS</label>
                    <input type="text" id="total_affectations_display" readonly style="background:#f0fdf4; font-weight:bold;" value="<?= number_format($total_affectations,0,',',' ') ?> FCFA">
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-header"><i class="fas fa-minus-circle"></i> AFFECTATION DU RÉSULTAT DÉFICITAIRE</div>
            <div class="card-body">
                <div class="form-group">
                    <label>776 - Report à nouveau déficitaire</label>
                    <input type="number" name="report_deficitaire" step="1" id="report_deficitaire" value="<?= number_format($default_values['report_deficitaire'],0,'','') ?>">
                </div>
                <div class="form-group">
                    <label>778 - Prélèvement sur les réserves</label>
                    <input type="number" name="prelevement_reserves" step="1" id="prelevement_reserves" value="<?= number_format($default_values['prelevement_reserves'],0,'','') ?>">
                </div>
                <div class="form-group" style="background:#f0fdf4; padding:12px; border-radius:12px;">
                    <label style="font-weight:bold;">TOTAL AFFECTATIONS</label>
                    <input type="text" id="total_affectations_display" readonly style="background:#f0fdf4; font-weight:bold;" value="<?= number_format($total_deficit,0,',',' ') ?> FCFA">
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><i class="fas fa-check-circle"></i> VÉRIFICATION ET SAUVEGARDE</div>
            <div class="card-body">
                <div class="info-box" id="verification-box">
                    <i class="fas fa-calculator"></i>
                    <div id="verification-message">Vérification de l'équilibre...</div>
                </div>
                <div style="text-align:center; margin-top:20px;">
                    <button type="submit" class="btn-save" id="btn-submit"><i class="fas fa-save"></i> Enregistrer l'affectation</button>
                </div>
            </div>
        </div>
    </form>

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
                warningHtml = '<br><span style="color:#ef6c00;">⚠️ La dotation à la réserve générale (' + reserveGenerale.toLocaleString('fr-FR') + ' FCFA) est inférieure au minimum requis de ' + minReserve.toLocaleString('fr-FR') + ' FCFA (15%).</span>';
            }
        <?php else: ?>
            let reportDeficitaire = parseFloat(document.getElementById('report_deficitaire').value) || 0;
            let prelevementReserves = parseFloat(document.getElementById('prelevement_reserves').value) || 0;
            totalAffectations = reportDeficitaire + prelevementReserves;
            let warningHtml = '';
        <?php endif; ?>

        document.getElementById('total_affectations_display').value = totalAffectations.toLocaleString('fr-FR') + ' FCFA';
        let difference = resultatAAffecter - totalAffectations;
        let box = document.getElementById('verification-box');
        let msg = document.getElementById('verification-message');
        let submitBtn = document.getElementById('btn-submit');

        if (Math.abs(difference) < 1) {
            box.className = 'info-box';
            msg.innerHTML = '<span style="color:#2e7d32;">✓ ÉQUILIBRE - Le résultat à affecter correspond au total des affectations.</span>' + (typeof warningHtml !== 'undefined' ? warningHtml : '');
            submitBtn.disabled = false;
        } else {
            box.className = 'alert alert-error';
            msg.innerHTML = '<span style="color:#c62828;">✗ DÉSÉQUILIBRE - Écart de ' + Math.abs(difference).toLocaleString('fr-FR') + ' FCFA. Veuillez ajuster les montants.</span>' + (typeof warningHtml !== 'undefined' ? warningHtml : '');
            submitBtn.disabled = true;
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);

        const inputs = ['resultat', 'report_anterieur', 'reserve_generale', 'reserve_facultative', 
                        'autres_reserves', 'report_nouveau', 'autres_affectations', 'report_deficitaire', 
                        'prelevement_reserves'];
        inputs.forEach(id => {
            const inp = document.getElementById(id);
            if (inp) { inp.addEventListener('input', verifierEquilibre); inp.addEventListener('change', verifierEquilibre); }
        });
        verifierEquilibre();
    });
</script>
</body>
</html>