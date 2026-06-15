<?php
// DIMF_2008.php - État des biens avec clause de réserve de propriété (avec FPDF)
session_start();

// ============================================================
// CONFIGURATION BDD
// ============================================================
require_once '../../databases/database.php';
require_once '../../fpdf/fpdf.php';

class PDF_DIMF extends FPDF {
    public $codeDimf = 'DIMF'; public $titreDimf = 'Etat financier'; public $nomSfd = 'SFD'; public $periode = ''; public $exercice = '';
    static function u($str) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str); }
    function Header() { /* identique à DIMF_2007 */ $this->SetFillColor(156,163,175); $this->Rect(0,0,$this->GetPageWidth(),28,'F'); $this->SetFont('Arial','',7); $this->SetTextColor(255,255,255); $this->SetXY(8,3); $this->Cell(0,4,self::u('Republique de Cote d\'Ivoire  •  Ministere de l\'Economie et des Finances  -  DGTCP / DSFD'),0,1,'L'); $this->SetFont('Arial','B',13); $this->SetX(8); $this->Cell(0,7,self::u($this->codeDimf.'  -  '.$this->titreDimf),0,1,'L'); $this->SetFont('Arial','',8); $this->SetX(8); $this->Cell(0,5,self::u('SFD : '.$this->nomSfd.'   |   Periode : '.$this->periode.'   |   Exercice : '.$this->exercice.'   |   Arrete au : '.date('d/m/Y')),0,1,'L'); $this->SetTextColor(0,0,0); $this->Ln(4); }
    function Footer() { $this->SetY(-12); $this->SetFont('Arial','I',7); $this->SetTextColor(100,116,139); $this->Cell(0,4,self::u('SICS-BCEAO  •  Genere le '.date('d/m/Y a H:i:s').'  •  Page '.$this->PageNo().'/{nb}'),0,0,'C'); }
    function SectionTitle($label) { $this->SetFont('Arial','B',9); $this->SetFillColor(0,0,0); $this->SetTextColor(255,255,255); $this->Cell(0,7,self::u('  '.strtoupper($label)),0,1,'L',true); $this->SetTextColor(0,0,0); $this->Ln(1); }
    function TableHeader($cols) { $this->SetFont('Arial','B',8); $this->SetFillColor(248,250,252); $this->SetTextColor(30,41,59); $this->SetDrawColor(226,232,240); foreach($cols as $col){ $align=isset($col['align'])?$col['align']:'L'; $this->Cell($col['w'],6,self::u($col['label']),1,0,$align,true); } $this->Ln(); }
    function TableRow($cols,$data,$style='') { $fill=false; if($style=='subtotal'){ $this->SetFillColor(248,250,252); $this->SetFont('Arial','B',8); $fill=true; }elseif($style=='total'){ $this->SetFillColor(240,253,244); $this->SetFont('Arial','B',8.5); $fill=true; }else{ $this->SetFillColor(255,255,255); $this->SetFont('Arial','',7.5); } $this->SetTextColor(15,23,42); $this->SetDrawColor(226,232,240); foreach($cols as $i=>$col){ $val=isset($data[$i])?$data[$i]:''; $align=isset($col['align'])?$col['align']:'L'; $this->Cell($col['w'],5.5,self::u($val),1,0,$align,$fill); } $this->Ln(); }
    static function montant($val){ return number_format((float)$val,0,',',' ').' F'; }
}


// Paramètres période
$exercice = isset($_GET['exercice'])?(int)$_GET['exercice']:date('Y');
$type_periode = isset($_GET['type_periode'])?$_GET['type_periode']:'mensuel';
$mois = isset($_GET['mois'])?(int)$_GET['mois']:12;
$trimestre = isset($_GET['trimestre'])?(int)$_GET['trimestre']:4;
$semestre = isset($_GET['semestre'])?(int)$_GET['semestre']:null;
switch ($type_periode) { case 'trimestre': $mois=$trimestre*3; break; case 'semestre': $mois=($semestre==1)?6:12; break; case 'annuel': $mois=12; }
$date_fin_periode = date('Y-m-t', strtotime($exercice.'-'.str_pad($mois,2,'0',STR_PAD_LEFT).'-01'));

