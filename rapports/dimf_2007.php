<?php
// DIMF_2007.php - État des biens détenus dans le cadre de la concession
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
// TRAITEMENT DU FORMULAIRE (AJOUT / MODIFICATION / SUPPRESSION)
// ============================================================
$message = '';
$message_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $date_acquisition = (!empty($_POST['date_acquisition'])) ? $_POST['date_acquisition'] : null;
    $duree_concession = (!empty($_POST['duree_concession'])) ? (int)$_POST['duree_concession'] : null;
    try {
        // Création de la table si elle n'existe pas
        $pdo->exec("CREATE TABLE IF NOT EXISTS biens_concession (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exercice INT NOT NULL,
            libelle VARCHAR(255) NOT NULL,
            valeur_inventaire DECIMAL(15,2) DEFAULT 0,
            concessionnaire_nom VARCHAR(200),
            valeur_declaree DECIMAL(15,2) DEFAULT 0,
            date_acquisition DATE,
            duree_concession INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if ($_POST['action'] === 'add') {
            $stmt = $pdo->prepare("INSERT INTO biens_concession (exercice, libelle, valeur_inventaire, concessionnaire_nom, valeur_declaree, date_acquisition, duree_concession) VALUES (:exercice, :libelle, :valeur_inventaire, :concessionnaire, :valeur_declaree, :date_acquisition, :duree)");
            $stmt->execute([
                ':exercice' => $exercice,
                ':libelle' => $_POST['libelle'] ?? '',
                ':valeur_inventaire' => $_POST['valeur_inventaire'] ?? 0,
                ':concessionnaire' => $_POST['concessionnaire_nom'] ?? '',
                ':valeur_declaree' => $_POST['valeur_declaree'] ?? 0,
                ':date_acquisition' => $date_acquisition,
                ':duree' => $duree_concession
            ]);
            $message = "Bien ajouté !";
            $message_type = "success";
        } elseif ($_POST['action'] === 'delete' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("DELETE FROM biens_concession WHERE id = :id AND exercice = :exercice");
            $stmt->execute([':id' => (int)$_POST['id'], ':exercice' => $exercice]);
            $message = "Bien supprimé !";
            $message_type = "success";
        } elseif ($_POST['action'] === 'update' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("UPDATE biens_concession SET libelle = :libelle, valeur_inventaire = :valeur_inventaire, concessionnaire_nom = :concessionnaire, valeur_declaree = :valeur_declaree, date_acquisition = :date_acquisition, duree_concession = :duree WHERE id = :id AND exercice = :exercice");
            $stmt->execute([
                ':id' => (int)$_POST['id'],
                ':exercice' => $exercice,
                ':libelle' => $_POST['libelle'] ?? '',
                ':valeur_inventaire' => $_POST['valeur_inventaire'] ?? 0,
                ':concessionnaire' => $_POST['concessionnaire_nom'] ?? '',
                ':valeur_declaree' => $_POST['valeur_declaree'] ?? 0,
                ':date_acquisition' => $date_acquisition,
                ':duree' => $duree_concession
            ]);
            $message = "Bien modifié !";
            $message_type = "success";
        }
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
    // Redirection pour éviter double soumission
    $url = "DIMF_2007.php?exercice=$exercice&type_periode=$type_periode" .
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
// RÉCUPÉRATION DES BIENS
// ============================================================
$biens_concession = [];
$total_valeur_inventaire = 0;
$total_valeur_declaree = 0;
try {
    $stmt = $pdo->prepare("SELECT * FROM biens_concession WHERE exercice = :exercice ORDER BY id");
    $stmt->execute([':exercice' => $exercice]);
    $biens_concession = $stmt->fetchAll();
    foreach ($biens_concession as $bien) {
        $total_valeur_inventaire += (float)$bien['valeur_inventaire'];
        $total_valeur_declaree += (float)$bien['valeur_declaree'];
    }
} catch (PDOException $e) {}

$edit_bien = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM biens_concession WHERE id = :id AND exercice = :exercice");
        $stmt->execute([':id' => (int)$_GET['edit'], ':exercice' => $exercice]);
        $edit_bien = $stmt->fetch();
    } catch (PDOException $e) {}
}

