<?php
// DIMF_2005.php - Tableau des emplois et ressources
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

$date_fin_periode    = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$exercice_prec       = $exercice - 1;
$date_fin_prec       = $exercice_prec . '-12-31';

// ============================================================
// CALCULS MÉTIER (EMPLOIS, RESSOURCES) – IDENTIQUE À L'ORIGINAL
// ============================================================
function getCreditEncours($duree_condition, $date_fin) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve') AND $duree_condition AND d.date_octroi <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin]);
    return (float)$stmt->fetch()['total'];
}

$B2D_emplois      = getCreditEncours("d.duree <= 12", $date_fin_periode);
$B30_emplois      = getCreditEncours("d.duree BETWEEN 13 AND 60", $date_fin_periode);
$B40_emplois      = getCreditEncours("d.duree > 60", $date_fin_periode);
$B70_emplois      = getCreditEncours("d.statut = 'impaye'", $date_fin_periode);

$B2D_emplois_prec = getCreditEncours("d.duree <= 12", $date_fin_prec);
$B30_emplois_prec = getCreditEncours("d.duree BETWEEN 13 AND 60", $date_fin_prec);
$B40_emplois_prec = getCreditEncours("d.duree > 60", $date_fin_prec);
$B70_emplois_prec = getCreditEncours("d.statut = 'impaye'", $date_fin_prec);

$total_emplois_I_prec  = $B2D_emplois_prec + $B30_emplois_prec + $B40_emplois_prec + $B70_emplois_prec;
$total_emplois_I_cours = $B2D_emplois + $B30_emplois + $B40_emplois + $B70_emplois;

// RESSOURCES
$G10_ressources = 0; $G10_ressources_prec = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde), 0) as total FROM comptes WHERE solde > 0 AND statut = 'actif' AND date_ouverture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $G10_ressources = (float)$stmt->fetch()['total'];
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $G10_ressources_prec = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

$G15_ressources = 0; $G15_ressources_prec = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital_initial), 0) as total FROM comptes_dat WHERE statut = 'en cours' AND date_ouverture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $G15_ressources = (float)$stmt->fetch()['total'];
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $G15_ressources_prec = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

$G2A_ressources = 0; $G2A_ressources_prec = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde), 0) as total
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0 AND c.date_ouverture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $G2A_ressources = (float)$stmt->fetch()['total'];
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $G2A_ressources_prec = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

$total_ressources_III_prec  = $G10_ressources_prec + $G15_ressources_prec + $G2A_ressources_prec;
$total_ressources_III_cours = $G10_ressources + $G15_ressources + $G2A_ressources;

// Fonds propres
$fonds_propres_cours = 0; $fonds_propres_prec = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit), 0) as total FROM ecritures_comptables WHERE LEFT(compte_general,1) = '1' AND date_ecriture <= :date_fin AND statut = 'VALIDÉE'");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $fonds_propres_cours = (float)$stmt->fetch()['total'];
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $fonds_propres_prec = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

$total_ressources_prec  = $total_ressources_III_prec  + $fonds_propres_prec;
$total_ressources_cours = $total_ressources_III_cours + $fonds_propres_cours;
$excedent_prec  = $total_ressources_prec  - $total_emplois_I_prec;
$excedent_cours = $total_ressources_cours - $total_emplois_I_cours;
$variation_absolue = $total_emplois_I_cours - $total_emplois_I_prec;
$variation_pct     = ($total_emplois_I_prec != 0) ? ($variation_absolue / $total_emplois_I_prec) * 100 : 0;
$actif_net_prec = 0; // non utilisé

