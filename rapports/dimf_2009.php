<?php
// dimf_2009.php - Personnel extérieur (compte 6221)
// Version avec gestion des lignes ZB1 à ZB8, table z_bceao_personnel_exterieur
// Pas de création de nouvelle table

session_start();

require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// Vérification de l'existence de la table
try {
    $pdo->query("SELECT 1 FROM z_bceao_personnel_exterieur LIMIT 1");
} catch (PDOException $e) {
    die("<div style='color:red;font-weight:bold;padding:20px;'>
            La table 'z_bceao_personnel_exterieur' est introuvable.<br>
            Veuillez la créer avec le script SQL fourni.
         </div>");
}

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
            $this->SetFillColor(255, 255, 255);
            $this->SetFont('Arial', '', 7.5);
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

// ============================================================
// PARAMÈTRES
// ============================================================
$exercice     = isset($_POST['exercice'])     ? (int)$_POST['exercice']     : date('Y');
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode']      : 'mensuel';
$mois         = isset($_POST['mois'])         ? (int)$_POST['mois']         : 12;
$trimestre    = isset($_POST['trimestre'])    ? (int)$_POST['trimestre']    : 4;
$semestre     = isset($_POST['semestre'])     ? (int)$_POST['semestre']     : null;
$format       = isset($_POST['format'])       ? $_POST['format']            : 'html';

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
}

// Libellé de la période
switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Année ' . $exercice;
}

// ============================================================
// DÉFINITION DES CATÉGORIES (incluant ZB6 et ZB7)
// ============================================================
$categories = [
    'ZB1' => '1. Cadres Supérieurs',
    'ZB2' => '2. Techniciens Supérieurs et cadres moyens',
    'ZB3' => '3. Techniciens Agents de Maîtrise et ouvriers qualifiés',
    'ZB4' => '4. Employés, manœuvres, ouvriers et apprentis',
    'ZB6' => 'PERMANENTS',
    'ZB7' => 'SAISONNIERS'
];

// ============================================================
// TRAITEMENT POST (SAUVEGARDE)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {
    try {
        // Supprimer les données existantes pour l'exercice (toutes catégories)
        $stmtDel = $pdo->prepare("DELETE FROM z_bceao_personnel_exterieur WHERE exercice = :exercice");
        $stmtDel->execute([':exercice' => $exercice]);

        $stmtIns = $pdo->prepare("INSERT INTO z_bceao_personnel_exterieur 
            (exercice, categorie, libelle, nationaux, autre_umoa, hors_umoa, 
             secteur_primaire, secteur_secondaire, secteur_tertiaire, total_effectif, facturation, statut)
            VALUES (:exercice, :categorie, :libelle, :nationaux, :autre_umoa, :hors_umoa,
                    :secteur_primaire, :secteur_secondaire, :secteur_tertiaire, :total_effectif, :facturation, 'actif')");

        foreach ($categories as $code => $lib) {
            $nationaux       = (int)($_POST[$code . '_nationaux'] ?? 0);
            $autre_umoa      = (int)($_POST[$code . '_autre_umoa'] ?? 0);
            $hors_umoa       = (int)($_POST[$code . '_hors_umoa'] ?? 0);
            $secteur_primaire  = (int)($_POST[$code . '_secteur_primaire'] ?? 0);
            $secteur_secondaire = (int)($_POST[$code . '_secteur_secondaire'] ?? 0);
            $secteur_tertiaire = (int)($_POST[$code . '_secteur_tertiaire'] ?? 0);
            $total_effectif  = $nationaux + $autre_umoa + $hors_umoa;
            $facturation     = (float)($_POST[$code . '_facturation'] ?? 0);

            $stmtIns->execute([
                ':exercice' => $exercice,
                ':categorie' => $code,
                ':libelle' => $lib,
                ':nationaux' => $nationaux,
                ':autre_umoa' => $autre_umoa,
                ':hors_umoa' => $hors_umoa,
                ':secteur_primaire' => $secteur_primaire,
                ':secteur_secondaire' => $secteur_secondaire,
                ':secteur_tertiaire' => $secteur_tertiaire,
                ':total_effectif' => $total_effectif,
                ':facturation' => $facturation
            ]);
        }
        $_SESSION['flash_message'] = "Données enregistrées avec succès !";
        $_SESSION['flash_type'] = "success";
    } catch (PDOException $e) {
        $_SESSION['flash_message'] = "Erreur : " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
    }
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// ============================================================
// LECTURE DU MESSAGE FLASH
// ============================================================
$message = $_SESSION['flash_message'] ?? '';
$message_type = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// ============================================================
// RÉCUPÉRATION DES DONNÉES ENREGISTRÉES
// ============================================================
$personnel_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM z_bceao_personnel_exterieur WHERE exercice = :exercice AND statut = 'actif'");
    $stmt->execute([':exercice' => $exercice]);
    foreach ($stmt->fetchAll() as $row) {
        $personnel_data[$row['categorie']] = $row;
    }
} catch (PDOException $e) {}

