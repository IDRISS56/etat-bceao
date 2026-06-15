<?php
// DIMF_2015.php - État des valeurs immobilisées (corrigé : affichage des valeurs réelles dans les codes D)
// Design DIMF_2000 - Filtres dynamiques

session_start();

// ------------------------- CONNEXION BDD -------------------------
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

// ------------------------- PARAMÈTRES -------------------------
$exercice     = isset($_GET['exercice'])     ? (int)$_GET['exercice']     : date('Y');
$type_periode = isset($_GET['type_periode']) ? $_GET['type_periode']      : 'mensuel';
$mois         = isset($_GET['mois'])         ? (int)$_GET['mois']         : 12;
$trimestre    = isset($_GET['trimestre'])    ? (int)$_GET['trimestre']    : 4;
$semestre     = isset($_GET['semestre'])     ? (int)$_GET['semestre']     : 2;
$format       = isset($_GET['format'])       ? $_GET['format']            : 'html';

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
    default:          $mois = isset($_GET['mois']) ? (int)$_GET['mois'] : 12;
}
$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$lib_periode = match($type_periode) {
    'mensuel'   => 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice,
    'trimestre' => $trimestre . 'e Trim. ' . $exercice,
    'semestre'  => $semestre . 'er Sem. ' . $exercice,
    default     => 'Année ' . $exercice,
};

// ============================================================
// RÉCUPÉRATION DES IMMOBILISATIONS DEPUIS LA TABLE
// ============================================================
$immobilisations_data = [];
$total_brut = $total_amort = $total_net = 0;

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

    if (!empty($immos)) {
        // Regrouper par type_immobilisation
        foreach ($immos as $immo) {
            $type = $immo['type_immobilisation'];
            if (!isset($immobilisations_data[$type])) {
                $immobilisations_data[$type] = [
                    'brut'  => 0,
                    'amort' => 0,
                    'net'   => 0,
                    'libelle' => $type,
                    'details' => []
                ];
            }
            $immobilisations_data[$type]['brut']  += (float)$immo['brut'];
            $immobilisations_data[$type]['amort'] += (float)$immo['amort'];
            $immobilisations_data[$type]['net']   += (float)$immo['net'];
            $immobilisations_data[$type]['details'][] = $immo;
        }
    }
} catch (PDOException $e) {
    // Table absente -> on utilisera le fallback plus tard
}

// ============================================================
// FALLBACK : SI AUCUNE DONNÉE, UTILISATION DES ÉCRITURES COMPTABLES
// ============================================================
if (empty($immobilisations_data)) {
    // Liste des catégories avec plages de comptes
    $categories_comptes = [
        'D1E' => ['libelle' => 'Titres de participation',        'debut' => '261', 'fin' => '261', 'amort_debut' => '281'],
        'D1L' => ['libelle' => 'Titres d\'investissement',       'debut' => '271', 'fin' => '271', 'amort_debut' => '281'],
        'D1S' => ['libelle' => 'Dépôts et cautionnements',       'debut' => '274', 'fin' => '274', 'amort_debut' => '281'],
        'D31' => ['libelle' => 'Immobilisations incorporelles',  'debut' => '201', 'fin' => '208', 'amort_debut' => '281'],
        'D36' => ['libelle' => 'Immobilisations corporelles',    'debut' => '21',  'fin' => '22',  'amort_debut' => '281'],
        'D41' => ['libelle' => 'Immobilisations incorporelles hors exploitation', 'debut' => '291', 'fin' => '291', 'amort_debut' => '291'],
        'D45' => ['libelle' => 'Immobilisations corporelles hors exploitation', 'debut' => '292', 'fin' => '292', 'amort_debut' => '292'],
    ];

    foreach ($categories_comptes as $code => $cat) {
        $brut = 0;
        $amort = 0;

        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total
                FROM ecritures_comptables e
                INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
                WHERE pc.numero_compte BETWEEN :debut AND :fin
                  AND e.date_ecriture <= :date_fin
            ");
            $stmt->execute([':debut' => $cat['debut'], ':fin' => $cat['fin'], ':date_fin' => $date_fin_periode]);
            $brut = abs((float)$stmt->fetch()['total']);
        } catch (PDOException $e) {}

        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(montant_credit - montant_debit), 0) as total
                FROM ecritures_comptables e
                INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
                WHERE pc.numero_compte BETWEEN :debut AND :fin
                  AND e.date_ecriture <= :date_fin
            ");
            $stmt->execute([':debut' => $cat['amort_debut'], ':fin' => $cat['amort_debut'] . '99', ':date_fin' => $date_fin_periode]);
            $amort = abs((float)$stmt->fetch()['total']);
        } catch (PDOException $e) {}

        $net = $brut - $amort;

        $immobilisations_data[$code] = [
            'brut'   => $brut,
            'amort'  => $amort,
            'net'    => $net,
            'libelle'=> $cat['libelle'],
            'details'=> []
        ];
    }
}

