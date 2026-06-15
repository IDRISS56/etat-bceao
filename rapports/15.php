<?php
// 15-CommAuxComptes.php - Suivi des commissariats aux comptes
// Pour les SFD soumis à l'obligation d'un commissariat aux comptes

session_start();

// Configuration BDD
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ============================================================
// PARAMÈTRES (POST avec session)
// ============================================================
$exercice     = isset($_POST['exercice']) ? (int)$_POST['exercice'] : (isset($_SESSION['comm_exercice']) ? $_SESSION['comm_exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode'] : (isset($_SESSION['comm_type_periode']) ? $_SESSION['comm_type_periode'] : 'annuel');
$mois         = isset($_POST['mois']) ? (int)$_POST['mois'] : (isset($_SESSION['comm_mois']) ? $_SESSION['comm_mois'] : 12);
$trimestre    = isset($_POST['trimestre']) ? (int)$_POST['trimestre'] : (isset($_SESSION['comm_trimestre']) ? $_SESSION['comm_trimestre'] : 4);
$semestre     = isset($_POST['semestre']) ? (int)$_POST['semestre'] : (isset($_SESSION['comm_semestre']) ? $_SESSION['comm_semestre'] : 2);
$format       = isset($_POST['format']) ? $_POST['format'] : (isset($_SESSION['comm_format']) ? $_SESSION['comm_format'] : 'html');

// Sauvegarde en session
$_SESSION['comm_exercice'] = $exercice;
$_SESSION['comm_type_periode'] = $type_periode;
$_SESSION['comm_mois'] = $mois;
$_SESSION['comm_trimestre'] = $trimestre;
$_SESSION['comm_semestre'] = $semestre;
$_SESSION['comm_format'] = $format;

// Calcul de la période
switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
    default:          $mois = isset($_POST['mois']) ? (int)$_POST['mois'] : 12;
}

$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));

// Libellé période
switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Annee ' . $exercice;
}