// Traitement formulaire
$message=''; $message_type='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS biens_reserve_propriete (id INT AUTO_INCREMENT PRIMARY KEY, exercice INT NOT NULL, libelle VARCHAR(255) NOT NULL, objet_clause VARCHAR(100), montant_brut DECIMAL(15,2) DEFAULT 0, date_inscription DATE, duree_jouissance INT, creancier_nom VARCHAR(200), creancier_adresse TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        if ($_POST['action']==='add') {
            $stmt = $pdo->prepare("INSERT INTO biens_reserve_propriete (exercice, libelle, objet_clause, montant_brut, date_inscription, duree_jouissance, creancier_nom, creancier_adresse) VALUES (:exercice, :libelle, :objet_clause, :montant_brut, :date_inscription, :duree_jouissance, :creancier_nom, :creancier_adresse)");
            $stmt->execute([':exercice'=>$exercice, ':libelle'=>$_POST['libelle']??'', ':objet_clause'=>$_POST['objet_clause']??'', ':montant_brut'=>$_POST['montant_brut']??0, ':date_inscription'=>!empty($_POST['date_inscription'])?$_POST['date_inscription']:null, ':duree_jouissance'=>!empty($_POST['duree_jouissance'])?(int)$_POST['duree_jouissance']:null, ':creancier_nom'=>$_POST['creancier_nom']??'', ':creancier_adresse'=>$_POST['creancier_adresse']??'']);
            $message="Bien ajouté !"; $message_type="success";
        } elseif ($_POST['action']==='delete' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("DELETE FROM biens_reserve_propriete WHERE id = :id AND exercice = :exercice");
            $stmt->execute([':id'=>(int)$_POST['id'], ':exercice'=>$exercice]); $message="Bien supprimé !"; $message_type="success";
        } elseif ($_POST['action']==='update' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("UPDATE biens_reserve_propriete SET libelle = :libelle, objet_clause = :objet_clause, montant_brut = :montant_brut, date_inscription = :date_inscription, duree_jouissance = :duree_jouissance, creancier_nom = :creancier_nom, creancier_adresse = :creancier_adresse WHERE id = :id AND exercice = :exercice");
            $stmt->execute([':id'=>(int)$_POST['id'], ':exercice'=>$exercice, ':libelle'=>$_POST['libelle']??'', ':objet_clause'=>$_POST['objet_clause']??'', ':montant_brut'=>$_POST['montant_brut']??0, ':date_inscription'=>!empty($_POST['date_inscription'])?$_POST['date_inscription']:null, ':duree_jouissance'=>!empty($_POST['duree_jouissance'])?(int)$_POST['duree_jouissance']:null, ':creancier_nom'=>$_POST['creancier_nom']??'', ':creancier_adresse'=>$_POST['creancier_adresse']??'']);
            $message="Bien modifié !"; $message_type="success";
        }
    } catch(PDOException $e){ $message="Erreur : ".$e->getMessage(); $message_type="error"; }
    header("Location: DIMF_2008.php?exercice=$exercice&type_periode=$type_periode".($type_periode=='mensuel'?"&mois=$mois":($type_periode=='trimestre'?"&trimestre=$trimestre":($type_periode=='semestre'?"&semestre=$semestre":"")))."&msg=".urlencode($message)."&msg_type=$message_type");
    exit;
}
if(isset($_GET['msg'])){ $message=$_GET['msg']; $message_type=$_GET['msg_type']??'success'; }

// Récupération biens
$biens_reserve = []; $total_montant_brut=0;
try {
    $stmt = $pdo->prepare("SELECT * FROM biens_reserve_propriete WHERE exercice = :exercice ORDER BY id");
    $stmt->execute([':exercice'=>$exercice]); $biens_reserve=$stmt->fetchAll();
    foreach($biens_reserve as $b){ $total_montant_brut+=$b['montant_brut']; }
} catch(PDOException $e){}

$edit_bien = null;
if(isset($_GET['edit']) && is_numeric($_GET['edit'])){
    try{ $stmt=$pdo->prepare("SELECT * FROM biens_reserve_propriete WHERE id=:id AND exercice=:exercice"); $stmt->execute([':id'=>$_GET['edit'],':exercice'=>$exercice]); $edit_bien=$stmt->fetch(); } catch(PDOException $e){}
}
$objets_clause = ['ACHAT'=>'Achat','CONSTRUCTION'=>'Construction','EQUIPEMENT'=>'Équipement','AUTRE'=>'Autre'];

