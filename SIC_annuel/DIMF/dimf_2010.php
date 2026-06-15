<?php
// DIMF_2010.php - État des crédits en souffrance avec FPDF
session_start();

// ============================================================
// CONFIGURATION BDD
// ============================================================
require_once '../../databases/database.php';

require_once '../../fpdf/fpdf.php';

class PDF_DIMF extends FPDF {
    public $codeDimf = 'DIMF'; public $titreDimf = 'Etat financier'; public $nomSfd = 'SFD'; public $periode = ''; public $exercice = '';
    static function u($str) {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $str);
    }
    function Header() { /* identique à la version précédente */ $this->SetFillColor(156,163,175); $this->Rect(0,0,$this->GetPageWidth(),28,'F'); $this->SetFont('Arial','',7); $this->SetTextColor(255,255,255); $this->SetXY(8,3); $this->Cell(0,4,self::u('Republique de Cote d\'Ivoire  •  Ministere de l\'Economie et des Finances  -  DGTCP / DSFD'),0,1,'L'); $this->SetFont('Arial','B',13); $this->SetX(8); $this->Cell(0,7,self::u($this->codeDimf.'  -  '.$this->titreDimf),0,1,'L'); $this->SetFont('Arial','',8); $this->SetX(8); $this->Cell(0,5,self::u('SFD : '.$this->nomSfd.'   |   Periode : '.$this->periode.'   |   Exercice : '.$this->exercice.'   |   Arrete au : '.date('d/m/Y')),0,1,'L'); $this->SetTextColor(0,0,0); $this->Ln(4); }
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
$date_reference = new DateTime($date_fin_periode);

// Calcul des crédits en souffrance (identique)
$credits_souffrance = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.dossier_id, d.montant as montant_initial, d.date_octroi, d.statut as dossier_statut,
            COALESCE(d.montant - SUM(CASE WHEN e.statut = 'payee' THEN e.montant ELSE 0 END), d.montant) as encours_actuel,
            SUM(CASE WHEN e.statut = 'attente' AND e.date_echeance < :date_fin THEN e.montant ELSE 0 END) as montant_impaye,
            MAX(CASE WHEN e.statut = 'attente' AND e.date_echeance < :date_fin THEN e.date_echeance ELSE NULL END) as date_dernier_impaye,
            COUNT(CASE WHEN e.statut = 'attente' AND e.date_echeance < :date_fin THEN 1 ELSE NULL END) as nb_echeances_impayees
        FROM dossiers d LEFT JOIN echeances e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve','impaye') AND d.date_octroi <= :date_fin
        GROUP BY d.dossier_id HAVING montant_impaye > 0 ORDER BY date_dernier_impaye DESC
    ");
    $stmt->execute([':date_fin'=>$date_fin_periode]);
    $credits_souffrance = $stmt->fetchAll();
} catch(PDOException $e){ $credits_souffrance = []; }

$tranches = [
    'B71'=>['libelle'=>'Crédits comportant au moins une échéance impayée ≤ 6 mois','min'=>1,'max'=>180,'montant_brut'=>0,'depots_garantie'=>0,'solde_restant'=>0,'provisions'=>0,'montant_net'=>0],
    'B72'=>['libelle'=>'Crédits comportant au moins une échéance impayée > 6 à ≤ 12 mois','min'=>181,'max'=>365,'montant_brut'=>0,'depots_garantie'=>0,'solde_restant'=>0,'provisions'=>0,'montant_net'=>0],
    'B73'=>['libelle'=>'Crédits comportant au moins une échéance impayée > 12 à ≤ 24 mois','min'=>366,'max'=>730,'montant_brut'=>0,'depots_garantie'=>0,'solde_restant'=>0,'provisions'=>0,'montant_net'=>0]
];

