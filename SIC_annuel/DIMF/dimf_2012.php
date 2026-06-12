<?php
// DIMF_2012.php - Top 10 des débiteurs (avec FPDF)
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

// Connexion BDD
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

// Paramètres période
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
// RÉCUPÉRATION DU TOP 10 DES DÉBITEURS
// ============================================================
$top_debiteurs = [];
$total_encours = 0;

try {
    $stmt = $pdo->prepare("
        SELECT 
            c.client_id,
            c.matricule,
            CONCAT(COALESCE(c.nom, ''), ' ', COALESCE(c.prenom, '')) as nom_complet,
            c.categorie,
            c.secteur_id,
            SUM(encours_restant) as encours_total,
            AVG(d.duree) as duree_moyenne,
            COUNT(d.dossier_id) as nb_credits,
            MAX(CASE WHEN e.date_dernier_impaye IS NOT NULL THEN 1 ELSE 0 END) as has_impaye
        FROM (
            SELECT 
                d.compte_id,
                d.dossier_id,
                d.duree,
                COALESCE(d.montant - COALESCE(e2.rembourse, 0), d.montant) as encours_restant
            FROM dossiers d
            LEFT JOIN (
                SELECT dossier_id, SUM(montant) as rembourse
                FROM echeances
                WHERE statut = 'payee'
                GROUP BY dossier_id
            ) e2 ON d.dossier_id = e2.dossier_id
            WHERE d.statut IN ('actif', 'approuve')
              AND d.date_octroi <= :date_fin
        ) AS encours_par_dossier
        INNER JOIN comptes cpt ON encours_par_dossier.compte_id = cpt.compte_id
        INNER JOIN clients c ON cpt.client_id = c.client_id
        LEFT JOIN (
            SELECT d.client_id, MAX(e.date_echeance) as date_dernier_impaye
            FROM dossiers d
            INNER JOIN comptes cpt ON d.compte_id = cpt.compte_id
            INNER JOIN echeances e ON d.dossier_id = e.dossier_id
            WHERE e.statut = 'attente' AND e.date_echeance < :date_fin
            GROUP BY d.client_id
        ) e ON c.client_id = e.client_id
        GROUP BY c.client_id, c.matricule, c.nom, c.prenom, c.categorie, c.secteur_id
        HAVING encours_total > 0
        ORDER BY encours_total DESC
        LIMIT 10
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $top_debiteurs = $stmt->fetchAll();
    foreach ($top_debiteurs as $debiteur) {
        $total_encours += (float)$debiteur['encours_total'];
    }
} catch (PDOException $e) { $top_debiteurs = []; }

// Fonds propres
$fonds_propres = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin AND e.statut = 'VALIDÉE'
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $fonds_propres = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $fonds_propres = 0; }

$ratio_concentration = ($fonds_propres > 0 && !empty($top_debiteurs)) ? ($top_debiteurs[0]['encours_total'] / $fonds_propres) * 100 : 0;

// Détails par débiteur
$details_par_debiteur = [];
foreach ($top_debiteurs as $debiteur) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                d.dossier_id,
                d.date_octroi,
                d.montant as montant_initial,
                COALESCE(d.montant - COALESCE(e.rembourse, 0), d.montant) as encours_restant,
                d.duree,
                d.objet,
                (SELECT COUNT(*) FROM echeances WHERE dossier_id = d.dossier_id AND statut = 'attente' AND date_echeance < :date_fin) as nb_impayes
            FROM dossiers d
            LEFT JOIN (
                SELECT dossier_id, SUM(montant) as rembourse
                FROM echeances
                WHERE statut = 'payee'
                GROUP BY dossier_id
            ) e ON d.dossier_id = e.dossier_id
            INNER JOIN comptes cpt ON d.compte_id = cpt.compte_id
            WHERE cpt.client_id = :client_id
              AND d.statut IN ('actif', 'approuve')
            ORDER BY encours_restant DESC
        ");
        $stmt->execute([':client_id' => $debiteur['client_id'], ':date_fin' => $date_fin_periode]);
        $details_par_debiteur[$debiteur['client_id']] = $stmt->fetchAll();
    } catch (PDOException $e) { $details_par_debiteur[$debiteur['client_id']] = []; }
}

