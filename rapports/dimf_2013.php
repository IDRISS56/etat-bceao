<?php
// DIMF_2013.php - Prêts aux dirigeants
// Version conforme au modèle Excel officiel DIMF_2013

session_start();

require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

class PDF_DIMF extends FPDF {
    public $codeDimf  = 'DIMF';
    public $titreDimf = 'Etat financier';
    public $nomSfd    = 'SFD';
    public $periode   = '';
    public $exercice  = '';

    static function u($str) {
        return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
    }

    function Header() {
        $this->SetFillColor(156, 163, 175);
        $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(8, 3);
        $this->Cell(0, 4, self::u('République de Côte d\'Ivoire  •  Ministère de l\'Économie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
        $this->SetFont('Arial', 'B', 13);
        $this->SetX(8);
        $this->Cell(0, 7, self::u($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
        $this->SetFont('Arial', '', 8);
        $this->SetX(8);
        $this->Cell(0, 5, self::u(
            'SFD : ' . $this->nomSfd .
            '   |   Période : ' . $this->periode .
            '   |   Exercice : ' . $this->exercice .
            '   |   Arrêté au : ' . date('d/m/Y')),
            0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 4, self::u(
            'SICS-BCEAO  •  Généré le ' . date('d/m/Y à H:i:s') .
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

// ============================================================
// PARAMÈTRES (POST / GET)
// ============================================================
$exercice     = isset($_POST['exercice'])     ? (int)$_POST['exercice']     : (isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode']      : (isset($_GET['type_periode']) ? $_GET['type_periode'] : 'mensuel');
$mois         = isset($_POST['mois'])         ? (int)$_POST['mois']         : (isset($_GET['mois']) ? (int)$_GET['mois'] : 12);
$trimestre    = isset($_POST['trimestre'])    ? (int)$_POST['trimestre']    : (isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4);
$semestre     = isset($_POST['semestre'])     ? (int)$_POST['semestre']     : (isset($_GET['semestre']) ? (int)$_GET['semestre'] : null);

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
}
$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));

// ============================================================
// RÉCUPÉRATION DES DIRIGEANTS ET DE LEURS ENCOURS
// ============================================================

// 1. Tous les dirigeants actifs
$dirigeants = [];
try {
    $stmt = $pdo->prepare("
        SELECT utilisateur_id, matricule, nom_prenom, role
        FROM utilisateurs
        WHERE role IN ('Superviseur', 'Administrateur', 'Responsable', 'Directeur')
          AND etat = 'actif'
        ORDER BY nom_prenom
    ");
    $stmt->execute();
    $dirigeants = $stmt->fetchAll();
} catch (PDOException $e) { $dirigeants = []; }

// 2. Encours par dirigeant (prêts actifs)
$encours_par_dirigeant = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            u.utilisateur_id,
            COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as encours
        FROM utilisateurs u
        LEFT JOIN dossiers d ON u.utilisateur_id = d.utilisateur_id AND d.statut IN ('actif', 'approuve') AND d.date_octroi <= :date_fin
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE u.role IN ('Superviseur', 'Administrateur', 'Responsable', 'Directeur')
          AND u.etat = 'actif'
        GROUP BY u.utilisateur_id
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    foreach ($stmt->fetchAll() as $row) {
        $encours_par_dirigeant[$row['utilisateur_id']] = (float)$row['encours'];
    }
} catch (PDOException $e) {}

// ============================================================
// FONDS PROPRES (classe 1 du plan comptable)
// ============================================================
$fonds_propres = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin AND e.statut = 'VALIDÉE'
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $fonds_propres = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $fonds_propres = 0; }

// ============================================================
// CALCUL R03
// ============================================================
$total_encours_dirigeants = array_sum($encours_par_dirigeant);
$ratio_r03 = ($fonds_propres > 0) ? ($total_encours_dirigeants / $fonds_propres) : 0;
$norme_r03 = 0.10;
$conformite_r03 = ($ratio_r03 <= $norme_r03) ? 'CONFORME' : 'NON CONFORME';

// ============================================================
// GÉNÉRATION PDF
// ============================================================
$format = isset($_POST['format']) ? $_POST['format'] : (isset($_GET['format']) ? $_GET['format'] : 'html');
if ($format === 'pdf') {
    switch ($type_periode) {
        case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
        case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
        case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
        default:          $lib_periode = 'Année ' . $exercice;
    }
    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->codeDimf = 'DIMF_2013';
    $pdf->titreDimf = 'Encours total des prêts aux dirigeants';
    $pdf->nomSfd = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    // En-tête R03
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 7, PDF_DIMF::u('R03 - LIMITATION DES PRÊTS AUX DIRIGEANTS'), 0, 1);
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell(0, 5, PDF_DIMF::u(
        "Ratio calculé : ".number_format($ratio_r03*100,2)."% (Norme ≤10%)\n".
        "Total encours des prêts aux dirigeants : ".PDF_DIMF::montant($total_encours_dirigeants)."\n".
        "Fonds propres : ".PDF_DIMF::montant($fonds_propres)."\n".
        "Conformité : $conformite_r03"
    ));
    $pdf->Ln(4);

    // Tableau principal
    $cols = [
        ['label'=>'CODE', 'w'=>35],
        ['label'=>'IDENTIFIANT', 'w'=>40],
        ['label'=>'PRÉNOMS/NOMS', 'w'=>70],
        ['label'=>'ENCOURS DES PRÊTS (bruts)', 'w'=>50, 'align'=>'R']
    ];
    $pdf->SectionTitle('État de l\'encours total des prêts aux dirigeants');
    $pdf->TableHeader($cols);

    $i = 1;
    foreach ($dirigeants as $d) {
        $enc = $encours_par_dirigeant[$d['utilisateur_id']] ?? 0;
        $code = 'DIMF_2013_1_' . $i;
        $pdf->TableRow($cols, [
            $code,
            PDF_DIMF::u($d['matricule']??'-'),
            PDF_DIMF::u($d['nom_prenom']??'-'),
            $enc > 0 ? PDF_DIMF::montant($enc) : '0'
        ]);
        $i++;
    }

    // Ligne TOTAL
    $pdf->TableRow($cols, [
        'DIMF_2013_1_' . $i,
        'TOTAL',
        '',
        $total_encours_dirigeants > 0 ? PDF_DIMF::montant($total_encours_dirigeants) : '0'
    ], 'total');

    $pdf->Output('I', 'DIMF_2013_PrêtsDirigeants_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2013 - Prêts aux dirigeants</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter', sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; margin-bottom:6px; display:flex; align-items:center; gap:10px; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; font-size:0.7rem; }
        .btn-group { display:flex; gap:12px; }
        .btn-excel { background:#10b981; color:white; padding:8px 20px; border-radius:40px; border:none; cursor:pointer; }
        .btn-pdf { background:#ef4444; color:white; padding:8px 20px; border-radius:40px; border:none; cursor:pointer; }
        .card { background:white; border-radius:20px; padding:20px 24px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
        .card-header { display:flex; align-items:center; gap:10px; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #eef2f6; font-weight:600; color:#1e40af; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select { border:1px solid #d1d5db; border-radius:12px; padding:8px 14px; font-size:0.85rem; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        th { text-align:left; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
        td { padding:10px 16px; border-bottom:1px solid #f1f5f9; }
        .text-right { text-align:right; font-family:monospace; font-weight:500; }
        .total-row { background:#f0fdf4; font-weight:700; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px 20px; border-radius:16px; }
        .status-badge { display:inline-block; padding:4px 12px; border-radius:20px; font-size:0.7rem; font-weight:600; }
        .status-conforme { background:#d1fae5; color:#065f46; }
        .status-non-conforme { background:#fee2e2; color:#991b1b; }
        .progress-bar { background:#e2e8f0; border-radius:10px; height:8px; overflow:hidden; margin:10px 0; }
        .progress-fill { background:#10b981; height:100%; border-radius:10px; }
        .progress-fill.non-conforme { background:#ef4444; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .page-footer, #filtersCard { display:none; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-user-tie"></i> DIMF_2013 - PRÊTS AUX DIRIGEANTS</h1>
            <div class="subtitle">République de Côte d'Ivoire – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Conformité R03</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Filtres -->
    <form method="post" class="card" id="filtersForm">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="filters-row">
            <div class="filter-item">
                <label>Année</label>
                <select name="exercice" id="exerciceSelect">
                    <?php for($y=2020;$y<=date('Y')+1;$y++) echo "<option value='$y' ".($y==$exercice?'selected':'').">$y</option>"; ?>
                </select>
            </div>
            <div class="filter-item">
                <label>Type période</label>
                <select name="type_periode" id="typePeriodeSelect">
                    <option value="mensuel"   <?= $type_periode=='mensuel'?'selected':'' ?>>Mensuel</option>
                    <option value="trimestre" <?= $type_periode=='trimestre'?'selected':'' ?>>Trimestre</option>
                    <option value="semestre"  <?= $type_periode=='semestre'?'selected':'' ?>>Semestre</option>
                    <option value="annuel"    <?= $type_periode=='annuel'?'selected':'' ?>>Annuel</option>
                </select>
            </div>
            <div class="filter-item" id="dynamicSelectContainer">
                <?php
                if ($type_periode == 'mensuel') {
                    echo '<label>Mois</label><select name="mois" id="moisSelect">';
                    for ($m=1;$m<=12;$m++) echo "<option value='$m' ".($m==$mois?'selected':'').">".str_pad($m,2,'0')." - ".date('F',mktime(0,0,0,$m,1))."</option>";
                    echo '</select>';
                } elseif ($type_periode == 'trimestre') {
                    echo '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
                    for ($t=1;$t<=4;$t++) echo "<option value='$t' ".($t==$trimestre?'selected':'').">$t".($t==1?'er':'ème')." Trimestre</option>";
                    echo '</select>';
                } elseif ($type_periode == 'semestre') {
                    echo '<label>Semestre</label><select name="semestre" id="semestreSelect">';
                    for ($s=1;$s<=2;$s++) echo "<option value='$s' ".($s==$semestre?'selected':'').">$s".($s==1?'er':'e')." semestre</option>";
                    echo '</select>';
                } else {
                    echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
                }
                ?>
            </div>
            <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
        </div>
    </form>

    <!-- R03 - Ratio -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> R03 - LIMITATION DES PRÊTS AUX DIRIGEANTS</div>
        <div class="info-box">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                <div><strong>Ratio calculé :</strong> <span style="font-size:1.8rem; font-weight:700; <?= $ratio_r03<=0.10?'color:#16a34a':'color:#dc2626' ?>"><?= number_format($ratio_r03*100,2) ?>%</span></div>
                <div><strong>Norme BCEAO :</strong> ≤ 10%</div>
                <div><span class="status-badge <?= $conformite_r03=='CONFORME'?'status-conforme':'status-non-conforme' ?>"><?= $conformite_r03 ?></span></div>
            </div>
            <div class="progress-bar">
                <div class="progress-fill <?= $ratio_r03>0.10?'non-conforme':'' ?>" style="width:<?= min($ratio_r03*100/0.10*100,100) ?>%"></div>
            </div>
            <div class="indicators-grid" style="margin-top:16px; display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px;">
                <div><strong>Total encours :</strong><br><?= number_format($total_encours_dirigeants,0,',',' ') ?> FCFA</div>
                <div><strong>Fonds propres :</strong><br><?= number_format($fonds_propres,0,',',' ') ?> FCFA</div>
            </div>
        </div>
    </div>

    <!-- Tableau des dirigeants -->
    <div class="card">
        <div class="card-header"><i class="fas fa-table"></i> ÉTAT DE L'ENCOURS TOTAL DES PRÊTS AUX DIRIGEANTS</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>CODE</th><th>IDENTIFIANT</th><th>PRÉNOMS/NOMS</th><th class="text-right">ENCOURS DES PRÊTS (bruts)</th></tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    foreach ($dirigeants as $d):
                        $enc = $encours_par_dirigeant[$d['utilisateur_id']] ?? 0;
                        $code = 'DIMF_2013_1_' . $i;
                    ?>
                    <tr>
                        <td><?= $code ?></td>
                        <td><?= htmlspecialchars($d['matricule']??'-') ?></td>
                        <td><?= htmlspecialchars($d['nom_prenom']??'-') ?></td>
                        <td class="text-right"><?= $enc > 0 ? number_format($enc,0,',',' ') : '0' ?></td>
                    </tr>
                    <?php $i++; endforeach; ?>
                    <tr class="total-row">
                        <td>DIMF_2013_1_<?= $i ?></td>
                        <td colspan="2"><strong>TOTAL</strong></td>
                        <td class="text-right"><strong><?= number_format($total_encours_dirigeants,0,',',' ') ?></strong></td>
                    </tr>
                </tbody>
            </table>
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
        const cm = <?= $mois ?>, ct = <?= $trimestre ?>, cs = <?= json_encode($semestre) ?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois" id="moisSelect">';
            for(let m=1;m<=12;m++) { const s=(m===cm)?'selected':''; const n=new Date(2000,m-1,1).toLocaleString('fr',{month:'long'}); html+=`<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`; }
            html+='</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
            for(let t=1;t<=4;t++) { const s=(t===ct)?'selected':''; html+=`<option value="${t}" ${s}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
            html+='</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
            for(let s=1;s<=2;s++) { const sel=(s===cs)?'selected':''; html+=`<option value="${s}" ${sel}>${s}${s===1?'er':'e'} semestre</option>`; }
            html+='</select>';
        } else { html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">'; }
        container.innerHTML = html;
    }

    function exporterPDF() {
        const form = document.getElementById('filtersForm');
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'format'; inp.value = 'pdf';
        form.appendChild(inp);
        // PDF dans la même fenêtre (pas de target)
        form.submit();
        form.removeChild(inp);
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        const data = [
            ['DIMF_2013 - ÉTAT DE L\'ENCOURS TOTAL DES PRÊTS AUX DIRIGEANTS'],
            ['Exercice', '<?= $exercice ?>', 'Type', '<?= $type_periode ?>'],
            [],
            ['CODE', 'IDENTIFIANT', 'PRÉNOMS/NOMS', 'ENCOURS DES PRÊTS (bruts)']
        ];
        <?php
        $i = 1;
        foreach ($dirigeants as $d):
            $enc = $encours_par_dirigeant[$d['utilisateur_id']] ?? 0;
        ?>
        data.push(['DIMF_2013_1_<?= $i ?>', '<?= addslashes($d['matricule']??'-') ?>', '<?= addslashes($d['nom_prenom']??'-') ?>', <?= $enc ?>]);
        <?php $i++; endforeach; ?>
        data.push(['DIMF_2013_1_<?= $i ?>', 'TOTAL', '', <?= $total_encours_dirigeants ?>]);
        const ws = XLSX.utils.aoa_to_sheet(data);
        XLSX.utils.book_append_sheet(wb, ws, "PRETS_DIRIGEANTS");
        XLSX.writeFile(wb, 'DIMF_2013_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);
    });
</script>
</body>
</html>