<?php
// DIMF_2080.php - Compte de résultat (Charges et Produits) avec FPDF
// Design DIMF_2000 - gestion POST, Bootstrap

session_start();

// ============================================================
// CONFIGURATION BDD
// ============================================================
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

class PDF_DIMF extends FPDF {
    public $codeDimf = 'DIMF';
    public $titreDimf = 'Etat financier';
    public $nomSfd = 'SFD';
    public $periode = '';
    public $exercice = '';

    static function u($str) {
        return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
    }

    function Header() {
        $this->SetFillColor(156,163,175);
        $this->Rect(0,0,$this->GetPageWidth(),28,'F');
        $this->SetFont('Arial','',7);
        $this->SetTextColor(255,255,255);
        $this->SetXY(8,3);
        $this->Cell(0,4,self::u('Republique de Cote d\'Ivoire  •  Ministere de l\'Economie et des Finances  -  DGTCP / DSFD'),0,1,'L');
        $this->SetFont('Arial','B',13);
        $this->SetX(8);
        $this->Cell(0,7,self::u($this->codeDimf.'  -  '.$this->titreDimf),0,1,'L');
        $this->SetFont('Arial','',8);
        $this->SetX(8);
        $this->Cell(0,5,self::u('SFD : '.$this->nomSfd.'   |   Periode : '.$this->periode.'   |   Exercice : '.$this->exercice.'   |   Arrete au : '.date('d/m/Y')),0,1,'L');
        $this->SetTextColor(0,0,0);
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial','I',7);
        $this->SetTextColor(100,116,139);
        $this->Cell(0,4,self::u('SICS-BCEAO  •  Genere le '.date('d/m/Y a H:i:s').'  •  Page '.$this->PageNo().'/{nb}'),0,0,'C');
    }

    function SectionTitle($label) {
        $this->SetFont('Arial','B',9);
        $this->SetFillColor(0,0,0);
        $this->SetTextColor(255,255,255);
        $this->Cell(0,7,self::u('  '.strtoupper($label)),0,1,'L',true);
        $this->SetTextColor(0,0,0);
        $this->Ln(1);
    }

    function TableHeader($cols) {
        $this->SetFont('Arial','B',8);
        $this->SetFillColor(248,250,252);
        $this->SetTextColor(30,41,59);
        $this->SetDrawColor(226,232,240);
        foreach($cols as $col){
            $align = isset($col['align']) ? $col['align'] : 'L';
            $this->Cell($col['w'],6,self::u($col['label']),1,0,$align,true);
        }
        $this->Ln();
    }

    function TableRow($cols,$data,$style='') {
        $fill = false;
        if($style=='subtotal'){
            $this->SetFillColor(248,250,252);
            $this->SetFont('Arial','B',8);
            $fill = true;
        }elseif($style=='total'){
            $this->SetFillColor(240,253,244);
            $this->SetFont('Arial','B',8.5);
            $fill = true;
        }else{
            $this->SetFillColor(255,255,255);
            $this->SetFont('Arial','',7.5);
        }
        $this->SetTextColor(15,23,42);
        $this->SetDrawColor(226,232,240);
        foreach($cols as $i=>$col){
            $val = isset($data[$i]) ? $data[$i] : '';
            $align = isset($col['align']) ? $col['align'] : 'L';
            $this->Cell($col['w'],5.5,self::u($val),1,0,$align,$fill);
        }
        $this->Ln();
    }

    static function montant($val) {
        return number_format((float)$val,0,',',' ').' F';
    }
}

// ============================================================
// PARAMÈTRES (priorité POST > GET)
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
$date_fin_periode = date('Y-m-t', strtotime($exercice.'-'.str_pad($mois,2,'0',STR_PAD_LEFT).'-01'));
$date_debut_exercice = $exercice.'-01-01';

// Libellé période pour affichage
switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Année ' . $exercice;
}

