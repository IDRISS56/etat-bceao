<?php
// DIMF_2013.php - Prêts aux dirigeants (avec FPDF)
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

    static function u($str) {
        return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
    }

    function Header() { /* identique aux autres fichiers */ 
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
        $this->Ln(10);
    }
    function Footer() { $this->SetY(-12); $this->SetFont('Arial','I',7); $this->SetTextColor(100,116,139); $this->Cell(0,4,self::u('SICS-BCEAO  •  Genere le '.date('d/m/Y a H:i:s').'  •  Page '.$this->PageNo().'/{nb}'),0,0,'C'); }
    function SectionTitle($label) { $this->SetFont('Arial','B',9); $this->SetFillColor(0,0,0); $this->SetTextColor(255,255,255); $this->Cell(0,7,self::u('  '.strtoupper($label)),0,1,'L',true); $this->SetTextColor(0,0,0); $this->Ln(1); }
    function TableHeader($cols) { $this->SetFont('Arial','B',8); $this->SetFillColor(248,250,252); $this->SetTextColor(30,41,59); $this->SetDrawColor(226,232,240); foreach($cols as $col){ $align=isset($col['align'])?$col['align']:'L'; $this->Cell($col['w'],6,self::u($col['label']),1,0,$align,true); } $this->Ln(); }
    function TableRow($cols,$data,$style='') { $fill=false; if($style=='subtotal'){ $this->SetFillColor(248,250,252); $this->SetFont('Arial','B',8); $fill=true; }elseif($style=='total'){ $this->SetFillColor(240,253,244); $this->SetFont('Arial','B',8.5); $fill=true; }else{ $this->SetFillColor(255,255,255); $this->SetFont('Arial','',7.5); } $this->SetTextColor(15,23,42); $this->SetDrawColor(226,232,240); foreach($cols as $i=>$col){ $val=isset($data[$i])?$data[$i]:''; $align=isset($col['align'])?$col['align']:'L'; $this->Cell($col['w'],5.5,self::u($val),1,0,$align,$fill); } $this->Ln(); }
    static function montant($val) { return number_format((float)$val,0,',',' ').' F'; }
}


// Paramètres période (identique)
$exercice = isset($_GET['exercice'])?(int)$_GET['exercice']:date('Y');
$type_periode = isset($_GET['type_periode'])?$_GET['type_periode']:'mensuel';
$mois = isset($_GET['mois'])?(int)$_GET['mois']:12;
$trimestre = isset($_GET['trimestre'])?(int)$_GET['trimestre']:4;
$semestre = isset($_GET['semestre'])?(int)$_GET['semestre']:null;
switch ($type_periode) { case 'trimestre': $mois=$trimestre*3; break; case 'semestre': $mois=($semestre==1)?6:12; break; case 'annuel': $mois=12; }
$date_fin_periode = date('Y-m-t', strtotime($exercice.'-'.str_pad($mois,2,'0',STR_PAD_LEFT).'-01'));

// ============================================================
// RÉCUPÉRATION DES PRÊTS AUX DIRIGEANTS
// ============================================================
$prets_dirigeants = [];
$total_encours_dirigeants = 0;
try {
    $stmt = $pdo->prepare("
        SELECT 
            u.utilisateur_id, u.matricule, u.nom_prenom, u.role, u.telephone, u.email,
            d.dossier_id, d.date_octroi, d.montant as montant_initial,
            COALESCE(d.montant - COALESCE(e.rembourse, 0), d.montant) as encours_restant,
            d.duree, d.objet, d.statut as dossier_statut,
            (SELECT COUNT(*) FROM echeances WHERE dossier_id = d.dossier_id AND statut = 'attente' AND date_echeance < :date_fin) as nb_impayes
        FROM dossiers d
        INNER JOIN utilisateurs u ON d.utilisateur_id = u.utilisateur_id
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE u.role IN ('Superviseur','Administrateur','Responsable','Directeur')
          AND d.statut IN ('actif','approuve')
          AND d.date_octroi <= :date_fin
        ORDER BY encours_restant DESC
    ");
    $stmt->execute([':date_fin'=>$date_fin_periode]);
    $prets_dirigeants = $stmt->fetchAll();
    foreach($prets_dirigeants as $p) $total_encours_dirigeants += (float)$p['encours_restant'];
} catch(PDOException $e){ $prets_dirigeants=[]; }

// Fonds propres
$fonds_propres = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin AND e.statut = 'VALIDÉE'");
    $stmt->execute([':date_fin'=>$date_fin_periode]);
    $fonds_propres = (float)$stmt->fetch()['total'];
} catch(PDOException $e){ $fonds_propres=0; }

