<?php
// DIMF_2015.php - État des valeurs immobilisées
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

// ============================================================
// STRUCTURE DES CATÉGORIES D'IMMOBILISATIONS
// ============================================================
$categories = [
    'D1A' => ['libelle' => 'Immobilisations financières', 'code_debut' => '26', 'code_fin' => '27', 'is_title' => true],
    'D1E' => ['libelle' => 'Titres de participation', 'code_debut' => '261', 'code_fin' => '261', 'is_title' => false],
    'D1L' => ['libelle' => 'Titres d\'investissement', 'code_debut' => '271', 'code_fin' => '271', 'is_title' => false],
    'D1S' => ['libelle' => 'Dépôts et cautionnements', 'code_debut' => '274', 'code_fin' => '274', 'is_title' => false],
    'D30' => ['libelle' => 'Immobilisations d\'exploitation', 'code_debut' => '21', 'code_fin' => '22', 'is_title' => true],
    'D31' => ['libelle' => 'Immobilisations incorporelles d\'exploitation', 'code_debut' => '201', 'code_fin' => '208', 'is_title' => false],
    'D36' => ['libelle' => 'Immobilisations corporelles d\'exploitation', 'code_debut' => '21', 'code_fin' => '22', 'is_title' => false],
    'D40' => ['libelle' => 'Immobilisations hors exploitation', 'code_debut' => '29', 'code_fin' => '29', 'is_title' => true],
    'D41' => ['libelle' => 'Immobilisations incorporelles hors exploitation', 'code_debut' => '291', 'code_fin' => '291', 'is_title' => false],
    'D45' => ['libelle' => 'Immobilisations corporelles hors exploitation', 'code_debut' => '292', 'code_fin' => '292', 'is_title' => false]
];

// ============================================================
// RÉCUPÉRATION DES DONNÉES D'IMMOBILISATIONS
// ============================================================
$immobilisations_data = [];
$total_brut = 0;
$total_amort = 0;
$total_net = 0;

// Récupération depuis la table immobilisations
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
    
    // Regrouper par type
    foreach ($immos as $immo) {
        $type = $immo['type_immobilisation'];
        if (!isset($immobilisations_data[$type])) {
            $immobilisations_data[$type] = [
                'brut' => 0,
                'amort' => 0,
                'net' => 0,
                'details' => []
            ];
        }
        $immobilisations_data[$type]['brut'] += (float)$immo['brut'];
        $immobilisations_data[$type]['amort'] += (float)$immo['amort'];
        $immobilisations_data[$type]['net'] += (float)$immo['net'];
        $immobilisations_data[$type]['details'][] = $immo;
    }
} catch (PDOException $e) {
    // Table n'existe pas
}

// Récupération depuis les écritures comptables (fallback) et calcul des totaux
if (empty($immobilisations_data)) {
    try {
        foreach ($categories as $code => $cat) {
            $brut = 0;
            $amort = 0;
            
            // Montant brut (débit des comptes d'immobilisations)
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total
                FROM ecritures_comptables e
                INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
                WHERE pc.numero_compte LIKE :code_debut
                  AND e.date_ecriture <= :date_fin
            ");
            $stmt->execute([
                ':code_debut' => $cat['code_debut'] . '%',
                ':date_fin' => $date_fin_periode
            ]);
            $result = $stmt->fetch();
            $brut = abs((float)$result['total']);
            
            // Amortissements (crédit des comptes d'amortissements)
            $code_amort = substr($cat['code_debut'], 0, 1) == '2' ? '28' : '29';
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total
                FROM ecritures_comptables e
                INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
                WHERE pc.numero_compte LIKE :code_amort
                  AND e.date_ecriture <= :date_fin
            ");
            $stmt->execute([
                ':code_amort' => $code_amort . '%',
                ':date_fin' => $date_fin_periode
            ]);
            $result = $stmt->fetch();
            $amort = abs((float)$result['total']);
            
            $net = $brut - $amort;
            
            $immobilisations_data[$code] = [
                'brut' => $brut,
                'amort' => $amort,
                'net' => $net,
                'libelle' => $cat['libelle'],
                'is_title' => $cat['is_title']
            ];
            
            $total_brut += $brut;
            $total_amort += $amort;
            $total_net += $net;
        }
    } catch (PDOException $e) {
        // Erreur de récupération
    }
} else {
    // Calcul des totaux à partir des données
    foreach ($immobilisations_data as $data) {
        $total_brut += $data['brut'];
        $total_amort += $data['amort'];
        $total_net += $data['net'];
    }
}