// ============================================================
// MAPPING DES TYPES VERS LES CODES D (pour les données réelles)
// ============================================================
$map_type_to_code = [
    'Immobilisations financières' => 'D1A',
    'Titres de participation' => 'D1E',
    'Titres d\'investissement' => 'D1L',
    'Dépôts et cautionnements' => 'D1S',
    'Immobilisations d\'exploitation' => 'D30',
    'Immobilisations incorporelles' => 'D31',
    'Immobilisations incorporelles d\'exploitation' => 'D31',
    'Immobilisations corporelles' => 'D36',
    'Immobilisations corporelles d\'exploitation' => 'D36',
    'Immobilisations hors exploitation' => 'D40',
    'Immobilisations incorporelles hors exploitation' => 'D41',
    'Immobilisations corporelles hors exploitation' => 'D45',
];

// Initialisation des lignes fixes du tableau (codes D attendus)
$codes_standards = [
    'D1A' => 'Immobilisations financières',
    'D1E' => 'Titres de participation',
    'D1L' => 'Titres d\'investissement',
    'D1S' => 'Dépôts et cautionnements',
    'D30' => 'Immobilisations d\'exploitation',
    'D31' => 'Immobilisations incorporelles d\'exploitation',
    'D36' => 'Immobilisations corporelles d\'exploitation',
    'D40' => 'Immobilisations hors exploitation',
    'D41' => 'Immobilisations incorporelles hors exploitation',
    'D45' => 'Immobilisations corporelles hors exploitation',
];

$tableau_immobilisations = [];
foreach ($codes_standards as $code => $lib) {
    $tableau_immobilisations[$code] = [
        'code' => $code,
        'libelle' => $lib,
        'brut' => 0,
        'amort' => 0,
        'net' => 0,
        'is_title' => false
    ];
}

// Parcourir les immobilisations réelles (groupées par type) et ajouter leurs montants au bon code D
$lignes_supplementaires = [];
foreach ($immobilisations_data as $type => $data) {
    $code_d = null;
    // Chercher une correspondance dans le mapping
    foreach ($map_type_to_code as $type_key => $code) {
        if (stripos($type, $type_key) !== false) {
            $code_d = $code;
            break;
        }
    }
    if ($code_d && isset($tableau_immobilisations[$code_d])) {
        $tableau_immobilisations[$code_d]['brut']  += $data['brut'];
        $tableau_immobilisations[$code_d]['amort'] += $data['amort'];
        $tableau_immobilisations[$code_d]['net']   += $data['net'];
    } else {
        // Aucun mapping -> on ajoutera une ligne supplémentaire
        $lignes_supplementaires[] = [
            'code' => $type,
            'libelle' => $type,
            'brut' => $data['brut'],
            'amort' => $data['amort'],
            'net' => $data['net'],
            'is_title' => false
        ];
    }
}

// Calcul des totaux à partir des lignes du tableau (inclut les valeurs mappées)
$total_brut = $total_amort = $total_net = 0;
foreach ($tableau_immobilisations as $item) {
    $total_brut  += $item['brut'];
    $total_amort += $item['amort'];
    $total_net   += $item['net'];
}
// Ajouter les contributions des lignes supplémentaires éventuelles
foreach ($lignes_supplementaires as $item) {
    $total_brut  += $item['brut'];
    $total_amort += $item['amort'];
    $total_net   += $item['net'];
}

// Fusionner les lignes supplémentaires à la fin du tableau
$tableau_immobilisations = array_merge($tableau_immobilisations, $lignes_supplementaires);
// Convertir en tableau indexé pour faciliter l'affichage
$tableau_immobilisations = array_values($tableau_immobilisations);

