<?php
// DIMF_2006.php - État des biens donnés en crédit-bail
// Alimentation depuis immobilisations + saisie manuelle pour ZA6 et ZA7

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
        $this->SetTextColor(15, 23, 42);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.1);
        switch ($style) {
            case 'subtotal':
                $this->SetFillColor(248, 250, 252);
                $this->SetFont('Arial', 'B', 8);
                $fill = true;
                break;
            case 'total':
                $this->SetFillColor(240, 253, 244);
                $this->SetFont('Arial', 'B', 8.5);
                $fill = true;
                break;
            default:
                $this->SetFillColor(255, 255, 255);
                $this->SetFont('Arial', '', 7.5);
                $fill = false;
                break;
        }
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

// ============================================================
// TRAITEMENT DU FORMULAIRE DE SAISIE (POST)
// ============================================================
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_credit_bail') {
    $codes = $_POST['code'] ?? [];
    $bruts = $_POST['montant_brut'] ?? [];
    $amorts = $_POST['amortissements'] ?? [];
    $nets = $_POST['montant_net'] ?? [];
    $durees = $_POST['duree'] ?? [];
    $libelles = $_POST['libelle'] ?? [];

    foreach ($codes as $index => $code) {
        $code = trim($code);
        if (empty($code)) continue;
        $brut = (float)($bruts[$index] ?? 0);
        $amort = (float)($amorts[$index] ?? 0);
        $net = (float)($nets[$index] ?? 0);
        $duree = trim($durees[$index] ?? '');
        $libelle = trim($libelles[$index] ?? '');

        $stmt_check = $pdo->prepare("SELECT id FROM z_bceao_credit_bail WHERE exercice = :exo AND code = :code");
        $stmt_check->execute([':exo' => $exercice, ':code' => $code]);
        if ($stmt_check->fetch()) {
            $stmt_upd = $pdo->prepare("UPDATE z_bceao_credit_bail SET 
                libelle = :libelle,
                duree = :duree,
                montant_brut = :brut,
                amortissements_provisions = :amort,
                montant_net = :net,
                statut = 'actif'
                WHERE exercice = :exo AND code = :code");
            $stmt_upd->execute([
                ':libelle' => $libelle,
                ':duree' => $duree,
                ':brut' => $brut,
                ':amort' => $amort,
                ':net' => $net,
                ':exo' => $exercice,
                ':code' => $code
            ]);
        } else {
            $stmt_ins = $pdo->prepare("INSERT INTO z_bceao_credit_bail 
                (exercice, code, libelle, duree, montant_brut, amortissements_provisions, montant_net, statut)
                VALUES (:exo, :code, :libelle, :duree, :brut, :amort, :net, 'actif')");
            $stmt_ins->execute([
                ':exo' => $exercice,
                ':code' => $code,
                ':libelle' => $libelle,
                ':duree' => $duree,
                ':brut' => $brut,
                ':amort' => $amort,
                ':net' => $net
            ]);
        }
    }
    $message = "<div class='alert-success'><i class='fas fa-check-circle'></i> Données enregistrées avec succès.</div>";
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES DEPUIS immobilisations POUR ZA2 à ZA5
// ============================================================
$map_types = [
    'ZA2' => ['type' => 'MOBILIER', 'libelle' => 'Crédit-bail Mobilier'],
    'ZA3' => ['type' => 'IMMOBILIER', 'libelle' => 'Crédit-bail Immobilier'],
    'ZA4' => ['type' => 'INCORPOREL', 'libelle' => 'Crédit-bail sur actifs incorporels'],
    'ZA5' => ['type' => 'LOA', 'libelle' => 'Location avec option d\'achat']
];

$data_from_immobilisations = [];
$total_brut_auto = 0;
$total_amort_auto = 0;
$total_net_auto = 0;

try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM immobilisations LIKE 'type_credit_bail'");
    if ($colCheck->rowCount() > 0) {
        foreach ($map_types as $code => $info) {
            $stmt = $pdo->prepare("SELECT 
                COALESCE(SUM(montant_achat), 0) as brut,
                COALESCE(SUM(amortissement_total), 0) as amort
                FROM immobilisations 
                WHERE type_credit_bail = :type AND statut = 'actif'");
            $stmt->execute([':type' => $info['type']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $brut = (float)$row['brut'];
            $amort = (float)$row['amort'];
            $net = $brut - $amort;
            $data_from_immobilisations[$code] = [
                'brut' => $brut,
                'amort' => $amort,
                'net' => $net,
                'libelle' => $info['libelle']
            ];
            $total_brut_auto += $brut;
            $total_amort_auto += $amort;
            $total_net_auto += $net;
        }
    } else {
        foreach ($map_types as $code => $info) {
            $data_from_immobilisations[$code] = [
                'brut' => 0,
                'amort' => 0,
                'net' => 0,
                'libelle' => $info['libelle']
            ];
        }
    }
} catch (PDOException $e) {
    // ignore
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES EXISTANTES DANS z_bceao_credit_bail
// ============================================================
$concessions_exist = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM z_bceao_credit_bail WHERE exercice = :exo ORDER BY code");
    $stmt->execute([':exo' => $exercice]);
    $concessions_exist = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // ignore
}

$data_by_code = [];
foreach ($concessions_exist as $row) {
    $data_by_code[$row['code']] = $row;
}

// ============================================================
// DÉFINITION DES LIGNES ET CONSTRUCTION DU TABLEAU
// ============================================================
$codes = ['ZA1', 'ZA2', 'ZA3', 'ZA4', 'ZA5', 'ZA6', 'ZA7'];
$libelles = [
    'ZA1' => 'CRÉDIT-BAIL (total des ZA2 à ZA5)',
    'ZA2' => 'Crédit-bail Mobilier',
    'ZA3' => 'Crédit-bail Immobilier',
    'ZA4' => 'Crédit-bail sur actifs incorporels',
    'ZA5' => 'Location avec option d\'achat',
    'ZA6' => 'LOCATION-VENTE',
    'ZA7' => 'CRÉANCES EN SOUFFRANCE SUR OPÉRATIONS DE CRÉDIT-BAIL ET ASSIMILÉES'
];

$table_data = [];
foreach ($codes as $code) {
    $is_parent = in_array($code, ['ZA1', 'ZA6', 'ZA7']);
    if ($code == 'ZA1') {
        $brut = $total_brut_auto;
        $amort = $total_amort_auto;
        $net = $total_net_auto;
        $duree = '';
    } elseif (in_array($code, ['ZA2','ZA3','ZA4','ZA5'])) {
        $brut = $data_from_immobilisations[$code]['brut'] ?? 0;
        $amort = $data_from_immobilisations[$code]['amort'] ?? 0;
        $net = $data_from_immobilisations[$code]['net'] ?? 0;
        $duree = '';
    } else {
        $brut = isset($data_by_code[$code]) ? (float)$data_by_code[$code]['montant_brut'] : 0;
        $amort = isset($data_by_code[$code]) ? (float)$data_by_code[$code]['amortissements_provisions'] : 0;
        $net = isset($data_by_code[$code]) ? (float)$data_by_code[$code]['montant_net'] : 0;
        $duree = isset($data_by_code[$code]) ? $data_by_code[$code]['duree'] : '';
    }
    $table_data[$code] = [
        'code' => $code,
        'libelle' => $libelles[$code],
        'duree' => $duree,
        'montant_brut' => $brut,
        'amortissements' => $amort,
        'montant_net' => $net,
        'is_parent' => $is_parent
    ];
}

// Totaux généraux
$total_brut = $table_data['ZA1']['montant_brut'] + $table_data['ZA6']['montant_brut'] + $table_data['ZA7']['montant_brut'];
$total_amort = $table_data['ZA1']['amortissements'] + $table_data['ZA6']['amortissements'] + $table_data['ZA7']['amortissements'];
$total_net = $table_data['ZA1']['montant_net'] + $table_data['ZA6']['montant_net'] + $table_data['ZA7']['montant_net'];

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

    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->codeDimf  = 'DIMF_2006';
    $pdf->titreDimf = 'Biens donnés en crédit-bail';
    $pdf->nomSfd    = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode   = $lib_periode;
    $pdf->exercice  = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'CODE', 'w' => 20],
        ['label' => 'LIBELLÉS', 'w' => 110],
        ['label' => 'Durée', 'w' => 25, 'align' => 'R'],
        ['label' => 'Montants Bruts', 'w' => 45, 'align' => 'R'],
        ['label' => 'Amortissements/Provisions', 'w' => 45, 'align' => 'R'],
        ['label' => 'Montants nets', 'w' => 45, 'align' => 'R'],
    ];
    $pdf->SectionTitle('Crédit-bail et opérations assimilées');
    $pdf->TableHeader($cols);

    foreach ($codes as $code) {
        $item = $table_data[$code];
        if ($item['is_parent']) {
            $pdf->TableRow($cols, [
                $item['code'],
                $item['libelle'],
                $item['duree'],
                PDF_DIMF::montant($item['montant_brut']),
                PDF_DIMF::montant($item['amortissements']),
                PDF_DIMF::montant($item['montant_net'])
            ], 'subtotal');
        } else {
            $pdf->TableRow($cols, [
                $item['code'],
                $item['libelle'],
                $item['duree'],
                PDF_DIMF::montant($item['montant_brut']),
                PDF_DIMF::montant($item['amortissements']),
                PDF_DIMF::montant($item['montant_net'])
            ]);
        }
    }

    $pdf->TableRow($cols, [
        '',
        'TOTAL',
        '',
        PDF_DIMF::montant($total_brut),
        PDF_DIMF::montant($total_amort),
        PDF_DIMF::montant($total_net)
    ], 'total');

    $pdf->Output('I', 'DIMF_2006_CreditBail_' . $exercice . '_' . $type_periode . '.pdf');
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
    <title>DIMF_2006 - Crédit-bail</title>
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
        .total-row { background:#f0fdf4; font-weight:700; border-top:2px solid #bbf7d0; }
        .parent-row { background:#f8fafc; font-weight:600; }
        .child-indent { padding-left:30px; }
        .alert-success, .alert-error { padding:12px 16px; border-radius:16px; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .alert-success { background:#d1fae5; color:#065f46; }
        .alert-error { background:#fee2e2; color:#991b1b; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px 20px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .page-footer, #filtersCard { display:none; } }
        .form-saisie input[type="number"] { width:120px; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-handshake"></i> DIMF_2006 - CRÉDIT-BAIL</h1>
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

    <?= $message ?>

    <!-- ===== FORMULAIRE DE SAISIE ===== -->
    <div class="card">
        <div class="card-header"><i class="fas fa-pen"></i> Saisie des montants pour DIMF_2006</div>
        <form method="post">
            <input type="hidden" name="action" value="save_credit_bail">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>CODE</th>
                            <th>LIBELLÉS</th>
                            <th>Durée</th>
                            <th class="text-right">Montants Bruts</th>
                            <th class="text-right">Amortissements/Provisions</th>
                            <th class="text-right">Montants nets</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($codes as $code): ?>
                            <?php
                                if ($code == 'ZA1') continue; // ZA1 est calculé automatiquement
                                $item = $table_data[$code];
                                $is_auto = in_array($code, ['ZA2','ZA3','ZA4','ZA5']);
                                $readonly = $is_auto ? 'readonly' : '';
                                $style = ($code == 'ZA1') ? 'parent-row' : '';
                            ?>
                            <tr class="<?= $style ?>">
                                <td><strong><?= $code ?></strong></td>
                                <td class="child-indent"><?= htmlspecialchars($item['libelle']) ?></td>
                                <td>
                                    <input type="text" name="duree[]" value="<?= htmlspecialchars($item['duree']) ?>" class="form-control form-control-sm" placeholder="Durée" style="width:80px;">
                                </td>
                                <td>
                                    <input type="number" name="montant_brut[]" value="<?= $item['montant_brut'] ?>" step="0.01" class="form-control form-control-sm text-right" <?= $readonly ?> style="width:130px;">
                                </td>
                                <td>
                                    <input type="number" name="amortissements[]" value="<?= $item['amortissements'] ?>" step="0.01" class="form-control form-control-sm text-right" <?= $readonly ?> style="width:130px;">
                                </td>
                                <td>
                                    <input type="number" name="montant_net[]" value="<?= $item['montant_net'] ?>" step="0.01" class="form-control form-control-sm text-right" <?= $readonly ?> style="width:130px;">
                                </td>
                            </tr>
                            <input type="hidden" name="code[]" value="<?= $code ?>">
                            <input type="hidden" name="libelle[]" value="<?= htmlspecialchars($item['libelle']) ?>">
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn-save" style="background:#10b981; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer;"><i class="fas fa-save"></i> Enregistrer</button>
                <span class="text-muted ms-3">(les champs en lecture seule sont calculés automatiquement)</span>
            </div>
        </form>
    </div>

    <!-- ===== TABLEAU RÉCAPITULATIF ===== -->
    <div class="card">
        <div class="card-header"><i class="fas fa-list-ul"></i> ÉTAT DES BIENS DONNÉS EN CRÉDIT-BAIL</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>CODE</th>
                        <th>LIBELLÉS</th>
                        <th class="text-right">Durée</th>
                        <th class="text-right">Montants Bruts</th>
                        <th class="text-right">Amortissements/Provisions</th>
                        <th class="text-right">Montants nets</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($codes as $code): ?>
                        <?php $item = $table_data[$code]; ?>
                        <?php if ($item['is_parent']): ?>
                            <tr class="parent-row">
                                <td><strong><?= htmlspecialchars($item['code']) ?></strong></td>
                                <td><strong><?= htmlspecialchars($item['libelle']) ?></strong></td>
                                <td class="text-right"><?= $item['duree'] ?: '-' ?></td>
                                <td class="text-right"><?= $item['montant_brut']>0 ? number_format($item['montant_brut'],0,',',' ') : '-' ?></td>
                                <td class="text-right"><?= $item['amortissements']>0 ? number_format($item['amortissements'],0,',',' ') : '-' ?></td>
                                <td class="text-right"><?= $item['montant_net']>0 ? number_format($item['montant_net'],0,',',' ') : '-' ?></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td><?= htmlspecialchars($item['code']) ?></td>
                                <td class="child-indent"><?= htmlspecialchars($item['libelle']) ?></td>
                                <td class="text-right"><?= $item['duree'] ?: '-' ?></td>
                                <td class="text-right"><?= number_format($item['montant_brut'],0,',',' ') ?></td>
                                <td class="text-right"><?= number_format($item['amortissements'],0,',',' ') ?></td>
                                <td class="text-right"><?= number_format($item['montant_net'],0,',',' ') ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="2"><strong>TOTAL</strong></td>
                        <td class="text-right">-</td>
                        <td class="text-right"><strong><?= number_format($total_brut,0,',',' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_amort,0,',',' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_net,0,',',' ') ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="page-footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>) – Données issues des tables <code>immobilisations</code> et <code>z_bceao_credit_bail</code>
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
            ['DIMF_2006 - CRÉDIT-BAIL'],
            ['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],
            [],
            ['CODE','LIBELLÉS','Durée','Montants Bruts','Amortissements/Provisions','Montants nets']
        ];
        <?php foreach ($codes as $code): $item = $table_data[$code]; ?>
            data.push([
                '<?= addslashes($item['code']) ?>',
                '<?= addslashes($item['libelle']) ?>',
                '<?= addslashes($item['duree']) ?>',
                <?= $item['montant_brut'] ?>,
                <?= $item['amortissements'] ?>,
                <?= $item['montant_net'] ?>
            ]);
        <?php endforeach; ?>
        data.push(['TOTAL','','',<?= $total_brut ?>,<?= $total_amort ?>,<?= $total_net ?>]);
        const ws = XLSX.utils.aoa_to_sheet(data);
        XLSX.utils.book_append_sheet(wb, ws, "CREDIT_BAIL");
        XLSX.writeFile(wb, 'DIMF_2006_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);
    });
</script>
</body>
</html>