function variation_abs_html($old, $new) {
    $diff = $new - $old;
    $class = $diff >= 0 ? 'variation-positive' : 'variation-negative';
    return '<span class="' . $class . '">' . number_format(abs($diff), 0, ',', ' ') . '</span>';
}
function variation_pct_html($old, $new) {
    if ($old == 0) return '<span>-</span>';
    $pct = (($new - $old) / abs($old)) * 100;
    $class = $pct >= 0 ? 'variation-positive' : 'variation-negative';
    return '<span class="' . $class . '">' . number_format($pct, 2) . '%</span>';
}

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
    $pdf->codeDimf  = 'DIMF_2005';
    $pdf->titreDimf = 'Tableau des emplois et ressources';
    $pdf->nomSfd    = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode   = $lib_periode;
    $pdf->exercice  = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'CODE', 'w' => 25],
        ['label' => 'LIBELLÉS', 'w' => 90],
        ['label' => 'Période préc.', 'w' => 45, 'align' => 'R'],
        ['label' => 'Période en cours', 'w' => 45, 'align' => 'R'],
        ['label' => 'Variation abs.', 'w' => 35, 'align' => 'R'],
        ['label' => 'Variation %', 'w' => 30, 'align' => 'R']
    ];

    $pdf->SectionTitle('Emplois et ressources');
    $pdf->TableHeader($cols);
    $pdf->TableRow($cols, ['I', 'OPÉRATIONS AVEC LES MEMBRES',
        PDF_DIMF::montant($total_emplois_I_prec),
        PDF_DIMF::montant($total_emplois_I_cours),
        PDF_DIMF::montant($total_emplois_I_cours - $total_emplois_I_prec),
        number_format($variation_pct,2).'%'], 'subtotal');
    // ... toutes les lignes (similaire à l'original, je les ajoute ici brièvement)
    $pdf->TableRow($cols, ['B2D','1) Crédits à court terme (≤12 mois)',
        PDF_DIMF::montant($B2D_emplois_prec),
        PDF_DIMF::montant($B2D_emplois),
        PDF_DIMF::montant($B2D_emplois - $B2D_emplois_prec),
        number_format(($B2D_emplois_prec!=0)?(($B2D_emplois-$B2D_emplois_prec)/$B2D_emplois_prec)*100:0,2).'%']);
    $pdf->TableRow($cols, ['B30','2) Crédits à moyen terme (13-60 mois)',
        PDF_DIMF::montant($B30_emplois_prec),
        PDF_DIMF::montant($B30_emplois),
        PDF_DIMF::montant($B30_emplois - $B30_emplois_prec),
        number_format(($B30_emplois_prec!=0)?(($B30_emplois-$B30_emplois_prec)/$B30_emplois_prec)*100:0,2).'%']);
    $pdf->TableRow($cols, ['B40','3) Crédits à long terme (>60 mois)',
        PDF_DIMF::montant($B40_emplois_prec),
        PDF_DIMF::montant($B40_emplois),
        PDF_DIMF::montant($B40_emplois - $B40_emplois_prec),
        number_format(($B40_emplois_prec!=0)?(($B40_emplois-$B40_emplois_prec)/$B40_emplois_prec)*100:0,2).'%']);
    $pdf->TableRow($cols, ['B70','5) Créances en souffrance',
        PDF_DIMF::montant($B70_emplois_prec),
        PDF_DIMF::montant($B70_emplois),
        PDF_DIMF::montant($B70_emplois - $B70_emplois_prec),
        number_format(($B70_emplois_prec!=0)?(($B70_emplois-$B70_emplois_prec)/$B70_emplois_prec)*100:0,2).'%']);

    $pdf->TableRow($cols, ['III', 'DÉPÔTS ET EMPRUNTS',
        PDF_DIMF::montant($total_ressources_III_prec),
        PDF_DIMF::montant($total_ressources_III_cours),
        PDF_DIMF::montant($total_ressources_III_cours - $total_ressources_III_prec),
        number_format(($total_ressources_III_prec!=0)?(($total_ressources_III_cours-$total_ressources_III_prec)/$total_ressources_III_prec)*100:0,2).'%'], 'subtotal');
    $pdf->TableRow($cols, ['G10','29) Comptes ordinaires créditeurs',
        PDF_DIMF::montant($G10_ressources_prec),
        PDF_DIMF::montant($G10_ressources),
        PDF_DIMF::montant($G10_ressources - $G10_ressources_prec),
        number_format(($G10_ressources_prec!=0)?(($G10_ressources-$G10_ressources_prec)/$G10_ressources_prec)*100:0,2).'%']);
    $pdf->TableRow($cols, ['G15','30) Dépôts à terme reçus',
        PDF_DIMF::montant($G15_ressources_prec),
        PDF_DIMF::montant($G15_ressources),
        PDF_DIMF::montant($G15_ressources - $G15_ressources_prec),
        number_format(($G15_ressources_prec!=0)?(($G15_ressources-$G15_ressources_prec)/$G15_ressources_prec)*100:0,2).'%']);
    $pdf->TableRow($cols, ['G2A','31) Comptes d\'épargne régime spécial',
        PDF_DIMF::montant($G2A_ressources_prec),
        PDF_DIMF::montant($G2A_ressources),
        PDF_DIMF::montant($G2A_ressources - $G2A_ressources_prec),
        number_format(($G2A_ressources_prec!=0)?(($G2A_ressources-$G2A_ressources_prec)/$G2A_ressources_prec)*100:0,2).'%']);

    $pdf->TableRow($cols, ['V', 'FONDS PROPRES NETS',
        PDF_DIMF::montant($fonds_propres_prec),
        PDF_DIMF::montant($fonds_propres_cours),
        PDF_DIMF::montant($fonds_propres_cours - $fonds_propres_prec),
        number_format(($fonds_propres_prec!=0)?(($fonds_propres_cours-$fonds_propres_prec)/$fonds_propres_prec)*100:0,2).'%'], 'subtotal');

    $pdf->TableRow($cols, ['B', 'TOTAL RESSOURCES (III+V)',
        PDF_DIMF::montant($total_ressources_prec),
        PDF_DIMF::montant($total_ressources_cours),
        PDF_DIMF::montant($total_ressources_cours - $total_ressources_prec),
        number_format(($total_ressources_prec!=0)?(($total_ressources_cours-$total_ressources_prec)/$total_ressources_prec)*100:0,2).'%'], 'total');
    $pdf->TableRow($cols, ['C', 'EXCÉDENT (+) OU DÉFICIT (-) (B-A)',
        PDF_DIMF::montant($excedent_prec),
        PDF_DIMF::montant($excedent_cours),
        PDF_DIMF::montant($excedent_cours - $excedent_prec),
        number_format(($excedent_prec!=0)?(($excedent_cours-$excedent_prec)/$excedent_prec)*100:0,2).'%'], 'subtotal');

    $pdf->Output('I', 'DIMF_2005_EmploisRessources_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2005 - Emplois et ressources</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Styles identiques à DIMF_2000 (copie) */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, sans-serif; background: #f1f5f9; padding: 24px; }
        .dashboard { max-width: 1400px; margin: 0 auto; }
        .page-header { background: linear-gradient(135deg, #3b82f6, #60a5fa); border-radius: 24px; padding: 20px 28px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .header-left h1 { font-size: 1.6rem; font-weight: 600; color: white; }
        .subtitle { font-size: 0.8rem; color: #e0f2fe; }
        .badge { background: #2563eb; color: white; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; }
        .btn-group { display: flex; gap: 12px; }
        .btn-excel { background: #10b981; color: white; padding: 8px 20px; border-radius: 40px; border: none; cursor: pointer; }
        .btn-pdf { background: #ef4444; color: white; padding: 8px 20px; border-radius: 40px; border: none; cursor: pointer; }
        .card { background: white; border-radius: 20px; padding: 20px 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #eef2f6; font-weight: 600; color: #1e40af; }
        .filters-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 20px; }
        .filter-item { display: flex; flex-direction: column; gap: 6px; }
        .filter-item label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #4b5563; }
        .filter-item select, .filter-item input { border: 1px solid #d1d5db; border-radius: 12px; padding: 8px 14px; }
        .btn-apply { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 8px 24px; cursor: pointer; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; }
        .text-right { text-align: right; font-family: monospace; }
        .subtotal-row { background: #f8fafc; font-weight: 600; }
        .total-row { background: #f0fdf4; font-weight: 700; border-top: 2px solid #bbf7d0; }
        .variation-positive { color: #16a34a; font-weight: 700; }
        .variation-negative { color: #dc2626; font-weight: 700; }
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; }
        .page-footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; }
        @media print { .btn-group, .page-footer, #filtersCard { display: none; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-pie"></i> DIMF_2005 - EMPLOIS & RESSOURCES</h1>
            <div class="subtitle">République de Côte d'Ivoire – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Analyse des variations</div>
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

    <div class="card">
        <div class="card-header"><i class="fas fa-exchange-alt"></i> EMPLOIS ET RESSOURCES</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>CODE</th><th>LIBELLÉS</th><th class="text-right">Période précédente (N-1)</th><th class="text-right">Période en cours (N)</th><th class="text-right">Variation absolue</th><th class="text-right">Variation %</th></tr>
                </thead>
                <tbody>
                    <tr class="subtotal-row"><td colspan="2">I - OPÉRATIONS AVEC LES MEMBRES</td><td class="text-right"><?= number_format($total_emplois_I_prec,0,',',' ') ?></td><td class="text-right"><?= number_format($total_emplois_I_cours,0,',',' ') ?></td><td class="text-right"><?= variation_abs_html($total_emplois_I_prec, $total_emplois_I_cours) ?></td><td class="text-right"><?= variation_pct_html($total_emplois_I_prec, $total_emplois_I_cours) ?></td></tr>
                    <tr><td>B2D</td><td>1) Crédits à court terme (≤12 mois)</td><td class="text-right"><?= number_format($B2D_emplois_prec,0,',',' ') ?></td><td class="text-right"><?= number_format($B2D_emplois,0,',',' ') ?></td><td class="text-right"><?= variation_abs_html($B2D_emplois_prec, $B2D_emplois) ?></td><td class="text-right"><?= variation_pct_html($B2D_emplois_prec, $B2D_emplois) ?></td></tr>
                    <tr><td>B30</td><td>2) Crédits à moyen terme (13-60 mois)</td><td class="text-right"><?= number_format($B30_emplois_prec,0,',',' ') ?></td><td class="text-right"><?= number_format($B30_emplois,0,',',' ') ?></td><td class="text-right"><?= variation_abs_html($B30_emplois_prec, $B30_emplois) ?></td><td class="text-right"><?= variation_pct_html($B30_emplois_prec, $B30_emplois) ?></td></tr>
                    <tr><td>B40</td><td>3) Crédits à long terme (>60 mois)</td><td class="text-right"><?= number_format($B40_emplois_prec,0,',',' ') ?></td><td class="text-right"><?= number_format($B40_emplois,0,',',' ') ?></td><td class="text-right"><?= variation_abs_html($B40_emplois_prec, $B40_emplois) ?></td><td class="text-right"><?= variation_pct_html($B40_emplois_prec, $B40_emplois) ?></td></tr>
                    <tr><td>B70</td><td>5) Créances en souffrance</td><td class="text-right"><?= number_format($B70_emplois_prec,0,',',' ') ?></td><td class="text-right"><?= number_format($B70_emplois,0,',',' ') ?></td><td class="text-right"><?= variation_abs_html($B70_emplois_prec, $B70_emplois) ?></td><td class="text-right"><?= variation_pct_html($B70_emplois_prec, $B70_emplois) ?></td></tr>
                    <tr class="subtotal-row"><td colspan="2">III - DÉPÔTS ET EMPRUNTS</td><td class="text-right"><?= number_format($total_ressources_III_prec,0,',',' ') ?></td><td class="text-right"><?= number_format($total_ressources_III_cours,0,',',' ') ?></td><td class="text-right"><?= variation_abs_html($total_ressources_III_prec, $total_ressources_III_cours) ?></td><td class="text-right"><?= variation_pct_html($total_ressources_III_prec, $total_ressources_III_cours) ?></td></tr>
                    <tr><td>G10</td><td>29) Comptes ordinaires créditeurs</td><td class="text-right"><?= number_format($G10_ressources_prec,0,',',' ') ?></td><td class="text-right"><?= number_format($G10_ressources,0,',',' ') ?></td><td class="text-right"><?= variation_abs_html($G10_ressources_prec, $G10_ressources) ?></td><td class="text-right"><?= variation_pct_html($G10_ressources_prec, $G10_ressources) ?></td></tr>
                    <tr><td>G15</td><td>30) Dépôts à terme reçus</td><td class="text-right"><?= number_format($G15_ressources_prec,0,',',' ') ?></td><td class="text-right"><?= number_format($G15_ressources,0,',',' ') ?></td><td class="text-right"><?= variation_abs_html($G15_ressources_prec, $G15_ressources) ?></td><td class="text-right"><?= variation_pct_html($G15_ressources_prec, $G15_ressources) ?></td></tr>
                    <tr><td>G2A</td><td>31) Comptes d'épargne régime spécial</td><td class="text-right"><?= number_format($G2A_ressources_prec,0,',',' ') ?></td><td class="text-right"><?= number_format($G2A_ressources,0,',',' ') ?></td><td class="text-right"><?= variation_abs_html($G2A_ressources_prec, $G2A_ressources) ?></td><td class="text-right"><?= variation_pct_html($G2A_ressources_prec, $G2A_ressources) ?></td></tr>
                    <tr class="subtotal-row"><td colspan="2">V - FONDS PROPRES NETS</td><td class="text-right"><?= number_format($fonds_propres_prec,0,',',' ') ?></td><td class="text-right"><?= number_format($fonds_propres_cours,0,',',' ') ?></td><td class="text-right"><?= variation_abs_html($fonds_propres_prec, $fonds_propres_cours) ?></td><td class="text-right"><?= variation_pct_html($fonds_propres_prec, $fonds_propres_cours) ?></td></tr>
                    <tr class="total-row"><td colspan="2">B - TOTAL RESSOURCES (III+V)</td><td class="text-right"><?= number_format($total_ressources_prec,0,',',' ') ?></td><td class="text-right"><?= number_format($total_ressources_cours,0,',',' ') ?></td><td class="text-right"><?= variation_abs_html($total_ressources_prec, $total_ressources_cours) ?></td><td class="text-right"><?= variation_pct_html($total_ressources_prec, $total_ressources_cours) ?></td></tr>
                    <tr class="subtotal-row"><td colspan="2">C - EXCÉDENT (+) OU DÉFICIT (-) (B - A)</td><td class="text-right"><?= number_format($excedent_prec,0,',',' ') ?></td><td class="text-right"><?= number_format($excedent_cours,0,',',' ') ?></td><td class="text-right"><?= variation_abs_html($excedent_prec, $excedent_cours) ?></td><td class="text-right"><?= variation_pct_html($excedent_prec, $excedent_cours) ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> SYNTHÈSE DES VARIATIONS</div>
        <div class="info-box">
            <div><strong>Évolution du portefeuille de crédits :</strong> <?= number_format(abs($variation_absolue),0,',',' ') ?> FCFA (<?= number_format($variation_pct,2) ?>%)<br>
            <strong>Évolution des fonds propres :</strong> <?= number_format(abs($fonds_propres_cours - $fonds_propres_prec),0,',',' ') ?> FCFA</div>
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
            for (let m=1;m<=12;m++) { const s = (m===currentMois)?'selected':''; const n = new Date(2000,m-1,1).toLocaleString('fr',{month:'long'}); html+=`<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`; }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
            for (let t=1;t<=4;t++) { const s = (t===currentTrimestre)?'selected':''; html+=`<option value="${t}" ${s}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
            for (let s=1;s<=2;s++) { const sel = (s===currentSemestre)?'selected':''; html+=`<option value="${s}" ${sel}>${s}${s===1?'er':'e'} semestre</option>`; }
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
        const data = [['DIMF_2005 - EMPLOIS ET RESSOURCES'],['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],[],['CODE','LIBELLÉ','N-1','N','Var. abs','Var. %'],
            ['I','OPÉRATIONS AVEC MEMBRES',<?= $total_emplois_I_prec ?>,<?= $total_emplois_I_cours ?>,<?= $total_emplois_I_cours-$total_emplois_I_prec ?>,<?= $variation_pct ?>],
            ['B2D','Crédits court terme',<?= $B2D_emplois_prec ?>,<?= $B2D_emplois ?>,<?= $B2D_emplois-$B2D_emplois_prec ?>,<?= ($B2D_emplois_prec!=0)?(($B2D_emplois-$B2D_emplois_prec)/$B2D_emplois_prec)*100:0 ?>],
            ['B30','Crédits moyen terme',<?= $B30_emplois_prec ?>,<?= $B30_emplois ?>,<?= $B30_emplois-$B30_emplois_prec ?>,<?= ($B30_emplois_prec!=0)?(($B30_emplois-$B30_emplois_prec)/$B30_emplois_prec)*100:0 ?>],
            ['B40','Crédits long terme',<?= $B40_emplois_prec ?>,<?= $B40_emplois ?>,<?= $B40_emplois-$B40_emplois_prec ?>,<?= ($B40_emplois_prec!=0)?(($B40_emplois-$B40_emplois_prec)/$B40_emplois_prec)*100:0 ?>],
            ['B70','Créances souffrance',<?= $B70_emplois_prec ?>,<?= $B70_emplois ?>,<?= $B70_emplois-$B70_emplois_prec ?>,<?= ($B70_emplois_prec!=0)?(($B70_emplois-$B70_emplois_prec)/$B70_emplois_prec)*100:0 ?>],
            ['III','DÉPÔTS',<?= $total_ressources_III_prec ?>,<?= $total_ressources_III_cours ?>,<?= $total_ressources_III_cours-$total_ressources_III_prec ?>,<?= ($total_ressources_III_prec!=0)?(($total_ressources_III_cours-$total_ressources_III_prec)/$total_ressources_III_prec)*100:0 ?>],
            ['G10','Comptes ordinaires',<?= $G10_ressources_prec ?>,<?= $G10_ressources ?>,<?= $G10_ressources-$G10_ressources_prec ?>,<?= ($G10_ressources_prec!=0)?(($G10_ressources-$G10_ressources_prec)/$G10_ressources_prec)*100:0 ?>],
            ['G15','Dépôts à terme',<?= $G15_ressources_prec ?>,<?= $G15_ressources ?>,<?= $G15_ressources-$G15_ressources_prec ?>,<?= ($G15_ressources_prec!=0)?(($G15_ressources-$G15_ressources_prec)/$G15_ressources_prec)*100:0 ?>],
            ['G2A','Épargne régime spécial',<?= $G2A_ressources_prec ?>,<?= $G2A_ressources ?>,<?= $G2A_ressources-$G2A_ressources_prec ?>,<?= ($G2A_ressources_prec!=0)?(($G2A_ressources-$G2A_ressources_prec)/$G2A_ressources_prec)*100:0 ?>],
            ['V','FONDS PROPRES',<?= $fonds_propres_prec ?>,<?= $fonds_propres_cours ?>,<?= $fonds_propres_cours-$fonds_propres_prec ?>,<?= ($fonds_propres_prec!=0)?(($fonds_propres_cours-$fonds_propres_prec)/$fonds_propres_prec)*100:0 ?>],
            ['B','TOTAL RESSOURCES',<?= $total_ressources_prec ?>,<?= $total_ressources_cours ?>,<?= $total_ressources_cours-$total_ressources_prec ?>,<?= ($total_ressources_prec!=0)?(($total_ressources_cours-$total_ressources_prec)/$total_ressources_prec)*100:0 ?>],
            ['C','EXCÉDENT/DÉFICIT',<?= $excedent_prec ?>,<?= $excedent_cours ?>,<?= $excedent_cours-$excedent_prec ?>,<?= ($excedent_prec!=0)?(($excedent_cours-$excedent_prec)/$excedent_prec)*100:0 ?>]
        ];
        const ws = XLSX.utils.aoa_to_sheet(data);
        XLSX.utils.book_append_sheet(wb, ws, "EMPLOIS_RESSOURCES");
        XLSX.writeFile(wb, 'DIMF_2005_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);
    });
</script>
</body>
</html>