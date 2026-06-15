<?php
// DIMF_2009.php - Détail du compte 6221 (Personnel extérieur) avec FPDF
session_start();

// ============================================================
// CONFIGURATION BDD
// ============================================================
require_once '../../databases/database.php';

require_once '../../fpdf/fpdf.php';

class PDF_DIMF extends FPDF {
    public $codeDimf = 'DIMF';
    public $titreDimf = 'Etat financier';
    public $nomSfd = 'SFD';
    public $periode = '';
    public $exercice = '';

    // Utilisation de mb_convert_encoding (robuste pour les caractères accentués)
    static function u($str) {
        return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
    }

    function Header() {
        $this->SetFillColor(156,163,175);
        $this->Rect(0,0,$this->GetPageWidth(),28,'F');
        $this->SetFont('Arial','',7);
        $this->SetTextColor(255,255,255);
        $this->SetXY(8,3);
        $this->Cell(0,4,self::u('Republique de Cote d\'Ivoire  •  Ministere de l\'Economie et des Finances  -  DGTCP / DSFD'),0,1,'L');
        $this->SetFont('Arial','B',13);
        $this->SetX(8);
        $this->Cell(0,7,self::u($this->codeDimf.'  -  '.$this->titreDimf),0,1,'L');
        $this->SetFont('Arial','',8);
        $this->SetX(8);
        $this->Cell(0,5,self::u('SFD : '.$this->nomSfd.'   |   Periode : '.$this->periode.'   |   Exercice : '.$this->exercice.'   |   Arrete au : '.date('d/m/Y')),0,1,'L');
        $this->SetTextColor(0,0,0);
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial','I',7);
        $this->SetTextColor(100,116,139);
        $this->Cell(0,4,self::u('SICS-BCEAO  •  Genere le '.date('d/m/Y a H:i:s').'  •  Page '.$this->PageNo().'/{nb}'),0,0,'C');
    }

    function SectionTitle($label) {
        $this->SetFont('Arial','B',9);
        $this->SetFillColor(0,0,0);
        $this->SetTextColor(255,255,255);
        $this->Cell(0,7,self::u('  '.strtoupper($label)),0,1,'L',true);
        $this->SetTextColor(0,0,0);
        $this->Ln(1);
    }

    function TableHeader($cols) {
        $this->SetFont('Arial','B',8);
        $this->SetFillColor(248,250,252);
        $this->SetTextColor(30,41,59);
        $this->SetDrawColor(226,232,240);
        foreach ($cols as $col) {
            $align = isset($col['align'])?$col['align']:'L';
            $this->Cell($col['w'],6,self::u($col['label']),1,0,$align,true);
        }
        $this->Ln();
    }

    function TableRow($cols, $data, $style='') {
        $fill = false;
        if ($style=='subtotal') {
            $this->SetFillColor(248,250,252);
            $this->SetFont('Arial','B',8);
            $fill = true;
        } elseif ($style=='total') {
            $this->SetFillColor(240,253,244);
            $this->SetFont('Arial','B',8.5);
            $fill = true;
        } else {
            $this->SetFillColor(255,255,255);
            $this->SetFont('Arial','',7.5);
        }
        $this->SetTextColor(15,23,42);
        $this->SetDrawColor(226,232,240);
        foreach ($cols as $i=>$col) {
            $val = isset($data[$i])?$data[$i]:'';
            $align = isset($col['align'])?$col['align']:'L';
            $this->Cell($col['w'],5.5,self::u($val),1,0,$align,$fill);
        }
        $this->Ln();
    }

    static function montant($val) {
        return number_format((float)$val,0,',',' ').' F';
    }
}


