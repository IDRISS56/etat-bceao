<?php
// DIMF_2014.php - Ressources affectées et crédits consentis
// Utilise UNIQUEMENT la table z_bceao_ressources_affectees
// PDF : affiche toutes les lignes DIMF_2014_1_1 à 1_10, même avec des zéros

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
$semestre     = isset($_POST['semestre'])     ? (int)$_POST['semestre']     : (isset($_GET['semestre']) ? (int)$_GET['semestre'] : 2);

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
}

$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));

switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Annee ' . $exercice;
}

// ============================================================
// TRAITEMENT POST
// ============================================================
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_ressources') {
    try {
        // Supprimer toutes les lignes de l'exercice
        $stmtDel = $pdo->prepare("DELETE FROM z_bceao_ressources_affectees WHERE exercice = :exercice");
        $stmtDel->execute([':exercice' => $exercice]);

        $stmtIns = $pdo->prepare("
            INSERT INTO z_bceao_ressources_affectees 
            (exercice, code, libelle, court_terme, moyen_terme, long_terme, total, statut)
            VALUES (:exercice, :code, :libelle, :court_terme, :moyen_terme, :long_terme, :total, 'actif')
        ");

        // 1. Enregistrer les ressources
        $ressources = $_POST['ressources'] ?? [];
        $i = 1;
        foreach ($ressources as $libelle => $values) {
            // Ignorer les lignes vides (titres avec champs cachés)
            if (empty($values['court_terme']) && empty($values['moyen_terme']) && empty($values['long_terme'])) {
                continue;
            }
            $court = (float)($values['court_terme'] ?? 0);
            $moyen = (float)($values['moyen_terme'] ?? 0);
            $long = (float)($values['long_terme'] ?? 0);
            $total = $court + $moyen + $long;

            $code = 'DIMF_2014_1_' . $i;
            $stmtIns->execute([
                ':exercice' => $exercice,
                ':code' => $code,
                ':libelle' => $libelle,
                ':court_terme' => $court,
                ':moyen_terme' => $moyen,
                ':long_terme' => $long,
                ':total' => $total
            ]);
            $i++;
        }

        // 2. Enregistrer les crédits (codes fixes)
        $credits_total = (float)($_POST['credits_total'] ?? 0);
        $credits_souffrance = (float)($_POST['credits_souffrance'] ?? 0);

        $stmtIns->execute([
            ':exercice' => $exercice,
            ':code' => 'CREDITS_TOTAL',
            ':libelle' => 'Crédits consentis',
            ':court_terme' => 0,
            ':moyen_terme' => 0,
            ':long_terme' => 0,
            ':total' => $credits_total
        ]);
        $stmtIns->execute([
            ':exercice' => $exercice,
            ':code' => 'CREDITS_SOUFFRANCE',
            ':libelle' => 'Dont crédits en souffrance',
            ':court_terme' => 0,
            ':moyen_terme' => 0,
            ':long_terme' => 0,
            ':total' => $credits_souffrance
        ]);

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
// RÉCUPÉRATION DES DONNÉES EXISTANTES
// ============================================================
$ressources_data = [];
$credits_total = 0;
$credits_souffrance = 0;

try {
    $stmt = $pdo->prepare("SELECT * FROM z_bceao_ressources_affectees WHERE exercice = :exercice AND statut = 'actif' ORDER BY code");
    $stmt->execute([':exercice' => $exercice]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        if ($row['code'] == 'CREDITS_TOTAL') {
            $credits_total = (float)$row['total'];
        } elseif ($row['code'] == 'CREDITS_SOUFFRANCE') {
            $credits_souffrance = (float)$row['total'];
        } else {
            $ressources_data[] = $row;
        }
    }
} catch (PDOException $e) {}

// ============================================================
// LISTE DES RESSOURCES PAR DÉFAUT (pour l'affichage)
// ============================================================
$default_ressources = [
    'RESSOURCES AFFECTEES' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => true],
    'Subventions d\'investissement' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Fonds de garantie' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Lignes de credit (BCEAO)' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Lignes de credit (Banques locales)' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Apports en capital' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Subventions d\'equipement' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Dons et legs' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Fonds de reserve' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Autres ressources' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false],
    'Autres ressources (suite)' => ['court_terme' => 0, 'moyen_terme' => 0, 'long_terme' => 0, 'is_title' => false] // 10ème ligne
];

// Mettre à jour avec les données existantes
foreach ($ressources_data as $r) {
    $libelle = $r['libelle'];
    if (isset($default_ressources[$libelle])) {
        $default_ressources[$libelle]['court_terme'] = (float)$r['court_terme'];
        $default_ressources[$libelle]['moyen_terme'] = (float)$r['moyen_terme'];
        $default_ressources[$libelle]['long_terme'] = (float)$r['long_terme'];
    }
}

// Construire le tableau d'affichage
$tableau_ressources = [];
$total_court = 0;
$total_moyen = 0;
$total_long = 0;
$total_global = 0;

foreach ($default_ressources as $libelle => $values) {
    $court = (float)$values['court_terme'];
    $moyen = (float)$values['moyen_terme'];
    $long = (float)$values['long_terme'];
    $total_ligne = $court + $moyen + $long;

    $total_court += $court;
    $total_moyen += $moyen;
    $total_long += $long;
    $total_global += $total_ligne;

    $tableau_ressources[] = [
        'libelle' => $libelle,
        'court_terme' => $court,
        'moyen_terme' => $moyen,
        'long_terme' => $long,
        'total' => $total_ligne,
        'is_title' => $values['is_title']
    ];
}

// Calcul des variables pour la synthèse HTML
$total_ressources = $total_global;
$taux_utilisation = ($total_ressources > 0) ? ($credits_total / $total_ressources) * 100 : 0;
$taux_souffrance = ($credits_total > 0) ? ($credits_souffrance / $credits_total) * 100 : 0;

// Emprunts réels (pour affichage HTML)
$emprunts_reels = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM capital WHERE statut = 'valide' AND mode_paiement = 'BANQUE' AND date_creation <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $emprunts_reels = (float)$result['total'];
} catch (PDOException $e) {
    $emprunts_reels = 0;
}

