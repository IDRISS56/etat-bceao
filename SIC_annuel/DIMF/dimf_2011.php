<?php
// DIMF_2011.php - Informations annexes (avec FPDF)
session_start();

require_once '../../fpdf/fpdf.php';

class PDF_DIMF extends FPDF {
    public $codeDimf = 'DIMF';
    public $titreDimf = 'Etat financier';
    public $nomSfd = 'SFD';
    public $periode = '';
    public $exercice = '';

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
            $align = isset($col['align']) ? $col['align'] : 'L';
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
            $val = isset($data[$i]) ? $data[$i] : '';
            $align = isset($col['align']) ? $col['align'] : 'L';
            $this->Cell($col['w'],5.5,self::u($val),1,0,$align,$fill);
        }
        $this->Ln();
    }

    static function montant($val) {
        return number_format((float)$val,0,',',' ').' F';
    }
}

// Connexion BDD (identique aux autres)
$host = 'localhost';
$dbname = 'microfinances_dg';
$username = 'root';
$password = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur : " . $e->getMessage());
}

// Paramètres période (identique)
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

// Traitement formulaire (sauvegarde)
$message = '';
$message_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS infos_annexes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exercice INT NOT NULL,
            code_indicateur VARCHAR(20) NOT NULL,
            valeur_montant DECIMAL(15,2) DEFAULT NULL,
            valeur_effectif INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_exercice_indicateur (exercice, code_indicateur)
        )");
        $stmtDel = $pdo->prepare("DELETE FROM infos_annexes WHERE exercice = :exercice");
        $stmtDel->execute([':exercice' => $exercice]);

        $stmtIns = $pdo->prepare("INSERT INTO infos_annexes (exercice, code_indicateur, valeur_montant, valeur_effectif) VALUES (:exercice, :code, :montant, :effectif)");

        $indicateurs = [
            'ZC01' => ['type' => 'montant', 'poste' => 'encours_engagements_ct'],
            'ZC02' => ['type' => 'montant', 'poste' => 'encours_engagements_mlt'],
            'ZC03' => ['type' => 'montant', 'poste' => 'montant_autres_activites'],
            'ZC04' => ['type' => 'effectif', 'poste' => 'nb_membres_total'],
            'ZC05' => ['type' => 'effectif', 'poste' => 'nb_groupements'],
            'ZC06' => ['type' => 'effectif', 'poste' => 'nb_membres_hommes'],
            'ZC07' => ['type' => 'effectif', 'poste' => 'nb_membres_femmes'],
            'ZC08' => ['type' => 'effectif', 'poste' => 'nb_groupements_beneficiaires'],
            'ZC09' => ['type' => 'effectif', 'poste' => 'nb_usagers_beneficiaires'],
            'ZC10' => ['type' => 'effectif', 'poste' => 'nb_societaires_beneficiaires'],
            'ZC11' => ['type' => 'effectif', 'poste' => 'population_cible'],
            'ZC12' => ['type' => 'montant', 'poste' => 'depots_plus_1_an_inst_fin'],
            'ZC13' => ['type' => 'montant', 'poste' => 'depots_terme_plus_1_an_membres'],
            'ZC14' => ['type' => 'montant', 'poste' => 'epargne_regime_special'],
            'ZC15' => ['type' => 'montant', 'poste' => 'autres_depots_plus_1_an_membres'],
            'ZC16' => ['type' => 'montant', 'poste' => 'recouvrements_prevus'],
            'ZC17' => ['type' => 'montant', 'poste' => 'recouvrements_attendus']
        ];

        foreach ($indicateurs as $code => $info) {
            if ($info['type'] == 'montant') {
                $valeur_montant = (float)($_POST[$info['poste']] ?? 0);
                $valeur_effectif = null;
            } else {
                $valeur_montant = null;
                $valeur_effectif = (int)($_POST[$info['poste']] ?? 0);
            }
            $stmtIns->execute([
                ':exercice' => $exercice,
                ':code' => $code,
                ':montant' => $valeur_montant,
                ':effectif' => $valeur_effectif
            ]);
        }
        $message = "Informations annexes enregistrées !";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
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

// Récupération données saisies
$infos = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM infos_annexes WHERE exercice = :exercice");
    $stmt->execute([':exercice' => $exercice]);
    foreach ($stmt->fetchAll() as $row) {
        $infos[$row['code_indicateur']] = $row;
    }
} catch (PDOException $e) {}

