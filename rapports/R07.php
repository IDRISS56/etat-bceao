<?php
// R07.php - Constitution de la réserve générale
// Norme BCEAO: ≥ 15% du bénéfice distribuable
// Version avec POST et Bootstrap 5

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once('../databases/database.php');
require_once('../fpdf/fpdf.php');

// ------------------------- LECTURE DES PARAMÈTRES -------------------------
$exercice = isset($_POST['exercice']) ? (int)$_POST['exercice'] : date('Y');
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode'] : 'annuel';
$mois = isset($_POST['mois']) ? (int)$_POST['mois'] : 12;
$trimestre = isset($_POST['trimestre']) ? (int)$_POST['trimestre'] : 4;
$semestre = isset($_POST['semestre']) ? (int)$_POST['semestre'] : 2;

switch ($type_periode) {
    case 'mensuel': break;
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre': $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel': $mois = 12; break;
    default: $mois = 12;
}
$date_fin_periode = date('Y-m-t', strtotime("$exercice-" . str_pad($mois, 2, '0', STR_PAD_LEFT) . "-01"));
$date_debut_exercice = "$exercice-01-01";
$date_fin_exercice = "$exercice-12-31";

// ------------------------- CALCUL DE LA BASE -------------------------
$resultatExercice = 0;
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
    $stmt->execute([':date_debut' => $date_debut_exercice, ':date_fin' => $date_fin_exercice]);
    $res = $stmt->fetch();
    $resultatBrut = $res['produits'] - $res['charges'];
    if ($resultatBrut > 0) {
        $resultatExercice = (float)$resultatBrut;
    }
} catch (PDOException $e) { $resultatExercice = 0; }

$reportNegatif = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN (e.montant_credit - e.montant_debit) < 0 THEN ABS(e.montant_credit - e.montant_debit) ELSE 0 END), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '11%' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $reportNegatif = (float)$stmt->fetch()['solde'];
} catch (PDOException $e) { $reportNegatif = 0; }

$base = $resultatExercice - $reportNegatif;
if ($base < 0) $base = 0;
$montantMinimal = $base * 0.15;

// ------------------------- DOTATION -------------------------
$dotation = 0;
$detailsDotation = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total_dotation
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '106%' AND e.montant_credit > 0
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmt->execute([':date_debut' => $date_debut_exercice, ':date_fin' => $date_fin_exercice]);
    $dotation = (float)$stmt->fetch()['total_dotation'];

    $stmt = $pdo->prepare("
        SELECT e.date_ecriture, e.numero_piece, e.libelle_ecriture, e.montant_credit as montant
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '106%' AND e.montant_credit > 0
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
        ORDER BY e.date_ecriture DESC
    ");
    $stmt->execute([':date_debut' => $date_debut_exercice, ':date_fin' => $date_fin_exercice]);
    $detailsDotation = $stmt->fetchAll();
} catch (PDOException $e) { $dotation = 0; }

if ($dotation == 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(montant), 0) as total_reserve
            FROM capital
            WHERE statut = 'valide' AND libelle LIKE '%réserve%' AND YEAR(date_creation) = :exercice
        ");
        $stmt->execute([':exercice' => $exercice]);
        $dotation = (float)$stmt->fetch()['total_reserve'];
    } catch (PDOException $e) { $dotation = 0; }
}

$soldeReserve = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde_reserve
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '106%' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $soldeReserve = (float)$stmt->fetch()['solde_reserve'];
} catch (PDOException $e) { $soldeReserve = 0; }

// ------------------------- RATIO -------------------------
if ($base <= 0) {
    $ratioR07 = 0;
    $pourcentage = 0;
    $conformite = 'N/A - Pas de bénéfice distribuable';
} else {
    $ratioR07 = $dotation / $base;
    $pourcentage = $ratioR07 * 100;
    $conformite = ($ratioR07 >= 0.15) ? 'CONFORME' : 'NON_CONFORME';
}

