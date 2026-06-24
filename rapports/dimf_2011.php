<?php
// DIMF_2011.php - Informations annexes
// Utilise la table z_bceao_infos_annexes (existe déjà)

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
        // Supprimer les anciennes données pour l'exercice
        $stmtDel = $pdo->prepare("DELETE FROM z_bceao_infos_annexes WHERE exercice = :exercice");
        $stmtDel->execute([':exercice' => $exercice]);

        // Définition des indicateurs avec leur type
        $indicateurs = [
            'ZC01' => ['type' => 'montant', 'poste' => 'zc01'],
            'ZC02' => ['type' => 'montant', 'poste' => 'zc02'],
            'ZC03' => ['type' => 'montant', 'poste' => 'zc03'],
            'ZC04' => ['type' => 'effectif', 'poste' => 'zc04'],
            'ZC05' => ['type' => 'effectif', 'poste' => 'zc05'],
            'ZC06' => ['type' => 'effectif', 'poste' => 'zc06'],
            'ZC07' => ['type' => 'effectif', 'poste' => 'zc07'],
            'ZC08' => ['type' => 'effectif', 'poste' => 'zc08'],
            'ZC09' => ['type' => 'effectif', 'poste' => 'zc09'],
            'ZC10' => ['type' => 'effectif', 'poste' => 'zc10'],
            'ZC11' => ['type' => 'effectif', 'poste' => 'zc11'],
            'ZC12' => ['type' => 'montant', 'poste' => 'zc12'],
            'ZC13' => ['type' => 'montant', 'poste' => 'zc13'],
            'ZC14' => ['type' => 'montant', 'poste' => 'zc14'],
            'ZC15' => ['type' => 'montant', 'poste' => 'zc15'],
            'ZC16' => ['type' => 'montant', 'poste' => 'zc16'],
            'ZC17' => ['type' => 'montant', 'poste' => 'zc17']
        ];

        $stmtIns = $pdo->prepare("INSERT INTO z_bceao_infos_annexes 
            (exercice, code_indicateur, valeur_montant, valeur_effectif, statut) 
            VALUES (:exercice, :code, :montant, :effectif, 'actif')");

        foreach ($indicateurs as $code => $info) {
            $val = isset($_POST[$info['poste']]) ? trim($_POST[$info['poste']]) : '';
            if ($info['type'] === 'montant') {
                $valeur_montant = (float) str_replace([' ', ','], ['', '.'], $val);
                $valeur_effectif = null;
            } else {
                $valeur_montant = null;
                $valeur_effectif = (int) $val;
            }
            $stmtIns->execute([
                ':exercice' => $exercice,
                ':code' => $code,
                ':montant' => $valeur_montant,
                ':effectif' => $valeur_effectif
            ]);
        }
        $message = "Informations annexes enregistrées avec succès !";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
    // Redirection pour éviter la resoumission
    $url = "DIMF_2011.php?exercice=$exercice&type_periode=$type_periode" .
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
// RÉCUPÉRATION DES DONNÉES EXISTANTES
// ============================================================
$infos = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM z_bceao_infos_annexes WHERE exercice = :exercice AND statut = 'actif'");
    $stmt->execute([':exercice' => $exercice]);
    foreach ($stmt->fetchAll() as $row) {
        $infos[$row['code_indicateur']] = $row;
    }
} catch (PDOException $e) {}

