<?php
// DIMF_2015.php - État des valeurs immobilisées
// Version conforme au modèle Excel DIMF_2015
// Utilise les tables existantes : immobilisations, amortissements, ecritures_comptables, plan_comptables

session_start();

require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ============================================================
// PARAMÈTRES (POST / GET)
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
// RÉCUPÉRATION DES DONNÉES D'IMMOBILISATIONS
// ============================================================
$immos_data = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            type_immobilisation,
            libelle,
            montant_achat as brut,
            amortissement_total as amort,
            (montant_achat - amortissement_total) as net
        FROM immobilisations
        WHERE statut = 'actif' AND date_achat <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $immos = $stmt->fetchAll();
} catch (PDOException $e) {
    $immos = [];
}

// ============================================================
// DÉFINITION DE LA STRUCTURE DU TABLEAU (conforme au fichier Excel)
// ============================================================
$lignes_excel = [
    // Code => [libelle, parent (pour agrégat), type de regroupement dans la table immobilisations]
    'D1A' => ['Immobilisations financières', null, 'aggregate', ['D1E','D1L','D1S']],
    'D1E' => ['Titres de participation', 'D1A', 'type', 'Immobilisations financières'],
    'D1L' => ['Titres d\'investissement', 'D1A', 'type', 'Immobilisations financières'],
    'D1S' => ['Dépôts et cautionnements', 'D1A', 'type', 'Immobilisations financières'],
    'D23' => ['Immobilisations en cours', null, 'type', 'Immobilisations en cours'],
    'D24' => ['Incorporelles', null, 'type', 'Immobilisations incorporelles'],
    'D25' => ['Corporelles', null, 'type', 'Immobilisations corporelles'],
    'D30' => ['Immobilisations d\'exploitation', null, 'aggregate', ['D31','D36']],
    'D31' => ['Incorporelles', 'D30', 'type', 'Immobilisations incorporelles d\'exploitation'],
    'D32' => ['Droit au bail', 'D31', 'type', 'Immobilisations incorporelles d\'exploitation - droit au bail'],
    'D33' => ['Autres éléments du fonds commercial', 'D31', 'type', 'Immobilisations incorporelles d\'exploitation - fonds commercial'],
    'D34' => ['Frais d\'établissement', 'D31', 'type', 'Immobilisations incorporelles d\'exploitation - frais d\'établissement'],
    'D35' => ['Autres immobilisations incorporelles', 'D31', 'type', 'Immobilisations incorporelles d\'exploitation - autres'],
    'D36' => ['Corporelles', 'D30', 'type', 'Immobilisations corporelles d\'exploitation'],
    'D40' => ['Immobilisations hors exploitation', null, 'aggregate', ['D41','D45']],
    'D41' => ['Incorporelles', 'D40', 'type', 'Immobilisations incorporelles hors exploitation'],
    'D42' => ['Droit au bail', 'D41', 'type', 'Immobilisations incorporelles hors exploitation - droit au bail'],
    'D43' => ['Autres éléments du fonds commercial', 'D41', 'type', 'Immobilisations incorporelles hors exploitation - fonds commercial'],
    'D44' => ['Autres immobilisations incorporelles', 'D41', 'type', 'Immobilisations incorporelles hors exploitation - autres'],
    'D45' => ['Corporelles', 'D40', 'type', 'Immobilisations corporelles hors exploitation'],
    'DIMF_2015_1_21' => ['Immobilisations acquises par réalisation de garantie', null, 'garantie', null],
    'D46' => ['Incorporelles', 'DIMF_2015_1_21', 'garantie_incorporelles', null],
    'D47' => ['Corporelles', 'DIMF_2015_1_21', 'garantie_corporelles', null],
];