// ============================================================
// PARAMÈTRES DE PÉRIODE
// ============================================================
$exercice = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$type_periode = isset($_GET['type_periode']) ? $_GET['type_periode'] : 'mensuel';
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : 12;
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4;
$semestre = isset($_GET['semestre']) ? (int)$_GET['semestre'] : null;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {
    try {
        // Création de la table si elle n'existe pas
        $pdo->exec("CREATE TABLE IF NOT EXISTS personnel_exterieur (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exercice INT NOT NULL,
            categorie VARCHAR(100) NOT NULL,
            nationaux INT DEFAULT 0,
            autre_umoa INT DEFAULT 0,
            hors_umoa INT DEFAULT 0,
            secteur_primaire INT DEFAULT 0,
            secteur_secondaire INT DEFAULT 0,
            secteur_tertiaire INT DEFAULT 0,
            total_effectif INT DEFAULT 0,
            facturation DECIMAL(15,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_exercice_categorie (exercice, categorie)
        )");

        // Supprimer les anciennes données
        $stmtDel = $pdo->prepare("DELETE FROM personnel_exterieur WHERE exercice = :exercice");
        $stmtDel->execute([':exercice' => $exercice]);

        // Insérer les nouvelles données
        $stmtIns = $pdo->prepare("INSERT INTO personnel_exterieur (exercice, categorie, nationaux, autre_umoa, hors_umoa, secteur_primaire, secteur_secondaire, secteur_tertiaire, total_effectif, facturation) VALUES (:exercice, :categorie, :nationaux, :autre_umoa, :hors_umoa, :secteur_primaire, :secteur_secondaire, :secteur_tertiaire, :total_effectif, :facturation)");

        $categories = [
            'ZB1' => 'Cadres Supérieurs',
            'ZB2' => 'Techniciens Supérieurs et cadres moyens',
            'ZB3' => 'Techniciens Agents de Maîtrise et ouvriers qualifiés',
            'ZB4' => 'Employés, manœuvres, ouvriers et apprentis'
        ];

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

        $message = "Données enregistrées avec succès !";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }

    // Redirection POST → GET
    $url = "DIMF_2009.php?exercice=$exercice&type_periode=$type_periode" .
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
$personnel_data = [];
$categories = [
    'ZB1' => 'Cadres Supérieurs',
    'ZB2' => 'Techniciens Supérieurs et cadres moyens',
    'ZB3' => 'Techniciens Agents de Maîtrise et ouvriers qualifiés',
    'ZB4' => 'Employés, manœuvres, ouvriers et apprentis'
];

try {
    $stmt = $pdo->prepare("SELECT * FROM personnel_exterieur WHERE exercice = :exercice");
    $stmt->execute([':exercice' => $exercice]);
    foreach ($stmt->fetchAll() as $row) {
        $personnel_data[$row['categorie']] = $row;
    }
} catch (PDOException $e) {
    // Table inexistante, on continue
}

// Calcul des totaux
$totaux = [
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
    $data = $personnel_data[$code] ?? null;
    if ($data) {
        $totaux['nationaux'] += (int)$data['nationaux'];
        $totaux['autre_umoa'] += (int)$data['autre_umoa'];
        $totaux['hors_umoa'] += (int)$data['hors_umoa'];
        $totaux['secteur_primaire'] += (int)$data['secteur_primaire'];
        $totaux['secteur_secondaire'] += (int)$data['secteur_secondaire'];
        $totaux['secteur_tertiaire'] += (int)$data['secteur_tertiaire'];
        $totaux['total_effectif'] += (int)$data['total_effectif'];
        $totaux['facturation'] += (float)$data['facturation'];
    }
}

// ============================================================
// GÉNÉRATION DU PDF (si format=pdf)
// ============================================================
$format = isset($_GET['format']) ? $_GET['format'] : 'html';

if ($format === 'pdf') {
    switch ($type_periode) {
        case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
        case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
        case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
        default:          $lib_periode = 'Annee ' . $exercice;
    }

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
        ['label' => 'Catégorie',        'w' => 55],
        ['label' => 'Nationaux',        'w' => 20, 'align' => 'R'],
        ['label' => 'Autres UMOA',      'w' => 25, 'align' => 'R'],
        ['label' => 'Hors UMOA',        'w' => 25, 'align' => 'R'],
        ['label' => 'Primaire',         'w' => 20, 'align' => 'R'],
        ['label' => 'Secondaire',       'w' => 22, 'align' => 'R'],
        ['label' => 'Tertiaire',        'w' => 22, 'align' => 'R'],
        ['label' => 'Total effectif',   'w' => 30, 'align' => 'R'],
        ['label' => 'Facturation (FCFA)','w' => 40, 'align' => 'R']
    ];

    $pdf->SectionTitle('Personnel extérieur');
    $pdf->TableHeader($cols);

    foreach ($categories as $code => $lib) {
        $d = $personnel_data[$code] ?? [
            'nationaux' => 0,
            'autre_umoa' => 0,
            'hors_umoa' => 0,
            'secteur_primaire' => 0,
            'secteur_secondaire' => 0,
            'secteur_tertiaire' => 0,
            'total_effectif' => 0,
            'facturation' => 0
        ];
        $pdf->TableRow($cols, [
            PDF_DIMF::u($lib),
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

    $pdf->TableRow($cols, [
        'TOTAL',
        $totaux['nationaux'],
        $totaux['autre_umoa'],
        $totaux['hors_umoa'],
        $totaux['secteur_primaire'],
        $totaux['secteur_secondaire'],
        $totaux['secteur_tertiaire'],
        $totaux['total_effectif'],
        PDF_DIMF::montant($totaux['facturation'])
    ], 'total');

    $pdf->Output('I', 'DIMF_2009_Personnel_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2009 - Personnel extérieur (compte 6221)</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#f1f5f9;padding:24px;}
        .dashboard{max-width:1400px;margin:0 auto;}
        .page-header{background:linear-gradient(135deg,#3b82f6,#60a5fa);border-radius:24px;padding:20px 28px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;}
        .header-left h1{font-size:1.6rem;font-weight:600;color:white;margin-bottom:6px;display:flex;align-items:center;gap:10px;}
        .subtitle{font-size:0.8rem;color:#e0f2fe;}
        .badge{background:#2563eb;color:white;padding:4px 12px;border-radius:30px;font-size:0.7rem;}
        .btn-group{display:flex;gap:12px;}
        .btn-excel,.btn-pdf{display:inline-flex;align-items:center;gap:8px;padding:8px 20px;border-radius:40px;font-weight:500;font-size:0.85rem;border:none;cursor:pointer;text-decoration:none;}
        .btn-excel{background:#10b981;color:white;}
        .btn-pdf{background:#ef4444;color:white;}
        .card{background:white;border-radius:20px;padding:20px 24px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);}
        .card-header{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #eef2f6;font-weight:600;color:#1e40af;}
        .filters-row{display:flex;flex-wrap:wrap;align-items:flex-end;gap:20px;}
        .filter-item{display:flex;flex-direction:column;gap:6px;}
        .filter-item label{font-size:0.7rem;font-weight:600;text-transform:uppercase;color:#4b5563;}
        .filter-item select,.filter-item input{border:1px solid #d1d5db;border-radius:12px;padding:8px 14px;font-size:0.85rem;}
        .btn-apply{background:#3b82f6;color:white;border:none;border-radius:40px;padding:8px 24px;cursor:pointer;}
        .table-wrapper{overflow-x:auto;}
        table{width:100%;border-collapse:collapse;font-size:0.85rem;}
        th{text-align:left;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;}
        td{padding:10px 16px;border-bottom:1px solid #f1f5f9;}
        .text-right{text-align:right;}
        .total-row{background:#f0fdf4;font-weight:700;}
        .info-box{background:#eef2ff;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:16px;display:flex;align-items:center;gap:14px;}
        input[type="number"]{width:100px;text-align:right;}
        .page-footer{text-align:center;font-size:0.75rem;color:#6b7280;margin-top:16px;}
        @media print{.btn-group,.page-footer,#filtersCard{display:none;}}
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
            <?php
            $pdf_params = http_build_query([
                'exercice' => $exercice,
                'type_periode' => $type_periode,
                'mois' => $mois,
                'trimestre' => $trimestre,
                'semestre' => $semestre,
                'format' => 'pdf'
            ]);
            ?>
            <a class="btn-pdf" href="?<?= $pdf_params ?>" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
    </div>

    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="filters-row">
            <div class="filter-item">
                <label>Année</label>
                <select id="exerciceSelect">
                    <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                        <option value="<?= $y ?>" <?= $y==$exercice?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="filter-item">
                <label>Type période</label>
                <select id="typePeriodeSelect">
                    <option value="mensuel" <?= $type_periode=='mensuel'?'selected':'' ?>>Mensuel</option>
                    <option value="trimestre" <?= $type_periode=='trimestre'?'selected':'' ?>>Trimestre</option>
                    <option value="semestre" <?= $type_periode=='semestre'?'selected':'' ?>>Semestre</option>
                    <option value="annuel" <?= $type_periode=='annuel'?'selected':'' ?>>Annuel</option>
                </select>
            </div>
            <div class="filter-item" id="dynamicSelectContainer">
                <?php
                if ($type_periode == 'mensuel') {
                    echo '<label>Mois</label><select id="moisSelect">';
                    for ($m=1;$m<=12;$m++) {
                        $selected = ($m == $mois) ? 'selected' : '';
                        echo "<option value='$m' $selected>" . str_pad($m,2,'0') . " - " . date('F', mktime(0,0,0,$m,1)) . "</option>";
                    }
                    echo '</select>';
                } elseif ($type_periode == 'trimestre') {
                    echo '<label>Trimestre</label><select id="trimestreSelect">';
                    for ($t=1;$t<=4;$t++) {
                        $selected = ($t == $trimestre) ? 'selected' : '';
                        echo "<option value='$t' $selected>$t" . ($t==1?'er':'ème') . " Trimestre</option>";
                    }
                    echo '</select>';
                } elseif ($type_periode == 'semestre') {
                    echo '<label>Semestre</label><select id="semestreSelect">';
                    for ($s=1;$s<=2;$s++) {
                        $selected = ($s == $semestre) ? 'selected' : '';
                        echo "<option value='$s' $selected>$s" . ($s==1?'er':'e') . " semestre</option>";
                    }
                    echo '</select>';
                } else {
                    echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
                }
                ?>
            </div>
            <button class="btn-apply" onclick="appliquerFiltres()"><i class="fas fa-filter"></i> Appliquer</button>
        </div>
        <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;"><i class="fas fa-info-circle"></i> Choisissez le type de période pour affiner la date d'arrêté.</div>
    </div>

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
                        <tr>
                            <th>Catégorie</th>
                            <th>Nationaux</th>
                            <th>Autres UMOA</th>
                            <th>Hors UMOA</th>
                            <th>Secteur primaire</th>
                            <th>Secteur secondaire</th>
                            <th>Secteur tertiaire</th>
                            <th class="text-right">Total effectif</th>
                            <th class="text-right">Facturation (FCFA)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($categories as $code => $lib): 
                            $d = $personnel_data[$code] ?? [
                                'nationaux' => 0,
                                'autre_umoa' => 0,
                                'hors_umoa' => 0,
                                'secteur_primaire' => 0,
                                'secteur_secondaire' => 0,
                                'secteur_tertiaire' => 0,
                                'total_effectif' => 0,
                                'facturation' => 0
                            ];
                        ?>
                        <tr>
                            <td class="text-left"><?= htmlspecialchars($lib) ?></td>
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
                        <tr class="total-row">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($totaux['nationaux'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux['autre_umoa'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux['hors_umoa'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux['secteur_primaire'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux['secteur_secondaire'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux['secteur_tertiaire'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux['total_effectif'],0,',',' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($totaux['facturation'],0,',',' ') ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="filters-row" style="justify-content:flex-end; margin-top:16px;">
                <button type="submit" class="btn-apply"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-chart-pie"></i> SYNTHÈSE</div>
        <div class="info-box">
            <strong>Effectifs :</strong> Nationaux <?= number_format($totaux['nationaux'],0,',',' ') ?> | Autres UMOA <?= number_format($totaux['autre_umoa'],0,',',' ') ?> | Hors UMOA <?= number_format($totaux['hors_umoa'],0,',',' ') ?> | Total <?= number_format($totaux['total_effectif'],0,',',' ') ?><br>
            <strong>Facturation totale :</strong> <?= number_format($totaux['facturation'],0,',',' ') ?> FCFA
        </div>
    </div>

    <div class="page-footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>) arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>
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
            html = '<label>Mois</label><select id="moisSelect">';
            for (let m=1; m<=12; m++) {
                const selected = (m === currentMois) ? 'selected' : '';
                const monthName = new Date(2000, m-1, 1).toLocaleString('fr', {month:'long'});
                html += `<option value="${m}" ${selected}>${String(m).padStart(2,'0')} - ${monthName}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select id="trimestreSelect">';
            for (let t=1; t<=4; t++) {
                const selected = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${selected}>${t}${t===1?'er':'ème'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select id="semestreSelect">';
            for (let s=1; s<=2; s++) {
                const selected = (s === currentSemestre) ? 'selected' : '';
                html += `<option value="${s}" ${selected}>${s}${s===1?'er':'e'} semestre</option>`;
            }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
        }
        container.innerHTML = html;
    }

    function appliquerFiltres() {
        const exercice = document.getElementById('exerciceSelect').value;
        const type = document.getElementById('typePeriodeSelect').value;
        let url = 'DIMF_2009.php?exercice=' + exercice + '&type_periode=' + type;
        if (type === 'mensuel')   url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre')  url += '&semestre=' + document.getElementById('semestreSelect').value;
        window.location.href = url;
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        const data = [
            ['DIMF_2009 - PERSONNEL EXTÉRIEUR'],
            ['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],
            [],
            ['Catégorie','Nationaux','Autres UMOA','Hors UMOA','Primaire','Secondaire','Tertiaire','Total effectif','Facturation']
        ];
        <?php foreach($categories as $code => $lib): 
            $d = $personnel_data[$code] ?? [
                'nationaux' => 0,
                'autre_umoa' => 0,
                'hors_umoa' => 0,
                'secteur_primaire' => 0,
                'secteur_secondaire' => 0,
                'secteur_tertiaire' => 0,
                'total_effectif' => 0,
                'facturation' => 0
            ];
        ?>
        data.push(['<?= addslashes($lib) ?>', <?= $d['nationaux'] ?>, <?= $d['autre_umoa'] ?>, <?= $d['hors_umoa'] ?>, <?= $d['secteur_primaire'] ?>, <?= $d['secteur_secondaire'] ?>, <?= $d['secteur_tertiaire'] ?>, <?= $d['total_effectif'] ?>, <?= $d['facturation'] ?>]);
        <?php endforeach; ?>
        data.push(['TOTAL', <?= $totaux['nationaux'] ?>, <?= $totaux['autre_umoa'] ?>, <?= $totaux['hors_umoa'] ?>, <?= $totaux['secteur_primaire'] ?>, <?= $totaux['secteur_secondaire'] ?>, <?= $totaux['secteur_tertiaire'] ?>, <?= $totaux['total_effectif'] ?>, <?= $totaux['facturation'] ?>]);
        const ws = XLSX.utils.aoa_to_sheet(data);
        XLSX.utils.book_append_sheet(wb, ws, "PERSONNEL_EXTERIEUR");
        XLSX.writeFile(wb, 'DIMF_2009_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }
</script>
</body>
</html>