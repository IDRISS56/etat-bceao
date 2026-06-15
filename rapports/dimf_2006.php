<?php
// DIMF_2006.php - État des biens donnés en crédit-bail
// FPDF intégré, gestion POST, Bootstrap

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
// PARAMÈTRES (priorité POST > GET > défaut)
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
// TRAITEMENT DU FORMULAIRE D'AJOUT (POST)
// ============================================================
$message_ajout = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type_contrat'])) {
    $type_contrat = trim($_POST['type_contrat'] ?? '');
    $duree        = (int)($_POST['duree'] ?? 0);
    $montant_brut = (float)($_POST['montant_brut'] ?? 0);
    $date_debut   = $_POST['date_debut'] ?? '';
    $date_fin     = $_POST['date_fin'] ?? '';
    $exo_post     = (int)($_POST['exercice_form'] ?? date('Y'));

    if ($type_contrat && $montant_brut > 0 && $date_debut) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS credit_bail_contrats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                numero_contrat VARCHAR(50),
                type VARCHAR(50),
                duree INT,
                date_debut DATE,
                date_fin DATE,
                montant_brut DECIMAL(15,2) DEFAULT 0,
                valeur_nette DECIMAL(15,2) DEFAULT 0,
                statut VARCHAR(30) DEFAULT 'actif',
                exercice INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            $stmt_num = $pdo->query("SELECT COUNT(*) as n FROM credit_bail_contrats");
            $num = ($stmt_num->fetch()['n'] ?? 0) + 1;
            $numero = 'CB-' . date('Y') . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);

            $stmt_ins = $pdo->prepare("INSERT INTO credit_bail_contrats (numero_contrat, type, duree, date_debut, date_fin, montant_brut, valeur_nette, statut, exercice) VALUES (:num, :type, :duree, :dd, :df, :mb, :mb, 'actif', :exo)");
            $stmt_ins->execute([
                ':num'  => $numero,
                ':type' => $type_contrat,
                ':duree'=> $duree,
                ':dd'   => $date_debut,
                ':df'   => $date_fin ?: null,
                ':mb'   => $montant_brut,
                ':exo'  => $exo_post
            ]);
            $message_ajout = "<div class='alert-success'><i class='fas fa-check-circle'></i> Contrat $numero enregistré.</div>";
        } catch (PDOException $e) {
            $message_ajout = "<div class='alert-error'><i class='fas fa-exclamation-triangle'></i> Erreur : " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $message_ajout = "<div class='alert-error'><i class='fas fa-exclamation-triangle'></i> Champs obligatoires manquants.</div>";
    }
}

// ============================================================
// STRUCTURE DES CATÉGORIES (inchangée)
// ============================================================
$categories = [
    'ZA1' => ['code' => 'ZA1', 'libelle' => 'CRÉDIT-BAIL', 'is_parent' => true],
    'ZA2' => ['code' => 'ZA2', 'libelle' => 'Crédit-bail Mobilier', 'parent' => 'ZA1'],
    'ZA3' => ['code' => 'ZA3', 'libelle' => 'Crédit-bail Immobilier', 'parent' => 'ZA1'],
    'ZA4' => ['code' => 'ZA4', 'libelle' => 'Crédit-bail sur actifs incorporels', 'parent' => 'ZA1'],
    'ZA5' => ['code' => 'ZA5', 'libelle' => "Location avec option d'achat", 'parent' => 'ZA1'],
    'ZA6' => ['code' => 'ZA6', 'libelle' => 'LOCATION-VENTE', 'is_parent' => true],
    'ZA7' => ['code' => 'ZA7', 'libelle' => 'CRÉANCES EN SOUFFRANCE SUR OPÉRATIONS DE CRÉDIT-BAIL', 'is_parent' => true]
];

$data = [];
foreach ($categories as $key => $cat) {
    $data[$key] = [
        'code'          => $cat['code'],
        'libelle'       => $cat['libelle'],
        'duree'         => '',
        'montant_brut'  => 0.0,
        'amortissements'=> 0.0,
        'montant_net'   => 0.0,
        'is_parent'     => !empty($cat['is_parent']),
        'parent'        => $cat['parent'] ?? null
    ];
}

// Récupération depuis immobilisations
$map_types = ['ZA2' => 'MOBILIER', 'ZA3' => 'IMMOBILIER', 'ZA4' => 'INCORPOREL', 'ZA5' => 'LOA'];
try {
    $tbl_check = $pdo->query("SHOW TABLES LIKE 'immobilisations'");
    if ($tbl_check->rowCount() > 0 && $pdo->query("SHOW COLUMNS FROM immobilisations LIKE 'type_credit_bail'")->rowCount() > 0) {
        foreach ($map_types as $code => $type_val) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_achat),0) as brut, COALESCE(SUM(amortissement_total),0) as amort FROM immobilisations WHERE type_credit_bail = :type AND statut = 'actif' AND date_achat <= :date_fin");
            $stmt->execute([':type' => $type_val, ':date_fin' => $date_fin_periode]);
            $row = $stmt->fetch();
            $data[$code]['montant_brut'] = (float)$row['brut'];
            $data[$code]['amortissements'] = (float)$row['amort'];
            $data[$code]['montant_net'] = $data[$code]['montant_brut'] - $data[$code]['amortissements'];
        }
    }
} catch (PDOException $e) {}