// ============================================================
// IMMOBILISATIONS ACQUISES PAR RÉALISATION DE GARANTIE
// ============================================================
$immobilisations_garantie = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valeur_nette), 0) as total FROM garanties WHERE code_type_garantie = '04' AND statut = 'realise'");
    $stmt->execute();
    $immobilisations_garantie = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// ============================================================
// AMORTISSEMENTS DE L'EXERCICE
// ============================================================
$amortissements_exercice = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(dotation_mois), 0) as total FROM amortissements WHERE exercice = :exercice");
    $stmt->execute([':exercice' => $exercice]);
    $amortissements_exercice = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

$taux_amortissement = ($total_brut > 0) ? ($total_amort / $total_brut) * 100 : 0;

// ============================================================
// EXPORT PDF (FPDF) – version inchangée (reprend $tableau_immobilisations)
// ============================================================
if ($format === 'pdf') {
    // Recherche du fichier FPDF (chemin à adapter)
    $fpdf_path = __DIR__ . '/../../fpdf/fpdf.php';
    if (!file_exists($fpdf_path)) {
        $fpdf_path = __DIR__ . '/fpdf/fpdf.php';
    }
    if (!file_exists($fpdf_path)) {
        die("Erreur: FPDF non trouvé.");
    }
    require_once($fpdf_path);

    class PDF_DIMF extends FPDF {
        public $codeDimf = 'DIMF_2015';
        public $titreDimf = 'État des valeurs immobilisées';
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
            $this->Cell(0, 4, self::u('République de Côte d\'Ivoire  •  Ministère de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
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

    $cols = [
        ['label' => 'CODE', 'w' => 30, 'align' => 'L'],
        ['label' => 'LIBELLES', 'w' => 120, 'align' => 'L'],
        ['label' => 'Montant brut (FCFA)', 'w' => 60, 'align' => 'R'],
        ['label' => 'Amortissements/Provisions (FCFA)', 'w' => 60, 'align' => 'R'],
        ['label' => 'Montants nets (FCFA)', 'w' => 60, 'align' => 'R'],
    ];

    $pdf->SectionTitle('VALEURS IMMOBILISEES');
    $pdf->TableHeader($cols);

    foreach ($tableau_immobilisations as $item) {
        $pdf->TableRow($cols, [
            $item['code'],
            $item['libelle'],
            PDF_DIMF::montant($item['brut']),
            PDF_DIMF::montant($item['amort']),
            PDF_DIMF::montant($item['net'])
        ]);
    }
    // Ligne Z03 (garanties)
    $pdf->TableRow($cols, ['Z03', 'Immobilisations acquises par réalisation de garantie',
        PDF_DIMF::montant($immobilisations_garantie), '0', PDF_DIMF::montant($immobilisations_garantie)]);

    $total_brut_final = $total_brut + $immobilisations_garantie;
    $total_net_final = $total_net + $immobilisations_garantie;
    $pdf->TableRow($cols, ['', 'TOTAL',
        PDF_DIMF::montant($total_brut_final),
        PDF_DIMF::montant($total_amort),
        PDF_DIMF::montant($total_net_final)], 'total');

    $pdf->Ln(8);
    $pdf->SectionTitle('AMORTISSEMENTS DE L\'EXERCICE');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(80, 6, 'Dotations aux amortissements de l\'exercice :', 0, 0);
    $pdf->Cell(0, 6, PDF_DIMF::montant($amortissements_exercice), 0, 1);
    $pdf->Cell(80, 6, "Taux d'amortissement global :", 0, 0);
    $pdf->Cell(0, 6, number_format($taux_amortissement, 2) . '%', 0, 1);

    $pdf->Output('I', 'DIMF_2015_' . $exercice . '.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL (HTML .xls)
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
    </style></head><body>';
    echo '<h2>DIMF_2015 - État des valeurs immobilisées</h2>';
    echo '<p>Période : ' . $lib_periode . '</p>';
    echo '</table>';
    echo '<tr><th>CODE</th><th>LIBELLÉS</th><th class="text-right">Montant brut (FCFA)</th><th class="text-right">Amortissements (FCFA)</th><th class="text-right">Montants nets (FCFA)</th></tr>';
    foreach ($tableau_immobilisations as $item) {
        echo '<tr><td>' . htmlspecialchars($item['code']) . '</td><td>' . htmlspecialchars($item['libelle']) . '</td>';
        echo '<td class="text-right">' . number_format($item['brut'],0,',',' ') . '</td>';
        echo '<td class="text-right">' . number_format($item['amort'],0,',',' ') . '</td>';
        echo '<td class="text-right">' . number_format($item['net'],0,',',' ') . '</td></tr>';
    }
    echo '<tr><td>Z03</td><td>Immobilisations acquises par réalisation de garantie</td>';
    echo '<td class="text-right">' . number_format($immobilisations_garantie,0,',',' ') . '</td><td class="text-right">0</td>';
    echo '<td class="text-right">' . number_format($immobilisations_garantie,0,',',' ') . '</td></tr>';
    echo '<tr style="background:#e8f5e9;"><td colspan="2"><strong>TOTAL</strong></td>';
    echo '<td class="text-right"><strong>' . number_format($total_brut + $immobilisations_garantie,0,',',' ') . '</strong></td>';
    echo '<td class="text-right"><strong>' . number_format($total_amort,0,',',' ') . '</strong></td>';
    echo '<td class="text-right"><strong>' . number_format($total_net + $immobilisations_garantie,0,',',' ') . '</strong></td></tr>';
    echo '</table>';
    echo '</body></html>';
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
    <title>DIMF_2015 - État des valeurs immobilisées</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',system-ui,sans-serif; background:#f1f5f9; padding:24px; }
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
        .card { background:white; border-radius:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px; overflow:hidden; }
        .card-header { display:flex; align-items:center; gap:10px; padding:16px 24px; background:#f8fafc; border-bottom:1px solid #eef2f6; font-weight:600; color:#1e40af; }
        .card-body { padding:20px 24px; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select { background:white; border:1px solid #d1d5db; border-radius:12px; padding:8px 14px; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        th, td { padding:12px 16px; text-align:left; border-bottom:1px solid #f1f5f9; }
        th { background:#f8fafc; font-weight:600; }
        .text-right { text-align:right; font-family:'Courier New',monospace; }
        .total-row { background:#f0fdf4; font-weight:700; }
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
            <a class="btn-pdf" href="?<?= http_build_query(array_merge($_GET, ['format'=>'pdf'])) ?>" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
    </div>

    <!-- Filtres -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
            <div class="filters-row">
                <div class="filter-item"><label>Année</label><select id="exerciceSelect"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
                <div class="filter-item"><label>Type de période</label><select id="typePeriodeSelect"><option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option><option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option><option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option><option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option></select></div>
                <div class="filter-item" id="dynamicSelectContainer"><?php
                    if ($type_periode == 'mensuel') {
                        echo '<label>Mois</label><select id="moisSelect">';
                        for ($m=1;$m<=12;$m++) echo '<option value="'.$m.'" '.($m==$mois?'selected':'').'>'.str_pad($m,2,'0',STR_PAD_LEFT).' - '.date('F',mktime(0,0,0,$m,1)).'</option>';
                        echo '</select>';
                    } elseif ($type_periode == 'trimestre') {
                        echo '<label>Trimestre</label><select id="trimestreSelect">';
                        for ($t=1;$t<=4;$t++) echo '<option value="'.$t.'" '.($t==$trimestre?'selected':'').'>'.$t.($t==1?'er':'ème').' Trimestre</option>';
                        echo '</select>';
                    } elseif ($type_periode == 'semestre') {
                        echo '<label>Semestre</label><select id="semestreSelect">';
                        for ($s=1;$s<=2;$s++) echo '<option value="'.$s.'" '.($s==$semestre?'selected':'').'>'.$s.($s==1?'er':'e').' semestre</option>';
                        echo '</select>';
                    } else {
                        echo '<label>Période</label><input type="text" disabled value="Année complète">';
                    }
                ?></div>
                <button class="btn-apply" onclick="appliquerFiltres()">Appliquer</button>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;"><i class="fas fa-info-circle"></i> Période : <?= $lib_periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)</div>
        </div>
    </div>

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
                        <?php foreach ($tableau_immobilisations as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['code']) ?></td>
                            <td><?= htmlspecialchars($item['libelle']) ?></td>
                            <td class="text-right"><?= number_format($item['brut'], 0, ',', ' ') ?></td>
                            <td class="text-right"><?= number_format($item['amort'], 0, ',', ' ') ?></td>
                            <td class="text-right"><?= number_format($item['net'], 0, ',', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td>Z03</td>
                            <td>Immobilisations acquises par réalisation de garantie</td>
                            <td class="text-right"><?= number_format($immobilisations_garantie, 0, ',', ' ') ?></td>
                            <td class="text-right">0</td>
                            <td class="text-right"><?= number_format($immobilisations_garantie, 0, ',', ' ') ?></td>
                        </tr>
                        <tr class="total-row">
                            <td colspan="2"><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($total_brut + $immobilisations_garantie, 0, ',', ' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_amort, 0, ',', ' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_net + $immobilisations_garantie, 0, ',', ' ') ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Détail des immobilisations (si disponible) -->
    <?php if (!empty($immobilisations_data) && isset($immobilisations_data['D30']['details'])): ?>
    <div class="card">
        <div class="card-header"><i class="fas fa-list-ul"></i> DÉTAIL DES IMMOBILISATIONS</div>
        <div class="card-body">
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Type</th><th>Libellé</th><th class="text-right">Brut</th><th class="text-right">Amort</th><th class="text-right">Net</th></tr></thead>
                    <tbody>
                        <?php foreach ($immobilisations_data as $type => $data): ?>
                            <?php if (!empty($data['details'])): ?>
                                <tr class="subtotal-row"><td colspan="5"><strong><?= htmlspecialchars($type) ?></strong></td></tr>
                                <?php foreach ($data['details'] as $detail): ?>
                                <tr><td></td><td><?= htmlspecialchars($detail['libelle']) ?></td>
                                    <td class="text-right"><?= number_format($detail['brut'],0,',',' ') ?></td>
                                    <td class="text-right"><?= number_format($detail['amort'],0,',',' ') ?></td>
                                    <td class="text-right"><?= number_format($detail['net'],0,',',' ') ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr><td colspan="2"><strong>Sous-total</strong></td>
                                    <td class="text-right"><strong><?= number_format($data['brut'],0,',',' ') ?></strong></td>
                                    <td class="text-right"><strong><?= number_format($data['amort'],0,',',' ') ?></strong></td>
                                    <td class="text-right"><strong><?= number_format($data['net'],0,',',' ') ?></strong></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Amortissements de l'exercice -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> AMORTISSEMENTS DE L'EXERCICE</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-calculator"></i>
                <div>
                    <strong>Dotations aux amortissements :</strong> <?= number_format($amortissements_exercice, 0, ',', ' ') ?> FCFA<br>
                    <strong>Taux d'amortissement global :</strong> <?= number_format($taux_amortissement, 2) ?>%
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
            html = '<label>Mois</label><select id="moisSelect">';
            for (let m = 1; m <= 12; m++) { html += `<option value="${m}" ${m===currentMois?'selected':''}>${String(m).padStart(2,'0')} - ${new Date(2000,m-1,1).toLocaleString('fr',{month:'long'})}</option>`; }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select id="trimestreSelect">';
            for (let t = 1; t <= 4; t++) { html += `<option value="${t}" ${t===currentTrimestre?'selected':''}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select id="semestreSelect">';
            for (let s = 1; s <= 2; s++) { html += `<option value="${s}" ${s===currentSemestre?'selected':''}>${s}${s===1?'er':'e'} semestre</option>`; }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" disabled value="Année complète">';
        }
        container.innerHTML = html;
    }

    function appliquerFiltres() {
        const exercice = document.getElementById('exerciceSelect').value;
        const type = document.getElementById('typePeriodeSelect').value;
        let url = 'DIMF_2015.php?exercice=' + exercice + '&type_periode=' + type;
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        window.location.href = url;
    }

    function exporterExcel() {
        // Utilisation du même export Excel que dans le format 'excel'
        window.location.href = '?exercice=<?= $exercice ?>&type_periode=<?= $type_periode ?>&mois=<?= $mois ?>&trimestre=<?= $trimestre ?>&semestre=<?= $semestre ?>&format=excel';
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>