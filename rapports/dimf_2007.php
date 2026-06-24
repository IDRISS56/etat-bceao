<?php
// DIMF_2007.php - État des biens détenus dans le cadre de la concession
// Alimentation depuis immobilisations + saisie concessionnaire

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
// TRAITEMENT DU FORMULAIRE DE SAISIE CONCESSIONNAIRE
// ============================================================
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_concession') {
    // Récupération des données postées
    $concessionnaires = $_POST['concessionnaire'] ?? [];
    $immobilisation_ids = $_POST['immobilisation_id'] ?? [];

    foreach ($immobilisation_ids as $index => $immob_id) {
        $nom = trim($concessionnaires[$index] ?? '');
        // Récupérer les infos de l'immobilisation
        $stmt = $pdo->prepare("SELECT * FROM immobilisations WHERE immobilisation_id = :id");
        $stmt->execute([':id' => $immob_id]);
        $bien = $stmt->fetch();
        if ($bien) {
            $duree_annees = $bien['duree_mois_vie'] ? round($bien['duree_mois_vie'] / 12, 1) : 0;
            $code = 'DIMF_2007_1_' . ($index + 1); // numéro séquentiel basé sur l'ordre d'affichage
            // Vérifier si l'enregistrement existe déjà
            $stmt_check = $pdo->prepare("SELECT id FROM z_bceao_concessions WHERE exercice = :exo AND code = :code");
            $stmt_check->execute([':exo' => $exercice, ':code' => $code]);
            if ($stmt_check->fetch()) {
                // Mise à jour
                $stmt_upd = $pdo->prepare("UPDATE z_bceao_concessions SET 
                    duree = :duree,
                    valeur_inventaire = :v_inv,
                    concessionnaire_nom = :nom,
                    valeur_declaree_cahier = :v_dec
                    WHERE exercice = :exo AND code = :code");
                $stmt_upd->execute([
                    ':duree' => $duree_annees,
                    ':v_inv' => $bien['montant_achat'],
                    ':nom' => $nom,
                    ':v_dec' => $bien['valeur_nette'],
                    ':exo' => $exercice,
                    ':code' => $code
                ]);
            } else {
                // Insertion
                $stmt_ins = $pdo->prepare("INSERT INTO z_bceao_concessions 
                    (exercice, code, postes, duree, valeur_inventaire, concessionnaire_nom, valeur_declaree_cahier, statut)
                    VALUES (:exo, :code, :postes, :duree, :v_inv, :nom, :v_dec, 'actif')");
                $stmt_ins->execute([
                    ':exo' => $exercice,
                    ':code' => $code,
                    ':postes' => $bien['libelle'],
                    ':duree' => $duree_annees,
                    ':v_inv' => $bien['montant_achat'],
                    ':nom' => $nom,
                    ':v_dec' => $bien['valeur_nette']
                ]);
            }
        }
    }
    $message = "<div class='alert-success'><i class='fas fa-check-circle'></i> Concessionnaires enregistrés avec succès.</div>";
}

// ============================================================
// RÉCUPÉRATION DES IMMOBILISATIONS ET DES CONCESSIONS EXISTANTES
// ============================================================
// 1. Liste des immobilisations actives (ou toutes)
$immobilisations = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM immobilisations WHERE statut = 'actif' ORDER BY date_achat");
    $stmt->execute();
    $immobilisations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // ignore
}

// 2. Récupération des concessions existantes pour l'exercice
$concessions_exist = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM z_bceao_concessions WHERE exercice = :exo ORDER BY code");
    $stmt->execute([':exo' => $exercice]);
    $concessions_exist = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // ignore
}

// Création d'un tableau associatif code => données pour faciliter l'affichage
$concessions_by_code = [];
foreach ($concessions_exist as $c) {
    $concessions_by_code[$c['code']] = $c;
}

// On va afficher les immobilisations et proposer de saisir les concessionnaires
// Les codes seront générés séquentiellement

// ============================================================
// GÉNÉRATION PDF (si demandé)
// ============================================================
$format = isset($_POST['format']) ? $_POST['format'] : (isset($_GET['format']) ? $_GET['format'] : 'html');