// Données calculées automatiquement (préremplissage)
$donnees_calculees = [
    'nb_membres_total' => 0,
    'nb_membres_hommes' => 0,
    'nb_membres_femmes' => 0,
    'depots_terme_plus_1_an_membres' => 0,
    'epargne_regime_special' => 0
];
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM clients WHERE statut = 'actif'");
    $stmt->execute();
    $donnees_calculees['nb_membres_total'] = (int)$stmt->fetch()['total'];
} catch (PDOException $e) {}
try {
    $stmt = $pdo->prepare("SELECT SUM(CASE WHEN genre = 'Masculin' THEN 1 ELSE 0 END) as hommes, SUM(CASE WHEN genre = 'Feminin' THEN 1 ELSE 0 END) as femmes FROM clients WHERE statut = 'actif'");
    $stmt->execute();
    $r = $stmt->fetch();
    $donnees_calculees['nb_membres_hommes'] = (int)$r['hommes'];
    $donnees_calculees['nb_membres_femmes'] = (int)$r['femmes'];
} catch (PDOException $e) {}
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital_initial), 0) as total FROM comptes_dat WHERE statut = 'en cours'");
    $stmt->execute();
    $donnees_calculees['depots_terme_plus_1_an_membres'] = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(c.solde), 0) as total FROM comptes c INNER JOIN produits p ON c.produit_id = p.produit_id INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0");
    $stmt->execute();
    $donnees_calculees['epargne_regime_special'] = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

function getValeur($infos, $code, $type, $calcule = null) {
    if (isset($infos[$code])) {
        return $type === 'montant' ? (float)$infos[$code]['valeur_montant'] : (int)$infos[$code]['valeur_effectif'];
    }
    return $calcule ?? 0;
}