// Récupération depuis credit_bail_contrats (ZA6)
try {
    $tbl_cb = $pdo->query("SHOW TABLES LIKE 'credit_bail_contrats'");
    if ($tbl_cb->rowCount() > 0) {
        $stmt_lv = $pdo->prepare("SELECT COALESCE(SUM(montant_brut),0) as brut, COALESCE(SUM(valeur_nette),0) as net FROM credit_bail_contrats WHERE type = 'VENTE' AND statut = 'actif' AND exercice = :exo");
        $stmt_lv->execute([':exo' => $exercice]);
        $row_lv = $stmt_lv->fetch();
        $data['ZA6']['montant_brut'] = (float)$row_lv['brut'];
        $data['ZA6']['montant_net'] = (float)$row_lv['net'];
    }
} catch (PDOException $e) {}

// Calcul des totaux
$data['ZA1']['montant_brut'] = $data['ZA2']['montant_brut'] + $data['ZA3']['montant_brut'] + $data['ZA4']['montant_brut'] + $data['ZA5']['montant_brut'];
$data['ZA1']['amortissements'] = $data['ZA2']['amortissements'] + $data['ZA3']['amortissements'] + $data['ZA4']['amortissements'] + $data['ZA5']['amortissements'];
$data['ZA1']['montant_net'] = $data['ZA2']['montant_net'] + $data['ZA3']['montant_net'] + $data['ZA4']['montant_net'] + $data['ZA5']['montant_net'];
$total_general_brut = $data['ZA1']['montant_brut'] + $data['ZA6']['montant_brut'] + $data['ZA7']['montant_brut'];
$total_general_amor = $data['ZA1']['amortissements'] + $data['ZA6']['amortissements'] + $data['ZA7']['amortissements'];
$total_general_net = $data['ZA1']['montant_net'] + $data['ZA6']['montant_net'] + $data['ZA7']['montant_net'];

// Détail des contrats
$details_contrats = [];
try {
    if ($pdo->query("SHOW TABLES LIKE 'credit_bail_contrats'")->rowCount() > 0) {
        $stmt_d = $pdo->prepare("SELECT * FROM credit_bail_contrats WHERE exercice = :exo ORDER BY date_debut DESC");
        $stmt_d->execute([':exo' => $exercice]);
        $details_contrats = $stmt_d->fetchAll();
    }
} catch (PDOException $e) {}

