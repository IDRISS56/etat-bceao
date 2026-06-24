<?php
// DIMF_2011_1.php - État des engagements par signature
// Utilise la table z_bceao_infos_annexes (existante) pour ZC18 et ZC19

session_start();

// ============================================================
// CONFIGURATION BDD
// ============================================================
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
        $this->Cell(0, 4, self::u('Republique de Cote d\'Ivoire  •  Ministere de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
        $this->SetFont('Arial', 'B', 13);
        $this->SetX(8);
        $this->Cell(0, 7, self::u($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
        $this->SetFont('Arial', '', 8);
        $this->SetX(8);
        $this->Cell(0, 5, self::u(
            'SFD : ' . $this->nomSfd .
            '   |   Periode : ' . $this->periode .
            '   |   Exercice : ' . $this->exercice .
            '   |   Arrete au : ' . date('d/m/Y')),
            0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 4, self::u(
            'SICS-BCEAO  •  Genere le ' . date('d/m/Y a H:i:s') .
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
        $fill = false;
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

// ============================================================
// PARAMÈTRES
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
// TRAITEMENT POST (SAUVEGARDE)
// ============================================================
$message = '';
$message_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    try {
        // Supprimer les anciennes données pour ZC18 et ZC19
        $stmtDel = $pdo->prepare("DELETE FROM z_bceao_infos_annexes WHERE exercice = :exercice AND code_indicateur IN ('ZC18', 'ZC19')");
        $stmtDel->execute([':exercice' => $exercice]);

        // Insérer les nouvelles valeurs
        $stmtIns = $pdo->prepare("INSERT INTO z_bceao_infos_annexes 
            (exercice, code_indicateur, valeur_montant, statut) 
            VALUES (:exercice, :code, :montant, 'actif')");

        $montant_ct = (float) str_replace([' ', ','], ['', '.'], $_POST['montant_CT'] ?? 0);
        $montant_mlt = (float) str_replace([' ', ','], ['', '.'], $_POST['montant_MLT'] ?? 0);

        $stmtIns->execute([':exercice' => $exercice, ':code' => 'ZC18', ':montant' => $montant_ct]);
        $stmtIns->execute([':exercice' => $exercice, ':code' => 'ZC19', ':montant' => $montant_mlt]);

        $message = "Engagements enregistrés avec succès !";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
    $url = "DIMF_2011_1.php?exercice=$exercice&type_periode=$type_periode" .
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
// RÉCUPÉRATION DES VALEURS SAISIES (depuis z_bceao_infos_annexes)
// ============================================================
$valeurs_saisies = ['ZC18' => 0, 'ZC19' => 0];
try {
    $stmt = $pdo->prepare("SELECT code_indicateur, valeur_montant FROM z_bceao_infos_annexes 
                           WHERE exercice = :exercice AND code_indicateur IN ('ZC18', 'ZC19') AND statut = 'actif'");
    $stmt->execute([':exercice' => $exercice]);
    foreach ($stmt->fetchAll() as $row) {
        $valeurs_saisies[$row['code_indicateur']] = (float)$row['valeur_montant'];
    }
} catch (PDOException $e) {}

// ============================================================
// CALCUL AUTOMATIQUE DEPUIS LES GARANTIES
// ============================================================
$engagements_calcules = ['CT' => 0, 'MLT' => 0];
try {
    // Court terme : crédits avec durée <= 12 mois
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(g.valeur_nette),0) as total 
        FROM garanties g 
        INNER JOIN dossiers d ON g.credit_id = d.dossier_id 
        WHERE g.statut = 'actif' AND d.duree <= 12");
    $stmt->execute();
    $engagements_calcules['CT'] = (float)$stmt->fetch()['total'];

    // Moyen/long terme : durée > 12 mois
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(g.valeur_nette),0) as total 
        FROM garanties g 
        INNER JOIN dossiers d ON g.credit_id = d.dossier_id 
        WHERE g.statut = 'actif' AND d.duree > 12");
    $stmt->execute();
    $engagements_calcules['MLT'] = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// Les valeurs affichées : si une valeur saisie existe, elle prévaut sur le calcul
$affichage_ct = ($valeurs_saisies['ZC18'] > 0) ? $valeurs_saisies['ZC18'] : $engagements_calcules['CT'];
$affichage_mlt = ($valeurs_saisies['ZC19'] > 0) ? $valeurs_saisies['ZC19'] : $engagements_calcules['MLT'];
$total_engagements = $affichage_ct + $affichage_mlt;

// Détail des garanties actives (pour information)
$details_garanties = [];
try {
    $stmt = $pdo->prepare("SELECT garantie_id, libelle_garantie, code_type_garantie, valeur_nette, date_evaluation, date_expiration, d.duree as credit_duree 
        FROM garanties g 
        LEFT JOIN dossiers d ON g.credit_id = d.dossier_id 
        WHERE g.statut = 'actif' 
        ORDER BY g.valeur_nette DESC 
        LIMIT 20");
    $stmt->execute();
    $details_garanties = $stmt->fetchAll();
} catch (PDOException $e) {}

// ============================================================
// GÉNÉRATION PDF
// ============================================================
$format = isset($_POST['format']) ? $_POST['format'] : (isset($_GET['format']) ? $_GET['format'] : 'html');

if ($format === 'pdf') {
    switch ($type_periode) {
        case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
        case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
        case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
        default:          $lib_periode = 'Annee ' . $exercice;
    }

    $pdf = new PDF_DIMF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->codeDimf  = 'DIMF_2011_1';
    $pdf->titreDimf = 'Engagements par signature';
    $pdf->nomSfd    = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode   = $lib_periode;
    $pdf->exercice  = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'CODE', 'w' => 20],
        ['label' => 'LIBELLÉ', 'w' => 100],
        ['label' => 'Montant (FCFA)', 'w' => 60, 'align' => 'R']
    ];
    $pdf->SectionTitle('Engagements par signature');
    $pdf->TableHeader($cols);
    $pdf->TableRow($cols, ['ZC18', 'Engagements à court terme (≤12 mois)', PDF_DIMF::montant($affichage_ct)]);
    $pdf->TableRow($cols, ['ZC19', 'Engagements à moyen et long termes (>12 mois)', PDF_DIMF::montant($affichage_mlt)]);
    $pdf->TableRow($cols, ['', 'TOTAL', PDF_DIMF::montant($total_engagements)], 'total');

    $pdf->Output('I', 'DIMF_2011_1_Engagements_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ============================================================
// AFFICHAGE HTML
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2011_1 - Engagements par signature</title>
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
        .filter-item select, .filter-item input { border:1px solid #d1d5db; border-radius:12px; padding:8px 14px; font-size:0.85rem; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        th { text-align:left; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
        td { padding:10px 16px; border-bottom:1px solid #f1f5f9; }
        .text-right { text-align:right; font-family:monospace; font-weight:500; }
        .total-row { background:#f0fdf4; font-weight:700; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px 20px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .page-footer, #filtersCard { display:none; } }
        .input-group { display:flex; align-items:center; gap:8px; }
        .input-group input { flex:1; }
        .auto-badge { font-size:0.65rem; color:#16a34a; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-handshake"></i> DIMF_2011_1 - ENGAGEMENTS PAR SIGNATURE</h1>
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
        <div class="filters-row">
            <div class="filter-item">
                <label>Année</label>
                <select name="exercice" id="exerciceSelect">
                    <?php for ($y = 2020; $y <= date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y==$exercice?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="filter-item">
                <label>Type période</label>
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

    <?php if($message): ?>
        <div class="info-box" style="background:<?= $message_type=='success'?'#d1fae5':'#fee2e2' ?>;border-left-color:<?= $message_type=='success'?'#10b981':'#ef4444' ?>;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- ===== TABLEAU DES ENGAGEMENTS ===== -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-simple"></i> ENGAGEMENTS PAR SIGNATURE</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>CODE</th><th>LIBELLÉ</th><th class="text-right">Montant (FCFA)</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>ZC18</td>
                        <td>Encours des engagements par signature donnés à court terme (≤12 mois)</td>
                        <td class="text-right"><?= number_format($affichage_ct,0,',',' ') ?></td>
                    </tr>
                    <tr>
                        <td>ZC19</td>
                        <td>Encours des engagements par signature donnés à moyen et long termes (>12 mois)</td>
                        <td class="text-right"><?= number_format($affichage_mlt,0,',',' ') ?></td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="2"><strong>TOTAL</strong></td>
                        <td class="text-right"><strong><?= number_format($total_engagements,0,',',' ') ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px; font-size:0.8rem; color:#6b7280;">
            <i class="fas fa-info-circle"></i> Les montants affichés sont calculés automatiquement à partir des garanties actives. 
            Vous pouvez les modifier manuellement via le formulaire ci-dessous.
        </div>
    </div>

    <!-- ===== FORMULAIRE DE SAISIE ===== -->
    <div class="card">
        <div class="card-header"><i class="fas fa-pen"></i> SAISIE MANUELLE DES MONTANTS</div>
        <form method="post">
            <input type="hidden" name="action" value="save">
            <div class="filters-row" style="margin-bottom:0;">
                <div class="filter-item">
                    <label>ZC18 - Court terme (FCFA)</label>
                    <div class="input-group">
                        <input type="text" name="montant_CT" value="<?= number_format($valeurs_saisies['ZC18'],0,',',' ') ?>" class="form-control form-control-sm">
                        <?php if ($valeurs_saisies['ZC18'] == 0): ?>
                            <span class="auto-badge"><i class="fas fa-calculator"></i> auto: <?= number_format($engagements_calcules['CT'],0,',',' ') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="filter-item">
                    <label>ZC19 - Moyen/long terme (FCFA)</label>
                    <div class="input-group">
                        <input type="text" name="montant_MLT" value="<?= number_format($valeurs_saisies['ZC19'],0,',',' ') ?>" class="form-control form-control-sm">
                        <?php if ($valeurs_saisies['ZC19'] == 0): ?>
                            <span class="auto-badge"><i class="fas fa-calculator"></i> auto: <?= number_format($engagements_calcules['MLT'],0,',',' ') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="filter-item">
                    <button type="submit" class="btn-apply"><i class="fas fa-save"></i> Enregistrer</button>
                </div>
            </div>
        </form>
    </div>

    <!-- ===== DÉTAIL DES GARANTIES ===== -->
    <div class="card">
        <div class="card-header"><i class="fas fa-list-ul"></i> DÉTAIL DES GARANTIES ACTIVES</div>
        <?php if(empty($details_garanties)): ?>
            <div class="info-box">Aucune garantie active trouvée.</div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>N° Garantie</th><th>Libellé</th><th>Type</th><th class="text-right">Valeur nette</th><th>Date évaluation</th><th>Expiration</th><th>Durée crédit</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($details_garanties as $g):
                            $typeLabel = match($g['code_type_garantie']) { 
                                '01'=>'Hypothèque', '02'=>'Nantissement', '03'=>'Caution', '04'=>'Gage', default=>'Autre' 
                            };
                        ?>
                        <tr>
                            <td><?= substr(htmlspecialchars($g['garantie_id']),0,8) ?>…</td>
                            <td><?= htmlspecialchars($g['libelle_garantie']) ?></td>
                            <td><?= $typeLabel ?></td>
                            <td class="text-right"><?= number_format($g['valeur_nette'],0,',',' ') ?></td>
                            <td><?= date('d/m/Y',strtotime($g['date_evaluation'])) ?></td>
                            <td><?= $g['date_expiration']?date('d/m/Y',strtotime($g['date_expiration'])):'-' ?></td>
                            <td><?= $g['credit_duree']?$g['credit_duree'].' mois':'-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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
            for (let m=1;m<=12;m++) {
                const s = (m===currentMois)?'selected':'';
                const n = new Date(2000,m-1,1).toLocaleString('fr',{month:'long'});
                html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
            for (let t=1;t<=4;t++) {
                const s = (t===currentTrimestre)?'selected':'';
                html += `<option value="${t}" ${s}>${t}${t===1?'er':'ème'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
            for (let s=1;s<=2;s++) {
                const sel = (s===currentSemestre)?'selected':'';
                html += `<option value="${s}" ${sel}>${s}${s===1?'er':'e'} semestre</option>`;
            }
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
        form.target = '_self';
        form.submit();
        form.target = '';
        form.removeChild(input);
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        const data = [
            ['DIMF_2011_1 - ENGAGEMENTS PAR SIGNATURE'],
            ['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],
            [],
            ['CODE','LIBELLÉ','Montant']
        ];
        data.push(['ZC18','Engagements à court terme (≤12 mois)',<?= $affichage_ct ?>]);
        data.push(['ZC19','Engagements à moyen et long termes (>12 mois)',<?= $affichage_mlt ?>]);
        data.push(['','TOTAL',<?= $total_engagements ?>]);
        const ws = XLSX.utils.aoa_to_sheet(data);
        XLSX.utils.book_append_sheet(wb, ws, "ENGAGEMENTS");
        XLSX.writeFile(wb, 'DIMF_2011_1_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);
    });
</script>
</body>
</html>