// Génération PDF (si format=pdf)
$format = isset($_GET['format']) ? $_GET['format'] : 'html';
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

    $cols = [['label' => 'Code', 'w' => 20], ['label' => 'Indicateur', 'w' => 120], ['label' => 'Valeur', 'w' => 45, 'align' => 'R']];
    $pdf->SectionTitle('Informations annexes');
    $pdf->TableHeader($cols);

    $liste = [
        ['ZC01','Encours engagements CT', getValeur($infos,'ZC01','montant')],
        ['ZC02','Encours engagements MLT', getValeur($infos,'ZC02','montant')],
        ['ZC03','Autres activités', getValeur($infos,'ZC03','montant')],
        ['ZC04','Nb membres total', getValeur($infos,'ZC04','effectif', $donnees_calculees['nb_membres_total'])],
        ['ZC05','Nb groupements', getValeur($infos,'ZC05','effectif')],
        ['ZC06','Nb hommes', getValeur($infos,'ZC06','effectif', $donnees_calculees['nb_membres_hommes'])],
        ['ZC07','Nb femmes', getValeur($infos,'ZC07','effectif', $donnees_calculees['nb_membres_femmes'])],
        ['ZC08','Nb groupements bénéf.', getValeur($infos,'ZC08','effectif')],
        ['ZC09','Nb usagers bénéf.', getValeur($infos,'ZC09','effectif')],
        ['ZC10','Nb sociétaires bénéf.', getValeur($infos,'ZC10','effectif')],
        ['ZC11','Population cible', getValeur($infos,'ZC11','effectif')],
        ['ZC12','Dépôts >1 an inst. fin.', getValeur($infos,'ZC12','montant')],
        ['ZC13','Dépôts terme >1 an membres', getValeur($infos,'ZC13','montant', $donnees_calculees['depots_terme_plus_1_an_membres'])],
        ['ZC14','Épargne régime spécial', getValeur($infos,'ZC14','montant', $donnees_calculees['epargne_regime_special'])],
        ['ZC15','Autres dépôts >1 an membres', getValeur($infos,'ZC15','montant')],
        ['ZC16','Recouvrements intervenus', getValeur($infos,'ZC16','montant')],
        ['ZC17','Recouvrements attendus', getValeur($infos,'ZC17','montant')]
    ];

    foreach ($liste as $l) {
        $pdf->TableRow($cols, [$l[0], $l[1], is_float($l[2]) ? PDF_DIMF::montant($l[2]) : $l[2]]);
    }

    $pdf->Output('I', 'DIMF_2011_InfosAnnexes_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2011 - Informations annexes</title>
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
        .info-box{background:#eef2ff;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:16px;display:flex;align-items:center;gap:14px;}
        .calculated-value{background-color:#f0fdf4;}
        .page-footer{text-align:center;font-size:0.75rem;color:#6b7280;margin-top:16px;}
        @media print{.btn-group,.page-footer,#filtersCard{display:none;}}
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
            <?php $pdf_params = http_build_query(['exercice'=>$exercice,'type_periode'=>$type_periode,'mois'=>$mois,'trimestre'=>$trimestre,'semestre'=>$semestre,'format'=>'pdf']); ?>
            <a class="btn-pdf" href="?<?= $pdf_params ?>" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
    </div>

    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="filters-row">
            <div class="filter-item"><label>Année</label><select id="exerciceSelect"><?php for($y=2020;$y<=date('Y')+1;$y++) echo "<option value='$y' ".($y==$exercice?'selected':'').">$y</option>"; ?></select></div>
            <div class="filter-item"><label>Type période</label><select id="typePeriodeSelect"><option value="mensuel" <?= $type_periode=='mensuel'?'selected':'' ?>>Mensuel</option><option value="trimestre" <?= $type_periode=='trimestre'?'selected':'' ?>>Trimestre</option><option value="semestre" <?= $type_periode=='semestre'?'selected':'' ?>>Semestre</option><option value="annuel" <?= $type_periode=='annuel'?'selected':'' ?>>Annuel</option></select></div>
            <div class="filter-item" id="dynamicSelectContainer"><?php
                if($type_periode=='mensuel'){ echo '<label>Mois</label><select id="moisSelect">'; for($m=1;$m<=12;$m++) echo "<option value='$m' ".($m==$mois?'selected':'').">".str_pad($m,2,'0')." - ".date('F',mktime(0,0,0,$m,1))."</option>"; echo '</select>';
                }elseif($type_periode=='trimestre'){ echo '<label>Trimestre</label><select id="trimestreSelect">'; for($t=1;$t<=4;$t++) echo "<option value='$t' ".($t==$trimestre?'selected':'').">$t".($t==1?'er':'ème')." Trimestre</option>"; echo '</select>';
                }elseif($type_periode=='semestre'){ echo '<label>Semestre</label><select id="semestreSelect">'; for($s=1;$s<=2;$s++) echo "<option value='$s' ".($s==$semestre?'selected':'').">$s".($s==1?'er':'e')." semestre</option>"; echo '</select>';
                }else{ echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">'; }
            ?></div>
            <button class="btn-apply" onclick="appliquerFiltres()"><i class="fas fa-filter"></i> Appliquer</button>
        </div>
    </div>

    <?php if($message): ?>
        <div class="info-box" style="background:<?= $message_type=='success'?'#d1fae5':'#fee2e2' ?>;border-left-color:<?= $message_type=='success'?'#10b981':'#ef4444' ?>;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> INFORMATIONS GÉNÉRALES</div>
        <form method="post">
            <input type="hidden" name="action" value="save">
            <div class="filters-row" style="margin-bottom:0;">
                <div class="filter-item"><label>ZC01 - Engagements CT (FCFA)</label><input type="number" name="encours_engagements_ct" value="<?= number_format(getValeur($infos,'ZC01','montant'),0,'','') ?>"></div>
                <div class="filter-item"><label>ZC02 - Engagements MLT (FCFA)</label><input type="number" name="encours_engagements_mlt" value="<?= number_format(getValeur($infos,'ZC02','montant'),0,'','') ?>"></div>
                <div class="filter-item"><label>ZC03 - Autres activités (FCFA)</label><input type="number" name="montant_autres_activites" value="<?= number_format(getValeur($infos,'ZC03','montant'),0,'','') ?>"></div>
            </div>
            <div class="card-header" style="margin-top:16px;"><i class="fas fa-users"></i> EFFECTIFS</div>
            <div class="filters-row" style="margin-bottom:0;">
                <div class="filter-item"><label>ZC04 - Nb membres total</label><input type="number" name="nb_membres_total" value="<?= getValeur($infos,'ZC04','effectif', $donnees_calculees['nb_membres_total']) ?>" class="<?= !isset($infos['ZC04'])?'calculated-value':'' ?>"><span style="font-size:0.7rem;"> ✓ auto: <?= number_format($donnees_calculees['nb_membres_total']) ?></span></div>
                <div class="filter-item"><label>ZC05 - Nb groupements</label><input type="number" name="nb_groupements" value="<?= getValeur($infos,'ZC05','effectif') ?>"></div>
                <div class="filter-item"><label>ZC06 - Nb hommes</label><input type="number" name="nb_membres_hommes" value="<?= getValeur($infos,'ZC06','effectif', $donnees_calculees['nb_membres_hommes']) ?>" class="<?= !isset($infos['ZC06'])?'calculated-value':'' ?>"><span style="font-size:0.7rem;"> ✓ auto: <?= number_format($donnees_calculees['nb_membres_hommes']) ?></span></div>
                <div class="filter-item"><label>ZC07 - Nb femmes</label><input type="number" name="nb_membres_femmes" value="<?= getValeur($infos,'ZC07','effectif', $donnees_calculees['nb_membres_femmes']) ?>" class="<?= !isset($infos['ZC07'])?'calculated-value':'' ?>"><span style="font-size:0.7rem;"> ✓ auto: <?= number_format($donnees_calculees['nb_membres_femmes']) ?></span></div>
                <div class="filter-item"><label>ZC08 - Nb groupements bénéf.</label><input type="number" name="nb_groupements_beneficiaires" value="<?= getValeur($infos,'ZC08','effectif') ?>"></div>
                <div class="filter-item"><label>ZC09 - Nb usagers bénéf.</label><input type="number" name="nb_usagers_beneficiaires" value="<?= getValeur($infos,'ZC09','effectif') ?>"></div>
                <div class="filter-item"><label>ZC10 - Nb sociétaires bénéf.</label><input type="number" name="nb_societaires_beneficiaires" value="<?= getValeur($infos,'ZC10','effectif') ?>"></div>
                <div class="filter-item"><label>ZC11 - Population cible</label><input type="number" name="population_cible" value="<?= getValeur($infos,'ZC11','effectif') ?>"></div>
            </div>
            <div class="card-header" style="margin-top:16px;"><i class="fas fa-piggy-bank"></i> DÉPÔTS ET ÉPARGNE</div>
            <div class="filters-row" style="margin-bottom:0;">
                <div class="filter-item"><label>ZC12 - Dépôts >1 an inst. fin. (FCFA)</label><input type="number" name="depots_plus_1_an_inst_fin" value="<?= number_format(getValeur($infos,'ZC12','montant'),0,'','') ?>"></div>
                <div class="filter-item"><label>ZC13 - Dépôts terme >1 an membres</label><input type="number" name="depots_terme_plus_1_an_membres" value="<?= number_format(getValeur($infos,'ZC13','montant', $donnees_calculees['depots_terme_plus_1_an_membres']),0,'','') ?>" class="<?= !isset($infos['ZC13'])?'calculated-value':'' ?>"><span style="font-size:0.7rem;"> ✓ auto: <?= number_format($donnees_calculees['depots_terme_plus_1_an_membres'],0,',',' ') ?></span></div>
                <div class="filter-item"><label>ZC14 - Épargne régime spécial</label><input type="number" name="epargne_regime_special" value="<?= number_format(getValeur($infos,'ZC14','montant', $donnees_calculees['epargne_regime_special']),0,'','') ?>" class="<?= !isset($infos['ZC14'])?'calculated-value':'' ?>"><span style="font-size:0.7rem;"> ✓ auto: <?= number_format($donnees_calculees['epargne_regime_special'],0,',',' ') ?></span></div>
                <div class="filter-item"><label>ZC15 - Autres dépôts >1 an membres</label><input type="number" name="autres_depots_plus_1_an_membres" value="<?= number_format(getValeur($infos,'ZC15','montant'),0,'','') ?>"></div>
            </div>
            <div class="card-header" style="margin-top:16px;"><i class="fas fa-hand-holding-usd"></i> RECOUVREMENTS</div>
            <div class="filters-row" style="margin-bottom:0;">
                <div class="filter-item"><label>ZC16 - Recouvrements intervenus</label><input type="number" name="recouvrements_prevus" value="<?= number_format(getValeur($infos,'ZC16','montant'),0,'','') ?>"></div>
                <div class="filter-item"><label>ZC17 - Recouvrements attendus</label><input type="number" name="recouvrements_attendus" value="<?= number_format(getValeur($infos,'ZC17','montant'),0,'','') ?>"></div>
                <div class="filter-item"><button type="submit" class="btn-apply"><i class="fas fa-save"></i> Enregistrer</button></div>
            </div>
        </form>
    </div>

    <div class="page-footer">Généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>) arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?></div>
</div>
<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select id="moisSelect">';
            for (let m=1;m<=12;m++) { html += `<option value="${m}" ${m==<?= $mois ?>?'selected':''}>${String(m).padStart(2,'0')} - ${new Date(2000,m-1,1).toLocaleString('fr',{month:'long'})}</option>`; }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select id="trimestreSelect">';
            for (let t=1;t<=4;t++) { html += `<option value="${t}" ${t==<?= $trimestre ?>?'selected':''}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select id="semestreSelect">';
            for (let s=1;s<=2;s++) { html += `<option value="${s}" ${s==<?= json_encode($semestre) ?>?'selected':''}>${s}${s===1?'er':'e'} semestre</option>`; }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
        }
        container.innerHTML = html;
    }
    function appliquerFiltres() {
        let e = document.getElementById('exerciceSelect').value;
        let t = document.getElementById('typePeriodeSelect').value;
        let u = 'DIMF_2011.php?exercice='+e+'&type_periode='+t;
        if (t==='mensuel') u += '&mois='+document.getElementById('moisSelect').value;
        if (t==='trimestre') u += '&trimestre='+document.getElementById('trimestreSelect').value;
        if (t==='semestre') u += '&semestre='+document.getElementById('semestreSelect').value;
        window.location.href = u;
    }
    document.addEventListener('DOMContentLoaded',function(){ updateDynamicSelect(); document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect); });
    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        const data = [['DIMF_2011 - INFORMATIONS ANNEXES'],['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],[],['Code','Indicateur','Valeur']];
        const liste = [
            ['ZC01','Encours engagements CT', getValeur($infos,'ZC01','montant')],
            ['ZC02','Encours engagements MLT', getValeur($infos,'ZC02','montant')],
            ['ZC03','Autres activités', getValeur($infos,'ZC03','montant')],
            ['ZC04','Nb membres total', getValeur($infos,'ZC04','effectif', $donnees_calculees['nb_membres_total'])],
            ['ZC05','Nb groupements', getValeur($infos,'ZC05','effectif')],
            ['ZC06','Nb hommes', getValeur($infos,'ZC06','effectif', $donnees_calculees['nb_membres_hommes'])],
            ['ZC07','Nb femmes', getValeur($infos,'ZC07','effectif', $donnees_calculees['nb_membres_femmes'])],
            ['ZC08','Nb groupements bénéf.', getValeur($infos,'ZC08','effectif')],
            ['ZC09','Nb usagers bénéf.', getValeur($infos,'ZC09','effectif')],
            ['ZC10','Nb sociétaires bénéf.', getValeur($infos,'ZC10','effectif')],
            ['ZC11','Population cible', getValeur($infos,'ZC11','effectif')],
            ['ZC12','Dépôts >1 an inst. fin.', getValeur($infos,'ZC12','montant')],
            ['ZC13','Dépôts terme >1 an membres', getValeur($infos,'ZC13','montant', $donnees_calculees['depots_terme_plus_1_an_membres'])],
            ['ZC14','Épargne régime spécial', getValeur($infos,'ZC14','montant', $donnees_calculees['epargne_regime_special'])],
            ['ZC15','Autres dépôts >1 an membres', getValeur($infos,'ZC15','montant')],
            ['ZC16','Recouvrements intervenus', getValeur($infos,'ZC16','montant')],
            ['ZC17','Recouvrements attendus', getValeur($infos,'ZC17','montant')]
        ];
        for (let i=0; i<liste.length; i++) data.push(liste[i]);
        const ws = XLSX.utils.aoa_to_sheet(data);
        XLSX.utils.book_append_sheet(wb, ws, "INFOS_ANNEXES");
        XLSX.writeFile(wb, 'DIMF_2011_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }
</script>
</body>
</html>