// ============================================================
// TRAITEMENT DU FORMULAIRE
// ============================================================
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {
    try {
        // Création des tables
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS commissaires_comptes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                nom_cabinet VARCHAR(200) NOT NULL,
                date_nomination DATE DEFAULT NULL,
                UNIQUE KEY uk_exercice_nom (exercice, nom_cabinet)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS audits_externes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                comptes_certifies ENUM('oui', 'non') DEFAULT 'non',
                avis ENUM('sans_reserve', 'avec_reserve', 'defavorable', 'impossible') DEFAULT NULL,
                date_audit DATE DEFAULT NULL,
                observations TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_exercice (exercice)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Sauvegarder les commissaires
        if (isset($_POST['commissaires']) && is_array($_POST['commissaires'])) {
            $stmtDel = $pdo->prepare("DELETE FROM commissaires_comptes WHERE exercice = :exercice");
            $stmtDel->execute([':exercice' => $exercice]);
            
            $stmtIns = $pdo->prepare("INSERT INTO commissaires_comptes (exercice, nom_cabinet, date_nomination) VALUES (:exercice, :nom, :date_nomination)");
            foreach ($_POST['commissaires'] as $commissaire) {
                if (!empty($commissaire['nom'])) {
                    $stmtIns->execute([
                        ':exercice' => $exercice,
                        ':nom' => $commissaire['nom'],
                        ':date_nomination' => !empty($commissaire['date_nomination']) ? $commissaire['date_nomination'] : null
                    ]);
                }
            }
        }
        
        // Sauvegarder l'audit
        $stmtAudit = $pdo->prepare("
            INSERT INTO audits_externes (exercice, comptes_certifies, avis, date_audit, observations) 
            VALUES (:exercice, :certifies, :avis, :date_audit, :observations)
            ON DUPLICATE KEY UPDATE 
                comptes_certifies = VALUES(comptes_certifies),
                avis = VALUES(avis),
                date_audit = VALUES(date_audit),
                observations = VALUES(observations)
        ");
        
        $stmtAudit->execute([
            ':exercice' => $exercice,
            ':certifies' => $_POST['comptes_certifies'] ?? 'non',
            ':avis' => $_POST['avis'] ?? null,
            ':date_audit' => !empty($_POST['date_audit']) ? $_POST['date_audit'] : null,
            ':observations' => $_POST['observations'] ?? null
        ]);
        
        $message = "Donnees enregistrees avec succes !";
        $message_type = "success";
        
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES EXISTANTES
// ============================================================
$commissaires = [];
$audit = [
    'comptes_certifies' => 'non',
    'avis' => '',
    'date_audit' => '',
    'observations' => ''
];

try {
    $stmtCommissaires = $pdo->prepare("SELECT * FROM commissaires_comptes WHERE exercice = :exercice ORDER BY id");
    $stmtCommissaires->execute([':exercice' => $exercice]);
    $commissaires = $stmtCommissaires->fetchAll();
    
    $stmtAudit = $pdo->prepare("SELECT * FROM audits_externes WHERE exercice = :exercice");
    $stmtAudit->execute([':exercice' => $exercice]);
    $auditDb = $stmtAudit->fetch();
    if ($auditDb) {
        $audit = $auditDb;
    }
} catch (PDOException $e) { }

// Historique des audits
$historique = [];
try {
    $stmtHisto = $pdo->prepare("SELECT * FROM audits_externes ORDER BY exercice DESC LIMIT 10");
    $stmtHisto->execute();
    $historique = $stmtHisto->fetchAll();
} catch (PDOException $e) { }

$nb_commissaires = max(5, count($commissaires));

// Fonction pour le format de l'avis
function getAvisText($avis) {
    switch($avis) {
        case 'sans_reserve': return 'Sans reserve';
        case 'avec_reserve': return 'Avec reserves';
        case 'defavorable': return 'Avis defavorable';
        case 'impossible': return 'Impossibilite de certifier';
        default: return '-';
    }
}

function getAvisClass($avis) {
    switch($avis) {
        case 'sans_reserve': return 'status-success';
        case 'avec_reserve': return 'status-warning';
        case 'defavorable': return 'status-danger';
        case 'impossible': return 'status-danger';
        default: return 'status-neutral';
    }
}

// ============================================================
// EXPORT PDF
// ============================================================
if ($format === 'pdf') {
    if (ob_get_length()) ob_end_clean();
    
    class PDF_DIMF extends FPDF {
        function convert($str) {
            $str = str_replace(array('é','è','ê','ë','à','â','ä','î','ï','ô','ö','ù','û','ü','ç','É','È','Ê','Ë','À','Â','Ä','Î','Ï','Ô','Ö','Ù','Û','Ü','Ç'), 
                              array('e','e','e','e','a','a','a','i','i','o','o','u','u','u','c','E','E','E','E','A','A','A','I','I','O','O','U','U','U','C'), $str);
            return $str;
        }
        
        function Header() {
            $this->SetFillColor(156, 163, 175);
            $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(8, 3);
            $this->Cell(0, 4, $this->convert('Republique de Cote d\'Ivoire  •  Ministere de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
            $this->SetFont('Arial', 'B', 13);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 7, $this->convert('15 - SUIVI DES COMMISSARIATS AUX COMPTES'), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, $this->convert('Pour les SFD soumis a l\'obligation d\'un commissariat aux comptes'), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }
        
        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, $this->convert('Page ' . $this->PageNo() . '/{nb} - Genere le ' . date('d/m/Y H:i:s')), 0, 0, 'C');
        }
        
        function SectionTitle($label) {
            $this->SetFont('Arial', 'B', 10);
            $this->SetFillColor(0, 0, 0);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 8, $this->convert($label), 0, 1, 'L', true);
            $this->SetTextColor(0, 0, 0);
            $this->Ln(2);
        }
        
        function TableHeader($cols) {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(240, 240, 240);
            foreach ($cols as $col) {
                $this->Cell($col['w'], 7, $this->convert($col['label']), 1, 0, $col['align'] ?? 'L', true);
            }
            $this->Ln();
        }
        
        function TableRow($cols, $data, $style = '') {
            if ($style == 'total') {
                $this->SetFont('Arial', 'B', 8);
                $this->SetFillColor(240, 253, 244);
                $fill = true;
            } else {
                $this->SetFont('Arial', '', 8);
                $fill = false;
            }
            foreach ($cols as $i => $col) {
                $val = $data[$i] ?? '';
                $this->Cell($col['w'], 6, $this->convert($val), 1, 0, $col['align'] ?? 'L', $fill);
            }
            $this->Ln();
        }
    }
    
    $pdf = new PDF_DIMF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(15, 35, 15);
    $pdf->AddPage();
    
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, $pdf->convert('Periode : ' . $lib_periode), 0, 1, 'C');
    $pdf->Ln(5);
    
    // Commissaires aux comptes
    $pdf->SectionTitle('COMMISSAIRES AUX COMPTES');
    $pdf->SetFont('Arial', '', 9);
    foreach ($commissaires as $c) {
        $pdf->Cell(50, 7, $pdf->convert('Cabinet :'), 0, 0);
        $pdf->Cell(0, 7, $pdf->convert($c['nom_cabinet']), 0, 1);
        if ($c['date_nomination']) {
            $pdf->Cell(50, 7, $pdf->convert('Date nomination :'), 0, 0);
            $pdf->Cell(0, 7, date('d/m/Y', strtotime($c['date_nomination'])), 0, 1);
        }
        $pdf->Ln(2);
    }
    if (empty($commissaires)) {
        $pdf->Cell(0, 7, $pdf->convert('Aucun commissaire aux comptes enregistre pour cet exercice.'), 0, 1);
    }
    $pdf->Ln(5);
    
    // Certification des comptes
    $pdf->SectionTitle('CERTIFICATION DES COMPTES');
    $pdf->Cell(80, 7, $pdf->convert('Comptes certifies :'), 0, 0);
    $pdf->Cell(0, 7, $pdf->convert($audit['comptes_certifies'] == 'oui' ? 'Oui' : 'Non'), 0, 1);
    
    if ($audit['comptes_certifies'] == 'oui') {
        $pdf->Cell(80, 7, $pdf->convert('Avis :'), 0, 0);
        $pdf->Cell(0, 7, $pdf->convert(getAvisText($audit['avis'])), 0, 1);
    }
    
    if ($audit['date_audit']) {
        $pdf->Cell(80, 7, $pdf->convert('Date de l\'audit :'), 0, 0);
        $pdf->Cell(0, 7, date('d/m/Y', strtotime($audit['date_audit'])), 0, 1);
    }
    
    if (!empty($audit['observations'])) {
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 7, $pdf->convert('Observations :'), 0, 1);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, $pdf->convert($audit['observations']));
    }
    
    $pdf->Output('I', '15_COMMISSAIRES_COMPTES_' . $exercice . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>15 - Suivi des commissariats aux comptes</title>
    <!-- Bootstrap 5 CSS uniquement ajouté sans modifier le design -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Design original - INCHANGÉ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; padding: 24px; }
        .dashboard { max-width: 1400px; margin: 0 auto; }
        
        .page-header { background: linear-gradient(135deg, #3b82f6, #60a5fa); border-radius: 24px; padding: 20px 28px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 6px -2px rgba(0,0,0,0.05); }
        .header-left h1 { font-size: 1.6rem; font-weight: 600; color: white; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
        .subtitle { font-size: 0.8rem; color: #e0f2fe; line-height: 1.4; }
        .badge { display: inline-block; background: #2563eb; color: white; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 500; margin-top: 8px; }

        .form-control {
        display: block;
        width: 100%;
        padding: 0.375rem 0.75rem;
        font-size: 1rem;
        font-weight: 400;
        line-height: 1.5;
        color: #212529;
        background-color: #fff;
        background-clip: padding-box;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .btn-group { display: flex; gap: 12px; }
        .btn-excel, .btn-pdf { display: inline-flex; align-items: center; gap: 8px; padding: 8px 20px; border-radius: 40px; font-weight: 500; font-size: 0.85rem; border: none; cursor: pointer; transition: 0.2s; text-decoration: none; }
        .btn-excel { background: #10b981; color: white; }
        .btn-excel:hover { background: #059669; transform: translateY(-1px); }
        .btn-pdf { background: #ef4444; color: white; }
        .btn-pdf:hover { background: #dc2626; transform: translateY(-1px); }
        .btn-save { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 8px 24px; font-weight: 500; font-size: 0.85rem; cursor: pointer; transition: 0.2s; }
        .btn-save:hover { background: #2563eb; transform: translateY(-1px); }
        .btn-add { background: #1a3a5c; color: white; border: none; border-radius: 40px; padding: 8px 20px; font-weight: 500; font-size: 0.85rem; cursor: pointer; transition: 0.2s; margin: 0 20px 20px 20px; display: inline-block; }
        .btn-add:hover { background: #0d2137; }
        .btn-remove { background: #dc2626; color: white; border: none; border-radius: 12px; padding: 8px 12px; cursor: pointer; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 5px; }
        .btn-remove:hover { background: #b91c1c; }
        
        .card { background: white; border-radius: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 8px 16px -4px rgba(0,0,0,0.05); margin-bottom: 24px; overflow: hidden; }
        .card-header { display: flex; align-items: center; gap: 10px; padding: 16px 24px; background: #f8fafc; border-bottom: 1px solid #eef2f6; font-weight: 600; font-size: 1rem; color: #1e40af; }
        .card-header i { color: #3b82f6; }
        .card-body { padding: 20px 24px; }
        
        .filters-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 20px; }
        .filter-item { display: flex; flex-direction: column; gap: 6px; }
        .filter-item label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #4b5563; }
        .filter-item select { background: white; border: 1px solid #d1d5db; border-radius: 12px; padding: 8px 14px; font-size: 0.85rem; color: #111827; cursor: pointer; }
        .filter-item select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.2); }
        .btn-apply { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 8px 24px; font-weight: 500; font-size: 0.85rem; cursor: pointer; transition: 0.2s; }
        .btn-apply:hover { background: #2563eb; transform: translateY(-1px); }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #4b5563; font-size: 0.85rem; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 15px; border: 1px solid #d1d5db; border-radius: 12px; font-size: 0.9rem; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }
        .form-group textarea { resize: vertical; min-height: 100px; }
        
        .commissaire-row { display: flex; gap: 15px; margin-bottom: 15px; padding: 0 20px; align-items: center; flex-wrap: wrap; }
        .commissaire-row .nom-input { flex: 3; }
        .commissaire-row .date-input { flex: 2; }
        
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .alert { padding: 14px 20px; border-radius: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #ecfdf5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border-left: 4px solid #ef4444; }
        .alert-info { background: #eef2ff; color: #1e40af; border-left: 4px solid #3b82f6; }
        
        .status-success { background: #e8f5e9; color: #2e7d32; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .status-warning { background: #fff3e0; color: #ef6c00; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .status-danger { background: #ffebee; color: #c62828; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .status-neutral { background: #f5f5f5; color: #757575; padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 12px 16px; background: #f8fafc; font-weight: 600; color: #1e293b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            .commissaire-row { flex-direction: column; }
            .commissaire-row .nom-input, .commissaire-row .date-input { width: 100%; }
        }
        
        @media print {
            body { background: white; padding: 0; }
            .btn-group, .footer, .filters-row, .btn-save, .btn-add, .btn-remove, #filtersCard, .alert { display: none !important; }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-gavel"></i> 15 - Suivi des commissariats aux comptes</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">Pour les SFD soumis a l'obligation d'un commissariat aux comptes</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <form method="POST" action="" style="display:inline-block">
                <input type="hidden" name="exercice" value="<?= $exercice ?>">
                <input type="hidden" name="type_periode" value="<?= $type_periode ?>">
                <input type="hidden" name="mois" value="<?= $mois ?>">
                <input type="hidden" name="trimestre" value="<?= $trimestre ?>">
                <input type="hidden" name="semestre" value="<?= $semestre ?>">
                <input type="hidden" name="format" value="pdf">
                <button type="submit" class="btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
            </form>
        </div>
    </div>

    <!-- Filtres - avec formulaire POST -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
            <form method="POST" action="">
                <div class="filters-row">
                    <div class="filter-item">
                        <label>Annee</label>
                        <select name="exercice" id="exerciceSelect">
                            <?php for ($y = 2020; $y <= date('Y')+1; $y++): ?>
                                <option value="<?= $y ?>" <?= $y==$exercice?'selected':'' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label>Type de periode</label>
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
                            echo '<label>Mois</label><select name="mois">';
                            for ($m=1;$m<=12;$m++) { $s=($m==$mois)?'selected':''; echo "<option value='$m' $s>".str_pad($m,2,'0',STR_PAD_LEFT)." - ".date('F',mktime(0,0,0,$m,1))."</option>"; }
                            echo '</select>';
                        } elseif ($type_periode == 'trimestre') {
                            echo '<label>Trimestre</label><select name="trimestre">';
                            for ($t=1;$t<=4;$t++) { $s=($t==$trimestre)?'selected':''; echo "<option value='$t' $s>$t".($t==1?'er':'eme')." Trimestre</option>"; }
                            echo '</select>';
                        } elseif ($type_periode == 'semestre') {
                            echo '<label>Semestre</label><select name="semestre">';
                            for ($s=1;$s<=2;$s++) { $sel=($s==$semestre)?'selected':''; echo "<option value='$s' $sel>$s".($s==1?'er':'e')." semestre</option>"; }
                            echo '</select>';
                        } else {
                            echo '<label>Periode</label><input class="form-control" type="text" disabled value="Annee complete" style="background:#f3f4f6;cursor:default;">';
                        }
                        ?>
                    </div>
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
            </form>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Periode : <?= $lib_periode ?> (arrete au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </div>

    <?php if($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <i class="fas fa-<?= $message_type == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="info-box">
        <i class="fas fa-info-circle"></i>
        <div><strong>Information :</strong> Ce formulaire concerne uniquement les SFD soumis a l'obligation legale de designer un commissaire aux comptes. Les donnees saisies sont conservees d'un exercice a l'autre.</div>
    </div>

    <form method="post" action="">
        <input type="hidden" name="action" value="save">
        
        <!-- Commissaires aux comptes -->
        <div class="card">
            <div class="card-header"><i class="fas fa-user-check"></i> IDENTIFICATION DES COMMISSAIRES AUX COMPTES</div>
            <div class="card-body">
                <div id="commissaires-container">
                    <?php for($i = 0; $i < $nb_commissaires; $i++): ?>
                        <div class="commissaire-row" data-index="<?= $i ?>">
                            <div class="nom-input">
                                <input type="text" class="form-control" name="commissaires[<?= $i ?>][nom]" placeholder="Nom du cabinet ou du commissaire" value="<?= isset($commissaires[$i]) ? htmlspecialchars($commissaires[$i]['nom_cabinet']) : '' ?>">
                            </div>
                            <div class="date-input">
                                <input type="date" class="form-control" name="commissaires[<?= $i ?>][date_nomination]" value="<?= isset($commissaires[$i]) && $commissaires[$i]['date_nomination'] ? date('Y-m-d', strtotime($commissaires[$i]['date_nomination'])) : '' ?>">
                            </div>
                            <?php if($i >= 5): ?>
                                <button type="button" class="btn-remove" onclick="supprimerCommissaire(this)"><i class="fas fa-trash"></i> Supprimer</button>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="btn-add" onclick="ajouterCommissaire()"><i class="fas fa-plus"></i> Ajouter un commissaire</button>
            </div>
        </div>
        
        <!-- Certification des comptes -->
        <div class="card">
            <div class="card-header"><i class="fas fa-file-signature"></i> CERTIFICATION DES COMPTES</div>
            <div class="card-body">
                <div class="form-group">
                    <label>Les comptes ont-ils ete certifies ?</label>
                    <select name="comptes_certifies" id="comptes_certifies">
                        <option value="non" <?= $audit['comptes_certifies'] == 'non' ? 'selected' : '' ?>>Non</option>
                        <option value="oui" <?= $audit['comptes_certifies'] == 'oui' ? 'selected' : '' ?>>Oui</option>
                    </select>
                </div>
                
                <div class="form-group" id="avis-group" style="display: <?= $audit['comptes_certifies'] == 'oui' ? 'block' : 'none' ?>;">
                    <label>Avec ou sans reserves :</label>
                    <select name="avis">
                        <option value="" <?= empty($audit['avis']) ? 'selected' : '' ?>>-- Selectionnez --</option>
                        <option value="sans_reserve" <?= $audit['avis'] == 'sans_reserve' ? 'selected' : '' ?>>Sans reserve</option>
                        <option value="avec_reserve" <?= $audit['avis'] == 'avec_reserve' ? 'selected' : '' ?>>Avec reserves</option>
                        <option value="defavorable" <?= $audit['avis'] == 'defavorable' ? 'selected' : '' ?>>Avis defavorable</option>
                        <option value="impossible" <?= $audit['avis'] == 'impossible' ? 'selected' : '' ?>>Impossibilite de certifier</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Date de l'audit / de la certification :</label>
                    <input type="date" name="date_audit" value="<?= $audit['date_audit'] ? date('Y-m-d', strtotime($audit['date_audit'])) : '' ?>">
                </div>
                
                <div class="form-group" id="reserves-group" style="display: <?= $audit['avis'] == 'avec_reserve' ? 'block' : 'none' ?>;">
                    <label>Liste des principales reserves :</label>
                    <textarea name="observations" placeholder="Decrivez les principales reserves emises par le commissaire aux comptes..."><?= htmlspecialchars($audit['observations'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
        
        <div style="text-align: center; margin: 20px 0;">
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer les informations</button>
        </div>
    </form>

    <!-- Historique des audits -->
    <div class="card">
        <div class="card-header"><i class="fas fa-history"></i> HISTORIQUE DES AUDITS</div>
        <div class="card-body">
            <div class="table-wrapper">
                <?php if(empty($historique)): ?>
                    <div class="info-box">Aucun historique d'audit disponible.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr><th>Exercice</th><th>Comptes certifies</th><th>Avis</th><th>Date audit</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($historique as $h): ?>
                            <tr>
                                <td><?= $h['exercice'] ?></td>
                                <td><span class="<?= $h['comptes_certifies'] == 'oui' ? 'status-success' : 'status-warning' ?>"><?= $h['comptes_certifies'] == 'oui' ? 'Oui' : 'Non' ?></span></td>
                                <td><span class="<?= getAvisClass($h['avis']) ?>"><?= getAvisText($h['avis']) ?></span></td>
                                <td><?= $h['date_audit'] ? date('d/m/Y', strtotime($h['date_audit'])) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Obligations legales -->
    <div class="card">
        <div class="card-header"><i class="fas fa-balance-scale"></i> RAPPEL DES OBLIGATIONS LEGALES</div>
        <div class="card-body">
            <ul style="margin-left: 20px; line-height: 1.8;">
                <li><i class="fas fa-check-circle" style="color:#10b981;"></i> Les SFD sont soumis a l'obligation de designer un commissaire aux comptes conformement a la reglementation en vigueur.</li>
                <li><i class="fas fa-check-circle" style="color:#10b981;"></i> Le rapport du commissaire aux comptes doit etre transmis a la DSFD dans les 6 mois suivant la cloture de l'exercice.</li>
                <li><i class="fas fa-check-circle" style="color:#10b981;"></i> En cas de reserves, un plan d'actions correctives doit etre soumis a la DSFD.</li>
                <li><i class="fas fa-check-circle" style="color:#10b981;"></i> Les SFD qui ne seraient pas soumis a cette obligation peuvent laisser ce formulaire vide.</li>
            </ul>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base Mandigo<br>
        Periode : <?= $lib_periode ?>
    </div>
</div>

<script>
    let commissaireIndex = <?= $nb_commissaires ?>;
    
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const currentMois = <?= $mois ?>;
        const currentTrimestre = <?= $trimestre ?>;
        const currentSemestre = <?= json_encode($semestre) ?>;
        let html = '';
        
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois">';
            for (let m = 1; m <= 12; m++) {
                const s = (m === currentMois) ? 'selected' : '';
                const n = new Date(2000, m-1, 1).toLocaleString('fr', {month:'long'});
                html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre">';
            for (let t = 1; t <= 4; t++) {
                const s = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${s}>${t}${t === 1 ? 'er' : 'eme'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre">';
            for (let s = 1; s <= 2; s++) {
                const sel = (s === currentSemestre) ? 'selected' : '';
                html += `<option value="${s}" ${sel}>${s}${s === 1 ? 'er' : 'e'} semestre</option>`;
            }
            html += '</select>';
        } else {
            html = '<label>Periode</label><input class="form-control" type="text" disabled value="Annee complete" style="background:#f3f4f6;cursor:default;">';
        }
        container.innerHTML = html;
    }

    function appliquerFiltres() {
        const exercice = document.getElementById('exerciceSelect').value;
        const type = document.getElementById('typePeriodeSelect').value;
        let url = '15.php?exercice=' + exercice + '&type_periode=' + type;
        
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        
        window.location.href = url;
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        
        let data = [
            ['15 - SUIVI DES COMMISSARIATS AUX COMPTES'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['COMMISSAIRES AUX COMPTES', ''],
            ['Cabinet', 'Date nomination']
        ];
        
        <?php foreach($commissaires as $c): ?>
        data.push(['<?= addslashes($c['nom_cabinet']) ?>', '<?= $c['date_nomination'] ? date('d/m/Y', strtotime($c['date_nomination'])) : "" ?>']);
        <?php endforeach; ?>
        
        data.push([], ['CERTIFICATION DES COMPTES', '']);
        data.push(['Comptes certifies', '<?= $audit['comptes_certifies'] == 'oui' ? 'Oui' : 'Non' ?>']);
        if ($audit['comptes_certifies'] == 'oui') {
            data.push(['Avis', '<?= getAvisText($audit['avis']) ?>']);
        }
        data.push(['Date de l\'audit', '<?= $audit['date_audit'] ? date('d/m/Y', strtotime($audit['date_audit'])) : "" ?>']);
        if (!empty($audit['observations'])) {
            data.push(['Observations', '<?= addslashes($audit['observations']) ?>']);
        }
        
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), "COMMISSAIRES_COMPTES");
        XLSX.writeFile(wb, '15_COMMISSAIRES_COMPTES_<?= $exercice ?>.xlsx');
    }

    function ajouterCommissaire() {
        const container = document.getElementById('commissaires-container');
        const newRow = document.createElement('div');
        newRow.className = 'commissaire-row';
        newRow.setAttribute('data-index', commissaireIndex);
        newRow.innerHTML = `
            <div class="nom-input">
                <input type="text" class="form-control" name="commissaires[${commissaireIndex}][nom]" placeholder="Nom du cabinet ou du commissaire">
            </div>
            <div class="date-input">
                <input type="date" class="form-control" name="commissaires[${commissaireIndex}][date_nomination]">
            </div>
            <button type="button" class="btn-remove" onclick="supprimerCommissaire(this)"><i class="fas fa-trash"></i> Supprimer</button>
        `;
        container.appendChild(newRow);
        commissaireIndex++;
    }
    
    function supprimerCommissaire(button) {
        const row = button.closest('.commissaire-row');
        row.remove();
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        
        // Afficher/masquer les champs conditionnels
        const comptesCertifies = document.getElementById('comptes_certifies');
        const avisGroup = document.getElementById('avis-group');
        const reservesGroup = document.getElementById('reserves-group');
        const avisSelect = document.querySelector('select[name="avis"]');
        
        if (comptesCertifies) {
            comptesCertifies.addEventListener('change', function() {
                if (this.value === 'oui') {
                    avisGroup.style.display = 'block';
                } else {
                    avisGroup.style.display = 'none';
                    if (reservesGroup) reservesGroup.style.display = 'none';
                }
            });
        }
        
        if (avisSelect) {
            avisSelect.addEventListener('change', function() {
                if (this.value === 'avec_reserve') {
                    if (reservesGroup) reservesGroup.style.display = 'block';
                } else {
                    if (reservesGroup) reservesGroup.style.display = 'none';
                }
            });
        }
    });
</script>
</body>
</html>