// ============================================================
// GÉNÉRATION PDF (si format=pdf) – support POST
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
        ['label' => 'CODE',         'w' => 20],
        ['label' => 'LIBELLÉS',     'w' => 110],
        ['label' => 'Durée (mois)', 'w' => 25, 'align' => 'R'],
        ['label' => 'Brut (FCFA)',  'w' => 45, 'align' => 'R'],
        ['label' => 'Amort. (FCFA)','w' => 45, 'align' => 'R'],
        ['label' => 'Net (FCFA)',   'w' => 45, 'align' => 'R'],
    ];
    $pdf->SectionTitle('Crédit-bail et opérations assimilées');
    $pdf->TableHeader($cols);
    foreach ($data as $item) {
        if ($item['is_parent']) {
            $pdf->TableRow($cols, [
                $item['code'],
                $item['libelle'],
                '-',
                $item['montant_brut'] > 0 ? PDF_DIMF::montant($item['montant_brut']) : '-',
                $item['amortissements'] > 0 ? PDF_DIMF::montant($item['amortissements']) : '-',
                $item['montant_net'] > 0 ? PDF_DIMF::montant($item['montant_net']) : '-'
            ], 'subtotal');
        } else {
            $pdf->TableRow($cols, [
                $item['code'],
                $item['libelle'],
                $item['duree'] ?: '-',
                PDF_DIMF::montant($item['montant_brut']),
                PDF_DIMF::montant($item['amortissements']),
                PDF_DIMF::montant($item['montant_net'])
            ]);
        }
    }
    $pdf->TableRow($cols, [
        'TOTAL', 'TOTAL GÉNÉRAL', '',
        PDF_DIMF::montant($total_general_brut),
        PDF_DIMF::montant($total_general_amor),
        PDF_DIMF::montant($total_general_net)
    ], 'total');

    $pdf->Output('I', 'DIMF_2006_CreditBail_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}
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
        .subtotal-row { background:#f8fafc; font-weight:600; }
        .total-row { background:#f0fdf4; font-weight:700; border-top:2px solid #bbf7d0; }
        .parent-row { background:#f8fafc; font-weight:600; }
        .child-indent { padding-left:30px; }
        .alert-success, .alert-error { padding:12px 16px; border-radius:16px; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .alert-success { background:#d1fae5; color:#065f46; }
        .alert-error { background:#fee2e2; color:#991b1b; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px 20px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .page-footer, #filtersCard { display:none; } }
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

    <?= $message_ajout ?>

    <div class="card">
        <div class="card-header"><i class="fas fa-building"></i> CRÉDIT-BAIL ET OPÉRATIONS ASSIMILÉES</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>CODE</th><th>LIBELLÉS</th><th class="text-right">Durée (mois)</th><th class="text-right">Brut (FCFA)</th><th class="text-right">Amort. (FCFA)</th><th class="text-right">Net (FCFA)</th></table>
                </thead>
                <tbody>
                    <?php foreach ($data as $item): ?>
                        <?php if ($item['is_parent']): ?>
                            <tr class="parent-row"><td><strong><?= htmlspecialchars($item['code']) ?></strong></td>
                            <td><strong><?= htmlspecialchars($item['libelle']) ?></strong></td>
                            <td class="text-right">-</td>
                            <td class="text-right"><?= $item['montant_brut']>0?number_format($item['montant_brut'],0,',',' '):'-' ?> </td>
                            <td class="text-right"><?= $item['amortissements']>0?number_format($item['amortissements'],0,',',' '):'-' ?> </td>
                            <td class="text-right"><?= $item['montant_net']>0?number_format($item['montant_net'],0,',',' '):'-' ?> </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td><?= htmlspecialchars($item['code']) ?></td>
                                <td class="child-indent"><?= htmlspecialchars($item['libelle']) ?></td>
                                <td class="text-right"><?= $item['duree']?:'-' ?></td>
                                <td class="text-right"><?= number_format($item['montant_brut'],0,',',' ') ?></td>
                                <td class="text-right"><?= number_format($item['amortissements'],0,',',' ') ?></td>
                                <td class="text-right"><?= number_format($item['montant_net'],0,',',' ') ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="3"><strong>TOTAL GÉNÉRAL</strong></td>
                        <td class="text-right"><strong><?= number_format($total_general_brut,0,',',' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_general_amor,0,',',' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format($total_general_net,0,',',' ') ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-file-contract"></i> DÉTAIL DES CONTRATS - Exercice <?= $exercice ?></div>
        <?php if(empty($details_contrats)): ?>
            <div class="info-box">Aucun contrat enregistré.</div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>N° Contrat</th><th>Type</th><th class="text-right">Durée</th><th>Date début</th><th>Date fin</th><th class="text-right">Montant brut</th><th class="text-right">Valeur nette</th><th>Statut</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($details_contrats as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['numero_contrat']) ?></td>
                                <td><?= htmlspecialchars($c['type']) ?></td>
                                <td class="text-right"><?= $c['duree'] ?> mois</td>
                                <td><?= date('d/m/Y',strtotime($c['date_debut'])) ?></td>
                                <td><?= $c['date_fin']?date('d/m/Y',strtotime($c['date_fin'])):'-' ?></td>
                                <td class="text-right"><?= number_format($c['montant_brut'],0,',',' ') ?></td>
                                <td class="text-right"><?= number_format($c['valeur_nette'],0,',',' ') ?></td>
                                <td><?= $c['statut'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-plus-circle"></i> AJOUTER UN CONTRAT</div>
        <form method="post">
            <input type="hidden" name="exercice_form" value="<?= $exercice ?>">
            <div class="filters-row" style="margin-bottom:0;">
                <div class="filter-item">
                    <label>Type *</label>
                    <select name="type_contrat" required>
                        <option value="">-- Sélectionner --</option>
                        <option value="MOBILIER">Crédit-bail Mobilier</option>
                        <option value="IMMOBILIER">Crédit-bail Immobilier</option>
                        <option value="INCORPOREL">Crédit-bail sur actifs incorporels</option>
                        <option value="LOA">Location avec option d'achat</option>
                        <option value="VENTE">Location-vente</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Durée (mois)</label>
                    <input type="number" name="duree">
                </div>
                <div class="filter-item">
                    <label>Montant brut *</label>
                    <input type="number" name="montant_brut" required>
                </div>
                <div class="filter-item">
                    <label>Date début *</label>
                    <input type="date" name="date_debut" required>
                </div>
                <div class="filter-item">
                    <label>Date fin</label>
                    <input type="date" name="date_fin">
                </div>
                <div class="filter-item">
                    <button type="submit" class="btn-apply">💾 Enregistrer</button>
                </div>
            </div>
        </form>
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
        form.target = '_blank';
        form.submit();
        form.target = '';
        form.removeChild(input);
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        const data = [['DIMF_2006 - CRÉDIT-BAIL'],['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],[],['CODE','LIBELLÉ','Durée','Brut','Amortissements','Net']];
        <?php foreach($data as $item): ?>
        data.push(['<?= $item['code'] ?>','<?= addslashes($item['libelle']) ?>','<?= $item['duree']?:'-' ?>',<?= $item['montant_brut'] ?>,<?= $item['amortissements'] ?>,<?= $item['montant_net'] ?>]);
        <?php endforeach; ?>
        data.push(['TOTAL','TOTAL GÉNÉRAL','',<?= $total_general_brut ?>,<?= $total_general_amor ?>,<?= $total_general_net ?>]);
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