// ============================================================
// GÉNÉRATION PDF (uniquement le tableau Excel)
// ============================================================
$format = isset($_POST['format']) ? $_POST['format'] : (isset($_GET['format']) ? $_GET['format'] : 'html');

if ($format === 'pdf') {
    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->codeDimf  = 'DIMF_2014';
    $pdf->titreDimf = 'Ressources affectees et credits consentis';
    $pdf->nomSfd    = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode   = $lib_periode;
    $pdf->exercice  = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    // Titre principal
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, PDF_DIMF::u('ÉTATS DES RESSOURCES AFFECTÉES ET DES CRÉDITS CONSENTIS SUR RESSOURCES AFFECTÉES'), 0, 1, 'C');
    $pdf->Ln(4);

    // Colonnes du tableau
    $cols = [
        ['label' => 'CODE',         'w' => 28],
        ['label' => 'LIBELLÉS',     'w' => 80],
        ['label' => 'COURT TERME',  'w' => 35, 'align' => 'R'],
        ['label' => 'MOYEN TERME',  'w' => 35, 'align' => 'R'],
        ['label' => 'LONG TERME',   'w' => 35, 'align' => 'R'],
        ['label' => 'TOTAL',        'w' => 35, 'align' => 'R'],
    ];

    $pdf->TableHeader($cols);

    // Ligne ZD1 (titre)
    $pdf->TableRow($cols, ['ZD1', 'RESSOURCES AFFECTÉES', '', '', '', ''], 'subtotal');

    // Sous-lignes (ressources) : on boucle sur $tableau_ressources pour afficher toutes les lignes, même avec 0
    $index = 1;
    foreach ($tableau_ressources as $item) {
        if ($item['is_title']) continue; // on saute la ligne "RESSOURCES AFFECTEES"
        $code = 'DIMF_2014_1_' . $index;
        $pdf->TableRow($cols, [
            $code,
            $item['libelle'],
            PDF_DIMF::montant($item['court_terme']),
            PDF_DIMF::montant($item['moyen_terme']),
            PDF_DIMF::montant($item['long_terme']),
            PDF_DIMF::montant($item['total'])
        ]);
        $index++;
    }

    // Ligne ZD2 (crédits consentis)
    $pdf->TableRow($cols, ['ZD2', 'CRÉDITS CONSENTIS SUR RESSOURCES AFFECTÉES', '', '', '', PDF_DIMF::montant($credits_total)], 'subtotal');

    // Ligne ZD3 (dont souffrance)
    $pdf->TableRow($cols, ['ZD3', 'dont crédits en souffrance', '', '', '', PDF_DIMF::montant($credits_souffrance)]);

    // Ligne TOTAL (DIMF_2014_1_11)
    $total_court_general = $total_court;
    $total_moyen_general = $total_moyen;
    $total_long_general = $total_long;
    $total_global_general = $total_global + $credits_total;

    $pdf->TableRow($cols, [
        'DIMF_2014_1_11',
        'TOTAL',
        PDF_DIMF::montant($total_court_general),
        PDF_DIMF::montant($total_moyen_general),
        PDF_DIMF::montant($total_long_general),
        PDF_DIMF::montant($total_global_general)
    ], 'total');

    // Sortie du PDF
    $pdf->Output('I', 'DIMF_2014_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ============================================================
// AFFICHAGE HTML (avec les sections supplémentaires pour la saisie)
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2014 - Ressources affectées et crédits consentis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .btn-excel { background: #10b981; color: white; padding: 8px 20px; border-radius: 40px; border: none; cursor: pointer; }
        .btn-pdf { background: #ef4444; color: white; padding: 8px 20px; border-radius: 40px; border: none; cursor: pointer; }
        .btn-save { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 8px 24px; font-weight: 500; font-size: 0.85rem; cursor: pointer; transition: 0.2s; }
        .btn-save:hover { background: #2563eb; transform: translateY(-1px); }
        .card { background: white; border-radius: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 8px 16px -4px rgba(0,0,0,0.05); margin-bottom: 24px; overflow: hidden; }
        .card-header { display: flex; align-items: center; gap: 10px; padding: 16px 24px; background: #f8fafc; border-bottom: 1px solid #eef2f6; font-weight: 600; font-size: 1rem; color: #1e40af; }
        .card-header i { color: #3b82f6; }
        .card-body { padding: 20px 24px; }
        .filters-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 20px; }
        .filter-item { display: flex; flex-direction: column; gap: 6px; }
        .filter-item label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #4b5563; }
        .filter-item select, .filter-item input { background: white; border: 1px solid #d1d5db; border-radius: 12px; padding: 8px 14px; font-size: 0.85rem; color: #111827; cursor: pointer; }
        .filter-item select:focus, .filter-item input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.2); }
        .btn-apply { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 8px 24px; font-weight: 500; font-size: 0.85rem; cursor: pointer; transition: 0.2s; }
        .btn-apply:hover { background: #2563eb; transform: translateY(-1px); }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 12px 16px; background: #f8fafc; font-weight: 600; color: #1e293b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
        .text-right { text-align: right; font-family: 'Courier New', monospace; font-weight: 500; }
        .subtotal-row { background: #f8fafc; font-weight: 600; }
        .total-row { background: #f0fdf4; font-weight: 700; border-top: 2px solid #bbf7d0; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 5px; color: #555; font-size: 0.8rem; }
        .form-group input { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 0.9rem; text-align: right; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .ressources-table input { min-width: 100px; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 8px; text-align: right; font-family: monospace; }
        .ressources-table input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }
        .alert { padding: 14px 20px; border-radius: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #ecfdf5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border-left: 4px solid #ef4444; }
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        @media (max-width: 768px) { body { padding: 12px; } .filters-row { flex-direction: column; align-items: stretch; } .btn-group { flex-wrap: wrap; } th, td { padding: 8px 12px; font-size: 0.75rem; } .ressources-table input { min-width: 70px; } }
        @media print { body { background: white; padding: 0; } .btn-group, .footer, .filters-row, .btn-save, #filtersCard, .alert { display: none !important; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-line"></i> DIMF_2014 - RESSOURCES AFFECTÉES</h1>
            <div class="subtitle">République de Côte d'Ivoire / Ministère de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Fonds spéciaux et lignes de crédit</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Filtres dynamiques -->
    <form method="post" class="card" id="filtersForm">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
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
                    <label>Type de période</label>
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
                        for ($m=1;$m<=12;$m++) { $s=($m==$mois)?'selected':''; echo "<option value='$m' $s>".str_pad($m,2,'0',STR_PAD_LEFT)." - ".date('F',mktime(0,0,0,$m,1))."</option>"; }
                        echo '</select>';
                    } elseif ($type_periode == 'trimestre') {
                        echo '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
                        for ($t=1;$t<=4;$t++) { $s=($t==$trimestre)?'selected':''; echo "<option value='$t' $s>$t".($t==1?'er':'ème')." Trimestre</option>"; }
                        echo '</select>';
                    } elseif ($type_periode == 'semestre') {
                        echo '<label>Semestre</label><select name="semestre" id="semestreSelect">';
                        for ($s=1;$s<=2;$s++) { $sel=($s==$semestre)?'selected':''; echo "<option value='$s' $sel>$s".($s==1?'er':'e')." semestre</option>"; }
                        echo '</select>';
                    } else {
                        echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;cursor:default;">';
                    }
                    ?>
                </div>
                <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Période : <?= $lib_periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </form>

    <?php if($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <i class="fas fa-<?= $message_type == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Formulaire Ressources affectées -->
    <form method="post" action="">
        <input type="hidden" name="action" value="save_ressources">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-money-bill-wave"></i> RESSOURCES AFFECTÉES
                <button type="submit" class="btn-save" style="margin-left: auto; padding: 6px 16px; font-size: 0.75rem;"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table class="ressources-table">
                        <thead>
                            <tr>
                                <th>CODE</th>
                                <th>LIBELLÉS</th>
                                <th class="text-right">COURT TERME (FCFA)</th>
                                <th class="text-right">MOYEN TERME (FCFA)</th>
                                <th class="text-right">LONG TERME (FCFA)</th>
                                <th class="text-right">TOTAL (FCFA)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $index = 1;
                            foreach ($tableau_ressources as $item):
                                if ($item['is_title']):
                            ?>
                                <tr class="subtotal-row">
                                    <td><strong>ZD1</strong></td>
                                    <td><strong><?= htmlspecialchars($item['libelle']) ?></strong></td>
                                    <td class="text-right">-</td>
                                    <td class="text-right">-</td>
                                    <td class="text-right">-</td>
                                    <td class="text-right">-</td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td><strong><?= 'DIMF_2014_1_' . $index ?></strong></td>
                                    <td>
                                        <input type="text" name="ressources[<?= htmlspecialchars($item['libelle']) ?>][libelle]" value="<?= htmlspecialchars($item['libelle']) ?>" style="width:100%; border:none; background:transparent;">
                                    </td>
                                    <td class="text-right">
                                        <input type="number" name="ressources[<?= htmlspecialchars($item['libelle']) ?>][court_terme]" step="1" class="court-input" value="<?= number_format($item['court_terme'], 0, '', '') ?>">
                                    </td>
                                    <td class="text-right">
                                        <input type="number" name="ressources[<?= htmlspecialchars($item['libelle']) ?>][moyen_terme]" step="1" class="moyen-input" value="<?= number_format($item['moyen_terme'], 0, '', '') ?>">
                                    </td>
                                    <td class="text-right">
                                        <input type="number" name="ressources[<?= htmlspecialchars($item['libelle']) ?>][long_terme]" step="1" class="long-input" value="<?= number_format($item['long_terme'], 0, '', '') ?>">
                                    </td>
                                    <td class="text-right total-cell" style="background:#f0fdf4; font-weight:bold;"><?= number_format($item['total'], 0, ',', ' ') ?></td>
                                </tr>
                            <?php
                                $index++;
                                endif;
                            endforeach;
                            ?>

                            <!-- Ligne ZD2 (Crédits consentis) -->
                            <tr class="subtotal-row">
                                <td><strong>ZD2</strong></td>
                                <td><strong>CRÉDITS CONSENTIS SUR RESSOURCES AFFECTÉES</strong></td>
                                <td class="text-right">-</td>
                                <td class="text-right">-</td>
                                <td class="text-right">-</td>
                                <td class="text-right">
                                    <input type="number" name="credits_total" step="1" value="<?= number_format($credits_total, 0, '', '') ?>" style="width:100%; text-align:right; border:none; background:transparent; font-weight:bold;">
                                </td>
                            </tr>

                            <!-- Ligne ZD3 (dont souffrance) -->
                            <tr>
                                <td><strong>ZD3</strong></td>
                                <td>dont crédits en souffrance</td>
                                <td class="text-right">-</td>
                                <td class="text-right">-</td>
                                <td class="text-right">-</td>
                                <td class="text-right">
                                    <input type="number" name="credits_souffrance" step="1" value="<?= number_format($credits_souffrance, 0, '', '') ?>" style="width:100%; text-align:right; border:none; background:transparent;">
                                </td>
                            </tr>

                            <!-- Ligne TOTAL (DIMF_2014_1_11) -->
                            <tr class="total-row">
                                <td><strong>DIMF_2014_1_11</strong></td>
                                <td><strong>TOTAL</strong></td>
                                <td class="text-right" id="total_court"><?= number_format($total_court, 0, ',', ' ') ?></td>
                                <td class="text-right" id="total_moyen"><?= number_format($total_moyen, 0, ',', ' ') ?></td>
                                <td class="text-right" id="total_long"><?= number_format($total_long, 0, ',', ' ') ?></td>
                                <td class="text-right" id="total_ressources"><?= number_format($total_global + $credits_total, 0, ',', ' ') ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </form>

    <!-- Emprunts réels (pour information) -->
    <div class="card">
        <div class="card-header"><i class="fas fa-building-columns"></i> EMPRUNTS RÉELS (HORS RESSOURCES AFFECTÉES)</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-chart-simple"></i>
                <div><strong>Emprunts bancaires / institutionnels :</strong> <?= number_format($emprunts_reels, 0, ',', ' ') ?> FCFA</div>
            </div>
        </div>
    </div>

    <!-- Synthèse -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-pie"></i> SYNTHÈSE</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-calculator"></i>
                <div>
                    <strong>Total ressources affectées :</strong> <?= number_format($total_ressources, 0, ',', ' ') ?> FCFA<br>
                    <strong>Crédits consentis :</strong> <?= number_format($credits_total, 0, ',', ' ') ?> FCFA<br>
                    <strong>Taux d'utilisation :</strong> <?= number_format($taux_utilisation, 2) ?>%<br>
                    <strong>Crédits en souffrance :</strong> <?= number_format($credits_souffrance, 0, ',', ' ') ?> FCFA<br>
                    <strong>Taux de souffrance :</strong> <?= number_format($taux_souffrance, 2) ?>%
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> - Données issues de <code>z_bceao_ressources_affectees</code>
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
            for (let m = 1; m <= 12; m++) {
                const s = (m === currentMois) ? 'selected' : '';
                const n = new Date(2000, m-1, 1).toLocaleString('fr', {month:'long'});
                html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
            for (let t = 1; t <= 4; t++) {
                const s = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${s}>${t}${t === 1 ? 'er' : 'ème'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
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

        let dataRessources = [
            ['DIMF_2014 - RESSOURCES AFFECTEES ET CREDITS CONSENTIS'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['CODE','LIBELLÉS','COURT TERME (FCFA)','MOYEN TERME (FCFA)','LONG TERME (FCFA)','TOTAL (FCFA)']
        ];

        // On utilise $tableau_ressources pour garantir les 10 lignes
        <?php
        $idx = 1;
        foreach ($tableau_ressources as $item):
            if ($item['is_title']) continue;
            $code = 'DIMF_2014_1_' . $idx;
        ?>
        dataRessources.push([
            '<?= $code ?>',
            '<?= addslashes($item['libelle']) ?>',
            <?= $item['court_terme'] ?>,
            <?= $item['moyen_terme'] ?>,
            <?= $item['long_terme'] ?>,
            <?= $item['total'] ?>
        ]);
        <?php
            $idx++;
        endforeach;
        ?>

        // Crédits
        dataRessources.push(['ZD2','CRÉDITS CONSENTIS SUR RESSOURCES AFFECTÉES','','','',<?= $credits_total ?>]);
        dataRessources.push(['ZD3','dont crédits en souffrance','','','',<?= $credits_souffrance ?>]);

        // Total
        dataRessources.push(['DIMF_2014_1_11','TOTAL',<?= $total_court ?>,<?= $total_moyen ?>,<?= $total_long ?>,<?= $total_global + $credits_total ?>]);

        const ws = XLSX.utils.aoa_to_sheet(dataRessources);
        XLSX.utils.book_append_sheet(wb, ws, "RESSOURCES_AFFECTEES");
        XLSX.writeFile(wb, 'DIMF_2014_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }

    function calculerTotaux() {
        let totalCourt = 0, totalMoyen = 0, totalLong = 0, totalGlobal = 0;
        const lignes = document.querySelectorAll('.ressources-table tbody tr');

        lignes.forEach(function(row) {
            if (row.classList.contains('total-row')) return;
            const courtInput = row.querySelector('input[name$="[court_terme]"]');
            const moyenInput = row.querySelector('input[name$="[moyen_terme]"]');
            const longInput = row.querySelector('input[name$="[long_terme]"]');
            const totalCell = row.querySelector('.total-cell');

            const court = courtInput ? parseFloat(courtInput.value) || 0 : 0;
            const moyen = moyenInput ? parseFloat(moyenInput.value) || 0 : 0;
            const long = longInput ? parseFloat(longInput.value) || 0 : 0;
            const total = court + moyen + long;

            totalCourt += court;
            totalMoyen += moyen;
            totalLong += long;
            totalGlobal += total;
            if (totalCell) totalCell.innerText = total.toLocaleString('fr-FR');
        });

        document.getElementById('total_court').innerText = totalCourt.toLocaleString('fr-FR');
        document.getElementById('total_moyen').innerText = totalMoyen.toLocaleString('fr-FR');
        document.getElementById('total_long').innerText = totalLong.toLocaleString('fr-FR');
        document.getElementById('total_ressources').innerText = totalGlobal.toLocaleString('fr-FR');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);

        const inputs = document.querySelectorAll('.ressources-table input[type="number"]');
        inputs.forEach(input => {
            input.addEventListener('input', calculerTotaux);
            input.addEventListener('change', calculerTotaux);
        });
        calculerTotaux();
    });
</script>
</body>
</html>