// ============================================================
// MAPPING : type_immobilisation -> code Excel
// ============================================================
$type_to_code = [
    'Immobilisations financières' => 'D1E', // on affecte aux titres de participation par défaut
    'Titres de participation' => 'D1E',
    'Titres d\'investissement' => 'D1L',
    'Dépôts et cautionnements' => 'D1S',
    'Immobilisations en cours' => 'D23',
    'Immobilisations incorporelles' => 'D24',
    'Immobilisations corporelles' => 'D25',
    'Immobilisations d\'exploitation' => 'D30', // non utilisé directement
    'Immobilisations incorporelles d\'exploitation' => 'D31',
    'Immobilisations corporelles d\'exploitation' => 'D36',
    'Immobilisations hors exploitation' => 'D40',
    'Immobilisations incorporelles hors exploitation' => 'D41',
    'Immobilisations corporelles hors exploitation' => 'D45',
];

// ============================================================
// AGRÉGATION DES DONNÉES PAR CODE
// ============================================================
$data = [];
foreach ($lignes_excel as $code => $info) {
    $data[$code] = ['brut' => 0, 'amort' => 0, 'net' => 0, 'libelle' => $info[0], 'parent' => $info[1], 'type' => $info[2]];
}

// Remplir à partir des immobilisations
foreach ($immos as $immo) {
    $type = $immo['type_immobilisation'];
    $code = $type_to_code[$type] ?? null;
    if ($code && isset($data[$code])) {
        $data[$code]['brut'] += (float)$immo['brut'];
        $data[$code]['amort'] += (float)$immo['amort'];
        $data[$code]['net'] += (float)$immo['net'];
    } else {
        // Si le type n'est pas reconnu, on le range dans la catégorie la plus générique (ex: D24 ou D25)
        if (stripos($type, 'incorporelle') !== false) {
            $code = 'D24';
        } elseif (stripos($type, 'corporelle') !== false) {
            $code = 'D25';
        } else {
            $code = 'D23'; // par défaut immobilisations en cours
        }
        if (isset($data[$code])) {
            $data[$code]['brut'] += (float)$immo['brut'];
            $data[$code]['amort'] += (float)$immo['amort'];
            $data[$code]['net'] += (float)$immo['net'];
        }
    }
}

// ============================================================
// CALCUL DES AGRÉGATS (D1A, D30, D40, DIMF_2015_1_21)
// ============================================================
foreach ($lignes_excel as $code => $info) {
    if ($info[2] === 'aggregate') {
        $children = $info[3];
        $brut = $amort = $net = 0;
        foreach ($children as $child) {
            if (isset($data[$child])) {
                $brut += $data[$child]['brut'];
                $amort += $data[$child]['amort'];
                $net += $data[$child]['net'];
            }
        }
        $data[$code]['brut'] = $brut;
        $data[$code]['amort'] = $amort;
        $data[$code]['net'] = $net;
    }
}

// ============================================================
// IMMOBILISATIONS ACQUISES PAR RÉALISATION DE GARANTIE (Z03)
// ============================================================
$immobilisations_garantie = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valeur_nette), 0) as total FROM garanties WHERE code_type_garantie = '04' AND statut = 'realise'");
    $stmt->execute();
    $immobilisations_garantie = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// On affecte à DIMF_2015_1_21 (et éventuellement D46, D47 si on a des détails)
$data['DIMF_2015_1_21']['brut'] = $immobilisations_garantie;
$data['DIMF_2015_1_21']['net'] = $immobilisations_garantie;
// D46 et D47 restent à zéro (pas de détail)

// ============================================================
// AMORTISSEMENTS DE L'EXERCICE
// ============================================================
$amortissements_exercice = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(dotation_mois), 0) as total FROM amortissements WHERE exercice = :exercice");
    $stmt->execute([':exercice' => $exercice]);
    $amortissements_exercice = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

$total_brut = array_sum(array_column($data, 'brut'));
$total_amort = array_sum(array_column($data, 'amort'));
$total_net = array_sum(array_column($data, 'net'));
$taux_amortissement = ($total_brut > 0) ? ($total_amort / $total_brut) * 100 : 0;