// GÉNÉRATION PDF
$format = isset($_GET['format'])?$_GET['format']:'html';
if($format==='pdf'){
    switch($type_periode){
        case 'mensuel': $lib_periode='Mois '.str_pad($mois,2,'0',STR_PAD_LEFT).'/'.$exercice; break;
        case 'trimestre': $lib_periode=$trimestre.'e Trim. '.$exercice; break;
        case 'semestre': $lib_periode=$semestre.'er Sem. '.$exercice; break;
        default: $lib_periode='Annee '.$exercice;
    }
    $pdf = new PDF_DIMF('L','mm','A4'); $pdf->AliasNbPages();
    $pdf->codeDimf='DIMF_2008'; $pdf->titreDimf='Réserve de propriété'; $pdf->nomSfd=$_SESSION['nom_sfd']??'SFD'; $pdf->periode=$lib_periode; $pdf->exercice=$exercice;
    $pdf->SetMargins(8,35,8); $pdf->SetAutoPageBreak(true,14); $pdf->AddPage();
    $cols = [['label'=>'Libellé','w'=>40],['label'=>'Objet clause','w'=>30],['label'=>'Montant brut','w'=>35,'align'=>'R'],['label'=>'Date inscription','w'=>30,'align'=>'C'],['label'=>'Durée (mois)','w'=>25,'align'=>'C'],['label'=>'Créancier','w'=>40],['label'=>'Adresse','w'=>50]];
    $pdf->SectionTitle('Biens avec clause de réserve de propriété');
    $pdf->TableHeader($cols);
    foreach($biens_reserve as $b){
        $pdf->TableRow($cols,[
            PDF_DIMF::u($b['libelle']),
            PDF_DIMF::u($objets_clause[$b['objet_clause']]??$b['objet_clause']?:'-'),
            PDF_DIMF::montant($b['montant_brut']),
            $b['date_inscription']?date('d/m/Y',strtotime($b['date_inscription'])):'-',
            $b['duree_jouissance']?$b['duree_jouissance'].' mois':'-',
            PDF_DIMF::u($b['creancier_nom']?:'-'),
            PDF_DIMF::u($b['creancier_adresse']?:'-')
        ]);
    }
    $pdf->TableRow($cols,['TOTAL','',PDF_DIMF::montant($total_montant_brut),'','','',''],'total');
    $pdf->Output('I','DIMF_2008_ReservePropriete_'.$exercice.'_'.$type_periode.'.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2008 - Réserve de propriété</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> /* mêmes styles que DIMF_2007 (copie intégrale, garder cohérence) */ *{margin:0;padding:0;box-sizing:border-box;} body{font-family:'Inter',sans-serif;background:#f1f5f9;padding:24px;} .dashboard{max-width:1400px;margin:0 auto;} .page-header{background:linear-gradient(135deg,#3b82f6,#60a5fa);border-radius:24px;padding:20px 28px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;} .header-left h1{font-size:1.6rem;font-weight:600;color:white;margin-bottom:6px;display:flex;align-items:center;gap:10px;} .subtitle{font-size:0.8rem;color:#e0f2fe;} .badge{background:#2563eb;color:white;padding:4px 12px;border-radius:30px;font-size:0.7rem;} .btn-group{display:flex;gap:12px;} .btn-excel,.btn-pdf{display:inline-flex;align-items:center;gap:8px;padding:8px 20px;border-radius:40px;font-weight:500;font-size:0.85rem;border:none;cursor:pointer;text-decoration:none;} .btn-excel{background:#10b981;color:white;} .btn-pdf{background:#ef4444;color:white;} .card{background:white;border-radius:20px;padding:20px 24px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);} .card-header{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #eef2f6;font-weight:600;color:#1e40af;} .filters-row{display:flex;flex-wrap:wrap;align-items:flex-end;gap:20px;} .filter-item{display:flex;flex-direction:column;gap:6px;} .filter-item label{font-size:0.7rem;font-weight:600;text-transform:uppercase;color:#4b5563;} .filter-item select,.filter-item input,.filter-item textarea{border:1px solid #d1d5db;border-radius:12px;padding:8px 14px;font-size:0.85rem;} .btn-apply{background:#3b82f6;color:white;border:none;border-radius:40px;padding:8px 24px;cursor:pointer;} .table-wrapper{overflow-x:auto;} table{width:100%;border-collapse:collapse;font-size:0.85rem;} th{text-align:left;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;} td{padding:10px 16px;border-bottom:1px solid #f1f5f9;} .text-right{text-align:right;} .total-row{background:#f0fdf4;font-weight:700;} .info-box{background:#eef2ff;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:16px;display:flex;align-items:center;gap:14px;} .action-buttons{display:flex;gap:8px;} .btn-warning{background:#f59e0b;color:white;padding:4px 12px;border-radius:20px;text-decoration:none;font-size:0.75rem;} .btn-danger{background:#ef4444;color:white;padding:4px 12px;border-radius:20px;border:none;cursor:pointer;font-size:0.75rem;} .page-footer{text-align:center;font-size:0.75rem;color:#6b7280;margin-top:16px;} @media print{.btn-group,.page-footer,#filtersCard{display:none;}} </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left"><h1><i class="fas fa-file-signature"></i> DIMF_2008 - RÉSERVE DE PROPRIÉTÉ</h1><div class="subtitle">République de Côte d'Ivoire – DGTCP / DSFD</div><div class="badge">SICS-BCEAO</div></div>
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
    <?php if($message): ?><div class="info-box" style="background:<?= $message_type=='success'?'#d1fae5':'#fee2e2' ?>;border-left-color:<?= $message_type=='success'?'#10b981':'#ef4444' ?>;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <div class="card">
        <div class="card-header"><i class="fas <?= $edit_bien?'fa-edit':'fa-plus-circle' ?>"></i> <?= $edit_bien?'MODIFIER UN BIEN':'AJOUTER UN BIEN' ?></div>
        <form method="post">
            <input type="hidden" name="action" value="<?= $edit_bien?'update':'add' ?>">
            <?php if($edit_bien): ?><input type="hidden" name="id" value="<?= $edit_bien['id'] ?>"><?php endif; ?>
            <div class="filters-row" style="margin-bottom:0;">
                <div class="filter-item"><label>Libellé *</label><input type="text" name="libelle" required value="<?= $edit_bien?htmlspecialchars($edit_bien['libelle']):'' ?>"></div>
                <div class="filter-item"><label>Objet clause</label><select name="objet_clause"><option value="">--</option><?php foreach($objets_clause as $k=>$v) echo "<option value='$k' ".($edit_bien&&$edit_bien['objet_clause']==$k?'selected':'').">$v</option>"; ?></select></div>
                <div class="filter-item"><label>Montant brut (FCFA)</label><input type="number" name="montant_brut" value="<?= $edit_bien?(int)$edit_bien['montant_brut']:0 ?>"></div>
                <div class="filter-item"><label>Date inscription</label><input type="date" name="date_inscription" value="<?= $edit_bien&&$edit_bien['date_inscription']?date('Y-m-d',strtotime($edit_bien['date_inscription'])):'' ?>"></div>
                <div class="filter-item"><label>Durée jouissance (mois)</label><input type="number" name="duree_jouissance" value="<?= $edit_bien&&$edit_bien['duree_jouissance']!==null?(int)$edit_bien['duree_jouissance']:'' ?>"></div>
                <div class="filter-item"><label>Créancier</label><input type="text" name="creancier_nom" value="<?= $edit_bien?htmlspecialchars($edit_bien['creancier_nom']):'' ?>"></div>
                <div class="filter-item"><label>Adresse</label><textarea name="creancier_adresse"><?= $edit_bien?htmlspecialchars($edit_bien['creancier_adresse']):'' ?></textarea></div>
                <div class="filter-item"><button type="submit" class="btn-apply"><?= $edit_bien?'Mettre à jour':'Ajouter' ?></button></div>
                <?php if($edit_bien): ?><div class="filter-item"><a href="DIMF_2008.php?exercice=<?= $exercice ?>&type_periode=<?= $type_periode ?><?= $type_periode=='mensuel'?"&mois=$mois":($type_periode=='trimestre'?"&trimestre=$trimestre":($type_periode=='semestre'?"&semestre=$semestre":"")) ?>" class="btn-warning">Annuler</a></div><?php endif; ?>
            </div>
        </form>
    </div>
    <div class="card">
        <div class="card-header"><i class="fas fa-list-ul"></i> LISTE DES BIENS</div>
        <?php if(empty($biens_reserve)): ?><div class="info-box">Aucun bien enregistré.</div><?php else: ?>
        <div class="table-wrapper"><table>
            <thead><th>Libellé</th><th>Objet clause</th><th class="text-right">Montant brut</th><th>Date inscription</th><th>Durée jouissance</th><th>Créancier</th><th>Actions</th></thead>
            <tbody><?php foreach($biens_reserve as $b): ?>
                <tr><td><?= htmlspecialchars($b['libelle']) ?></td><td><?= htmlspecialchars($objets_clause[$b['objet_clause']]??$b['objet_clause']?:'-') ?></td><td class="text-right"><?= number_format($b['montant_brut'],0,',',' ') ?></td><td><?= $b['date_inscription']?date('d/m/Y',strtotime($b['date_inscription'])):'-' ?></td><td><?= $b['duree_jouissance']?$b['duree_jouissance'].' mois':'-' ?></td><td><?= htmlspecialchars($b['creancier_nom']?:'-') ?></td>
                <td class="action-buttons"><a href="?exercice=<?= $exercice ?>&type_periode=<?= $type_periode ?><?= $type_periode=='mensuel'?"&mois=$mois":($type_periode=='trimestre'?"&trimestre=$trimestre":($type_periode=='semestre'?"&semestre=$semestre":"")) ?>&edit=<?= $b['id'] ?>" class="btn-warning"><i class="fas fa-edit"></i></a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button type="submit" class="btn-danger"><i class="fas fa-trash-alt"></i></button></form></td></tr>
            <?php endforeach; ?>
            <tr class="total-row"><td colspan="2"><strong>TOTAL</strong></td><td class="text-right"><strong><?= number_format($total_montant_brut,0,',',' ') ?></strong></td><td colspan="4"></td></tr>
            </tbody>
        </table></div><?php endif; ?>
    </div>
    <div class="page-footer">Généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>)</div>
</div>
<script>
    function updateDynamicSelect(){ const t=document.getElementById('typePeriodeSelect').value,c=document.getElementById('dynamicSelectContainer'); let h=''; if(t==='mensuel'){ h='<label>Mois</label><select id="moisSelect">'; for(let m=1;m<=12;m++){ h+=`<option value="${m}" ${m==<?= $mois ?>?'selected':''}>${String(m).padStart(2,'0')} - ${new Date(2000,m-1,1).toLocaleString('fr',{month:'long'})}</option>`; } h+='</select>'; }else if(t==='trimestre'){ h='<label>Trimestre</label><select id="trimestreSelect">'; for(let tq=1;tq<=4;tq++){ h+=`<option value="${tq}" ${tq==<?= $trimestre ?>?'selected':''}>${tq}${tq===1?'er':'ème'} Trimestre</option>`; } h+='</select>'; }else if(t==='semestre'){ h='<label>Semestre</label><select id="semestreSelect">'; for(let s=1;s<=2;s++){ h+=`<option value="${s}" ${s==<?= json_encode($semestre) ?>?'selected':''}>${s}${s===1?'er':'e'} semestre</option>`; } h+='</select>'; }else{ h='<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">'; } c.innerHTML=h; }
    function appliquerFiltres(){ let e=document.getElementById('exerciceSelect').value,t=document.getElementById('typePeriodeSelect').value,u='DIMF_2008.php?exercice='+e+'&type_periode='+t; if(t==='mensuel') u+='&mois='+document.getElementById('moisSelect').value; if(t==='trimestre') u+='&trimestre='+document.getElementById('trimestreSelect').value; if(t==='semestre') u+='&semestre='+document.getElementById('semestreSelect').value; window.location.href=u; }
    document.addEventListener('DOMContentLoaded',function(){ updateDynamicSelect(); document.getElementById('typePeriodeSelect').addEventListener('change',updateDynamicSelect); });
    function exporterExcel(){ const wb=XLSX.utils.book_new(); const data=[['DIMF_2008 - RÉSERVE DE PROPRIÉTÉ'],['Exercice','<?= $exercice ?>','Type','<?= $type_periode ?>'],[],['Libellé','Objet','Montant brut','Date inscription','Durée (mois)','Créancier','Adresse']];
        <?php foreach($biens_reserve as $b): ?> data.push(['<?= addslashes($b['libelle']) ?>','<?= addslashes($objets_clause[$b['objet_clause']]??$b['objet_clause']?:'-') ?>',<?= $b['montant_brut'] ?>,'<?= $b['date_inscription']?date('d/m/Y',strtotime($b['date_inscription'])):'-' ?>','<?= $b['duree_jouissance']??'-' ?>','<?= addslashes($b['creancier_nom']?:'-') ?>','<?= addslashes($b['creancier_adresse']?:'-') ?>']); <?php endforeach; ?>
        data.push(['TOTAL','',<?= $total_montant_brut ?>,'','','','']); const ws=XLSX.utils.aoa_to_sheet(data); XLSX.utils.book_append_sheet(wb,ws,"RESERVE_PROPRIETE"); XLSX.writeFile(wb,'DIMF_2008_<?= $exercice ?>_<?= $type_periode ?>.xlsx'); }
</script>
</body>
</html>