// Immobilisations acquises par réalisation de garantie
$immobilisations_garantie = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valeur_nette), 0) as total FROM garanties WHERE code_type_garantie = '04' AND statut = 'realise'");
    $stmt->execute();
    $result = $stmt->fetch();
    $immobilisations_garantie = (float)$result['total'];
} catch (PDOException $e) {
    $immobilisations_garantie = 0;
}

// Amortissements de l'exercice
$amortissements_exercice = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(dotation_mois), 0) as total FROM amortissements WHERE exercice = :exercice");
    $stmt->execute([':exercice' => $exercice]);
    $result = $stmt->fetch();
    $amortissements_exercice = (float)$result['total'];
} catch (PDOException $e) {
    $amortissements_exercice = 0;
}

$taux_amortissement = ($total_brut > 0) ? ($total_amort / $total_brut) * 100 : 0;

// Construction du tableau pour l'affichage
$tableau_immobilisations = [];
foreach ($categories as $code => $cat) {
    $data = isset($immobilisations_data[$code]) ? $immobilisations_data[$code] : [
        'brut' => 0, 'amort' => 0, 'net' => 0, 'libelle' => $cat['libelle'], 'is_title' => $cat['is_title']
    ];
    $tableau_immobilisations[] = [
        'code' => $code,
        'libelle' => $cat['libelle'],
        'brut' => $data['brut'],
        'amort' => $data['amort'],
        'net' => $data['net'],
        'is_title' => $cat['is_title']
    ];
}