// ============================================================
// CALCUL DES CHARGES ET PRODUITS
// ============================================================
function getCharges($like, $debut, $fin) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit),0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE :like AND e.date_ecriture BETWEEN :debut AND :fin");
        $stmt->execute([':like'=>$like, ':debut'=>$debut, ':fin'=>$fin]);
        return (float)$stmt->fetch()['total'];
    } catch(PDOException $e){ return 0; }
}
function getProduits($like, $debut, $fin) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit),0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE :like AND e.date_ecriture BETWEEN :debut AND :fin");
        $stmt->execute([':like'=>$like, ':debut'=>$debut, ':fin'=>$fin]);
        return (float)$stmt->fetch()['total'];
    } catch(PDOException $e){ return 0; }
}

$R08_total = getCharges('661%', $date_debut_exercice, $date_fin_periode) + getCharges('662%', $date_debut_exercice, $date_fin_periode);
$R3A_total = getCharges('663%', $date_debut_exercice, $date_fin_periode);
$S02_total = getCharges('62%', $date_debut_exercice, $date_fin_periode);
$S1A_total = getCharges('63%', $date_debut_exercice, $date_fin_periode);
$S2A_total = getCharges('64%', $date_debut_exercice, $date_fin_periode);
$T51_total = getCharges('681%', $date_debut_exercice, $date_fin_periode);
$T6B_total = getCharges('687%', $date_debut_exercice, $date_fin_periode);
$T80_total = getCharges('67%', $date_debut_exercice, $date_fin_periode);
$total_charges = $R08_total + $R3A_total + $S02_total + $S1A_total + $S2A_total + $T51_total + $T6B_total + $T80_total;

$V08_total = getProduits('761%', $date_debut_exercice, $date_fin_periode);
$V3A_total = getProduits('763%', $date_debut_exercice, $date_fin_periode);
$W4A_total = getProduits('78%', $date_debut_exercice, $date_fin_periode);
$X51_total = getProduits('781%', $date_debut_exercice, $date_fin_periode);
$X6B_total = getProduits('787%', $date_debut_exercice, $date_fin_periode);
$X80_total = getProduits('77%', $date_debut_exercice, $date_fin_periode);
$total_produits = $V08_total + $V3A_total + $W4A_total + $X51_total + $X6B_total + $X80_total;

$resultat_net = $total_produits - $total_charges;
$resultat_type = ($resultat_net >= 0) ? "EXCEDENT" : "DEFICIT";