$ratio_r03 = ($fonds_propres>0)?($total_encours_dirigeants/$fonds_propres):0;
$norme_r03 = 0.10;
$conformite_r03 = ($ratio_r03 <= $norme_r03) ? 'CONFORME' : 'NON CONFORME';

// Engagements par signature des dirigeants
$engagements_dirigeants = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(g.valeur_nette),0) as total FROM garanties g INNER JOIN dossiers d ON g.credit_id = d.dossier_id INNER JOIN utilisateurs u ON d.utilisateur_id = u.utilisateur_id WHERE u.role IN ('Superviseur','Administrateur','Responsable','Directeur') AND g.statut='actif'");
    $stmt->execute();
    $engagements_dirigeants = (float)$stmt->fetch()['total'];
} catch(PDOException $e){ $engagements_dirigeants=0; }

$exposition_totale = $total_encours_dirigeants + $engagements_dirigeants;
$ratio_exposition = ($fonds_propres>0)?($exposition_totale/$fonds_propres):0;

// Liste des dirigeants
$tous_dirigeants = [];
try {
    $stmt = $pdo->prepare("SELECT utilisateur_id, matricule, nom_prenom, role, telephone, email, etat FROM utilisateurs WHERE role IN ('Superviseur','Administrateur','Responsable','Directeur') AND etat='actif' ORDER BY nom_prenom");
    $stmt->execute();
    $tous_dirigeants = $stmt->fetchAll();
} catch(PDOException $e){ $tous_dirigeants=[]; }

$encours_par_dirigeant = [];
foreach($prets_dirigeants as $p) $encours_par_dirigeant[$p['utilisateur_id']] = ($encours_par_dirigeant[$p['utilisateur_id']]??0) + $p['encours_restant'];