// ============================================================
// CALCUL DES TOTAUX (ZB5 et ZB8)
// ============================================================
$totaux_zb1_4 = [
    'nationaux' => 0,
    'autre_umoa' => 0,
    'hors_umoa' => 0,
    'secteur_primaire' => 0,
    'secteur_secondaire' => 0,
    'secteur_tertiaire' => 0,
    'total_effectif' => 0,
    'facturation' => 0
];
$totaux_zb6_7 = [
    'nationaux' => 0,
    'autre_umoa' => 0,
    'hors_umoa' => 0,
    'secteur_primaire' => 0,
    'secteur_secondaire' => 0,
    'secteur_tertiaire' => 0,
    'total_effectif' => 0,
    'facturation' => 0
];

foreach ($categories as $code => $lib) {
    $d = $personnel_data[$code] ?? null;
    if (!$d) continue;
    if (in_array($code, ['ZB1','ZB2','ZB3','ZB4'])) {
        $totaux_zb1_4['nationaux'] += (int)$d['nationaux'];
        $totaux_zb1_4['autre_umoa'] += (int)$d['autre_umoa'];
        $totaux_zb1_4['hors_umoa'] += (int)$d['hors_umoa'];
        $totaux_zb1_4['secteur_primaire'] += (int)$d['secteur_primaire'];
        $totaux_zb1_4['secteur_secondaire'] += (int)$d['secteur_secondaire'];
        $totaux_zb1_4['secteur_tertiaire'] += (int)$d['secteur_tertiaire'];
        $totaux_zb1_4['total_effectif'] += (int)$d['total_effectif'];
        $totaux_zb1_4['facturation'] += (float)$d['facturation'];
    } elseif (in_array($code, ['ZB6','ZB7'])) {
        $totaux_zb6_7['nationaux'] += (int)$d['nationaux'];
        $totaux_zb6_7['autre_umoa'] += (int)$d['autre_umoa'];
        $totaux_zb6_7['hors_umoa'] += (int)$d['hors_umoa'];
        $totaux_zb6_7['secteur_primaire'] += (int)$d['secteur_primaire'];
        $totaux_zb6_7['secteur_secondaire'] += (int)$d['secteur_secondaire'];
        $totaux_zb6_7['secteur_tertiaire'] += (int)$d['secteur_tertiaire'];
        $totaux_zb6_7['total_effectif'] += (int)$d['total_effectif'];
        $totaux_zb6_7['facturation'] += (float)$d['facturation'];
    }
}