// ============================================================
// CLASSE FPDF
// ============================================================
if ($format === 'pdf') {
    // Recherche du fichier FPDF
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

    // Tableau des immobilisations
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
        $style = $item['is_title'] ? 'subtotal' : '';
        $pdf->TableRow($cols, [
            $item['code'],
            $item['libelle'],
            PDF_DIMF::montant($item['brut']),
            PDF_DIMF::montant($item['amort']),
            PDF_DIMF::montant($item['net'])
        ], $style);
    }
    
    // Immobilisations par réalisation de garantie
    $pdf->TableRow($cols, ['Z03', 'Immobilisations acquises par réalisation de garantie', 
        PDF_DIMF::montant($immobilisations_garantie), '0', PDF_DIMF::montant($immobilisations_garantie)]);
    
    $total_brut_final = $total_brut + $immobilisations_garantie;
    $total_net_final = $total_net + $immobilisations_garantie;
    
    $pdf->TableRow($cols, ['', 'TOTAL', 
        PDF_DIMF::montant($total_brut_final), 
        PDF_DIMF::montant($total_amort), 
        PDF_DIMF::montant($total_net_final)], 'total');
    
    $pdf->Ln(8);
    
    // Amortissements de l'exercice
    $pdf->SectionTitle('AMORTISSEMENTS DE L\'EXERCICE');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(80, 6, 'Dotations aux amortissements de l\'exercice :', 0, 0);
    $pdf->Cell(0, 6, PDF_DIMF::montant($amortissements_exercice), 0, 1);
    $pdf->Cell(80, 6, "Taux d'amortissement global :", 0, 0);
    $pdf->Cell(0, 6, number_format($taux_amortissement, 2) . '%', 0, 1);
    
    $pdf->Output('I', 'DIMF_2015_' . $exercice . '.pdf');
    exit;
}
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
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 12px 16px; background: #f8fafc; font-weight: 600; color: #1e293b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
        .text-right { text-align: right; font-family: 'Courier New', monospace; font-weight: 500; }
        .subtotal-row { background: #f8fafc; font-weight: 600; }
        .total-row { background: #f0fdf4; font-weight: 700; border-top: 2px solid #bbf7d0; }
        .indent { padding-left: 30px; }
        
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            th, td { padding: 8px 12px; font-size: 0.75rem; }
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
            <h1><i class="fas fa-building"></i> DIMF_2015 - ÉTAT DES VALEURS IMMOBILISÉES</h1>
            <div class="subtitle">République de Côte d'Ivoire / Ministère de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Immobilisations</div>
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

    <!-- Note d'information -->
    <div class="card">
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div><strong>Note :</strong> Cet état présente l'ensemble des immobilisations de l'institution, avec leur valeur brute, les amortissements et provisions constitués, et la valeur nette comptable.</div>
            </div>
        </div>
    </div>

    <!-- Tableau principal des immobilisations -->
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
                            <tr <?= $item['is_title'] ? 'class="subtotal-row"' : '' ?>>
                                <td><?= $item['code'] ?></td>
                                <td <?= !$item['is_title'] ? 'class="indent"' : '' ?>><?= htmlspecialchars($item['libelle']) ?></td>
                                <td class="text-right"><?= number_format($item['brut'], 0, ',', ' ') ?></td>
                                <td class="text-right"><?= number_format($item['amort'], 0, ',', ' ') ?></td>
                                <td class="text-right"><?= number_format($item['net'], 0, ',', ' ') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td>Z03</td>
                            <td class="indent">Immobilisations acquises par réalisation de garantie</td>
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
    <?php if(!empty($immobilisations_data) && isset($immobilisations_data['D30']['details'])): ?>
    <div class="card">
        <div class="card-header"><i class="fas fa-list-ul"></i> DÉTAIL DES IMMOBILISATIONS</div>
        <div class="card-body">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>Type</th><th>Libellé</th><th class="text-right">Valeur brute (FCFA)</th><th class="text-right">Amortissements (FCFA)</th><th class="text-right">Valeur nette (FCFA)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($immobilisations_data as $type => $data): ?>
                            <?php if (isset($data['details']) && !empty($data['details'])): ?>
                                <tr class="subtotal-row">
                                    <td colspan="5"><strong><?= htmlspecialchars($type) ?></strong></td>
                                </tr>
                                <?php foreach ($data['details'] as $detail): ?>
                                    <tr>
                                        <td></td>
                                        <td><?= htmlspecialchars($detail['libelle']) ?></td>
                                        <td class="text-right"><?= number_format($detail['brut'], 0, ',', ' ') ?></td>
                                        <td class="text-right"><?= number_format($detail['amort'], 0, ',', ' ') ?></td>
                                        <td class="text-right"><?= number_format($detail['net'], 0, ',', ' ') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td colspan="2"><strong>Sous-total <?= htmlspecialchars($type) ?></strong></td>
                                    <td class="text-right"><strong><?= number_format($data['brut'], 0, ',', ' ') ?></strong></td>
                                    <td class="text-right"><strong><?= number_format($data['amort'], 0, ',', ' ') ?></strong></td>
                                    <td class="text-right"><strong><?= number_format($data['net'], 0, ',', ' ') ?></strong></td>
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
                    <strong>Dotations aux amortissements de l'exercice :</strong> <?= number_format($amortissements_exercice, 0, ',', ' ') ?> FCFA<br>
                    <strong>Taux d'amortissement global :</strong> <?= number_format($taux_amortissement, 2) ?>%
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
        let url = 'DIMF_2015.php?exercice=' + exercice + '&type_periode=' + type;
        
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        
        window.location.href = url;
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        
        // Données pour l'onglet IMMOBILISATIONS
        let dataImmos = [
            ['DIMF_2015 - ETAT DES VALEURS IMMOBILISEES'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['CODE', 'LIBELLES', 'Montant brut (FCFA)', 'Amortissements/Provisions (FCFA)', 'Montants nets (FCFA)']
        ];
        
        <?php foreach ($tableau_immobilisations as $item): ?>
        dataImmos.push([
            '<?= $item['code'] ?>',
            '<?= addslashes($item['libelle']) ?>',
            <?= $item['brut'] ?>,
            <?= $item['amort'] ?>,
            <?= $item['net'] ?>
        ]);
        <?php endforeach; ?>
        
        dataImmos.push(['Z03', 'Immobilisations acquises par réalisation de garantie', <?= $immobilisations_garantie ?>, 0, <?= $immobilisations_garantie ?>]);
        dataImmos.push(['TOTAL', 'TOTAL', <?= $total_brut + $immobilisations_garantie ?>, <?= $total_amort ?>, <?= $total_net + $immobilisations_garantie ?>]);
        
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(dataImmos), "IMMOBILISATIONS");
        
        // Données pour l'onglet AMORTISSEMENTS
        let dataAmort = [
            ['DIMF_2015 - AMORTISSEMENTS'],
            [],
            ['INDICATEUR', 'VALEUR'],
            ['Dotations aux amortissements de l\'exercice', <?= $amortissements_exercice ?>],
            ['Taux d\'amortissement global', <?= $taux_amortissement ?>]
        ];
        
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(dataAmort), "AMORTISSEMENTS");
        
        XLSX.writeFile(wb, 'DIMF_2015_<?= $exercice ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>