// Génération PDF
$format = isset($_GET['format'])?$_GET['format']:'html';
if($format==='pdf'){
    switch($type_periode){
        case 'mensuel': $lib_periode='Mois '.str_pad($mois,2,'0',STR_PAD_LEFT).'/'.$exercice; break;
        case 'trimestre': $lib_periode=$trimestre.'e Trim. '.$exercice; break;
        case 'semestre': $lib_periode=$semestre.'er Sem. '.$exercice; break;
        default: $lib_periode='Annee '.$exercice;
    }
    $pdf = new PDF_DIMF('L','mm','A4'); $pdf->AliasNbPages();
    $pdf->codeDimf='DIMF_2013'; $pdf->titreDimf='Prêts aux dirigeants'; $pdf->nomSfd=$_SESSION['nom_sfd']??'SFD'; $pdf->periode=$lib_periode; $pdf->exercice=$exercice;
    $pdf->SetMargins(8,35,8); $pdf->SetAutoPageBreak(true,14); $pdf->AddPage();

    // Section Ratio R03
    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(0,7,PDF_DIMF::u('R03 - LIMITATION DES PRÊTS AUX DIRIGEANTS'),0,1);
    $pdf->SetFont('Arial','',8);
    $pdf->MultiCell(0,5,PDF_DIMF::u(
        "Ratio calculé : ".number_format($ratio_r03*100,2)."% (Norme ≤10%)\n".
        "Prêts aux dirigeants : ".PDF_DIMF::montant($total_encours_dirigeants)."\n".
        "Fonds propres : ".PDF_DIMF::montant($fonds_propres)."\n".
        "Engagements par signature : ".PDF_DIMF::montant($engagements_dirigeants)."\n".
        "Exposition totale : ".PDF_DIMF::montant($exposition_totale)." (".number_format($ratio_exposition*100,2)."%)\n".
        "Conformité : $conformite_r03"
    ));
    $pdf->Ln(4);

    // Liste des dirigeants
    $colsDir = [['label'=>'Matricule','w'=>30],['label'=>'Nom et prénom','w'=>45],['label'=>'Fonction','w'=>35],['label'=>'Téléphone','w'=>30],['label'=>'Email','w'=>50],['label'=>'Encours','w'=>35,'align'=>'R'],['label'=>'Statut','w'=>25]];
    $pdf->SectionTitle('Liste des dirigeants');
    $pdf->TableHeader($colsDir);
    foreach($tous_dirigeants as $d){
        $enc = $encours_par_dirigeant[$d['utilisateur_id']]??0;
        $pdf->TableRow($colsDir,[
            PDF_DIMF::u($d['matricule']??'-'),
            PDF_DIMF::u($d['nom_prenom']??'-'),
            PDF_DIMF::u($d['role']??'-'),
            PDF_DIMF::u($d['telephone']??'-'),
            PDF_DIMF::u($d['email']??'-'),
            $enc>0?PDF_DIMF::montant($enc):'-',
            $enc>0?'A un prêt':'Aucun prêt'
        ]);
    }

    // Détail des prêts
    if(!empty($prets_dirigeants)){
        $pdf->AddPage();
        $colsPrets = [['label'=>'Dirigeant','w'=>40],['label'=>'Fonction','w'=>30],['label'=>'N° Dossier','w'=>25],['label'=>'Date octroi','w'=>25],['label'=>'Montant initial','w'=>30,'align'=>'R'],['label'=>'Encours restant','w'=>30,'align'=>'R'],['label'=>'Durée','w'=>15,'align'=>'R'],['label'=>'Objet','w'=>40],['label'=>'Impayés','w'=>12,'align'=>'C'],['label'=>'Statut','w'=>20]];
        $pdf->SectionTitle('Détail des prêts aux dirigeants');
        $pdf->TableHeader($colsPrets);
        foreach($prets_dirigeants as $p){
            $pdf->TableRow($colsPrets,[
                PDF_DIMF::u($p['nom_prenom']),
                PDF_DIMF::u($p['role']),
                $p['dossier_id'],
                date('d/m/Y',strtotime($p['date_octroi'])),
                PDF_DIMF::montant($p['montant_initial']),
                PDF_DIMF::montant($p['encours_restant']),
                $p['duree'].' mois',
                PDF_DIMF::u($p['objet']?:'-'),
                $p['nb_impayes'],
                ucfirst($p['dossier_statut'])
            ]);
        }
        $pdf->TableRow($colsPrets,['TOTAL','','','','',PDF_DIMF::montant($total_encours_dirigeants),'','','',''],'total');
    }
    $pdf->Output('I','DIMF_2013_PretsDirigeants_'.$exercice.'_'.$type_periode.'.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2013 - Prêts aux dirigeants</title>
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
        .info-box{background:#eef2ff;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:16px;}
        .status-badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:0.7rem;font-weight:600;}
        .status-conforme{background:#d1fae5;color:#065f46;}
        .status-non-conforme{background:#fee2e2;color:#991b1b;}
        .progress-bar{background:#e2e8f0;border-radius:10px;height:8px;overflow:hidden;margin:10px 0;}
        .progress-fill{background:#10b981;height:100%;border-radius:10px;}
        .progress-fill.non-conforme{background:#ef4444;}
        .page-footer{text-align:center;font-size:0.75rem;color:#6b7280;margin-top:16px;}
        @media print{.btn-group,.page-footer,#filtersCard{display:none;}}
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left"><h1><i class="fas fa-user-tie"></i> DIMF_2013 - PRÊTS AUX DIRIGEANTS</h1><div class="subtitle">République de Côte d'Ivoire – DGTCP / DSFD</div><div class="badge">SICS-BCEAO • Conformité R03</div></div>
        <div class="btn-group"><button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button><a class="btn-pdf" href="?<?= http_build_query(['exercice'=>$exercice,'type_periode'=>$type_periode,'mois'=>$mois,'trimestre'=>$trimestre,'semestre'=>$semestre,'format'=>'pdf']) ?>" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a></div>
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

    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> R03 - LIMITATION DES PRÊTS AUX DIRIGEANTS</div>
        <div class="info-box">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                <div><strong>Ratio calculé :</strong> <span style="font-size:1.8rem; font-weight:700; <?= $ratio_r03<=0.10?'color:#16a34a':'color:#dc2626' ?>"><?= number_format($ratio_r03*100,2) ?>%</span></div>
                <div><strong>Norme BCEAO :</strong> ≤ 10%</div>
                <div><span class="status-badge <?= $conformite_r03=='CONFORME'?'status-conforme':'status-non-conforme' ?>"><?= $conformite_r03 ?></span></div>
            </div>
            <div class="progress-bar"><div class="progress-fill <?= $ratio_r03>0.10?'non-conforme':'' ?>" style="width: <?= min($ratio_r03*100,100) ?>%;"></div></div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-top:16px;">
                <div><strong>Prêts aux dirigeants :</strong><br><?= number_format($total_encours_dirigeants,0,',',' ') ?> FCFA</div>
                <div><strong>Fonds propres :</strong><br><?= number_format($fonds_propres,0,',',' ') ?> FCFA</div>
                <div><strong>Engagements par signature :</strong><br><?= number_format($engagements_dirigeants,0,',',' ') ?> FCFA</div>
                <div><strong>Exposition totale :</strong><br><?= number_format($exposition_totale,0,',',' ') ?> FCFA (<?= number_format($ratio_exposition*100,2) ?>%)</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-users"></i> LISTE DES DIRIGEANTS</div>
        <?php if(empty($tous_dirigeants)): ?><div class="info-box">Aucun dirigeant enregistré.</div><?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead><th>Matricule</th><th>Nom et prénom</th><th>Fonction</th><th>Téléphone</th><th>Email</th><th class="text-right">Encours prêts</th><th>Statut</th></thead>
                <tbody><?php foreach($tous_dirigeants as $d): $enc = $encours_par_dirigeant[$d['utilisateur_id']]??0; ?>
                <tr>
                    <td><?= htmlspecialchars($d['matricule']??'-') ?></td>
                    <td><?= htmlspecialchars($d['nom_prenom']??'-') ?></td>
                    <td><?= htmlspecialchars($d['role']??'-') ?></td>
                    <td><?= htmlspecialchars($d['telephone']??'-') ?></td>
                    <td><?= htmlspecialchars($d['email']??'-') ?></td>
                    <td class="text-right"><?= $enc>0?number_format($enc,0,',',' '):'-' ?></td>
                    <td><?= $enc>0?'<span class="status-badge status-conforme">A un prêt</span>':'<span style="color:#64748b;">Aucun prêt</span>' ?></td>
                </tr><?php endforeach; ?></tbody>
            </table>
        </div><?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><i class="fas fa-hand-holding-usd"></i> DÉTAIL DES PRÊTS AUX DIRIGEANTS</div>
        <?php if(empty($prets_dirigeants)): ?><div class="info-box">Aucun prêt en cours.</div><?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead><th>Dirigeant</th><th>Fonction</th><th>N° Dossier</th><th>Date octroi</th><th class="text-right">Montant initial</th><th class="text-right">Encours restant</th><th class="text-center">Durée</th><th>Objet</th><th class="text-center">Impayés</th><th>Statut</th></thead>
                <tbody><?php foreach($prets_dirigeants as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['nom_prenom']) ?></td>
                    <td><?= htmlspecialchars($p['role']) ?></td>
                    <td><?= htmlspecialchars($p['dossier_id']) ?></td>
                    <td><?= date('d/m/Y',strtotime($p['date_octroi'])) ?></td>
                    <td class="text-right"><?= number_format($p['montant_initial'],0,',',' ') ?></td>
                    <td class="text-right"><?= number_format($p['encours_restant'],0,',',' ') ?></td>
                    <td class="text-center"><?= $p['duree'] ?> mois</td>
                    <td><?= htmlspecialchars($p['objet']?:'-') ?></td>
                    <td class="text-center <?= $p['nb_impayes']>0?'non-conforme':'' ?>"><?= $p['nb_impayes'] ?></td>
                    <td><span class="status-badge <?= $p['dossier_statut']=='actif'?'status-conforme':'status-non-conforme' ?>"><?= ucfirst($p['dossier_statut']) ?></span></td>
                </tr><?php endforeach; ?>
                <tr class="total-row"><td colspan="5"><strong>TOTAL</strong></td><td class="text-right"><strong><?= number_format($total_encours_dirigeants,0,',',' ') ?></strong></td><td colspan="4"></td></tr>
                </tbody>
            </table>
        </div><?php endif; ?>
    </div>
    <div class="page-footer">Généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>) arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?></div>
</div>
<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        let html = '';
        if (type === 'mensuel') { html = '<label>Mois</label><select id="moisSelect">'; for (let m=1;m<=12;m++) html += `<option value="${m}" ${m==<?= $mois ?>?'selected':''}>${String(m).padStart(2,'0')} - ${new Date(2000,m-1,1).toLocaleString('fr',{month:'long'})}</option>`; html += '</select>'; }
        else if (type === 'trimestre') { html = '<label>Trimestre</label><select id="trimestreSelect">'; for (let t=1;t<=4;t++) html += `<option value="${t}" ${t==<?= $trimestre ?>?'selected':''}>${t}${t===1?'er':'ème'} Trimestre</option>`; html += '</select>'; }
        else if (type === 'semestre') { html = '<label>Semestre</label><select id="semestreSelect">'; for (let s=1;s<=2;s++) html += `<option value="${s}" ${s==<?= json_encode($semestre) ?>?'selected':''}>${s}${s===1?'er':'e'} semestre</option>`; html += '</select>'; }
        else { html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">'; }
        container.innerHTML = html;
    }
    function appliquerFiltres() {
        let e = document.getElementById('exerciceSelect').value;
        let t = document.getElementById('typePeriodeSelect').value;
        let u = 'DIMF_2013.php?exercice='+e+'&type_periode='+t;
        if (t==='mensuel') u += '&mois='+document.getElementById('moisSelect').value;
        if (t==='trimestre') u += '&trimestre='+document.getElementById('trimestreSelect').value;
        if (t==='semestre') u += '&semestre='+document.getElementById('semestreSelect').value;
        window.location.href = u;
    }
    document.addEventListener('DOMContentLoaded',function(){ updateDynamicSelect(); document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect); });
    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        const data = [['DIMF_2013 - PRÊTS AUX DIRIGEANTS'],['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],[],['Indicateur','Valeur']];
        data.push(['Prêts aux dirigeants (FCFA)',<?= $total_encours_dirigeants ?>]);
        data.push(['Engagements par signature (FCFA)',<?= $engagements_dirigeants ?>]);
        data.push(['Exposition totale (FCFA)',<?= $exposition_totale ?>]);
        data.push(['Fonds propres (FCFA)',<?= $fonds_propres ?>]);
        data.push(['Ratio R03 (%)',<?= number_format($ratio_r03*100,2,'.','') ?>]);
        data.push(['Norme BCEAO (%)','10']);
        data.push(['Conformité','<?= $conformite_r03 ?>']);
        const ws = XLSX.utils.aoa_to_sheet(data);
        XLSX.utils.book_append_sheet(wb, ws, "RATIO_R03");
        XLSX.writeFile(wb, 'DIMF_2013_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
    }
</script>
</body>
</html>