// ============================================================
// EXPORT PDF
// ============================================================
if ($format === 'pdf') {
    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->codeDimf  = 'DIMF_2009';
    $pdf->titreDimf = 'Personnel extérieur (compte 6221)';
    $pdf->nomSfd    = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode   = $lib_periode;
    $pdf->exercice  = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'Code',          'w' => 15],
        ['label' => 'Catégorie',     'w' => 45],
        ['label' => 'Nationaux',     'w' => 20, 'align' => 'R'],
        ['label' => 'Autres UMOA',   'w' => 22, 'align' => 'R'],
        ['label' => 'Hors UMOA',     'w' => 22, 'align' => 'R'],
        ['label' => 'Primaire',      'w' => 20, 'align' => 'R'],
        ['label' => 'Secondaire',    'w' => 22, 'align' => 'R'],
        ['label' => 'Tertiaire',     'w' => 22, 'align' => 'R'],
        ['label' => 'Total eff.',    'w' => 25, 'align' => 'R'],
        ['label' => 'Facturation',   'w' => 35, 'align' => 'R']
    ];
    $pdf->SectionTitle('Personnel extérieur');
    $pdf->TableHeader($cols);

    // Lignes ZB1 à ZB4
    foreach (['ZB1','ZB2','ZB3','ZB4'] as $code) {
        $d = $personnel_data[$code] ?? [
            'nationaux' => 0, 'autre_umoa' => 0, 'hors_umoa' => 0,
            'secteur_primaire' => 0, 'secteur_secondaire' => 0, 'secteur_tertiaire' => 0,
            'total_effectif' => 0, 'facturation' => 0
        ];
        $pdf->TableRow($cols, [
            $code,
            PDF_DIMF::u($categories[$code]),
            $d['nationaux'],
            $d['autre_umoa'],
            $d['hors_umoa'],
            $d['secteur_primaire'],
            $d['secteur_secondaire'],
            $d['secteur_tertiaire'],
            $d['total_effectif'],
            PDF_DIMF::montant($d['facturation'])
        ]);
    }
    // Ligne ZB5 (Total ZB1-ZB4)
    $pdf->TableRow($cols, [
        'ZB5',
        'TOTAL (ZB1-ZB4)',
        $totaux_zb1_4['nationaux'],
        $totaux_zb1_4['autre_umoa'],
        $totaux_zb1_4['hors_umoa'],
        $totaux_zb1_4['secteur_primaire'],
        $totaux_zb1_4['secteur_secondaire'],
        $totaux_zb1_4['secteur_tertiaire'],
        $totaux_zb1_4['total_effectif'],
        PDF_DIMF::montant($totaux_zb1_4['facturation'])
    ], 'subtotal');

    // Lignes ZB6 et ZB7
    foreach (['ZB6','ZB7'] as $code) {
        $d = $personnel_data[$code] ?? [
            'nationaux' => 0, 'autre_umoa' => 0, 'hors_umoa' => 0,
            'secteur_primaire' => 0, 'secteur_secondaire' => 0, 'secteur_tertiaire' => 0,
            'total_effectif' => 0, 'facturation' => 0
        ];
        $pdf->TableRow($cols, [
            $code,
            PDF_DIMF::u($categories[$code]),
            $d['nationaux'],
            $d['autre_umoa'],
            $d['hors_umoa'],
            $d['secteur_primaire'],
            $d['secteur_secondaire'],
            $d['secteur_tertiaire'],
            $d['total_effectif'],
            PDF_DIMF::montant($d['facturation'])
        ]);
    }
    // Ligne ZB8 (Total ZB6+ZB7)
    $pdf->TableRow($cols, [
        'ZB8',
        'TOTAL (Permanents + Saisonniers)',
        $totaux_zb6_7['nationaux'],
        $totaux_zb6_7['autre_umoa'],
        $totaux_zb6_7['hors_umoa'],
        $totaux_zb6_7['secteur_primaire'],
        $totaux_zb6_7['secteur_secondaire'],
        $totaux_zb6_7['secteur_tertiaire'],
        $totaux_zb6_7['total_effectif'],
        PDF_DIMF::montant($totaux_zb6_7['facturation'])
    ], 'total');

    $pdf->Output('I', 'DIMF_2009_Personnel_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL
// ============================================================
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="DIMF_2009_' . $exercice . '.xls"');
    echo '<html><head><meta charset="UTF-8"><style> body { font-family: Arial; } td { text-align: right; } .text-left { text-align: left; } </style></head><body>';
    echo '<h2>DIMF_2009 - Personnel extérieur</h2>';
    echo '<p>Période : ' . htmlspecialchars($lib_periode) . '</p>';
    echo '<table border="1"><thead><tr><th>Code</th><th>Catégorie</th><th>Nationaux</th><th>Autres UMOA</th><th>Hors UMOA</th><th>Primaire</th><th>Secondaire</th><th>Tertiaire</th><th>Total effectif</th><th>Facturation (FCFA)</th></tr></thead><tbody>';

    // ZB1-ZB4
    foreach (['ZB1','ZB2','ZB3','ZB4'] as $code) {
        $d = $personnel_data[$code] ?? [
            'nationaux' => 0, 'autre_umoa' => 0, 'hors_umoa' => 0,
            'secteur_primaire' => 0, 'secteur_secondaire' => 0, 'secteur_tertiaire' => 0,
            'total_effectif' => 0, 'facturation' => 0
        ];
        echo '<tr><td>' . $code . '</td><td class="text-left">' . htmlspecialchars($categories[$code]) . '</td>';
        echo '<td>' . $d['nationaux'] . '</td><td>' . $d['autre_umoa'] . '</td><td>' . $d['hors_umoa'] . '</td>';
        echo '<td>' . $d['secteur_primaire'] . '</td><td>' . $d['secteur_secondaire'] . '</td><td>' . $d['secteur_tertiaire'] . '</td>';
        echo '<td>' . $d['total_effectif'] . '</td><td>' . number_format($d['facturation'],0,',',' ') . '</td></tr>';
    }
    // ZB5
    echo '<tr style="background:#f3f4f6;"><td>ZB5</td><td><strong>TOTAL (ZB1-ZB4)</strong></td>';
    echo '<td>' . $totaux_zb1_4['nationaux'] . '</td><td>' . $totaux_zb1_4['autre_umoa'] . '</td><td>' . $totaux_zb1_4['hors_umoa'] . '</td>';
    echo '<td>' . $totaux_zb1_4['secteur_primaire'] . '</td><td>' . $totaux_zb1_4['secteur_secondaire'] . '</td><td>' . $totaux_zb1_4['secteur_tertiaire'] . '</td>';
    echo '<td>' . $totaux_zb1_4['total_effectif'] . '</td><td>' . number_format($totaux_zb1_4['facturation'],0,',',' ') . '</td></tr>';

    // ZB6-ZB7
    foreach (['ZB6','ZB7'] as $code) {
        $d = $personnel_data[$code] ?? [
            'nationaux' => 0, 'autre_umoa' => 0, 'hors_umoa' => 0,
            'secteur_primaire' => 0, 'secteur_secondaire' => 0, 'secteur_tertiaire' => 0,
            'total_effectif' => 0, 'facturation' => 0
        ];
        echo '<tr><td>' . $code . '</td><td class="text-left">' . htmlspecialchars($categories[$code]) . '</td>';
        echo '<td>' . $d['nationaux'] . '</td><td>' . $d['autre_umoa'] . '</td><td>' . $d['hors_umoa'] . '</td>';
        echo '<td>' . $d['secteur_primaire'] . '</td><td>' . $d['secteur_secondaire'] . '</td><td>' . $d['secteur_tertiaire'] . '</td>';
        echo '<td>' . $d['total_effectif'] . '</td><td>' . number_format($d['facturation'],0,',',' ') . '</td></tr>';
    }
    // ZB8
    echo '<tr style="background:#e8f5e9;"><td>ZB8</td><td><strong>TOTAL (Permanents+Saisonniers)</strong></td>';
    echo '<td>' . $totaux_zb6_7['nationaux'] . '</td><td>' . $totaux_zb6_7['autre_umoa'] . '</td><td>' . $totaux_zb6_7['hors_umoa'] . '</td>';
    echo '<td>' . $totaux_zb6_7['secteur_primaire'] . '</td><td>' . $totaux_zb6_7['secteur_secondaire'] . '</td><td>' . $totaux_zb6_7['secteur_tertiaire'] . '</td>';
    echo '<td>' . $totaux_zb6_7['total_effectif'] . '</td><td>' . number_format($totaux_zb6_7['facturation'],0,',',' ') . '</td></tr>';

    echo '</tbody></table></body></html>';
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
    <title>DIMF_2009 - Personnel extérieur</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter', sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; display:flex; align-items:center; gap:10px; }
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
        .subtotal-row { background:#f3f4f6; font-weight:600; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px 20px; border-radius:16px; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .page-footer, #filtersCard { display:none; } }
        input[type="number"] { width:70px; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-users"></i> DIMF_2009 - PERSONNEL EXTÉRIEUR (COMPTE 6221)</h1>
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

    <?php if($message): ?>
        <div class="info-box" style="background:<?= $message_type=='success'?'#d1fae5':'#fee2e2' ?>;border-left-color:<?= $message_type=='success'?'#10b981':'#ef4444' ?>;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><i class="fas fa-chart-bar"></i> SAISIE DES EFFECTIFS ET FACTURATION</div>
        <form method="post">
            <input type="hidden" name="action" value="save">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>Code</th><th>Catégorie</th><th>Nationaux</th><th>Autres UMOA</th><th>Hors UMOA</th><th>Secteur primaire</th><th>Secteur secondaire</th><th>Secteur tertiaire</th><th class="text-right">Total effectif</th><th class="text-right">Facturation (FCFA)</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        // Afficher les lignes ZB1 à ZB4
                        foreach (['ZB1','ZB2','ZB3','ZB4'] as $code):
                            $d = $personnel_data[$code] ?? [
                                'nationaux' => 0, 'autre_umoa' => 0, 'hors_umoa' => 0,
                                'secteur_primaire' => 0, 'secteur_secondaire' => 0, 'secteur_tertiaire' => 0,
                                'total_effectif' => 0, 'facturation' => 0
                            ];
                        ?>
                        <tr>
                            <td><?= $code ?></td>
                            <td><?= htmlspecialchars($categories[$code]) ?></td>
                            <td><input type="number" name="<?= $code ?>_nationaux" value="<?= $d['nationaux'] ?>"></td>
                            <td><input type="number" name="<?= $code ?>_autre_umoa" value="<?= $d['autre_umoa'] ?>"></td>
                            <td><input type="number" name="<?= $code ?>_hors_umoa" value="<?= $d['hors_umoa'] ?>"></td>
                            <td><input type="number" name="<?= $code ?>_secteur_primaire" value="<?= $d['secteur_primaire'] ?>"></td>
                            <td><input type="number" name="<?= $code ?>_secteur_secondaire" value="<?= $d['secteur_secondaire'] ?>"></td>
                            <td><input type="number" name="<?= $code ?>_secteur_tertiaire" value="<?= $d['secteur_tertiaire'] ?>"></td>
                            <td class="text-right"><?= number_format($d['total_effectif'],0,',',' ') ?></td>
                            <td><input type="number" name="<?= $code ?>_facturation" value="<?= number_format($d['facturation'],0,'','') ?>"></td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Ligne ZB5 (Total ZB1-ZB4) -->
                        <tr class="subtotal-row">
                            <td>ZB5</td>
                            <td><strong>TOTAL (ZB1-ZB4)</strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb1_4['nationaux'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb1_4['autre_umoa'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb1_4['hors_umoa'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb1_4['secteur_primaire'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb1_4['secteur_secondaire'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb1_4['secteur_tertiaire'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb1_4['total_effectif'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb1_4['facturation'],0,',',' ') ?></strong></td>
                        </tr>
                        <?php
                        // Lignes ZB6 et ZB7
                        foreach (['ZB6','ZB7'] as $code):
                            $d = $personnel_data[$code] ?? [
                                'nationaux' => 0, 'autre_umoa' => 0, 'hors_umoa' => 0,
                                'secteur_primaire' => 0, 'secteur_secondaire' => 0, 'secteur_tertiaire' => 0,
                                'total_effectif' => 0, 'facturation' => 0
                            ];
                        ?>
                        <tr>
                            <td><?= $code ?></td>
                            <td><?= htmlspecialchars($categories[$code]) ?></td>
                            <td><input type="number" name="<?= $code ?>_nationaux" value="<?= $d['nationaux'] ?>"></td>
                            <td><input type="number" name="<?= $code ?>_autre_umoa" value="<?= $d['autre_umoa'] ?>"></td>
                            <td><input type="number" name="<?= $code ?>_hors_umoa" value="<?= $d['hors_umoa'] ?>"></td>
                            <td><input type="number" name="<?= $code ?>_secteur_primaire" value="<?= $d['secteur_primaire'] ?>"></td>
                            <td><input type="number" name="<?= $code ?>_secteur_secondaire" value="<?= $d['secteur_secondaire'] ?>"></td>
                            <td><input type="number" name="<?= $code ?>_secteur_tertiaire" value="<?= $d['secteur_tertiaire'] ?>"></td>
                            <td class="text-right"><?= number_format($d['total_effectif'],0,',',' ') ?></td>
                            <td><input type="number" name="<?= $code ?>_facturation" value="<?= number_format($d['facturation'],0,'','') ?>"></td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Ligne ZB8 (Total ZB6+ZB7) -->
                        <tr class="total-row">
                            <td>ZB8</td>
                            <td><strong>TOTAL (Permanents+Saisonniers)</strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb6_7['nationaux'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb6_7['autre_umoa'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb6_7['hors_umoa'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb6_7['secteur_primaire'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb6_7['secteur_secondaire'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb6_7['secteur_tertiaire'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb6_7['total_effectif'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux_zb6_7['facturation'],0,',',' ') ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:16px; text-align:right;">
                <button type="submit" class="btn-apply"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>

    <div class="page-footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y H:i:s') ?>
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
    input.type = 'hidden'; input.name = 'format'; input.value = 'pdf';
    form.appendChild(input);
    form.submit();
    form.removeChild(input);
}

function exporterExcel() {
    const form = document.getElementById('filtersForm');
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = 'format'; input.value = 'excel';
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