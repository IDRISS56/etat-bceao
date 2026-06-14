<?php
// 13-MouvementsActifs.php - Acquisitions et cessions d'actifs
// Suivi des immobilisations (acquisitions, cessions, amortissements)

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
// PARAMÈTRES
// ============================================================
$exercice     = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$type_periode = isset($_GET['type_periode']) ? $_GET['type_periode'] : 'annuel';
$mois         = isset($_GET['mois']) ? (int)$_GET['mois'] : 12;
$trimestre    = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4;
$semestre     = isset($_GET['semestre']) ? (int)$_GET['semestre'] : 2;
$format       = isset($_GET['format']) ? $_GET['format'] : 'html';

// Calcul de la période
switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
    default:          $mois = isset($_GET['mois']) ? (int)$_GET['mois'] : 12;
}

$date_debut_exercice = $exercice . '-01-01';
$date_fin_exercice = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));

// Libellé période
switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Annee ' . $exercice;
}

// ============================================================
// CATÉGORIES D'IMMOBILISATIONS
// ============================================================
$categories = [
    'brevets_logiciels' => ['libelle' => 'Brevets, licences, logiciels et droits similaires', 'comptes' => ['205', '206', '207']],
    'recherche_developpement' => ['libelle' => 'Recherche et développement', 'comptes' => ['203']],
    'terrains' => ['libelle' => 'Terrains', 'comptes' => ['211']],
    'batiments' => ['libelle' => 'Bâtiments', 'comptes' => ['212', '213']],
    'installations_agencements' => ['libelle' => 'Installations et agencements', 'comptes' => ['215', '2184']],
    'mobilier_bureau' => ['libelle' => 'Mobilier de bureau', 'comptes' => ['2181', '2183']],
    'materiel_informatique' => ['libelle' => 'Matériel informatique', 'comptes' => ['2182']],
    'materiel_transport' => ['libelle' => 'Matériel de transport', 'comptes' => ['2185']],
    'autres_materiels' => ['libelle' => 'Autres matériels', 'comptes' => ['2186', '2187', '2188']]
];