// ============================================================
// VALEURS CALCULÉES AUTOMATIQUEMENT (pour pré-remplir)
// ============================================================
$donnees_calculees = [
    'nb_membres_total' => 0,
    'nb_membres_hommes' => 0,
    'nb_membres_femmes' => 0,
    'depots_terme_plus_1_an_membres' => 0,
    'epargne_regime_special' => 0
];
try {
    // ZC04 : nombre total de clients actifs
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM clients WHERE statut = 'actif'");
    $stmt->execute();
    $donnees_calculees['nb_membres_total'] = (int)$stmt->fetch()['total'];
} catch (PDOException $e) {}
try {
    // ZC06 et ZC07 : genre des clients
    $stmt = $pdo->prepare("SELECT 
        SUM(CASE WHEN genre = 'Masculin' THEN 1 ELSE 0 END) as hommes, 
        SUM(CASE WHEN genre = 'Feminin' THEN 1 ELSE 0 END) as femmes 
        FROM clients WHERE statut = 'actif'");
    $stmt->execute();
    $r = $stmt->fetch();
    $donnees_calculees['nb_membres_hommes'] = (int)$r['hommes'];
    $donnees_calculees['nb_membres_femmes'] = (int)$r['femmes'];
} catch (PDOException $e) {}
try {
    // ZC13 : dépôts à terme > 1 an (comptes DAT en cours)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital_initial), 0) as total FROM comptes_dat WHERE statut = 'en cours'");
    $stmt->execute();
    $donnees_calculees['depots_terme_plus_1_an_membres'] = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}
try {
    // ZC14 : épargne régime spécial (comptes épargne actifs)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(c.solde), 0) as total 
        FROM comptes c 
        INNER JOIN produits p ON c.produit_id = p.produit_id 
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id 
        WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0");
    $stmt->execute();
    $donnees_calculees['epargne_regime_special'] = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// Fonction utilitaire pour récupérer la valeur affichée
function getValeur($infos, $code, $type, $calcule = null) {
    if (isset($infos[$code])) {
        return $type === 'montant' ? (float)$infos[$code]['valeur_montant'] : (int)$infos[$code]['valeur_effectif'];
    }
    return $calcule ?? 0;
}

// ============================================================
// DÉFINITION DES INDICATEURS POUR L'AFFICHAGE
// ============================================================
$liste_indicateurs = [
    ['code' => 'ZC01', 'libelle' => 'Encours des engagements par signature à court terme', 'type' => 'montant', 'calcule' => null],
    ['code' => 'ZC02', 'libelle' => 'Encours des engagements par signature à moyen et long termes', 'type' => 'montant', 'calcule' => null],
    ['code' => 'ZC03', 'libelle' => 'Montant total consacré par l\'institution aux opérations autre que les activités d\'épargne et de crédit', 'type' => 'montant', 'calcule' => null],
    ['code' => 'ZC04', 'libelle' => 'Nombre total de membres, bénéficiaires ou clients de l\'institution', 'type' => 'effectif', 'calcule' => 'nb_membres_total'],
    ['code' => 'ZC05', 'libelle' => 'Nombre total de groupements de l\'institution ainsi que de leur membres', 'type' => 'effectif', 'calcule' => null],
    ['code' => 'ZC06', 'libelle' => 'Nombre total de membres, bénéficiaires ou clients de sexe masculin de l\'institution', 'type' => 'effectif', 'calcule' => 'nb_membres_hommes'],
    ['code' => 'ZC07', 'libelle' => 'Nombre total de membres, bénéficiaires ou clients de sexe féminin de l\'institution', 'type' => 'effectif', 'calcule' => 'nb_membres_femmes'],
    ['code' => 'ZC08', 'libelle' => 'Nombre total de groupements bénéficiaires', 'type' => 'effectif', 'calcule' => null],
    ['code' => 'ZC09', 'libelle' => 'Nombre total d\'usagers bénéficiaires', 'type' => 'effectif', 'calcule' => null],
    ['code' => 'ZC10', 'libelle' => 'Nombre total de sociétaires bénéficiaires', 'type' => 'effectif', 'calcule' => null],
    ['code' => 'ZC11', 'libelle' => 'Population cible de la caisse (ou son estimation)', 'type' => 'effectif', 'calcule' => null],
    ['code' => 'ZC12', 'libelle' => '126-127-128 Dépôts à plus d\'un an du SFD auprès des institutions financières', 'type' => 'montant', 'calcule' => null],
    ['code' => 'ZC13', 'libelle' => '252- Dépôts à terme à plus d\'un an des membres, bénéficiaires ou clients auprès de la caisse', 'type' => 'montant', 'calcule' => 'depots_terme_plus_1_an_membres'],
    ['code' => 'ZC14', 'libelle' => '253-Comptes d\'épargne à régime spécial', 'type' => 'montant', 'calcule' => 'epargne_regime_special'],
    ['code' => 'ZC15', 'libelle' => '254-255- Autres dépôts à plus d\'un an des membres, bénéficiaires ou clients auprès de la caisse', 'type' => 'montant', 'calcule' => null],
    ['code' => 'ZC16', 'libelle' => 'Recouvrements sur prêts intervenus au cours de l\'exercice', 'type' => 'montant', 'calcule' => null],
    ['code' => 'ZC17', 'libelle' => 'Recouvrements sur prêts attendus au cours de l\'exercice', 'type' => 'montant', 'calcule' => null]
];

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
    $pdf->codeDimf  = 'DIMF_2011';
    $pdf->titreDimf = 'Informations annexes';
    $pdf->nomSfd    = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode   = $lib_periode;
    $pdf->exercice  = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'CODE', 'w' => 20],
        ['label' => 'LIBELLÉS', 'w' => 120],
        ['label' => 'VALEUR', 'w' => 45, 'align' => 'R']
    ];
    $pdf->SectionTitle('Informations annexes');
    $pdf->TableHeader($cols);

    foreach ($liste_indicateurs as $ind) {
        $code = $ind['code'];
        $libelle = $ind['libelle'];
        $type = $ind['type'];
        $calcule_key = $ind['calcule'];
        $valeur = getValeur($infos, $code, $type, $calcule_key ? $donnees_calculees[$calcule_key] : null);
        $affichage = ($type === 'montant') ? PDF_DIMF::montant($valeur) : $valeur;
        $pdf->TableRow($cols, [$code, $libelle, $affichage]);
    }
    $pdf->Output('I', 'DIMF_2011_InfosAnnexes_' . $exercice . '_' . $type_periode . '.pdf');
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
    <title>DIMF_2011 - Informations annexes</title>
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
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px 20px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .page-footer, #filtersCard { display:none; } }
        .auto-value { background-color:#f0fdf4; }
        .input-group { display:flex; align-items:center; gap:8px; }
        .input-group input { flex:1; }
        .auto-badge { font-size:0.65rem; color:#16a34a; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-info-circle"></i> DIMF_2011 - INFORMATIONS ANNEXES</h1>
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

    <!-- ===== TABLEAU DE SAISIE ===== -->
    <div class="card">
        <div class="card-header"><i class="fas fa-edit"></i> SAISIE DES INFORMATIONS ANNEXES</div>
        <form method="post">
            <input type="hidden" name="action" value="save">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th style="width:80px;">CODE</th>
                            <th>LIBELLÉS</th>
                            <th style="width:200px;">VALEUR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($liste_indicateurs as $ind): 
                            $code = $ind['code'];
                            $libelle = $ind['libelle'];
                            $type = $ind['type'];
                            $calcule_key = $ind['calcule'];
                            $valeur = getValeur($infos, $code, $type, $calcule_key ? $donnees_calculees[$calcule_key] : null);
                            $is_auto = ($calcule_key !== null && !isset($infos[$code]));
                            $input_name = $ind['code'] === 'ZC01' ? 'zc01' : 
                                          ($ind['code'] === 'ZC02' ? 'zc02' : 
                                          ($ind['code'] === 'ZC03' ? 'zc03' : 
                                          ($ind['code'] === 'ZC04' ? 'zc04' : 
                                          ($ind['code'] === 'ZC05' ? 'zc05' : 
                                          ($ind['code'] === 'ZC06' ? 'zc06' : 
                                          ($ind['code'] === 'ZC07' ? 'zc07' : 
                                          ($ind['code'] === 'ZC08' ? 'zc08' : 
                                          ($ind['code'] === 'ZC09' ? 'zc09' : 
                                          ($ind['code'] === 'ZC10' ? 'zc10' : 
                                          ($ind['code'] === 'ZC11' ? 'zc11' : 
                                          ($ind['code'] === 'ZC12' ? 'zc12' : 
                                          ($ind['code'] === 'ZC13' ? 'zc13' : 
                                          ($ind['code'] === 'ZC14' ? 'zc14' : 
                                          ($ind['code'] === 'ZC15' ? 'zc15' : 
                                          ($ind['code'] === 'ZC16' ? 'zc16' : 'zc17')))))))))))))));
                        ?>
                        <tr>
                            <td><strong><?= $code ?></strong></td>
                            <td><?= htmlspecialchars($libelle) ?></td>
                            <td>
                                <div class="input-group">
                                    <input type="text" name="<?= $input_name ?>" value="<?= $type === 'montant' ? number_format($valeur,0,',',' ') : $valeur ?>" class="form-control form-control-sm <?= $is_auto ? 'auto-value' : '' ?>">
                                    <?php if ($is_auto): ?>
                                        <span class="auto-badge"><i class="fas fa-calculator"></i> auto</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:16px; text-align:right;">
                <button type="submit" class="btn-apply"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>

    <!-- ===== TABLEAU RÉCAPITULATIF (affichage) ===== -->
    <div class="card">
        <div class="card-header"><i class="fas fa-table"></i> ÉTAT DES INFORMATIONS ANNEXES</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>CODE</th>
                        <th>LIBELLÉS</th>
                        <th class="text-right">VALEUR</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste_indicateurs as $ind): 
                        $code = $ind['code'];
                        $libelle = $ind['libelle'];
                        $type = $ind['type'];
                        $calcule_key = $ind['calcule'];
                        $valeur = getValeur($infos, $code, $type, $calcule_key ? $donnees_calculees[$calcule_key] : null);
                        $affichage = ($type === 'montant') ? number_format($valeur,0,',',' ') . ' F' : $valeur;
                    ?>
                        <tr>
                            <td><?= $code ?></td>
                            <td><?= htmlspecialchars($libelle) ?></td>
                            <td class="text-right"><?= $affichage ?></td>
                        </tr>
                    <?php endforeach; ?>
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
            ['DIMF_2011 - INFORMATIONS ANNEXES'],
            ['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],
            [],
            ['CODE','LIBELLÉS','VALEUR']
        ];
        <?php foreach ($liste_indicateurs as $ind): 
            $code = $ind['code'];
            $type = $ind['type'];
            $calcule_key = $ind['calcule'];
            $valeur = getValeur($infos, $code, $type, $calcule_key ? $donnees_calculees[$calcule_key] : null);
        ?>
            data.push(['<?= $code ?>','<?= addslashes($ind['libelle']) ?>',<?= $type === 'montant' ? $valeur : $valeur ?>]);
        <?php endforeach; ?>
        const ws = XLSX.utils.aoa_to_sheet(data);
        XLSX.utils.book_append_sheet(wb, ws, "INFOS_ANNEXES");
        XLSX.writeFile(wb, 'DIMF_2011_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);
    });
</script>
</body>
</html>