foreach($credits_souffrance as $c){
    if($c['date_dernier_impaye']){
        $date_impaye = new DateTime($c['date_dernier_impaye']);
        $jours = $date_reference->diff($date_impaye)->days;
        if($jours>=1 && $jours<=180) $t='B71';
        elseif($jours>=181 && $jours<=365) $t='B72';
        elseif($jours>=366 && $jours<=730) $t='B73';
        else continue;
        $prov = 0;
        try { $s = $pdo->prepare("SELECT COALESCE(SUM(montant),0) as p FROM provisions WHERE credit_id=:id AND statut='actif'"); $s->execute([':id'=>$c['dossier_id']]); $prov = (float)$s->fetch()['p']; } catch(PDOException $e){}
        $tranches[$t]['montant_brut'] += $c['montant_initial'];
        $tranches[$t]['solde_restant'] += $c['encours_actuel'];
        $tranches[$t]['provisions'] += $prov;
        $tranches[$t]['montant_net'] += $c['encours_actuel'] - $prov;
    }
}
$total_brut = $tranches['B71']['montant_brut']+$tranches['B72']['montant_brut']+$tranches['B73']['montant_brut'];
$total_solde = $tranches['B71']['solde_restant']+$tranches['B72']['solde_restant']+$tranches['B73']['solde_restant'];
$total_prov = $tranches['B71']['provisions']+$tranches['B72']['provisions']+$tranches['B73']['provisions'];
$total_net = $tranches['B71']['montant_net']+$tranches['B72']['montant_net']+$tranches['B73']['montant_net'];

$details_credits = [];
foreach($credits_souffrance as $c){
    if($c['date_dernier_impaye']){
        $date_impaye = new DateTime($c['date_dernier_impaye']);
        $jours = $date_reference->diff($date_impaye)->days;
        if($jours>=1 && $jours<=730) $details_credits[]=$c;
    }
}

