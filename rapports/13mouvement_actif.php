<?php
// 13-MouvementsActifs.php - Acquisitions et cessions d'actifs
// Version utilisant les tables existantes (ecritures_comptables, plan_comptables)

session_start();

// ============================================================
// CONFIGURATION BDD
// ============================================================
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ============================================================
// PARAMÈTRES (POST avec session)
// ============================================================
$exercice     = isset($_POST['exercice']) ? (int)$_POST['exercice'] : (isset($_SESSION['mouv_exercice']) ? $_SESSION['mouv_exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode'] : (isset($_SESSION['mouv_type_periode']) ? $_SESSION['mouv_type_periode'] : 'annuel');
$mois         = isset($_POST['mois']) ? (int)$_POST['mois'] : (isset($_SESSION['mouv_mois']) ? $_SESSION['mouv_mois'] : 12);
$trimestre    = isset($_POST['trimestre']) ? (int)$_POST['trimestre'] : (isset($_SESSION['mouv_trimestre']) ? $_SESSION['mouv_trimestre'] : 4);
$semestre     = isset($_POST['semestre']) ? (int)$_POST['semestre'] : (isset($_SESSION['mouv_semestre']) ? $_SESSION['mouv_semestre'] : 2);
$format       = isset($_POST['format']) ? $_POST['format'] : 'html';

// Sauvegarde en session
$_SESSION['mouv_exercice'] = $exercice;
$_SESSION['mouv_type_periode'] = $type_periode;
$_SESSION['mouv_mois'] = $mois;
$_SESSION['mouv_trimestre'] = $trimestre;
$_SESSION['mouv_semestre'] = $semestre;

// Calcul de la période
switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
    default:          $mois = isset($_POST['mois']) ? (int)$_POST['mois'] : 12;
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
// CATÉGORIES D'IMMOBILISATIONS (libellés exacts du fichier 13.xlsx)
// ============================================================
$categories = [
    'brevets_logiciels' => ['libelle' => 'Brevets, licences, logiciels et droits similaires', 'comptes' => ['205', '206', '207']],
    'recherche_developpement' => ['libelle' => 'Recherche et développement', 'comptes' => ['203']],
    'terrains' => ['libelle' => 'Terrains', 'comptes' => ['211']],
    'batiments' => ['libelle' => 'Bâtiment', 'comptes' => ['212', '213']],
    'installations_agencements' => ['libelle' => 'Installations et agencements', 'comptes' => ['215', '2184']],
    'mobilier_bureau' => ['libelle' => 'Mobilier de bureau', 'comptes' => ['2181', '2183']],
    'materiel_informatique' => ['libelle' => 'Matériel informatiques', 'comptes' => ['2182']],
    'materiel_transport' => ['libelle' => 'Matériel de transport', 'comptes' => ['2185']],
    'autres_materiels' => ['libelle' => 'Autres matetriels', 'comptes' => ['2186', '2187', '2188']]
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
        
        // Acquisitions (débits)
        $stmtAcquisitions = $pdo->prepare("
            SELECT COALESCE(SUM(e.montant_debit), 0) as total
            FROM ecritures_comptables e
            WHERE e.compte_general LIKE :compte AND e.date_ecriture BETWEEN :date_debut AND :date_fin AND e.montant_debit > 0
        ");
        $stmtAcquisitions->execute([':compte' => $compte . '%', ':date_debut' => $date_debut_exercice, ':date_fin' => $date_fin_exercice]);
        $data[$key]['acquisitions'] += (float)$stmtAcquisitions->fetch()['total'];
        
        // Cessions (crédits)
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

// ============================================================
// EXPORT PDF
// ============================================================
if ($format === 'pdf') {
    if (ob_get_length()) ob_end_clean();
    
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
            $this->SetFillColor(0, 0, 0);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 8, $this->convert($label), 0, 1, 'L', true);
            $this->SetTextColor(0, 0, 0);
            $this->Ln(2);
        }
        
        function TableHeader($cols) {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(240, 240, 240);
            $this->SetTextColor(0, 0, 0);
            foreach ($cols as $col) {
                $this->Cell($col['w'], 7, $this->convert($col['label']), 1, 0, $col['align'] ?? 'L', true);
            }
            $this->Ln();
        }
        
        function TableRow($cols, $data, $style = '') {
            if ($style == 'total') {
                $this->SetFont('Arial', 'B', 8);
                $this->SetFillColor(240, 253, 244);
                $this->SetTextColor(0, 0, 0);
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
        ['label' => 'RUBRIQUES', 'w' => 80, 'align' => 'L'],
        ['label' => 'Montant à l\'ouverture de l\'exercice', 'w' => 45, 'align' => 'R'],
        ['label' => 'Acquisitions Apports/Créations', 'w' => 45, 'align' => 'R'],
        ['label' => 'Cessions/Scissions Hors service', 'w' => 45, 'align' => 'R'],
        ['label' => 'Montant à la cloture de l\'exercice', 'w' => 45, 'align' => 'R']
    ];
    
    $pdf->SectionTitle('FORMATION BRUTE DU CAPITAL FIXE - SITUATIONS ET MOUVEMENTS (En F CFA)');
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
    
    $pdf->Output('I', '13_MOUVEMENTS_ACTIFS_' . $exercice . '.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL (via JavaScript)
// ============================================================
// (Géré par le bouton Excel, code JavaScript en bas)

// ============================================================
// AFFICHAGE WEB
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>13 - Acquisitions et cessions d'actifs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Design original - INCHANGÉ */
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
            <h1><i class="fas fa-building"></i> 13 - Acquisitions et cessions d'actifs</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">Formation brute du capital fixe - Exercice <?= $exercice ?></div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <form method="POST" action="" style="display:inline-block">
                <input type="hidden" name="exercice" value="<?= $exercice ?>">
                <input type="hidden" name="type_periode" value="<?= $type_periode ?>">
                <input type="hidden" name="mois" value="<?= $mois ?>">
                <input type="hidden" name="trimestre" value="<?= $trimestre ?>">
                <input type="hidden" name="semestre" value="<?= $semestre ?>">
                <input type="hidden" name="format" value="pdf">
                <button type="submit" class="btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
            </form>
        </div>
    </div>

    <!-- Filtres - avec formulaire POST -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
            <form method="POST" action="">
                <div class="filters-row">
                    <div class="filter-item">
                        <label>Annee</label>
                        <select name="exercice" id="exerciceSelect">
                            <?php for ($y = 2020; $y <= date('Y')+1; $y++): ?>
                                <option value="<?= $y ?>" <?= $y==$exercice?'selected':'' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label>Type de periode</label>
                        <select name="type_periode" id="typePeriodeSelect">
                            <option value="mensuel"   <?= $type_periode=='mensuel'  ?'selected':'' ?>>Mensuel</option>
                            <option value="trimestre" <?= $type_periode=='trimestre'?'selected':'' ?>>Trimestre</option>
                            <option value="semestre"  <?= $type_periode=='semestre' ?'selected':'' ?>>Semestre</option>
                            <option value="annuel"    <?= $type_periode=='annuel'   ?'selected':'' ?>>Annuel</option>
                        </select>
                    </div>
                    <div class="filter-item" id="dynamicSelectContainer">
                        <?php
                        if ($type_periode == 'mensuel') {
                            echo '<label>Mois</label><select name="mois">';
                            for ($m=1;$m<=12;$m++) { $s=($m==$mois)?'selected':''; echo "<option value='$m' $s>".str_pad($m,2,'0',STR_PAD_LEFT)." - ".date('F',mktime(0,0,0,$m,1))."</option>"; }
                            echo '</select>';
                        } elseif ($type_periode == 'trimestre') {
                            echo '<label>Trimestre</label><select name="trimestre">';
                            for ($t=1;$t<=4;$t++) { $s=($t==$trimestre)?'selected':''; echo "<option value='$t' $s>$t".($t==1?'er':'eme')." Trimestre</option>"; }
                            echo '</select>';
                        } elseif ($type_periode == 'semestre') {
                            echo '<label>Semestre</label><select name="semestre">';
                            for ($s=1;$s<=2;$s++) { $sel=($s==$semestre)?'selected':''; echo "<option value='$s' $sel>$s".($s==1?'er':'e')." semestre</option>"; }
                            echo '</select>';
                        } else {
                            echo '<label>Periode</label><input type="text" disabled value="Annee complete" style="background:#f3f4f6;cursor:default;">';
                        }
                        ?>
                    </div>
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
            </form>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Periode : <?= $lib_periode ?> (arrete au <?= date('d/m/Y', strtotime($date_fin_exercice)) ?>)
            </div>
        </div>
    </div>

    <!-- Tableau principal -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> FORMATION BRUTE DU CAPITAL FIXE - SITUATIONS ET MOUVEMENTS (En F CFA)</div>
        <div class="card-body">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Rubriques</th>
                            <th class="text-right">Montant à l'ouverture de l'exercice</th>
                            <th class="text-right">Acquisitions Apports/Créations</th>
                            <th class="text-right">Cessions/Scissions Hors service</th>
                            <th class="text-right">Montant à la cloture de l'exercice</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['libelle']) ?></td>
                            <td class="text-right"><?= number_format($item['montant_ouverture'], 0, ',', ' ') ?></td>
                            <td class="text-right"><?= number_format($item['acquisitions'], 0, ',', ' ') ?></td>
                            <td class="text-right"><?= number_format($item['cessions'], 0, ',', ' ') ?></td>
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

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base<br>
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
            html = '<label>Mois</label><select name="mois">';
            for (let m = 1; m <= 12; m++) {
                const s = (m === currentMois) ? 'selected' : '';
                const n = new Date(2000, m-1, 1).toLocaleString('fr', {month:'long'});
                html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre">';
            for (let t = 1; t <= 4; t++) {
                const s = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${s}>${t}${t === 1 ? 'er' : 'eme'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre">';
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

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        
        let data = [
            ['13 - ACQUISITIONS ET CESSIONS D\'ACTIFS'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['Rubriques', 'Montant à l\'ouverture de l\'exercice', 'Acquisitions Apports/Créations', 'Cessions/Scissions Hors service', 'Montant à la cloture de l\'exercice']
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