if ($format === 'pdf') {
    // On utilise les données de z_bceao_concessions pour le PDF
    switch ($type_periode) {
        case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
        case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
        case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
        default:          $lib_periode = 'Annee ' . $exercice;
    }

    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->codeDimf  = 'DIMF_2007';
    $pdf->titreDimf = 'Biens en concession';
    $pdf->nomSfd    = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode   = $lib_periode;
    $pdf->exercice  = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'CODE', 'w' => 30],
        ['label' => 'postes', 'w' => 50],
        ['label' => 'DURÉE', 'w' => 25, 'align' => 'R'],
        ['label' => "VALEUR D'INVENTAIRE\nou VALEUR DE MARCHÉ", 'w' => 40, 'align' => 'R'],
        ['label' => 'CONCESSIONAIRE', 'w' => 50],
        ['label' => "VALEUR DÉCLARÉE\nDANS LE CAHIER DE CHARGES", 'w' => 40, 'align' => 'R']
    ];
    $pdf->SectionTitle('Biens en concession');
    $pdf->TableHeader($cols);

    $total_v_inv = 0;
    $total_v_dec = 0;
    foreach ($concessions_exist as $bien) {
        $pdf->TableRow($cols, [
            $bien['code'],
            PDF_DIMF::u($bien['postes'] ?? ''),
            $bien['duree'] ? $bien['duree'] . ' ans' : '-',
            PDF_DIMF::montant($bien['valeur_inventaire']),
            PDF_DIMF::u($bien['concessionnaire_nom'] ?: '-'),
            PDF_DIMF::montant($bien['valeur_declaree_cahier'])
        ]);
        $total_v_inv += $bien['valeur_inventaire'];
        $total_v_dec += $bien['valeur_declaree_cahier'];
    }

    $pdf->TableRow($cols, [
        '',
        'TOTAL',
        '',
        PDF_DIMF::montant($total_v_inv),
        '',
        PDF_DIMF::montant($total_v_dec)
    ], 'total');

    $pdf->Output('I', 'DIMF_2007_BiensConcession_' . $exercice . '_' . $type_periode . '.pdf');
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
    <title>DIMF_2007 - Biens en concession</title>
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
        .form-saisie { display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
        .form-saisie input { flex:1; min-width:150px; }
        .btn-save { background:#10b981; color:white; border:none; border-radius:40px; padding:6px 18px; cursor:pointer; }
        .btn-save:hover { background:#059669; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-landmark"></i> DIMF_2007 - BIENS EN CONCESSION</h1>
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

    <!-- ===== FORMULAIRE DE SAISIE DES CONCESSIONNAIRES ===== -->
    <div class="card">
        <div class="card-header"><i class="fas fa-pen"></i> Saisie des concessionnaires</div>
        <form method="post">
            <input type="hidden" name="action" value="save_concession">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>postes</th>
                            <th class="text-right">Durée</th>
                            <th class="text-right">Valeur d'inventaire</th>
                            <th class="text-right">Valeur nette</th>
                            <th>Concessionnaire <span class="text-muted">(saisir ici)</span></th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($immobilisations)): ?>
                            <tr><td colspan="7" class="text-center">Aucune immobilisation active.</td></tr>
                        <?php else: ?>
                            <?php
                            $i = 1;
                            foreach ($immobilisations as $bien):
                                $code = 'DIMF_2007_1_' . $i;
                                $duree = $bien['duree_mois_vie'] ? round($bien['duree_mois_vie'] / 12, 1) . ' ans' : '-';
                                // Récupérer le nom du concessionnaire déjà enregistré
                                $nom_existant = '';
                                if (isset($concessions_by_code[$code])) {
                                    $nom_existant = $concessions_by_code[$code]['concessionnaire_nom'];
                                }
                            ?>
                                <tr>
                                    <td><?= $code ?></td>
                                    <td><?= htmlspecialchars($bien['libelle']) ?></td>
                                    <td class="text-right"><?= $duree ?></td>
                                    <td class="text-right"><?= number_format($bien['montant_achat'],0,',',' ') ?></td>
                                    <td class="text-right"><?= number_format($bien['valeur_nette'],0,',',' ') ?></td>
                                    <td>
                                        <input type="hidden" name="immobilisation_id[]" value="<?= $bien['immobilisation_id'] ?>">
                                        <input type="text" name="concessionnaire[]" value="<?= htmlspecialchars($nom_existant) ?>" class="form-control form-control-sm" placeholder="Nom du concessionnaire">
                                    </td>
                                    <td>
                                        <button type="submit" class="btn-save btn-sm"><i class="fas fa-save"></i> Enregistrer</button>
                                    </td>
                                </tr>
                            <?php
                                $i++;
                            endforeach;
                            ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer tous les concessionnaires</button>
                <span class="text-muted ms-3">(seuls les champs remplis seront sauvegardés)</span>
            </div>
        </form>
    </div>

    <!-- ===== TABLEAU DE L'ÉTAT ===== -->
    <div class="card">
        <div class="card-header"><i class="fas fa-list-ul"></i> ÉTAT DES BIENS DÉTENUS DANS LE CADRE DE LA CONCESSION</div>
        <?php if(empty($concessions_exist)): ?>
            <div class="info-box">Aucune concession enregistrée pour l'exercice <?= $exercice ?>.</div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>CODE</th>
                            <th>postes</th>
                            <th class="text-right">DURÉE</th>
                            <th class="text-right">VALEUR D'INVENTAIRE<br>ou VALEUR DE MARCHÉ</th>
                            <th>CONCESSIONAIRE</th>
                            <th class="text-right">VALEUR DÉCLARÉE<br>DANS LE CAHIER DE CHARGES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total_v_inv = 0;
                        $total_v_dec = 0;
                        foreach ($concessions_exist as $bien):
                            $total_v_inv += $bien['valeur_inventaire'];
                            $total_v_dec += $bien['valeur_declaree_cahier'];
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($bien['code']) ?></td>
                                <td><?= htmlspecialchars($bien['postes'] ?? '') ?></td>
                                <td class="text-right"><?= $bien['duree'] ? $bien['duree'] . ' ans' : '-' ?></td>
                                <td class="text-right"><?= number_format($bien['valeur_inventaire'],0,',',' ') ?></td>
                                <td><?= htmlspecialchars($bien['concessionnaire_nom'] ?: '-') ?></td>
                                <td class="text-right"><?= number_format($bien['valeur_declaree_cahier'],0,',',' ') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td></td>
                            <td><strong>TOTAL</strong></td>
                            <td></td>
                            <td class="text-right"><strong><?= number_format($total_v_inv,0,',',' ') ?></strong></td>
                            <td></td>
                            <td class="text-right"><strong><?= number_format($total_v_dec,0,',',' ') ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="page-footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>) – Données issues des tables <code>immobilisations</code> et <code>z_bceao_concessions</code>
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
            ['DIMF_2007 - ÉTAT DES BIENS DÉTENUS DANS LE CADRE DE LA CONCESSION'],
            ['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],
            [],
            ['CODE','postes','DURÉE',"VALEUR D'INVENTAIRE ou VALEUR DE MARCHÉ",'CONCESSIONAIRE',"VALEUR DÉCLARÉE DANS LE CAHIER DE CHARGES"]
        ];
        <?php foreach ($concessions_exist as $bien): ?>
            data.push([
                '<?= addslashes($bien['code']) ?>',
                '<?= addslashes($bien['postes'] ?? '') ?>',
                '<?= $bien['duree'] ? $bien['duree'] . ' ans' : '-' ?>',
                <?= $bien['valeur_inventaire'] ?>,
                '<?= addslashes($bien['concessionnaire_nom'] ?: '') ?>',
                <?= $bien['valeur_declaree_cahier'] ?>
            ]);
        <?php endforeach; ?>
        data.push(['','TOTAL','',<?= $total_v_inv ?>,'',<?= $total_v_dec ?>]);
        const ws = XLSX.utils.aoa_to_sheet(data);
        XLSX.utils.book_append_sheet(wb, ws, "BIENS_CONCESSION");
        XLSX.writeFile(wb, 'DIMF_2007_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);
    });
</script>
</body>
</html>