// Portefeuille total
$portefeuille_total = 0;
try {
    $s = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id=e.dossier_id WHERE d.statut IN ('actif','approuve') AND d.date_octroi<=:df");
    $s->execute([':df'=>$date_fin_periode]); $portefeuille_total = (float)$s->fetch()['total'];
} catch(PDOException $e){}
$encours_souffrance = $tranches['B71']['solde_restant']+$tranches['B72']['solde_restant']+$tranches['B73']['solde_restant'];
$par30 = ($portefeuille_total>0)?($encours_souffrance/$portefeuille_total)*100:0;
$par90 = ($portefeuille_total>0)?(($tranches['B72']['solde_restant']+$tranches['B73']['solde_restant'])/$portefeuille_total)*100:0;
$par180 = ($portefeuille_total>0)?($tranches['B73']['solde_restant']/$portefeuille_total)*100:0;
$taux_couverture = ($encours_souffrance>0)?($total_prov/$encours_souffrance)*100:0;

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
    $pdf->codeDimf='DIMF_2010'; $pdf->titreDimf='Crédits en souffrance'; $pdf->nomSfd=$_SESSION['nom_sfd']??'SFD'; $pdf->periode=$lib_periode; $pdf->exercice=$exercice;
    $pdf->SetMargins(8,35,8); $pdf->SetAutoPageBreak(true,14); $pdf->AddPage();

    $cols = [['label'=>'CODE','w'=>20],['label'=>'CRÉDITS EN SOUFFRANCE','w'=>100],['label'=>'Brut (FCFA)','w'=>35,'align'=>'R'],['label'=>'Dépôts garantie','w'=>35,'align'=>'R'],['label'=>'Solde restant','w'=>35,'align'=>'R'],['label'=>'Provisions','w'=>35,'align'=>'R'],['label'=>'Net','w'=>35,'align'=>'R']];
    $pdf->SectionTitle('Crédits en souffrance par tranche');
    $pdf->TableHeader($cols);
    foreach($tranches as $code=>$t){
        $pdf->TableRow($cols,[$code,PDF_DIMF::u($t['libelle']),PDF_DIMF::montant($t['montant_brut']),PDF_DIMF::montant($t['depots_garantie']),PDF_DIMF::montant($t['solde_restant']),PDF_DIMF::montant($t['provisions']),PDF_DIMF::montant($t['montant_net'])]);
    }
    $pdf->TableRow($cols,['TOTAL','Ensemble des créances en souffrance',PDF_DIMF::montant($total_brut),PDF_DIMF::montant(0),PDF_DIMF::montant($total_solde),PDF_DIMF::montant($total_prov),PDF_DIMF::montant($total_net)],'total');

    $pdf->Ln(5);
    $pdf->SetFont('Arial','B',8);
    $pdf->Cell(0,6,PDF_DIMF::u('Indicateurs de qualité du portefeuille'),0,1);
    $pdf->SetFont('Arial','',8);
    $pdf->MultiCell(0,5,PDF_DIMF::u("PAR 30 : ".number_format($par30,2)."% (Norme ≤5%)\nPAR 90 : ".number_format($par90,2)."% (Norme ≤3%)\nPAR 180 : ".number_format($par180,2)."% (Norme ≤2%)\nTaux de couverture : ".number_format($taux_couverture,2)."% (Norme ≥40%)\nPortefeuille total : ".PDF_DIMF::montant($portefeuille_total)));
    $pdf->Output('I','DIMF_2010_Souffrance_'.$exercice.'_'.$type_periode.'.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2010 - Crédits en souffrance</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;} body{font-family:'Inter',sans-serif;background:#f1f5f9;padding:24px;}
        .dashboard{max-width:1400px;margin:0 auto;}
        .page-header{background:linear-gradient(135deg,#3b82f6,#60a5fa);border-radius:24px;padding:20px 28px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;}
        .header-left h1{font-size:1.6rem;font-weight:600;color:white;margin-bottom:6px;display:flex;align-items:center;gap:10px;}
        .subtitle{font-size:0.8rem;color:#e0f2fe;} .badge{background:#2563eb;color:white;padding:4px 12px;border-radius:30px;font-size:0.7rem;}
        .btn-group{display:flex;gap:12px;} .btn-excel,.btn-pdf{display:inline-flex;align-items:center;gap:8px;padding:8px 20px;border-radius:40px;font-weight:500;font-size:0.85rem;border:none;cursor:pointer;text-decoration:none;}
        .btn-excel{background:#10b981;color:white;} .btn-pdf{background:#ef4444;color:white;}
        .card{background:white;border-radius:20px;padding:20px 24px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);}
        .card-header{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #eef2f6;font-weight:600;color:#1e40af;}
        .filters-row{display:flex;flex-wrap:wrap;align-items:flex-end;gap:20px;}
        .filter-item{display:flex;flex-direction:column;gap:6px;} .filter-item label{font-size:0.7rem;font-weight:600;text-transform:uppercase;color:#4b5563;}
        .filter-item select{border:1px solid #d1d5db;border-radius:12px;padding:8px 14px;font-size:0.85rem;}
        .btn-apply{background:#3b82f6;color:white;border:none;border-radius:40px;padding:8px 24px;cursor:pointer;}
        .table-wrapper{overflow-x:auto;} table{width:100%;border-collapse:collapse;font-size:0.85rem;}
        th{text-align:left;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;}
        td{padding:10px 16px;border-bottom:1px solid #f1f5f9;} .text-right{text-align:right;}
        .total-row{background:#f0fdf4;font-weight:700;} .info-box{background:#eef2ff;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:16px;}
        .badge-retard{display:inline-block;padding:4px 10px;border-radius:20px;font-size:0.7rem;font-weight:600;}
        .retard-30{background:#fef3c7;color:#d97706;} .retard-90{background:#fee2e2;color:#dc2626;} .retard-180{background:#b91c1c;color:white;}
        .indicators-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;}
        .page-footer{text-align:center;font-size:0.75rem;color:#6b7280;margin-top:16px;}
        @media print{.btn-group,.page-footer,#filtersCard{display:none;}}
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left"><h1><i class="fas fa-exclamation-triangle"></i> DIMF_2010 - CRÉDITS EN SOUFFRANCE</h1><div class="subtitle">République de Côte d'Ivoire – DGTCP / DSFD</div><div class="badge">SICS-BCEAO</div></div>
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
        <div class="card-header"><i class="fas fa-chart-line"></i> CRÉDITS EN SOUFFRANCE PAR TRANCHE</div>
        <div class="table-wrapper">
            <table>
                <thead><th>CODE</th><th>CRÉDITS EN SOUFFRANCE</th><th class="text-right">Brut (FCFA)</th><th class="text-right">Dépôts garantie</th><th class="text-right">Solde restant</th><th class="text-right">Provisions</th><th class="text-right">Net</th></thead>
                <tbody><?php foreach($tranches as $code=>$t): ?>
                    <tr><td><?= $code ?></td><td class="text-left"><?= htmlspecialchars($t['libelle']) ?></td><td class="text-right"><?= number_format($t['montant_brut'],0,',',' ') ?></td><td class="text-right">0</td><td class="text-right"><?= number_format($t['solde_restant'],0,',',' ') ?></td><td class="text-right"><?= number_format($t['provisions'],0,',',' ') ?></td><td class="text-right"><?= number_format($t['montant_net'],0,',',' ') ?></td>
                <?php endforeach; ?>
                <tr class="total-row"><td colspan="2"><strong>TOTAL</strong></td><td class="text-right"><strong><?= number_format($total_brut,0,',',' ') ?></strong></td><td class="text-right"><strong>0</strong></td><td class="text-right"><strong><?= number_format($total_solde,0,',',' ') ?></strong></td><td class="text-right"><strong><?= number_format($total_prov,0,',',' ') ?></strong></td><td class="text-right"><strong><?= number_format($total_net,0,',',' ') ?></strong></td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><i class="fas fa-list-ul"></i> DÉTAIL DES CRÉDITS EN SOUFFRANCE</div>
        <?php if(empty($details_credits)): ?><div class="info-box">Aucun crédit en souffrance.</div><?php else: ?>
        <div class="table-wrapper"><table>
            <thead><th>N° Dossier</th><th>Date octroi</th><th class="text-right">Montant initial</th><th class="text-right">Encours actuel</th><th class="text-right">Impayé</th><th>Dernier impayé</th><th>Jours retard</th><th class="text-right">Provisions</th></thead>
            <tbody><?php foreach($details_credits as $c): $date_imp=new DateTime($c['date_dernier_impaye']); $j=$date_reference->diff($date_imp)->days; $class='retard-30'; if($j>=90)$class='retard-90'; if($j>=180)$class='retard-180'; $prov=0; try{$s=$pdo->prepare("SELECT COALESCE(SUM(montant),0) as p FROM provisions WHERE credit_id=:id AND statut='actif'"); $s->execute([':id'=>$c['dossier_id']]); $prov=$s->fetch()['p'];}catch(Exception $e){} ?>
                <tr><td><?= htmlspecialchars($c['dossier_id']) ?></td><td><?= date('d/m/Y',strtotime($c['date_octroi'])) ?></td><td class="text-right"><?= number_format($c['montant_initial'],0,',',' ') ?></td><td class="text-right"><?= number_format($c['encours_actuel'],0,',',' ') ?></td><td class="text-right"><?= number_format($c['montant_impaye'],0,',',' ') ?></td><td><?= date('d/m/Y',strtotime($c['date_dernier_impaye'])) ?></td><td><span class="badge-retard <?= $class ?>"><?= $j ?> jours</span></td><td class="text-right"><?= number_format($prov,0,',',' ') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </div>
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-simple"></i> INDICATEURS DE QUALITÉ</div>
        <div class="info-box">
            <div class="indicators-grid">
                <div><strong>PAR 30 :</strong><br><span style="font-size:1.3rem;"><?= number_format($par30,2) ?>%</span><br><span class="badge-retard" style="background:#e8f5e9;">Norme ≤5%</span></div>
                <div><strong>PAR 90 :</strong><br><span style="font-size:1.3rem;"><?= number_format($par90,2) ?>%</span><br><span class="badge-retard" style="background:#e8f5e9;">Norme ≤3%</span></div>
                <div><strong>PAR 180 :</strong><br><span style="font-size:1.3rem;"><?= number_format($par180,2) ?>%</span><br><span class="badge-retard" style="background:#e8f5e9;">Norme ≤2%</span></div>
                <div><strong>Taux couverture :</strong><br><span style="font-size:1.3rem;"><?= number_format($taux_couverture,2) ?>%</span><br><span class="badge-retard" style="background:#e8f5e9;">Norme ≥40%</span></div>
                <div><strong>Portefeuille total :</strong><br><?= number_format($portefeuille_total,0,',',' ') ?> FCFA</div>
            </div>
        </div>
    </div>
    <div class="page-footer">Généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>)</div>
</div>
<script>
    function updateDynamicSelect(){ const t=document.getElementById('typePeriodeSelect').value,c=document.getElementById('dynamicSelectContainer'); let h=''; if(t==='mensuel'){ h='<label>Mois</label><select id="moisSelect">'; for(let m=1;m<=12;m++){ h+=`<option value="${m}" ${m==<?= $mois ?>?'selected':''}>${String(m).padStart(2,'0')} - ${new Date(2000,m-1,1).toLocaleString('fr',{month:'long'})}</option>`; } h+='</select>'; }else if(t==='trimestre'){ h='<label>Trimestre</label><select id="trimestreSelect">'; for(let tq=1;tq<=4;tq++){ h+=`<option value="${tq}" ${tq==<?= $trimestre ?>?'selected':''}>${tq}${tq===1?'er':'ème'} Trimestre</option>`; } h+='</select>'; }else if(t==='semestre'){ h='<label>Semestre</label><select id="semestreSelect">'; for(let s=1;s<=2;s++){ h+=`<option value="${s}" ${s==<?= json_encode($semestre) ?>?'selected':''}>${s}${s===1?'er':'e'} semestre</option>`; } h+='</select>'; }else{ h='<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">'; } c.innerHTML=h; }
    function appliquerFiltres(){ let e=document.getElementById('exerciceSelect').value,t=document.getElementById('typePeriodeSelect').value,u='DIMF_2010.php?exercice='+e+'&type_periode='+t; if(t==='mensuel') u+='&mois='+document.getElementById('moisSelect').value; if(t==='trimestre') u+='&trimestre='+document.getElementById('trimestreSelect').value; if(t==='semestre') u+='&semestre='+document.getElementById('semestreSelect').value; window.location.href=u; }
    document.addEventListener('DOMContentLoaded',function(){ updateDynamicSelect(); document.getElementById('typePeriodeSelect').addEventListener('change',updateDynamicSelect); });
    function exporterExcel(){ const wb=XLSX.utils.book_new(); const data=[['DIMF_2010 - CRÉDITS EN SOUFFRANCE'],['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],[],['Tranche','Brut','Dépôts garantie','Solde restant','Provisions','Net']];
        <?php foreach($tranches as $code=>$t): ?>
        data.push(['<?= $code ?>',<?= $t['montant_brut'] ?>,0,<?= $t['solde_restant'] ?>,<?= $t['provisions'] ?>,<?= $t['montant_net'] ?>]);
        <?php endforeach; ?>
        data.push(['TOTAL',<?= $total_brut ?>,0,<?= $total_solde ?>,<?= $total_prov ?>,<?= $total_net ?>]);
        const ws=XLSX.utils.aoa_to_sheet(data); XLSX.utils.book_append_sheet(wb,ws,"SOUFFRANCE"); XLSX.writeFile(wb,'DIMF_2010_<?= $exercice ?>_<?= $type_periode ?>.xlsx'); }
</script>
</body>
</html>