// ============================================================
// CLASSE PDF (FPDF) avec mb_convert_encoding
// ============================================================
if ($format === 'pdf') {
    class PDF_DIMF extends FPDF {
        public $codeDimf = 'DIMF_2015';
        public $titreDimf = 'État des valeurs immobilisées';
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
            $this->Cell(0, 5, self::u('SFD : ' . $this->nomSfd . '   |   Periode : ' . $this->periode . '   |   Exercice : ' . $this->exercice . '   |   Arrete au : ' . date('d/m/Y')), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }

        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, self::u('SICS-BCEAO  •  Genere le ' . date('d/m/Y a H:i:s') . '  •  Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
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
    $pdf->nomSfd = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'CODE', 'w' => 35, 'align' => 'L'],
        ['label' => 'LIBELLÉS', 'w' => 130, 'align' => 'L'],
        ['label' => 'Montant brut (FCFA)', 'w' => 60, 'align' => 'R'],
        ['label' => 'Amortissements/Provisions (FCFA)', 'w' => 60, 'align' => 'R'],
        ['label' => 'Montants nets (FCFA)', 'w' => 60, 'align' => 'R'],
    ];

    $pdf->SectionTitle('VALEURS IMMOBILISEES');
    $pdf->TableHeader($cols);

    // Parcourir les lignes dans l'ordre du fichier Excel
    $ordre = ['D1A','D1E','D1L','D1S','D23','D24','D25','D30','D31','D32','D33','D34','D35','D36','D40','D41','D42','D43','D44','D45','DIMF_2015_1_21','D46','D47'];
    foreach ($ordre as $code) {
        if (!isset($data[$code])) continue;
        $ligne = $data[$code];
        $style = '';
        if (in_array($code, ['D1A','D30','D40','DIMF_2015_1_21'])) {
            $style = 'subtotal'; // titres de section
        }
        $pdf->TableRow($cols, [
            $code,
            PDF_DIMF::u($ligne['libelle']),
            PDF_DIMF::montant($ligne['brut']),
            PDF_DIMF::montant($ligne['amort']),
            PDF_DIMF::montant($ligne['net'])
        ], $style);
    }

    // Ligne TOTAL (somme de toutes les lignes sauf les agrégats ? dans le fichier Excel, le total est la somme de toutes les lignes)
    // On calcule la somme de toutes les lignes détaillées (non agrégats)
    $total_brut = $total_amort = $total_net = 0;
    foreach ($data as $code => $ligne) {
        if (in_array($code, ['D1A','D30','D40','DIMF_2015_1_21'])) continue; // on ne double pas les agrégats
        $total_brut += $ligne['brut'];
        $total_amort += $ligne['amort'];
        $total_net += $ligne['net'];
    }
    // On ajoute les immobilisations de garantie si non incluses
    $total_brut += $data['DIMF_2015_1_21']['brut'];
    $total_net += $data['DIMF_2015_1_21']['net'];

    $pdf->TableRow($cols, [
        '',
        'TOTAL',
        PDF_DIMF::montant($total_brut),
        PDF_DIMF::montant($total_amort),
        PDF_DIMF::montant($total_net)
    ], 'total');

    $pdf->Ln(8);
    $pdf->SectionTitle('AMORTISSEMENTS DE L\'EXERCICE');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(80, 6, 'Dotations aux amortissements de l\'exercice :', 0, 0);
    $pdf->Cell(0, 6, PDF_DIMF::montant($amortissements_exercice), 0, 1);
    $pdf->Cell(80, 6, "Taux d'amortissement global :", 0, 0);
    $pdf->Cell(0, 6, number_format($taux_amortissement, 2) . '%', 0, 1);

    $pdf->Output('I', 'DIMF_2015_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL
// ============================================================
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="DIMF_2015_' . $exercice . '.xls"');
    echo '<html><head><meta charset="UTF-8"><style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #999; padding: 8px; text-align: left; }
        th { background: #f2f2f2; }
        .text-right { text-align: right; }
        .subtotal { background: #f8fafc; font-weight: bold; }
        .total { background: #e8f5e9; font-weight: bold; }
    </style></head><body>';
    echo '<h2>DIMF_2015 - État des valeurs immobilisées</h2>';
    echo '<p>Période : ' . $lib_periode . '</p>';
    echo '<table>';
    echo '<tr><th>CODE</th><th>LIBELLÉS</th><th class="text-right">Montant brut (FCFA)</th><th class="text-right">Amortissements (FCFA)</th><th class="text-right">Montants nets (FCFA)</th></tr>';
    $ordre = ['D1A','D1E','D1L','D1S','D23','D24','D25','D30','D31','D32','D33','D34','D35','D36','D40','D41','D42','D43','D44','D45','DIMF_2015_1_21','D46','D47'];
    foreach ($ordre as $code) {
        if (!isset($data[$code])) continue;
        $ligne = $data[$code];
        $class = '';
        if (in_array($code, ['D1A','D30','D40','DIMF_2015_1_21'])) $class = 'subtotal';
        echo '<tr class="' . $class . '"><td>' . $code . '</td>';
        echo '<td>' . htmlspecialchars($ligne['libelle']) . '</td>';
        echo '<td class="text-right">' . number_format($ligne['brut'],0,',',' ') . '</td>';
        echo '<td class="text-right">' . number_format($ligne['amort'],0,',',' ') . '</td>';
        echo '<td class="text-right">' . number_format($ligne['net'],0,',',' ') . '</td>';
        echo '</tr>';
    }
    // TOTAL
    $total_brut = $total_amort = $total_net = 0;
    foreach ($data as $code => $ligne) {
        if (in_array($code, ['D1A','D30','D40','DIMF_2015_1_21'])) continue;
        $total_brut += $ligne['brut'];
        $total_amort += $ligne['amort'];
        $total_net += $ligne['net'];
    }
    $total_brut += $data['DIMF_2015_1_21']['brut'];
    $total_net += $data['DIMF_2015_1_21']['net'];
    echo '<tr class="total"><td colspan="2"><strong>TOTAL</strong></td>';
    echo '<td class="text-right"><strong>' . number_format($total_brut,0,',',' ') . '</strong></td>';
    echo '<td class="text-right"><strong>' . number_format($total_amort,0,',',' ') . '</strong></td>';
    echo '<td class="text-right"><strong>' . number_format($total_net,0,',',' ') . '</strong></td></tr>';
    echo '</table>';
    echo '<p><strong>Dotations aux amortissements :</strong> ' . number_format($amortissements_exercice,0,',',' ') . ' FCFA</p>';
    echo '<p><strong>Taux d\'amortissement global :</strong> ' . number_format($taux_amortissement,2) . '%</p>';
    echo '</body></html>';
    exit;
}

// ============================================================
// AFFICHAGE WEB (HTML)
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2015 - État des valeurs immobilisées</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',system-ui,sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; display:flex; align-items:center; gap:10px; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; display:inline-block; margin-top:8px; }
        .btn-group { display:flex; gap:12px; }
        .btn-excel, .btn-pdf { display:inline-flex; align-items:center; gap:8px; padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; }
        .btn-excel { background:#10b981; color:white; }
        .btn-excel:hover { background:#059669; }
        .btn-pdf { background:#ef4444; color:white; }
        .btn-pdf:hover { background:#dc2626; }
        .card { background:white; border-radius:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px; overflow:hidden; }
        .card-header { display:flex; align-items:center; gap:10px; padding:16px 24px; background:#f8fafc; border-bottom:1px solid #eef2f6; font-weight:600; color:#1e40af; }
        .card-body { padding:20px 24px; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select, .filter-item input { background:white; border:1px solid #d1d5db; border-radius:12px; padding:8px 14px; font-size:0.85rem; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        th, td { padding:12px 16px; text-align:left; border-bottom:1px solid #f1f5f9; }
        th { background:#f8fafc; font-weight:600; }
        .text-right { text-align:right; font-family:'Courier New',monospace; }
        .total-row { background:#f0fdf4; font-weight:700; }
        .subtotal-row { background:#f8fafc; font-weight:600; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px; border-radius:16px; display:flex; align-items:center; gap:14px; margin-bottom:20px; }
        .footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; padding:16px; }
        @media (max-width:768px) { body { padding:12px; } .filters-row { flex-direction:column; } }
        @media print { .btn-group, .filters-row, #filtersCard { display:none; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-building"></i> DIMF_2015 - ÉTAT DES VALEURS IMMOBILISÉES</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Immobilisations</div>
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
                        echo '<label>Période</label><input type="text" disabled value="Année complète">';
                    }
                    ?>
                </div>
                <div class="filter-item">
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;"><i class="fas fa-info-circle"></i> Période : <?= $lib_periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)</div>
        </div>
    </form>

    <!-- Tableau principal -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> VALEURS IMMOBILISÉES</div>
        <div class="card-body">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>CODE</th><th>LIBELLÉS</th><th class="text-right">Montant brut (FCFA)</th><th class="text-right">Amortissements/Provisions (FCFA)</th><th class="text-right">Montants nets (FCFA)</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $ordre = ['D1A','D1E','D1L','D1S','D23','D24','D25','D30','D31','D32','D33','D34','D35','D36','D40','D41','D42','D43','D44','D45','DIMF_2015_1_21','D46','D47'];
                        foreach ($ordre as $code):
                            if (!isset($data[$code])) continue;
                            $ligne = $data[$code];
                            $class = '';
                            if (in_array($code, ['D1A','D30','D40','DIMF_2015_1_21'])) $class = 'subtotal-row';
                        ?>
                        <tr class="<?= $class ?>">
                            <td><?= $code ?></td>
                            <td><?= htmlspecialchars($ligne['libelle']) ?></td>
                            <td class="text-right"><?= number_format($ligne['brut'],0,',',' ') ?></td>
                            <td class="text-right"><?= number_format($ligne['amort'],0,',',' ') ?></td>
                            <td class="text-right"><?= number_format($ligne['net'],0,',',' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php
                        // Calcul du TOTAL (somme des lignes détaillées)
                        $total_brut = $total_amort = $total_net = 0;
                        foreach ($data as $code => $ligne) {
                            if (in_array($code, ['D1A','D30','D40','DIMF_2015_1_21'])) continue;
                            $total_brut += $ligne['brut'];
                            $total_amort += $ligne['amort'];
                            $total_net += $ligne['net'];
                        }
                        $total_brut += $data['DIMF_2015_1_21']['brut'];
                        $total_net += $data['DIMF_2015_1_21']['net'];
                        ?>
                        <tr class="total-row">
                            <td colspan="2"><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($total_brut,0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_amort,0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_net,0,',',' ') ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Amortissements de l'exercice -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> AMORTISSEMENTS DE L'EXERCICE</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-calculator"></i>
                <div>
                    <strong>Dotations aux amortissements :</strong> <?= number_format($amortissements_exercice,0,',',' ') ?> FCFA<br>
                    <strong>Taux d'amortissement global :</strong> <?= number_format($taux_amortissement,2) ?>%
                </div>
            </div>
        </div>
    </div>

    <div class="footer"><i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?></div>
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
            html = '<label>Mois</label><select name="mois" id="moisSelect">';
            for (let m = 1; m <= 12; m++) {
                html += `<option value="${m}" ${m===currentMois?'selected':''}>${String(m).padStart(2,'0')} - ${new Date(2000,m-1,1).toLocaleString('fr',{month:'long'})}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
            for (let t = 1; t <= 4; t++) {
                html += `<option value="${t}" ${t===currentTrimestre?'selected':''}>${t}${t===1?'er':'ème'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
            for (let s = 1; s <= 2; s++) {
                html += `<option value="${s}" ${s===currentSemestre?'selected':''}>${s}${s===1?'er':'e'} semestre</option>`;
            }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" disabled value="Année complète">';
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
        // PDF dans la même fenêtre
        form.submit();
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