// Portefeuille total et indicateurs
$portefeuille_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e 
        ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve') AND d.date_octroi <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $portefeuille_total = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $portefeuille_total = 0; }
$part_top10 = ($portefeuille_total > 0) ? ($total_encours / $portefeuille_total) * 100 : 0;

$nb_superieur_10 = 0;
$nb_superieur_25 = 0;
foreach ($top_debiteurs as $debiteur) {
    $pourcentage = ($fonds_propres > 0) ? ($debiteur['encours_total'] / $fonds_propres) * 100 : 0;
    if ($pourcentage > 10) $nb_superieur_10++;
    if ($pourcentage > 25) $nb_superieur_25++;
}

// Génération PDF
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
    $pdf->codeDimf  = 'DIMF_2012';
    $pdf->titreDimf = 'Top 10 des débiteurs';
    $pdf->nomSfd    = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode   = $lib_periode;
    $pdf->exercice  = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'Rang', 'w' => 12],
        ['label' => 'Matricule', 'w' => 25],
        ['label' => 'Nom', 'w' => 45],
        ['label' => 'Catégorie', 'w' => 25],
        ['label' => 'Secteur', 'w' => 25],
        ['label' => 'Durée moy.', 'w' => 20, 'align' => 'R'],
        ['label' => 'Encours (FCFA)', 'w' => 40, 'align' => 'R'],
        ['label' => '% FP', 'w' => 20, 'align' => 'R']
    ];
    $pdf->SectionTitle('Top 10 des débiteurs');
    $pdf->TableHeader($cols);
    foreach ($top_debiteurs as $i => $d) {
        $pourc = ($fonds_propres > 0) ? ($d['encours_total'] / $fonds_propres) * 100 : 0;
        $pdf->TableRow($cols, [
            $i+1,
            PDF_DIMF::u($d['matricule'] ?? '-'),
            PDF_DIMF::u($d['nom_complet'] ?: 'N/A'),
            PDF_DIMF::u($d['categorie'] ?: '-'),
            PDF_DIMF::u($d['secteur_id'] ?: '-'),
            round($d['duree_moyenne']),
            PDF_DIMF::montant($d['encours_total']),
            number_format($pourc,2) . '%'
        ]);
    }
    $pdf->TableRow($cols, [
        'TOTAL', '', '', '', '',
        '',
        PDF_DIMF::montant($total_encours),
        number_format(($total_encours / max($fonds_propres,1))*100,2) . '%'
    ], 'total');

    $pdf->Ln(5);
    $pdf->SetFont('Arial','B',8);
    $pdf->Cell(0,6,PDF_DIMF::u('Synthèse des risques de concentration'),0,1);
    $pdf->SetFont('Arial','',8);
    $pdf->MultiCell(0,5,PDF_DIMF::u(
        "Débiteurs >10% des fonds propres : $nb_superieur_10\n".
        "Débiteurs >25% des fonds propres : $nb_superieur_25\n".
        "Encours total des 10 premiers débiteurs : ".PDF_DIMF::montant($total_encours)."\n".
        "Part dans le portefeuille total : ".number_format($part_top10,2)."%"
    ));

    $pdf->Output('I', 'DIMF_2012_Top10_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2012 - Top 10 des débiteurs</title>
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
        .filter-item select{border:1px solid #d1d5db;border-radius:12px;padding:8px 14px;font-size:0.85rem;}
        .btn-apply{background:#3b82f6;color:white;border:none;border-radius:40px;padding:8px 24px;cursor:pointer;}
        .table-wrapper{overflow-x:auto;}
        table{width:100%;border-collapse:collapse;font-size:0.85rem;}
        th{text-align:left;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;}
        td{padding:10px 16px;border-bottom:1px solid #f1f5f9;}
        .text-right{text-align:right;}
        .total-row{background:#f0fdf4;font-weight:700;}
        .danger-row{background:#fee2e2;}
        .warning-row{background:#fef3c7;}
        .info-box{background:#eef2ff;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:16px;}
        .badge-risk{display:inline-block;padding:4px 10px;border-radius:20px;font-size:0.7rem;font-weight:600;}
        .risk-high{background:#fee2e2;color:#dc2626;}
        .risk-low{background:#d1fae5;color:#059669;}
        .expand-btn{background:none;border:none;font-size:1rem;cursor:pointer;color:#3b82f6;}
        .hidden-row{display:none;}
        .sub-table{width:100%;margin-top:8px;font-size:0.75rem;}
        .sub-table th,.sub-table td{padding:6px 8px;background:#f8fafc;}
        .indicators-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;}
        .page-footer{text-align:center;font-size:0.75rem;color:#6b7280;margin-top:16px;}
        @media print{.btn-group,.page-footer,#filtersCard,.expand-btn{display:none;}.hidden-row{display:table-row;}}
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-line"></i> DIMF_2012 - TOP 10 DES DÉBITEURS</h1>
            <div class="subtitle">République de Côte d'Ivoire – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Grands risques</div>
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

    <?php if(empty($top_debiteurs)): ?>
        <div class="card"><div class="info-box">Aucun débiteur actif pour la période.</div></div>
    <?php else: ?>
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-simple"></i> INDICATEURS DE CONCENTRATION</div>
            <div class="info-box">
                <div class="indicators-grid">
                    <div><strong>Fonds propres :</strong><br><?= number_format($fonds_propres,0,',',' ') ?> FCFA</div>
                    <div><strong>Plus gros emprunteur :</strong><br><?= number_format($top_debiteurs[0]['encours_total'],0,',',' ') ?> FCFA<br><span class="badge-risk <?= $ratio_concentration>10?'risk-high':'risk-low' ?>"><?= number_format($ratio_concentration,2) ?>% des FP</span></div>
                    <div><strong>Norme BCEAO :</strong> ≤10%<br><?= $ratio_concentration>10?'<span class="badge-risk risk-high">❌ Non conforme</span>':'<span class="badge-risk risk-low">✅ Conforme</span>' ?></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fas fa-trophy"></i> TOP 10 DES DÉBITEURS</div>
            <div class="table-wrapper">
                <table>
                    <thead><th>Rang</th><th>Matricule</th><th>Nom complet</th><th>Catégorie</th><th>Secteur</th><th class="text-right">Durée moy.</th><th class="text-right">Encours (FCFA)</th><th class="text-right">% FP</th><th>Actions</th></thead>
                    <tbody><?php foreach($top_debiteurs as $i=>$d): $pourc = ($fonds_propres>0)?($d['encours_total']/$fonds_propres)*100:0; $rowClass = $pourc>10?'danger-row':($d['has_impaye']?'warning-row':''); ?>
                        <tr> id="row-<?= $i ?>" class="<?= $rowClass ?>">
                            <td class="text-center"><strong><?= $i+1 ?></strong></td>
                            <td><?= htmlspecialchars($d['matricule']??'-') ?></td>
                            <td><?= htmlspecialchars($d['nom_complet']?:'N/A') ?></td>
                            <td><?= htmlspecialchars($d['categorie']?:'-') ?></td>
                            <td><?= htmlspecialchars($d['secteur_id']?:'-') ?></td>
                            <td class="text-right"><?= round($d['duree_moyenne']) ?></td>
                            <td class="text-right"><?= number_format($d['encours_total'],0,',',' ') ?></td>
                            <td class="text-right <?= $pourc>10?'risk-high':'' ?>"><?= number_format($pourc,2) ?>%</td>
                            <td class="text-center"><button class="expand-btn" onclick="toggleDetails(<?= $i ?>)"><i class="fas fa-chevron-down"></i></button></td>
                        </tr>
                        <tr id="details-<?= $i ?>" class="hidden-row"><td colspan="9"><div style="padding:12px; background:#f8fafc;"><strong>Détail des prêts :</strong>
                            <table class="sub-table"><thead><th>N° Dossier</th><th>Date octroi</th><th class="text-right">Montant initial</th><th class="text-right">Encours restant</th><th class="text-center">Durée</th><th>Objet</th><th class="text-center">Impayés</th></thead>
                            <tbody><?php $details = $details_par_debiteur[$d['client_id']]??[]; if(empty($details)): ?>
                                <td><td colspan="7">Aucun prêt actif</td></tr><?php else: foreach($details as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['dossier_id']) ?></td>
                                    <td><?= date('d/m/Y',strtotime($p['date_octroi'])) ?></td>
                                    <td class="text-right"><?= number_format($p['montant_initial'],0,',',' ') ?></td>
                                    <td class="text-right"><?= number_format($p['encours_restant'],0,',',' ') ?></td>
                                    <td class="text-center"><?= $p['duree'] ?> mois</td>
                                    <td><?= htmlspecialchars($p['objet']?:'-') ?></td>
                                    <td class="text-center"><?= $p['nb_impayes'] ?></td>
                                </tr>
                                <?php endforeach; endif; ?></tbody>
                            </table></div></td></tr>
                    <?php endforeach; ?>
                    <tr class="total-row"><td colspan="6"><strong>TOTAL 10 PREMIERS DÉBITEURS</strong></td>
                        <td class="text-right"><strong><?= number_format($total_encours,0,',',' ') ?></strong></td>
                        <td class="text-right"><strong><?= number_format(($total_encours/max($fonds_propres,1))*100,2) ?>%</strong></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fas fa-chart-pie"></i> SYNTHÈSE DES RISQUES</div>
            <div class="info-box">
                <div class="indicators-grid">
                    <div><strong>Débiteurs >10% FP :</strong> <?= $nb_superieur_10 ?></div>
                    <div><strong>Débiteurs >25% FP :</strong> <?= $nb_superieur_25 ?></div>
                    <div><strong>Encours Top 10 :</strong> <?= number_format($total_encours,0,',',' ') ?> FCFA</div>
                    <div><strong>Part dans portefeuille :</strong> <?= number_format($part_top10,2) ?>%</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="page-footer">Généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>) arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?></div>
</div>
<script>
    function toggleDetails(index) {
        const row = document.getElementById('details-'+index);
        const btn = document.querySelector('#row-'+index+' .expand-btn i');
        if(row.classList.contains('hidden-row')) {
            row.classList.remove('hidden-row');
            if(btn) btn.classList.replace('fa-chevron-down','fa-chevron-up');
        } else {
            row.classList.add('hidden-row');
            if(btn) btn.classList.replace('fa-chevron-up','fa-chevron-down');
        }
    }
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
        let u = 'DIMF_2012.php?exercice='+e+'&type_periode='+t;
        if (t==='mensuel') u += '&mois='+document.getElementById('moisSelect').value;
        if (t==='trimestre') u += '&trimestre='+document.getElementById('trimestreSelect').value;
        if (t==='semestre') u += '&semestre='+document.getElementById('semestreSelect').value;
        window.location.href = u;
    }
    document.addEventListener('DOMContentLoaded',function(){ updateDynamicSelect(); document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect); });
    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        const data = [['DIMF_2012 - TOP 10 DÉBITEURS'],['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],[],['Rang','Matricule','Nom','Catégorie','Secteur','Durée moy.','Encours','% FP']];
        <?php foreach($top_debiteurs as $i=>$d): $pourc = ($fonds_propres>0)?($d['encours_total']/$fonds_propres)*100:0; ?>
        data.push([<?= $i+1 ?>,'<?= addslashes($d['matricule']??'-') ?>','<?= addslashes($d['nom_complet']?:'N/A') ?>','<?= addslashes($d['categorie']?:'-') ?>','<?= addslashes($d['secteur_id']?:'-') ?>',<?= round($d['duree_moyenne']) ?>,<?= $d['encours_total'] ?>,<?= number_format($pourc,2,'.','') ?>]);
        <?php endforeach; ?>
        data.push(['TOTAL','','','','','',<?= $total_encours ?>,<?= ($total_encours/max($fonds_propres,1))*100 ?>]);
        const ws = XLSX.utils.aoa_to_sheet(data);
        XLSX.utils.book_append_sheet(wb, ws, "TOP_DEBITEURS");
        XLSX.writeFile(wb, 'DIMF_2012_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }
</script>
</body>
</html>