// ============================================================
// GÉNÉRATION PDF (POST)
// ============================================================
if ($format === 'pdf') {
    if (ob_get_length()) ob_end_clean();

    $pdf = new PDF_DIMF('L','mm','A4');
    $pdf->AliasNbPages();
    $pdf->codeDimf = 'DIMF_2080';
    $pdf->titreDimf = 'Compte de résultat';
    $pdf->nomSfd = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(8,35,8);
    $pdf->SetAutoPageBreak(true,14);
    $pdf->AddPage();

    $cols = [['label'=>'CODE','w'=>25],['label'=>'LIBELLÉ','w'=>100],['label'=>'Montant (FCFA)','w'=>45,'align'=>'R']];
    $pdf->SectionTitle('CHARGES');
    $pdf->TableHeader($cols);
    $pdf->TableRow($cols,['R08','Charges sur opérations avec institutions financières',PDF_DIMF::montant($R08_total)],'subtotal');
    $pdf->TableRow($cols,['R3A','Charges sur opérations avec membres',PDF_DIMF::montant($R3A_total)]);
    $pdf->TableRow($cols,['S02','Frais de personnel',PDF_DIMF::montant($S02_total)],'subtotal');
    $pdf->TableRow($cols,['S1A','Impôts et taxes',PDF_DIMF::montant($S1A_total)]);
    $pdf->TableRow($cols,['S2A','Autres charges externes',PDF_DIMF::montant($S2A_total)]);
    $pdf->TableRow($cols,['T51','Dotations aux amortissements',PDF_DIMF::montant($T51_total)],'subtotal');
    $pdf->TableRow($cols,['T6B','Dotations aux provisions sur créances',PDF_DIMF::montant($T6B_total)]);
    $pdf->TableRow($cols,['T80','Charges exceptionnelles',PDF_DIMF::montant($T80_total)],'subtotal');
    $pdf->TableRow($cols,['TOTAL','TOTAL CHARGES',PDF_DIMF::montant($total_charges)],'total');

    $pdf->AddPage();
    $pdf->SectionTitle('PRODUITS');
    $pdf->TableHeader($cols);
    $pdf->TableRow($cols,['V08','Produits sur opérations avec institutions financières',PDF_DIMF::montant($V08_total)],'subtotal');
    $pdf->TableRow($cols,['V3A','Produits sur opérations avec membres',PDF_DIMF::montant($V3A_total)]);
    $pdf->TableRow($cols,['W4A','Produits divers d\'exploitation',PDF_DIMF::montant($W4A_total)],'subtotal');
    $pdf->TableRow($cols,['X51','Reprises d\'amortissements et provisions',PDF_DIMF::montant($X51_total)]);
    $pdf->TableRow($cols,['X6B','Reprises de provisions sur créances',PDF_DIMF::montant($X6B_total)]);
    $pdf->TableRow($cols,['X80','Produits exceptionnels',PDF_DIMF::montant($X80_total)]);
    $pdf->TableRow($cols,['TOTAL','TOTAL PRODUITS',PDF_DIMF::montant($total_produits)],'total');

    $pdf->Ln(10);
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(0,8,PDF_DIMF::u('RÉSULTAT DE L\'EXERCICE'),0,1);
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(0,6,PDF_DIMF::u("Résultat = Total Produits - Total Charges"),0,1);
    $pdf->SetFont('Arial','B',12);
    $pdf->SetTextColor($resultat_type=='EXCEDENT'?22:199, $resultat_type=='EXCEDENT'?163:40, $resultat_type=='EXCEDENT'?74:40);
    $pdf->Cell(0,8,PDF_DIMF::u(number_format(abs($resultat_net),0,',',' ').' FCFA ('.$resultat_type.')'),0,1,'C');
    $pdf->SetTextColor(0,0,0);
    $pdf->Output('I','DIMF_2080_CompteResultat_'.$exercice.'_'.$type_periode.'.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL (POST)
// ============================================================
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="DIMF_2080_'.$exercice.'.xls"');
    echo '<html><head><meta charset="UTF-8"><style> body { font-family: Arial; } table { border-collapse: collapse; } th, td { border: 1px solid #999; padding: 6px; } .text-right { text-align: right; } </style></head><body>';
    echo '<h2>DIMF_2080 - Compte de résultat</h2>';
    echo '<p>Période : ' . htmlspecialchars($lib_periode) . '</p>';
    echo '<h3>CHARGES</h3>';
    echo '<table><thead><tr><th>CODE</th><th>LIBELLÉ</th><th class="text-right">Montant (FCFA)</th></tr></thead><tbody>';
    echo '<tr><td>R08</td><td>Charges sur opérations avec institutions financières</td><td class="text-right">' . number_format($R08_total,0,',',' ') . '</td></tr>';
    echo '<tr><td>R3A</td><td>Charges sur opérations avec membres</td><td class="text-right">' . number_format($R3A_total,0,',',' ') . '</td></tr>';
    echo '<tr><td>S02</td><td>Frais de personnel</td><td class="text-right">' . number_format($S02_total,0,',',' ') . '</td></tr>';
    echo '<tr><td>S1A</td><td>Impôts et taxes</td><td class="text-right">' . number_format($S1A_total,0,',',' ') . '</td></tr>';
    echo '<tr><td>S2A</td><td>Autres charges externes</td><td class="text-right">' . number_format($S2A_total,0,',',' ') . '</td></tr>';
    echo '<tr><td>T51</td><td>Dotations aux amortissements</td><td class="text-right">' . number_format($T51_total,0,',',' ') . '</td></tr>';
    echo '<tr><td>T6B</td><td>Dotations aux provisions sur créances</td><td class="text-right">' . number_format($T6B_total,0,',',' ') . '</td></tr>';
    echo '<tr><td>T80</td><td>Charges exceptionnelles</td><td class="text-right">' . number_format($T80_total,0,',',' ') . '</td></tr>';
    echo '<tr style="background:#e8f5e9;"><td colspan="2"><strong>TOTAL CHARGES</strong></td><td class="text-right"><strong>' . number_format($total_charges,0,',',' ') . '</strong></td></tr>';
    echo '</tbody></table><br/><h3>PRODUITS</h3>';
    echo '<table><thead><tr><th>CODE</th><th>LIBELLÉ</th><th class="text-right">Montant (FCFA)</th></tr></thead><tbody>';
    echo '<tr><td>V08</td><td>Produits sur opérations avec institutions financières</td><td class="text-right">' . number_format($V08_total,0,',',' ') . '</td></tr>';
    echo '<tr><td>V3A</td><td>Produits sur opérations avec membres</td><td class="text-right">' . number_format($V3A_total,0,',',' ') . '</td></tr>';
    echo '<tr><td>W4A</td><td>Produits divers d\'exploitation</td><td class="text-right">' . number_format($W4A_total,0,',',' ') . '</td></tr>';
    echo '<tr><td>X51</td><td>Reprises d\'amortissements et provisions</td><td class="text-right">' . number_format($X51_total,0,',',' ') . '</td></tr>';
    echo '<tr><td>X6B</td><td>Reprises de provisions sur créances</td><td class="text-right">' . number_format($X6B_total,0,',',' ') . '</td></tr>';
    echo '<tr><td>X80</td><td>Produits exceptionnels</td><td class="text-right">' . number_format($X80_total,0,',',' ') . '</td></tr>';
    echo '<tr style="background:#e8f5e9;"><td colspan="2"><strong>TOTAL PRODUITS</strong></td><td class="text-right"><strong>' . number_format($total_produits,0,',',' ') . '</strong></td></tr>';
    echo '</tbody></table><br/>';
    echo '<h3>Résultat de l\'exercice</h3>';
    echo '<p><strong>Total Produits - Total Charges</strong> = ' . number_format(abs($resultat_net),0,',',' ') . ' FCFA (' . $resultat_type . ')</p>';
    echo '</body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2080 - Compte de résultat</title>
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
        .card { background:white; border-radius:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px; overflow:hidden; }
        .card-header { display:flex; align-items:center; gap:10px; padding:16px 24px; background:#f8fafc; border-bottom:1px solid #eef2f6; font-weight:600; color:#1e40af; }
        .card-body { padding:20px 24px; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select { background:white; border:1px solid #d1d5db; border-radius:12px; padding:8px 14px; font-size:0.85rem; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        th { text-align:left; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
        td { padding:10px 16px; border-bottom:1px solid #f1f5f9; }
        .text-right { text-align:right; font-family:monospace; }
        .total-row { background:#f0fdf4; font-weight:700; }
        .subtotal-row { background:#f8fafc; font-weight:600; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .two-columns { display:flex; gap:24px; flex-wrap:wrap; }
        .two-columns>.card { flex:1; min-width:400px; }
        .excedent { color:#16a34a; font-size:2rem; font-weight:700; }
        .deficit { color:#dc2626; font-size:2rem; font-weight:700; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; padding:16px; }
        @media (max-width:768px) { body { padding:12px; } .filters-row { flex-direction:column; } .btn-group { flex-wrap:wrap; } }
        @media print { .btn-group, .page-footer, .filters-row, #filtersCard { display:none !important; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-line"></i> DIMF_2080 - COMPTE DE RÉSULTAT</h1>
            <div class="subtitle">République de Côte d'Ivoire – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO</div>
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

    <div class="two-columns">
        <div class="card">
            <div class="card-header"><i class="fas fa-arrow-down"></i> CHARGES</div>
            <div class="table-wrapper">
                <table>
                    <thead><th>CODE</th><th>LIBELLÉ</th><th class="text-right">Montant (FCFA)</th></thead>
                    <tbody>
                        <tr class="subtotal-row"><td colspan="2">CHARGES SUR OPÉRATIONS FINANCIÈRES</td><td class="text-right"><?= number_format($R08_total+$R3A_total,0,',',' ') ?></td></tr>
                        <tr><td>R08</td><td>Charges sur opérations avec institutions financières</td><td class="text-right"><?= number_format($R08_total,0,',',' ') ?></td></tr>
                        <tr><td>R3A</td><td>Charges sur opérations avec membres</td><td class="text-right"><?= number_format($R3A_total,0,',',' ') ?></td></tr>
                        <tr class="subtotal-row"><td colspan="2">CHARGES D'EXPLOITATION</td><td class="text-right"><?= number_format($S02_total+$S1A_total+$S2A_total,0,',',' ') ?></td></tr>
                        <tr><td>S02</td><td>Frais de personnel</td><td class="text-right"><?= number_format($S02_total,0,',',' ') ?></td></tr>
                        <tr><td>S1A</td><td>Impôts et taxes</td><td class="text-right"><?= number_format($S1A_total,0,',',' ') ?></td></tr>
                        <tr><td>S2A</td><td>Autres charges externes</td><td class="text-right"><?= number_format($S2A_total,0,',',' ') ?></td></tr>
                        <tr class="subtotal-row"><td colspan="2">DOTATIONS ET PROVISIONS</td><td class="text-right"><?= number_format($T51_total+$T6B_total,0,',',' ') ?></td></tr>
                        <tr><td>T51</td><td>Dotations aux amortissements</td><td class="text-right"><?= number_format($T51_total,0,',',' ') ?></td></tr>
                        <tr><td>T6B</td><td>Dotations aux provisions sur créances</td><td class="text-right"><?= number_format($T6B_total,0,',',' ') ?></td></tr>
                        <tr class="subtotal-row"><td colspan="2">CHARGES EXCEPTIONNELLES</td><td class="text-right"><?= number_format($T80_total,0,',',' ') ?></td></tr>
                        <tr class="total-row"><td colspan="2"><strong>TOTAL CHARGES</strong></td><td class="text-right"><strong><?= number_format($total_charges,0,',',' ') ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="fas fa-arrow-up"></i> PRODUITS</div>
            <div class="table-wrapper">
                <table>
                    <thead><th>CODE</th><th>LIBELLÉ</th><th class="text-right">Montant (FCFA)</th></thead>
                    <tbody>
                        <tr class="subtotal-row"><td colspan="2">PRODUITS SUR OPÉRATIONS FINANCIÈRES</td><td class="text-right"><?= number_format($V08_total+$V3A_total,0,',',' ') ?></td></tr>
                        <tr><td>V08</td><td>Produits sur opérations avec institutions financières</td><td class="text-right"><?= number_format($V08_total,0,',',' ') ?></td></tr>
                        <tr><td>V3A</td><td>Produits sur opérations avec membres (intérêts crédits)</td><td class="text-right"><?= number_format($V3A_total,0,',',' ') ?></td></tr>
                        <tr class="subtotal-row"><td colspan="2">AUTRES PRODUITS</td><td class="text-right"><?= number_format($W4A_total+$X51_total+$X6B_total+$X80_total,0,',',' ') ?></td></tr>
                        <tr><td>W4A</td><td>Produits divers d'exploitation</td><td class="text-right"><?= number_format($W4A_total,0,',',' ') ?></td></tr>
                        <tr><td>X51</td><td>Reprises d'amortissements et provisions</td><td class="text-right"><?= number_format($X51_total,0,',',' ') ?></td></tr>
                        <tr><td>X6B</td><td>Reprises de provisions sur créances</td><td class="text-right"><?= number_format($X6B_total,0,',',' ') ?></td></tr>
                        <tr><td>X80</td><td>Produits exceptionnels</td><td class="text-right"><?= number_format($X80_total,0,',',' ') ?></td></tr>
                        <tr class="total-row"><td colspan="2"><strong>TOTAL PRODUITS</strong></td><td class="text-right"><strong><?= number_format($total_produits,0,',',' ') ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-balance-scale"></i> RÉSULTAT DE L'EXERCICE</div>
        <div class="info-box" style="text-align:center; display:block;">
            <strong>Résultat = Total Produits - Total Charges</strong><br><br>
            <span class="<?= $resultat_type=='EXCEDENT'?'excedent':'deficit' ?>"><?= number_format(abs($resultat_net),0,',',' ') ?> FCFA</span><br>
            <span style="font-size:0.9rem;">L'exercice <?= $exercice ?> se solde par un <strong><?= $resultat_type ?></strong> de <?= number_format(abs($resultat_net),0,',',' ') ?> FCFA</span>
        </div>
    </div>

    <div class="page-footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>) arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>
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