// ============================================================
// GÉNÉRATION PDF (si format=pdf)
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
    $pdf->codeDimf  = 'DIMF_2007';
    $pdf->titreDimf = 'Biens en concession';
    $pdf->nomSfd    = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode   = $lib_periode;
    $pdf->exercice  = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'Libellé', 'w' => 60],
        ['label' => 'Valeur inventaire', 'w' => 45, 'align' => 'R'],
        ['label' => 'Concessionnaire', 'w' => 50],
        ['label' => 'Valeur déclarée', 'w' => 45, 'align' => 'R'],
        ['label' => 'Date acquisition', 'w' => 35, 'align' => 'C'],
        ['label' => 'Durée (ans)', 'w' => 30, 'align' => 'C']
    ];
    $pdf->SectionTitle('Biens en concession');
    $pdf->TableHeader($cols);
    foreach ($biens_concession as $bien) {
        $pdf->TableRow($cols, [
            PDF_DIMF::u($bien['libelle']),
            PDF_DIMF::montant($bien['valeur_inventaire']),
            PDF_DIMF::u($bien['concessionnaire_nom'] ?: '-'),
            PDF_DIMF::montant($bien['valeur_declaree']),
            $bien['date_acquisition'] ? date('d/m/Y', strtotime($bien['date_acquisition'])) : '-',
            $bien['duree_concession'] ? $bien['duree_concession'] . ' ans' : '-'
        ]);
    }
    $pdf->TableRow($cols, [
        'TOTAL',
        PDF_DIMF::montant($total_valeur_inventaire),
        '',
        PDF_DIMF::montant($total_valeur_declaree),
        '',
        ''
    ], 'total');
    $pdf->Output('I', 'DIMF_2007_BiensConcession_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}
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
        /* Styles identiques aux autres DIMF */
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
        .action-buttons { display:flex; gap:8px; }
        .btn-warning { background:#f59e0b; color:white; padding:4px 12px; border-radius:20px; text-decoration:none; font-size:0.75rem; }
        .btn-danger { background:#ef4444; color:white; padding:4px 12px; border-radius:20px; border:none; cursor:pointer; font-size:0.75rem; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .page-footer, #filtersCard { display:none; } }
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

    <?php if($message): ?>
        <div class="info-box" style="background:<?= $message_type=='success'?'#d1fae5':'#fee2e2' ?>;border-left-color:<?= $message_type=='success'?'#10b981':'#ef4444' ?>;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><i class="fas <?= $edit_bien?'fa-edit':'fa-plus-circle' ?>"></i> <?= $edit_bien?'MODIFIER UN BIEN':'AJOUTER UN BIEN' ?></div>
        <form method="post">
            <input type="hidden" name="action" value="<?= $edit_bien?'update':'add' ?>">
            <?php if($edit_bien): ?><input type="hidden" name="id" value="<?= $edit_bien['id'] ?>"><?php endif; ?>
            <div class="filters-row" style="margin-bottom:0;">
                <div class="filter-item">
                    <label>Libellé *</label>
                    <input type="text" name="libelle" required value="<?= $edit_bien?htmlspecialchars($edit_bien['libelle']):'' ?>">
                </div>
                <div class="filter-item">
                    <label>Valeur inventaire (FCFA)</label>
                    <input type="number" name="valeur_inventaire" value="<?= $edit_bien?(int)$edit_bien['valeur_inventaire']:0 ?>">
                </div>
                <div class="filter-item">
                    <label>Concessionnaire</label>
                    <input type="text" name="concessionnaire_nom" value="<?= $edit_bien?htmlspecialchars($edit_bien['concessionnaire_nom']):'' ?>">
                </div>
                <div class="filter-item">
                    <label>Valeur déclarée (FCFA)</label>
                    <input type="number" name="valeur_declaree" value="<?= $edit_bien?(int)$edit_bien['valeur_declaree']:0 ?>">
                </div>
                <div class="filter-item">
                    <label>Date acquisition</label>
                    <input type="date" name="date_acquisition" value="<?= $edit_bien && $edit_bien['date_acquisition'] ? date('Y-m-d',strtotime($edit_bien['date_acquisition'])) : '' ?>">
                </div>
                <div class="filter-item">
                    <label>Durée (années)</label>
                    <input type="number" name="duree_concession" value="<?= $edit_bien && $edit_bien['duree_concession']!==null ? (int)$edit_bien['duree_concession'] : '' ?>">
                </div>
                <div class="filter-item">
                    <button type="submit" class="btn-apply"><?= $edit_bien?'Mettre à jour':'Ajouter' ?></button>
                </div>
                <?php if($edit_bien): ?>
                    <div class="filter-item">
                        <a href="DIMF_2007.php?exercice=<?= $exercice ?>&type_periode=<?= $type_periode ?><?= $type_periode=='mensuel'?"&mois=$mois":($type_periode=='trimestre'?"&trimestre=$trimestre":($type_periode=='semestre'?"&semestre=$semestre":"")) ?>" class="btn-warning">Annuler</a>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-list-ul"></i> LISTE DES BIENS</div>
        <?php if(empty($biens_concession)): ?>
            <div class="info-box">Aucun bien enregistré.</div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>Libellé</th><th class="text-right">Valeur inventaire</th><th>Concessionnaire</th><th class="text-right">Valeur déclarée</th><th>Date acquisition</th><th>Durée</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($biens_concession as $b): ?>
                            <tr>
                                <td><?= htmlspecialchars($b['libelle']) ?></td>
                                <td class="text-right"><?= number_format($b['valeur_inventaire'],0,',',' ') ?></td>
                                <td><?= htmlspecialchars($b['concessionnaire_nom']?:'-') ?></td>
                                <td class="text-right"><?= number_format($b['valeur_declaree'],0,',',' ') ?></td>
                                <td><?= $b['date_acquisition']?date('d/m/Y',strtotime($b['date_acquisition'])):'-' ?></td>
                                <td><?= $b['duree_concession']?$b['duree_concession'].' ans':'-' ?></td>
                                <td class="action-buttons">
                                    <a href="?exercice=<?= $exercice ?>&type_periode=<?= $type_periode ?><?= $type_periode=='mensuel'?"&mois=$mois":($type_periode=='trimestre'?"&trimestre=$trimestre":($type_periode=='semestre'?"&semestre=$semestre":"")) ?>&edit=<?= $b['id'] ?>" class="btn-warning"><i class="fas fa-edit"></i></a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                        <button type="submit" class="btn-danger"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($total_valeur_inventaire,0,',',' ') ?></strong></td>
                            <td></td>
                            <td class="text-right"><strong><?= number_format($total_valeur_declaree,0,',',' ') ?></strong></td>
                            <td colspan="3"></td>
                        </tr>
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
        form.target = '_blank';
        form.submit();
        form.target = '';
        form.removeChild(input);
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        const data = [['DIMF_2007 - BIENS EN CONCESSION'],['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],[],['Libellé','Valeur inventaire','Concessionnaire','Valeur déclarée','Date acquisition','Durée']];
        <?php foreach($biens_concession as $b): ?>
        data.push(['<?= addslashes($b['libelle']) ?>',<?= $b['valeur_inventaire'] ?>,'<?= addslashes($b['concessionnaire_nom']?:'-') ?>',<?= $b['valeur_declaree'] ?>,'<?= $b['date_acquisition']?date('d/m/Y',strtotime($b['date_acquisition'])):'-' ?>','<?= $b['duree_concession']??'-' ?>']);
        <?php endforeach; ?>
        data.push(['TOTAL',<?= $total_valeur_inventaire ?>,'',<?= $total_valeur_declaree ?>,'','']);
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