// ============================================================
// RÉCUPÉRATION DES DONNÉES
// ============================================================
$data = [];
foreach ($categories as $key => $category) {
    $data[$key] = [
        'libelle' => $category['libelle'],
        'montant_ouverture' => 0,
        'acquisitions' => 0,
        'cessions' => 0,
        'montant_cloture' => 0
    ];
    
    foreach ($category['comptes'] as $compte) {
        // Solde à l'ouverture
        $stmtOuverture = $pdo->prepare("
            SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as solde
            FROM ecritures_comptables e
            WHERE e.compte_general LIKE :compte AND e.date_ecriture < :date_debut
        ");
        $stmtOuverture->execute([':compte' => $compte . '%', ':date_debut' => $date_debut_exercice]);
        $data[$key]['montant_ouverture'] += (float)$stmtOuverture->fetch()['solde'];
        
        // Acquisitions
        $stmtAcquisitions = $pdo->prepare("
            SELECT COALESCE(SUM(e.montant_debit), 0) as total
            FROM ecritures_comptables e
            WHERE e.compte_general LIKE :compte AND e.date_ecriture BETWEEN :date_debut AND :date_fin AND e.montant_debit > 0
        ");
        $stmtAcquisitions->execute([':compte' => $compte . '%', ':date_debut' => $date_debut_exercice, ':date_fin' => $date_fin_exercice]);
        $data[$key]['acquisitions'] += (float)$stmtAcquisitions->fetch()['total'];
        
        // Cessions
        $stmtCessions = $pdo->prepare("
            SELECT COALESCE(SUM(e.montant_credit), 0) as total
            FROM ecritures_comptables e
            WHERE e.compte_general LIKE :compte AND e.date_ecriture BETWEEN :date_debut AND :date_fin AND e.montant_credit > 0
        ");
        $stmtCessions->execute([':compte' => $compte . '%', ':date_debut' => $date_debut_exercice, ':date_fin' => $date_fin_exercice]);
        $data[$key]['cessions'] += (float)$stmtCessions->fetch()['total'];
    }
    
    $data[$key]['montant_cloture'] = $data[$key]['montant_ouverture'] + $data[$key]['acquisitions'] - $data[$key]['cessions'];
}

// Totaux
$total_ouverture = array_sum(array_column($data, 'montant_ouverture'));
$total_acquisitions = array_sum(array_column($data, 'acquisitions'));
$total_cessions = array_sum(array_column($data, 'cessions'));
$total_cloture = array_sum(array_column($data, 'montant_cloture'));

// Détails acquisitions
$detailsAcquisitions = [];
try {
    $stmtDetails = $pdo->prepare("
        SELECT e.date_ecriture, e.numero_piece, e.libelle_ecriture, e.compte_general, pc.libelle_compte, e.montant_debit as montant
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE (e.compte_general LIKE '21%' OR e.compte_general LIKE '22%' OR e.compte_general LIKE '23%')
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin AND e.montant_debit > 0
        ORDER BY e.date_ecriture DESC LIMIT 50
    ");
    $stmtDetails->execute([':date_debut' => $date_debut_exercice, ':date_fin' => $date_fin_exercice]);
    $detailsAcquisitions = $stmtDetails->fetchAll();
} catch (PDOException $e) { }

// Détails cessions
$detailsCessions = [];
try {
    $stmtCessionsDetails = $pdo->prepare("
        SELECT e.date_ecriture, e.numero_piece, e.libelle_ecriture, e.compte_general, pc.libelle_compte, e.montant_credit as montant
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE (e.compte_general LIKE '21%' OR e.compte_general LIKE '22%' OR e.compte_general LIKE '23%')
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin AND e.montant_credit > 0
        ORDER BY e.date_ecriture DESC LIMIT 50
    ");
    $stmtCessionsDetails->execute([':date_debut' => $date_debut_exercice, ':date_fin' => $date_fin_exercice]);
    $detailsCessions = $stmtCessionsDetails->fetchAll();
} catch (PDOException $e) { }

// Amortissements
$total_amortissements_exercice = 0;
try {
    $stmtAmort = $pdo->prepare("SELECT COALESCE(SUM(dotation_mois), 0) as total_amort FROM amortissements WHERE exercice = :exercice");
    $stmtAmort->execute([':exercice' => $exercice]);
    $total_amortissements_exercice = (float)$stmtAmort->fetch()['total_amort'];
} catch (PDOException $e) { }

$valeur_nette_totale = $total_cloture - $total_amortissements_exercice;

// ============================================================
// FONCTION FORMATAGE
// ============================================================
function format_montant($val) {
    return number_format((float)$val, 0, ',', ' ') . ' F';
}

// ============================================================
// EXPORT PDF
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
            $this->Cell(0, 7, $this->convert('13 - ACQUISITIONS ET CESSIONS D\'ACTIFS'), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, $this->convert('Formation brute du capital fixe'), 0, 1, 'L');
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
        
        function TableRow($cols, $data, $style = '') {
            if ($style == 'total') {
                $this->SetFont('Arial', 'B', 8);
                $this->SetFillColor(240, 253, 244);
                $fill = true;
            } else {
                $this->SetFont('Arial', '', 8);
                $fill = false;
            }
            foreach ($cols as $i => $col) {
                $val = $data[$i] ?? '';
                $this->Cell($col['w'], 6, $this->convert($val), 1, 0, $col['align'] ?? 'L', $fill);
            }
            $this->Ln();
        }
        
        function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }
    
    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(10, 35, 10);
    $pdf->AddPage();
    
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, $pdf->convert('Periode : ' . $lib_periode), 0, 1, 'C');
    $pdf->Ln(5);
    
    // Tableau principal
    $cols = [
        ['label' => 'CATEGORIE D\'IMMOBILISATION', 'w' => 80, 'align' => 'L'],
        ['label' => 'Montant ouverture', 'w' => 45, 'align' => 'R'],
        ['label' => 'Acquisitions', 'w' => 45, 'align' => 'R'],
        ['label' => 'Cessions', 'w' => 45, 'align' => 'R'],
        ['label' => 'Montant cloture', 'w' => 45, 'align' => 'R']
    ];
    
    $pdf->SectionTitle('FORMATION BRUTE DU CAPITAL FIXE (En FCFA)');
    $pdf->TableHeader($cols);
    
    foreach ($data as $item) {
        $pdf->TableRow($cols, [
            $item['libelle'],
            $pdf->montant($item['montant_ouverture']),
            $pdf->montant($item['acquisitions']),
            $pdf->montant($item['cessions']),
            $pdf->montant($item['montant_cloture'])
        ]);
    }
    
    $pdf->TableRow($cols, [
        'TOTAL',
        $pdf->montant($total_ouverture),
        $pdf->montant($total_acquisitions),
        $pdf->montant($total_cessions),
        $pdf->montant($total_cloture)
    ], 'total');
    
    $pdf->Ln(8);
    
    // Amortissements
    $pdf->SectionTitle('AMORTISSEMENTS DE L\'EXERCICE');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(80, 7, 'Amortissements cumules de l\'exercice :', 0, 0);
    $pdf->Cell(0, 7, $pdf->montant($total_amortissements_exercice), 0, 1);
    $pdf->Cell(80, 7, 'Valeur brute des immobilisations (cloture) :', 0, 0);
    $pdf->Cell(0, 7, $pdf->montant($total_cloture), 0, 1);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(80, 7, 'Valeur nette des immobilisations (cloture) :', 0, 0);
    $pdf->Cell(0, 7, $pdf->montant($valeur_nette_totale), 0, 1);
    
    $pdf->Output('I', '13_MOUVEMENTS_ACTIFS_' . $exercice . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>13 - Acquisitions et cessions d'actifs</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; padding: 24px; }
        .dashboard { max-width: 1300px; margin: 0 auto; }
        
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
        .total-row { background: #f0fdf4; font-weight: 700; border-top: 2px solid #bbf7d0; }
        .acquisition { color: #16a34a; }
        .cession { color: #dc2626; }
        
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .alert { padding: 14px 20px; border-radius: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        
        .stats-row { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px; }
        .stat-card { flex: 1; background: #f8fafc; border-radius: 16px; padding: 20px; text-align: center; border-top: 3px solid #3b82f6; }
        .stat-card .value { font-size: 1.8rem; font-weight: 700; color: #1e293b; }
        .stat-card .label { font-size: 0.8rem; color: #64748b; margin-top: 5px; }
        
        .progress-bar { background: #e2e8f0; border-radius: 10px; height: 30px; overflow: hidden; margin-top: 20px; }
        .progress-bar .progress-ouverture { background: #1e40af; height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.7rem; float: left; }
        .progress-bar .progress-acquisitions { background: #16a34a; height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.7rem; float: left; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            .stats-row { flex-direction: column; }
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
            <h1><i class="fas fa-building"></i> 13 - Acquisitions et cessions d'actifs</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">Formation brute du capital fixe - Exercice <?= $exercice ?></div>
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

    <!-- Filtres -->
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
                <i class="fas fa-info-circle"></i> Periode : <?= $lib_periode ?> (arrete au <?= date('d/m/Y', strtotime($date_fin_exercice)) ?>)
            </div>
        </div>
    </div>

    <!-- Tableau principal -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> FORMATION BRUTE DU CAPITAL FIXE (En FCFA)</div>
        <div class="card-body">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>CATEGORIE D'IMMOBILISATION</th>
                            <th class="text-right">Montant a l'ouverture</th>
                            <th class="text-right">Acquisitions / Apports</th>
                            <th class="text-right">Cessions / Scissions</th>
                            <th class="text-right">Montant a la cloture</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['libelle']) ?></td>
                            <td class="text-right"><?= number_format($item['montant_ouverture'], 0, ',', ' ') ?></td>
                            <td class="text-right acquisition"><?= number_format($item['acquisitions'], 0, ',', ' ') ?></td>
                            <td class="text-right cession"><?= number_format($item['cessions'], 0, ',', ' ') ?></td>
                            <td class="text-right"><?= number_format($item['montant_cloture'], 0, ',', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($total_ouverture, 0, ',', ' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_acquisitions, 0, ',', ' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_cessions, 0, ',', ' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_cloture, 0, ',', ' ') ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Amortissements -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-simple"></i> AMORTISSEMENTS DE L'EXERCICE</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-calculator"></i>
                <div>
                    <strong>Amortissements cumules de l'exercice :</strong> <?= number_format($total_amortissements_exercice, 0, ',', ' ') ?> FCFA<br>
                    <strong>Valeur brute des immobilisations (cloture) :</strong> <?= number_format($total_cloture, 0, ',', ' ') ?> FCFA<br>
                    <strong style="font-size:1.1rem;">Valeur nette des immobilisations (cloture) :</strong> <?= number_format($valeur_nette_totale, 0, ',', ' ') ?> FCFA
                </div>
            </div>
        </div>
    </div>

    <!-- Graphique evolution -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-bar"></i> EVOLUTION DES IMMOBILISATIONS</div>
        <div class="card-body">
            <div class="stats-row">
                <div class="stat-card">
                    <div class="value"><?= number_format($total_ouverture, 0, ',', ' ') ?> F</div>
                    <div class="label">Ouverture</div>
                </div>
                <div class="stat-card">
                    <div class="value" style="color:#16a34a;">+ <?= number_format($total_acquisitions, 0, ',', ' ') ?> F</div>
                    <div class="label">Acquisitions</div>
                </div>
                <div class="stat-card">
                    <div class="value" style="color:#dc2626;">- <?= number_format($total_cessions, 0, ',', ' ') ?> F</div>
                    <div class="label">Cessions</div>
                </div>
                <div class="stat-card">
                    <div class="value"><?= number_format($total_cloture, 0, ',', ' ') ?> F</div>
                    <div class="label">Cloture</div>
                </div>
            </div>
            
            <?php $max_value = max($total_ouverture, $total_cloture, 1); ?>
            <div class="progress-bar">
                <div class="progress-ouverture" style="width: <?= ($total_ouverture / $max_value) * 100 ?>%;">
                    Ouverture
                </div>
                <div class="progress-acquisitions" style="width: <?= (($total_cloture - $total_ouverture) / $max_value) * 100 ?>%;">
                    + Acquisitions
                </div>
            </div>
        </div>
    </div>

    <!-- Détail acquisitions -->
    <div class="card">
        <div class="card-header"><i class="fas fa-arrow-down"></i> DETAIL DES ACQUISITIONS DE L'EXERCICE</div>
        <div class="card-body">
            <div class="table-wrapper">
                <?php if(empty($detailsAcquisitions)): ?>
                    <div class="info-box">Aucune acquisition enregistree pour l'exercice <?= $exercice ?>.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr><th>Date</th><th>N° piece</th><th>Libelle</th><th>Compte</th><th class="text-right">Montant (FCFA)</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($detailsAcquisitions as $acq): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($acq['date_ecriture'])) ?></td>
                                <td><?= htmlspecialchars($acq['numero_piece'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($acq['libelle_ecriture']) ?></td>
                                <td><?= htmlspecialchars($acq['compte_general'] . ' - ' . substr($acq['libelle_compte'] ?? '', 0, 30)) ?></td>
                                <td class="text-right acquisition"><?= number_format($acq['montant'], 0, ',', ' ') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Détail cessions -->
    <div class="card">
        <div class="card-header"><i class="fas fa-arrow-up"></i> DETAIL DES CESSIONS DE L'EXERCICE</div>
        <div class="card-body">
            <div class="table-wrapper">
                <?php if(empty($detailsCessions)): ?>
                    <div class="info-box">Aucune cession enregistree pour l'exercice <?= $exercice ?>.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr><th>Date</th><th>N° piece</th><th>Libelle</th><th>Compte</th><th class="text-right">Montant (FCFA)</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($detailsCessions as $ces): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($ces['date_ecriture'])) ?></td>
                                <td><?= htmlspecialchars($ces['numero_piece'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($ces['libelle_ecriture']) ?></td>
                                <td><?= htmlspecialchars($ces['compte_general'] . ' - ' . substr($ces['libelle_compte'] ?? '', 0, 30)) ?></td>
                                <td class="text-right cession"><?= number_format($ces['montant'], 0, ',', ' ') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base Mandigo<br>
        Exercice : <?= $exercice ?> - Periode : <?= $lib_periode ?>
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
        let url = '13mouv.php?exercice=' + exercice + '&type_periode=' + type;
        
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        
        window.location.href = url;
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        
        let data = [
            ['13 - ACQUISITIONS ET CESSIONS D\'ACTIFS'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['CATEGORIE D\'IMMOBILISATION', 'Montant ouverture (FCFA)', 'Acquisitions (FCFA)', 'Cessions (FCFA)', 'Montant cloture (FCFA)']
        ];
        
        <?php foreach ($data as $item): ?>
        data.push([
            '<?= addslashes($item['libelle']) ?>',
            <?= $item['montant_ouverture'] ?>,
            <?= $item['acquisitions'] ?>,
            <?= $item['cessions'] ?>,
            <?= $item['montant_cloture'] ?>
        ]);
        <?php endforeach; ?>
        
        data.push(['TOTAL', <?= $total_ouverture ?>, <?= $total_acquisitions ?>, <?= $total_cessions ?>, <?= $total_cloture ?>]);
        data.push([]);
        data.push(['AMORTISSEMENTS', '']);
        data.push(['Amortissements cumules de l\'exercice', <?= $total_amortissements_exercice ?>]);
        data.push(['Valeur brute des immobilisations (cloture)', <?= $total_cloture ?>]);
        data.push(['Valeur nette des immobilisations (cloture)', <?= $valeur_nette_totale ?>]);
        
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), "MOUVEMENTS_ACTIFS");
        XLSX.writeFile(wb, '13_MOUVEMENTS_ACTIFS_<?= $exercice ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>