// ------------------------- PRÉPARATION DES TABLEAUX -------------------------
$lignesBase = [
    ['code'=>'L80','lib'=>'Résultat excédentaire de l\'exercice (bénéfice)','montant'=>$resultatExercice],
    ['code'=>'L70','lib'=>'Report à nouveau déficitaire','montant'=>$reportNegatif],
];
$lignesDotation = [
    ['code'=>'106','lib'=>'Dotation annuelle de la réserve générale','montant'=>$dotation],
];

// ------------------------- EXPORT PDF -------------------------
if (isset($_POST['export']) && $_POST['export'] === 'pdf') {
    if (ob_get_length()) ob_clean();

    class PDF_DIMF extends FPDF {
        public $codeDimf  = 'R07';
        public $titreDimf = 'CONSTITUTION DE LA RESERVE GENERALE';
        public $nomSfd    = 'SFD';
        public $periode   = '';
        public $exercice  = '';

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
            $this->Cell(0, 5, self::u(
                'SFD : ' . $this->nomSfd .
                '   |   Période : ' . $this->periode .
                '   |   Exercice : ' . $this->exercice .
                '   |   Arrêté au : ' . date('d/m/Y', strtotime($GLOBALS['date_fin_periode']))
            ), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }

        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, self::u(
                'SICS-BCEAO  •  Généré le ' . date('d/m/Y H:i:s') .
                '  •  Page ' . $this->PageNo() . '/{nb}'),
                0, 0, 'C');
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
            $this->SetLineWidth(0.2);
            foreach ($cols as $col) {
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 6, self::u($col['label']), 1, 0, $align, true);
            }
            $this->Ln();
        }

        function TableRow($cols, $data, $style = '') {
            switch ($style) {
                case 'subtotal':
                    $this->SetFillColor(248, 250, 252);
                    $this->SetFont('Arial', 'B', 8);
                    $fill = true; break;
                case 'total':
                    $this->SetFillColor(240, 253, 244);
                    $this->SetFont('Arial', 'B', 8.5);
                    $fill = true; break;
                default:
                    $this->SetFillColor(255, 255, 255);
                    $this->SetFont('Arial', '', 7.5);
                    $fill = false; break;
            }
            $this->SetTextColor(15, 23, 42);
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.1);
            foreach ($cols as $i => $col) {
                $val   = isset($data[$i]) ? $data[$i] : '';
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 5.5, self::u($val), 1, 0, $align, $fill);
            }
            $this->Ln();
        }

        static function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }

    $pdf = new PDF_DIMF();
    $pdf->AliasNbPages();
    $pdf->codeDimf  = 'R07';
    $pdf->titreDimf = 'CONSTITUTION DE LA RESERVE GENERALE';
    $pdf->nomSfd    = 'SFD';
    $pdf->periode   = ucfirst($type_periode);
    $pdf->exercice  = $exercice;
    $pdf->AddPage();

    $cols = [
        ['w' => 30, 'label' => 'Code', 'align' => 'L'],
        ['w' => 100, 'label' => 'Libellé', 'align' => 'L'],
        ['w' => 50, 'label' => 'Montant (FCFA)', 'align' => 'R']
    ];

    // Section A
    $pdf->SectionTitle("A - BASE DE CALCUL (RESULTAT DE L'EXERCICE)");
    $pdf->TableHeader($cols);
    foreach ($lignesBase as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['lib'], PDF_DIMF::montant($row['montant'])]);
    }
    $pdf->TableRow($cols, ['', 'BASE = Bénéfice - Report déficitaire', PDF_DIMF::montant($base)], 'total');

    $pdf->Ln(5);

    // Section B
    $pdf->SectionTitle("B - DOTATION ANNUELLE DE LA RESERVE GENERALE");
    $pdf->TableHeader($cols);
    foreach ($lignesDotation as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['lib'], PDF_DIMF::montant($row['montant'])]);
    }
    $pdf->TableRow($cols, ['', 'TOTAL DOTATION', PDF_DIMF::montant($dotation)], 'total');

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, PDF_DIMF::u("RATIO R07 = Dotation / Base = " . number_format($pourcentage, 2) . "%"), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, PDF_DIMF::u("Norme BCEAO : Dotation ≥ 15% du bénéfice distribuable\nConformité : " . $conformite));

    $pdf->Output('I', 'R07_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ------------------------- EXPORT EXCEL -------------------------
if (isset($_POST['export']) && $_POST['export'] === 'excel') {
    if (ob_get_length()) ob_clean();

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="R07_' . $exercice . '_' . $type_periode . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<html><head><meta charset="UTF-8"><style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2 { color: #1a3a5c; font-size: 16pt; }
        h3 { color: #1a3a5c; font-size: 14pt; margin-top: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; font-size: 10pt; }
        th, td { border: 1px solid #999; padding: 8px; vertical-align: top; }
        th { background: #f2f2f2; text-align: center; font-weight: bold; }
        .text-right { text-align: right; }
        .total-row { background: #e8f5e9; font-weight: bold; }
        .col-code { width: 15%; }
        .col-libelle { width: 70%; }
        .col-montant { width: 15%; }
    </style></head><body>';
    echo '<h2>R07 - CONSTITUTION DE LA RESERVE GENERALE</h2>';
    echo '<p><strong>Période :</strong> ' . $exercice . ' - ' . ucfirst($type_periode) . ' (arrêtée au ' . date('d/m/Y', strtotime($date_fin_periode)) . ')</p>';

    // Tableau A
    echo '<h3>A - BASE DE CALCUL (Résultat de l\'exercice)</h3>';
    echo '<table>';
    echo '<tr><th class="col-code">Code</th><th class="col-libelle">Libellé</th><th class="col-montant text-right">Montant (FCFA)</th></tr>';
    foreach ($lignesBase as $r) {
        echo '<tr>';
        echo '<td class="col-code">' . $r['code'] . '</td>';
        echo '<td class="col-libelle">' . $r['lib'] . '</td>';
        echo '<td class="col-montant text-right">' . number_format($r['montant'], 0, ',', ' ') . '</td>';
        echo '</tr>';
    }
    echo '<tr class="total-row">';
    echo '<td colspan="2">BASE = Bénéfice - Report déficitaire</td>';
    echo '<td class="text-right">' . number_format($base, 0, ',', ' ') . '</td>';
    echo '</tr>';
    echo '</table>';

    // Tableau B
    echo '<h3>B - DOTATION ANNUELLE DE LA RESERVE GENERALE</h3>';
    echo '<table>';
    echo '<tr><th class="col-code">Code</th><th class="col-libelle">Libellé</th><th class="col-montant text-right">Montant (FCFA)</th></tr>';
    foreach ($lignesDotation as $r) {
        echo '<tr>';
        echo '<td class="col-code">' . $r['code'] . '</td>';
        echo '<td class="col-libelle">' . $r['lib'] . '</td>';
        echo '<td class="col-montant text-right">' . number_format($r['montant'], 0, ',', ' ') . '</td>';
        echo '</tr>';
    }
    echo '<tr class="total-row">';
    echo '<td colspan="2">TOTAL DOTATION</td>';
    echo '<td class="text-right">' . number_format($dotation, 0, ',', ' ') . '</td>';
    echo '</tr>';
    echo '</table>';

    echo '<p><strong>RATIO R07 = Dotation / Base = ' . number_format($pourcentage, 2) . '%</strong></p>';
    echo '<p>Norme BCEAO : Dotation ≥ 15% du bénéfice distribuable<br>Conformité : ' . $conformite . '</p>';
    echo '</body></html>';
    exit;
}

// ------------------------- AFFICHAGE WEB -------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>R07 - Constitution de la réserve générale (BCEAO)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .btn-excel, .btn-pdf { padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; }
        .btn-excel { background:#10b981; color:white; }
        .btn-pdf { background:#ef4444; color:white; }
        .card { background:white; border-radius:20px; padding:20px 24px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
        .card-header { display:flex; align-items:center; gap:10px; border-bottom:1px solid #eef2f6; padding-bottom:12px; margin-bottom:16px; font-weight:600; color:#1e40af; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select, .filter-item input { padding:8px 14px; border:1px solid #d1d5db; border-radius:12px; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .ratio-card { background:linear-gradient(145deg,#f8fafc,#fff); border-radius:20px; padding:24px; margin-bottom:24px; border:1px solid #e2e8f0; }
        .ratio-value { font-size:3rem; font-weight:800; }
        .ratio-value.conforme { color:#10b981; }
        .ratio-value.non-conforme { color:#ef4444; }
        .ratio-value.na { color:#6b7280; }
        .norme-box { background:#f1f5f9; border-radius:16px; padding:12px 20px; text-align:center; }
        .progress-bar { background:#e2e8f0; border-radius:50px; height:24px; overflow:hidden; margin-top:20px; }
        .progress-fill { background:linear-gradient(90deg,#3b82f6,#60a5fa); height:100%; border-radius:50px; text-align:center; color:white; font-size:0.75rem; line-height:24px; }
        .progress-fill.non-conforme { background:linear-gradient(90deg,#ef4444,#f97316); }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px 16px; text-align:left; border-bottom:1px solid #f1f5f9; }
        th { background:#f8fafc; font-weight:600; }
        .text-right { text-align:right; }
        .total-row { background:#f0fdf4; font-weight:700; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .two-columns { display:flex; gap:24px; flex-wrap:wrap; }
        .two-columns .card { flex:1; min-width:320px; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .filters-row, #filtersCard { display:none; } }
        .col-code { width: 15%; }
        .col-libelle { width: 70%; }
        .col-montant { width: 15%; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-piggy-bank"></i> R07 - CONSTITUTION DE LA RÉSERVE GÉNÉRALE</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">Norme BCEAO : Dotation ≥ 15% du bénéfice distribuable</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="submitExport('excel')"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" onclick="submitExport('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Formulaire de filtres -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres de période</div>
        <form method="post" id="filterForm">
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
                <div class="filter-item" id="dynamicSelectContainer"></div>
                <button type="submit" class="btn-apply">Appliquer</button>
            </div>
        </form>
    </div>

    <!-- Carte ratio -->
    <div class="ratio-card">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:20px;">
            <div>
                <div class="card-header" style="padding:0;">Ratio R07 – Taux de dotation</div>
                <div class="ratio-value <?=($base>0)?($pourcentage>=15?'conforme':'non-conforme'):'na'?>">
                    <?=($base>0)?number_format($pourcentage,2).'%':'N/A'?>
                </div>
                <div>Dotation / Base</div>
            </div>
            <div class="norme-box">
                <div><strong>Norme BCEAO</strong></div>
                <div style="font-size:1.5rem;">≥ 15%</div>
                <div>Seuil minimal : 15%</div>
            </div>
            <div>
                <span class="badge" style="background:<?=($base>0)?($pourcentage>=15?'#10b981':'#ef4444'):'#6b7280'?>;">
                    <?=$conformite?>
                </span>
            </div>
        </div>
        <?php if($base>0): ?>
        <div class="progress-bar">
            <div class="progress-fill <?=($pourcentage<15?'non-conforme':'')?>" style="width:<?=min($pourcentage,100)?>%;">
                <?=number_format($pourcentage,1)?>%
            </div>
        </div>
        <div style="margin-top:16px;">
            <i class="fas fa-calculator"></i> R07 = <?=number_format($dotation,0,',',' ')?> / <?=number_format($base,0,',',' ')?> = <?=number_format($pourcentage,2)?>%
            <?php if($pourcentage<15): ?>
                <span class="text-danger">(Manque <?=number_format($montantMinimal - $dotation,0,',',' ')?> FCFA)</span>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="info-box" style="margin-top:16px; background:#fefce8;">
            <i class="fas fa-info-circle"></i> Aucun bénéfice distribuable pour l'exercice <?=$exercice?>.
        </div>
        <?php endif; ?>
    </div>

    <!-- Deux colonnes -->
    <div class="two-columns">
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-line"></i> A – BASE DE CALCUL</div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th class="col-code">Code</th><th class="col-libelle">Libellé</th><th class="col-montant text-right">Montant</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($lignesBase as $r): ?>
                        <tr><td class="col-code"><?=$r['code']?></td><td class="col-libelle"><?=$r['lib']?></td><td class="col-montant text-right"><?=number_format($r['montant'],0,',',' ')?></td></tr>
                        <?php endforeach; ?>
                        <tr class="total-row"><td colspan="2">BASE = Bénéfice - Report déficitaire</td><td class="text-right"><?=number_format($base,0,',',' ')?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="fas fa-coins"></i> B – DOTATION À LA RÉSERVE</div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th class="col-code">Code</th><th class="col-libelle">Libellé</th><th class="col-montant text-right">Montant</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($lignesDotation as $r): ?>
                        <tr><td class="col-code"><?=$r['code']?></td><td class="col-libelle"><?=$r['lib']?></td><td class="col-montant text-right"><?=number_format($r['montant'],0,',',' ')?></td></tr>
                        <?php endforeach; ?>
                        <tr class="total-row"><td colspan="2">TOTAL DOTATION</td><td class="text-right"><?=number_format($dotation,0,',',' ')?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if(!empty($detailsDotation)): ?>
    <div class="card">
        <div class="card-header"><i class="fas fa-list-alt"></i> Détail des écritures de dotation</div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Date</th><th>N° pièce</th><th>Libellé</th><th class="text-right">Montant</th></tr></thead>
                <tbody>
                    <?php foreach($detailsDotation as $d): ?>
                    <tr>
                        <td><?=date('d/m/Y', strtotime($d['date_ecriture']))?></td>
                        <td><?=htmlspecialchars($d['numero_piece']??'-')?></td>
                        <td><?=htmlspecialchars($d['libelle_ecriture'])?></td>
                        <td class="text-right"><?=number_format($d['montant'],0,',',' ')?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Interprétation -->
    <div class="card">
        <div class="card-header">Interprétation</div>
        <div class="info-box">
            <i class="fas fa-gavel"></i>
            <div>
                <?php if($base<=0): ?>
                    ℹ️ Aucun bénéfice distribuable. La constitution de la réserve n'est pas exigée.
                <?php elseif($pourcentage>=15): ?>
                    ✓ Conforme – La dotation annuelle (<?=number_format($pourcentage,2)?>%) atteint au moins 15% du bénéfice distribuable.
                <?php else: ?>
                    ⚠️ Non conforme – La dotation annuelle (<?=number_format($pourcentage,2)?>%) est inférieure au minimum requis de 15%. Il manque <?=number_format($montantMinimal - $dotation,0,',',' ')?> FCFA.
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="page-footer"><i class="fas fa-calendar-alt"></i> Généré le <?=date('d/m/Y à H:i:s')?> – Période <?=$exercice?> (<?=ucfirst($type_periode)?>) arrêtée au <?=date('d/m/Y',strtotime($date_fin_periode))?></div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const currentMois = <?=$mois?>;
        const currentTrimestre = <?=$trimestre?>;
        const currentSemestre = <?=json_encode($semestre)?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois" id="moisSelect" class="form-select">';
            for (let m = 1; m <= 12; m++) {
                let selected = (m === currentMois) ? 'selected' : '';
                html += `<option value="${m}" ${selected}>${String(m).padStart(2,'0')} - ${new Date(2000,m-1,1).toLocaleString('fr',{month:'long'})}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect" class="form-select">';
            for (let t = 1; t <= 4; t++) {
                let selected = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${selected}>${t}${t===1?'er':'ème'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect" class="form-select">';
            for (let s = 1; s <= 2; s++) {
                let selected = (s === currentSemestre) ? 'selected' : '';
                html += `<option value="${s}" ${selected}>${s}${s===1?'er':'e'} semestre</option>`;
            }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" class="form-control" disabled value="Année complète">';
        }
        container.innerHTML = html;
    }

    function submitExport(type) {
        const form = document.getElementById('filterForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'export';
        input.value = type;
        form.appendChild(input);
        form.submit();
        form.removeChild(input);
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>