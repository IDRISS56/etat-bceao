<?php
// 15-CommAuxComptes.php - Suivi des commissariats aux comptes
// Utilise la table existante z_bceao_annexes_rapport

session_start();

// Configuration BDD
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ============================================================
// VÉRIFICATION / ADAPTATION DE LA TABLE
// ============================================================

// ============================================================
// PARAMÈTRES
// ============================================================
$exercice     = isset($_POST['exercice']) ? (int)$_POST['exercice'] : (isset($_SESSION['comm_exercice']) ? $_SESSION['comm_exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode'] : (isset($_SESSION['comm_type_periode']) ? $_SESSION['comm_type_periode'] : 'annuel');
$mois         = isset($_POST['mois']) ? (int)$_POST['mois'] : (isset($_SESSION['comm_mois']) ? $_SESSION['comm_mois'] : 12);
$trimestre    = isset($_POST['trimestre']) ? (int)$_POST['trimestre'] : (isset($_SESSION['comm_trimestre']) ? $_SESSION['comm_trimestre'] : 4);
$semestre     = isset($_POST['semestre']) ? (int)$_POST['semestre'] : (isset($_SESSION['comm_semestre']) ? $_SESSION['comm_semestre'] : 2);
$format       = isset($_POST['format']) ? $_POST['format'] : 'html';

$_SESSION['comm_exercice'] = $exercice;
$_SESSION['comm_type_periode'] = $type_periode;
$_SESSION['comm_mois'] = $mois;
$_SESSION['comm_trimestre'] = $trimestre;
$_SESSION['comm_semestre'] = $semestre;

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
    default:          $mois = isset($_POST['mois']) ? (int)$_POST['mois'] : 12;
}
$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));

switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Annee ' . $exercice;
}

// ============================================================
// LECTURE DES DONNÉES
// ============================================================
$data = [
    'commissaire_designe' => 'non',
    'comptes_certifies' => 'non',
    'avis' => ''
];
$commissaires = [];
$reserves = [];

try {
    $stmt = $pdo->prepare("SELECT code_indicateur, valeur_text FROM z_bceao_annexes_rapport WHERE exercice = :exercice AND statut = 'actif'");
    $stmt->execute([':exercice' => $exercice]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (isset($rows['COMM_DESIGNE'])) $data['commissaire_designe'] = $rows['COMM_DESIGNE'];
    if (isset($rows['COMM_CERTIFIES'])) $data['comptes_certifies'] = $rows['COMM_CERTIFIES'];
    if (isset($rows['COMM_AVIS'])) $data['avis'] = $rows['COMM_AVIS'];

    foreach ($rows as $code => $val) {
        if (strpos($code, 'COMMISSAIRE_') === 0 && !empty($val)) $commissaires[] = $val;
        if (strpos($code, 'RESERVE_') === 0 && !empty($val)) $reserves[] = $val;
    }
} catch (PDOException $e) {}

while (count($commissaires) < 5) $commissaires[] = '';
while (count($reserves) < 5) $reserves[] = '';

// ============================================================
// SAUVEGARDE
// ============================================================
$message = '';
$message_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {
    try {
        $stmtDel = $pdo->prepare("DELETE FROM z_bceao_annexes_rapport WHERE exercice = :exercice AND (code_indicateur LIKE 'COMM_%' OR code_indicateur LIKE 'COMMISSAIRE_%' OR code_indicateur LIKE 'RESERVE_%')");
        $stmtDel->execute([':exercice' => $exercice]);

        $stmtIns = $pdo->prepare("INSERT INTO z_bceao_annexes_rapport (exercice, code_indicateur, valeur_text, statut) VALUES (:exercice, :code, :val, 'actif')");

        $commissaire_designe = isset($_POST['commissaire_designe']) ? $_POST['commissaire_designe'] : 'non';
        $comptes_certifies = isset($_POST['comptes_certifies']) ? $_POST['comptes_certifies'] : 'non';
        $avis = isset($_POST['avis']) ? $_POST['avis'] : null;

        $stmtIns->execute([':exercice' => $exercice, ':code' => 'COMM_DESIGNE', ':val' => $commissaire_designe]);
        $stmtIns->execute([':exercice' => $exercice, ':code' => 'COMM_CERTIFIES', ':val' => $comptes_certifies]);
        if (!empty($avis)) $stmtIns->execute([':exercice' => $exercice, ':code' => 'COMM_AVIS', ':val' => $avis]);

        $commissaires_post = isset($_POST['commissaires']) ? array_filter(array_map('trim', $_POST['commissaires']), 'strlen') : [];
        $i = 1;
        foreach ($commissaires_post as $nom) {
            $stmtIns->execute([':exercice' => $exercice, ':code' => 'COMMISSAIRE_' . $i, ':val' => $nom]);
            $i++;
        }

        $reserves_post = isset($_POST['reserves']) ? array_filter(array_map('trim', $_POST['reserves']), 'strlen') : [];
        $j = 1;
        foreach ($reserves_post as $res) {
            $stmtIns->execute([':exercice' => $exercice, ':code' => 'RESERVE_' . $j, ':val' => $res]);
            $j++;
        }

        $data['commissaire_designe'] = $commissaire_designe;
        $data['comptes_certifies'] = $comptes_certifies;
        $data['avis'] = $avis;
        $commissaires = $commissaires_post;
        $reserves = $reserves_post;
        while (count($commissaires) < 5) $commissaires[] = '';
        while (count($reserves) < 5) $reserves[] = '';

        $message = "Informations enregistrées avec succès !";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
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
            $this->Cell(0, 4, $this->convert('République de Côte d\'Ivoire  •  Ministère de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
            $this->SetFont('Arial', 'B', 13);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 7, $this->convert('15 - COMMISSARIAT AUX COMPTES'), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, $this->convert('Uniquement pour les SFD soumis à l\'obligation d\'un commissariat aux comptes'), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }
        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, $this->convert('Page ' . $this->PageNo() . '/{nb} - Généré le ' . date('d/m/Y H:i:s')), 0, 0, 'C');
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
            $this->SetFont('Arial', '', 8);
            $fill = false;
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
    $pdf->Cell(0, 6, $pdf->convert('Période : ' . $lib_periode), 0, 1, 'C');
    $pdf->Ln(5);

    // 1. Commissaire désigné
    $pdf->SectionTitle('COMMISSAIRE AUX COMPTES');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 7, $pdf->convert('Un commissaire aux comptes a-t-il été désigné pour certifier les états financiers de cet exercice ?'), 0, 1);
    $pdf->Cell(80, 7, $pdf->convert('Réponse :'), 0, 0);
    $pdf->Cell(0, 7, $pdf->convert($data['commissaire_designe'] == 'oui' ? 'Oui' : 'Non'), 0, 1);
    $pdf->Ln(3);

    // 2. Noms des commissaires
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 7, $pdf->convert('Nom des commissaires aux comptes ou des cabinets :'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $i = 1;
    $has_comm = false;
    foreach ($commissaires as $nom) {
        if (!empty($nom)) {
            $pdf->Cell(20, 6, $pdf->convert($i . '.'), 0, 0);
            $pdf->Cell(0, 6, $pdf->convert($nom), 0, 1);
            $i++;
            $has_comm = true;
        }
    }
    if (!$has_comm) {
        $pdf->Cell(0, 7, $pdf->convert('Aucun commissaire renseigné.'), 0, 1);
    }
    $pdf->Ln(5);

    // 3. Certification des comptes
    $pdf->SectionTitle('CERTIFICATION DES COMPTES');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 7, $pdf->convert('Les comptes ont-ils été certifiés ?'), 0, 1);
    $pdf->Cell(80, 7, $pdf->convert('Réponse :'), 0, 0);
    $pdf->Cell(0, 7, $pdf->convert($data['comptes_certifies'] == 'oui' ? 'Oui' : 'Non'), 0, 1);
    $pdf->Ln(3);

    // 4. Avec ou sans réserves
    $pdf->Cell(80, 7, $pdf->convert('Avec ou sans réserves :'), 0, 0);
    $avis_text = '';
    if ($data['comptes_certifies'] == 'oui' && !empty($data['avis'])) {
        switch ($data['avis']) {
            case 'sans_reserve': $avis_text = 'Sans réserve'; break;
            case 'avec_reserve': $avis_text = 'Avec réserves'; break;
            case 'defavorable': $avis_text = 'Avis défavorable'; break;
            case 'impossible': $avis_text = 'Impossibilité de certifier'; break;
            default: $avis_text = '-';
        }
    } else {
        $avis_text = 'Non certifié / Non applicable';
    }
    $pdf->Cell(0, 7, $pdf->convert($avis_text), 0, 1);
    $pdf->Ln(3);

    // 5. Liste des principales réserves
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 7, $pdf->convert('Liste des principales réserves :'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $j = 1;
    $has_res = false;
    foreach ($reserves as $reserve) {
        if (!empty($reserve)) {
            $pdf->Cell(20, 6, $pdf->convert($j . '.'), 0, 0);
            $pdf->MultiCell(0, 6, $pdf->convert($reserve));
            $j++;
            $has_res = true;
        }
    }
    if (!$has_res) {
        $pdf->Cell(0, 7, $pdf->convert('Aucune réserve renseignée.'), 0, 1);
    }

    $pdf->Output('I', '15_COMMISSARIAT_AUX_COMPTES_' . $exercice . '.pdf');
    exit;
}

// ============================================================
// AFFICHAGE WEB
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>15 - Commissariat aux comptes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Design original - INCHANGÉ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; padding: 24px; }
        .dashboard { max-width: 1200px; margin: 0 auto; }
        .page-header { background: linear-gradient(135deg, #3b82f6, #60a5fa); border-radius: 24px; padding: 20px 28px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 6px -2px rgba(0,0,0,0.05); }
        .header-left h1 { font-size: 1.6rem; font-weight: 600; color: white; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
        .subtitle { font-size: 0.8rem; color: #e0f2fe; line-height: 1.4; }
        .badge { display: inline-block; background: #2563eb; color: white; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 500; margin-top: 8px; }
        .btn-group { display: flex; gap: 12px; }
        .btn-excel, .btn-pdf { display: inline-flex; align-items: center; gap: 8px; padding: 8px 20px; border-radius: 40px; font-weight: 500; font-size: 0.85rem; border: none; cursor: pointer; transition: 0.2s; text-decoration: none; }
        .btn-excel { background: #10b981; color: white; }
        .btn-excel:hover { background: #059669; transform: translateY(-1px); }
        .btn-pdf { background: #ef4444; color: white; }
        .btn-pdf:hover { background: #dc2626; transform: translateY(-1px); }
        .btn-save { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 8px 24px; font-weight: 500; font-size: 0.85rem; cursor: pointer; transition: 0.2s; }
        .btn-save:hover { background: #2563eb; transform: translateY(-1px); }
        .btn-add { background: #1a3a5c; color: white; border: none; border-radius: 40px; padding: 6px 18px; font-weight: 500; font-size: 0.8rem; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; }
        .btn-add:hover { background: #0d2137; }
        .btn-remove { background: #dc2626; color: white; border: none; border-radius: 12px; padding: 4px 12px; cursor: pointer; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 5px; margin-left: 10px; }
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
        .form-group textarea { resize: vertical; min-height: 60px; }
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .alert { padding: 14px 20px; border-radius: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #ecfdf5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border-left: 4px solid #ef4444; }
        .dynamic-item { display: flex; gap: 15px; margin-bottom: 10px; align-items: center; flex-wrap: wrap; }
        .dynamic-item .num-label { width: 30px; font-weight: 600; color: #4b5563; }
        .dynamic-item input, .dynamic-item textarea { flex: 1; }
        .dynamic-item .btn-remove { margin-left: 10px; }
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            .dynamic-item { flex-direction: column; align-items: stretch; }
            .dynamic-item .num-label { width: auto; }
            .dynamic-item .btn-remove { margin-left: 0; }
        }
        @media print {
            body { background: white; padding: 0; }
            .btn-group, .footer, .filters-row, .btn-save, .btn-add, .btn-remove, #filtersCard, .alert { display: none !important; }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <!-- HEADER -->
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-gavel"></i> 15 - Commissariat aux comptes</h1>
            <div class="subtitle">République de Côte d'Ivoire / Ministère de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">Uniquement pour les SFD soumis à l'obligation d'un commissariat aux comptes</div>
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

    <!-- FILTRES -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
            <form method="POST" action="">
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
                        <label>Type de période</label>
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
                            echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;cursor:default;">';
                        }
                        ?>
                    </div>
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
            </form>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Période : <?= $lib_periode ?> (arrêtée au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
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
        <div><strong>Information :</strong> Ce formulaire concerne uniquement les SFD soumis à l'obligation légale de désigner un commissaire aux comptes. Vous pouvez ajouter autant de commissaires et de réserves que nécessaire.</div>
    </div>

    <!-- FORMULAIRE -->
    <form method="POST" action="">
        <input type="hidden" name="action" value="save">

        <!-- 1. Commissaire désigné -->
        <div class="card">
            <div class="card-header"><i class="fas fa-user-check"></i> Commissaire aux comptes</div>
            <div class="card-body">
                <div class="form-group">
                    <label>Un commissaire aux comptes a-t-il été désigné pour certifier les états financiers de cet exercice ?</label>
                    <select name="commissaire_designe">
                        <option value="non" <?= $data['commissaire_designe'] == 'non' ? 'selected' : '' ?>>Non</option>
                        <option value="oui" <?= $data['commissaire_designe'] == 'oui' ? 'selected' : '' ?>>Oui</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- 2. Noms des commissaires -->
        <div class="card">
            <div class="card-header"><i class="fas fa-user-tie"></i> Nom des commissaires aux comptes ou des cabinets</div>
            <div class="card-body">
                <div id="commissaires-container">
                    <?php foreach ($commissaires as $idx => $nom): ?>
                        <div class="dynamic-item" data-type="commissaire">
                            <span class="num-label"><?= $idx+1 ?>.</span>
                            <input type="text" class="form-control" name="commissaires[]" placeholder="Nom du cabinet ou du commissaire" value="<?= htmlspecialchars($nom) ?>">
                            <?php if ($idx >= 5): ?>
                                <button type="button" class="btn-remove" onclick="supprimerLigne(this)"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn-add" onclick="ajouterLigne('commissaires-container', 'commissaire')"><i class="fas fa-plus"></i> Ajouter un commissaire</button>
                <p style="font-size:0.8rem; color:#6b7280; margin-top:5px;"><i class="fas fa-info-circle"></i> Les 5 premières lignes sont affichées par défaut, vous pouvez en ajouter davantage.</p>
            </div>
        </div>

        <!-- 3. Certification des comptes -->
        <div class="card">
            <div class="card-header"><i class="fas fa-file-signature"></i> Certification des comptes</div>
            <div class="card-body">
                <div class="form-group">
                    <label>Les comptes ont-ils été certifiés ?</label>
                    <select name="comptes_certifies" id="comptes_certifies">
                        <option value="non" <?= $data['comptes_certifies'] == 'non' ? 'selected' : '' ?>>Non</option>
                        <option value="oui" <?= $data['comptes_certifies'] == 'oui' ? 'selected' : '' ?>>Oui</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Avec ou sans réserves :</label>
                    <select name="avis">
                        <option value="" <?= empty($data['avis']) ? 'selected' : '' ?>>-- Sélectionnez --</option>
                        <option value="sans_reserve" <?= $data['avis'] == 'sans_reserve' ? 'selected' : '' ?>>Sans réserve</option>
                        <option value="avec_reserve" <?= $data['avis'] == 'avec_reserve' ? 'selected' : '' ?>>Avec réserves</option>
                        <option value="defavorable" <?= $data['avis'] == 'defavorable' ? 'selected' : '' ?>>Avis défavorable</option>
                        <option value="impossible" <?= $data['avis'] == 'impossible' ? 'selected' : '' ?>>Impossibilité de certifier</option>
                    </select>
                    <small style="color:#6b7280;">(Si les comptes ne sont pas certifiés, cet avis n'est pas applicable)</small>
                </div>
            </div>
        </div>

        <!-- 4. Liste des principales réserves -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Liste des principales réserves</div>
            <div class="card-body">
                <div id="reserves-container">
                    <?php foreach ($reserves as $idx => $reserve): ?>
                        <div class="dynamic-item" data-type="reserve">
                            <span class="num-label"><?= $idx+1 ?>.</span>
                            <textarea class="form-control" name="reserves[]" rows="2" placeholder="Décrivez la réserve..."><?= htmlspecialchars($reserve) ?></textarea>
                            <?php if ($idx >= 5): ?>
                                <button type="button" class="btn-remove" onclick="supprimerLigne(this)"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn-add" onclick="ajouterLigne('reserves-container', 'reserve')"><i class="fas fa-plus"></i> Ajouter une réserve</button>
                <p style="font-size:0.8rem; color:#6b7280; margin-top:5px;"><i class="fas fa-info-circle"></i> Les 5 premières lignes sont affichées par défaut, vous pouvez en ajouter davantage.</p>
            </div>
        </div>

        <div style="text-align: center; margin: 20px 0;">
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer les informations</button>
        </div>
    </form>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base<br>
        Période : <?= $lib_periode ?>
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
            html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;cursor:default;">';
        }
        container.innerHTML = html;
    }

    function ajouterLigne(containerId, type) {
        const container = document.getElementById(containerId);
        const items = container.getElementsByClassName('dynamic-item');
        const num = items.length + 1;
        const div = document.createElement('div');
        div.className = 'dynamic-item';
        div.setAttribute('data-type', type);
        let html = `<span class="num-label">${num}.</span>`;
        if (type === 'commissaire') {
            html += `<input type="text" class="form-control" name="commissaires[]" placeholder="Nom du cabinet ou du commissaire">`;
        } else if (type === 'reserve') {
            html += `<textarea class="form-control" name="reserves[]" rows="2" placeholder="Décrivez la réserve..."></textarea>`;
        }
        html += `<button type="button" class="btn-remove" onclick="supprimerLigne(this)"><i class="fas fa-trash"></i></button>`;
        div.innerHTML = html;
        container.appendChild(div);
        renumberItems(containerId);
    }

    function supprimerLigne(btn) {
        const div = btn.closest('.dynamic-item');
        const container = div.parentElement;
        const type = div.getAttribute('data-type');
        const items = container.getElementsByClassName('dynamic-item');
        const index = Array.from(items).indexOf(div);
        if (index < 5) {
            const input = div.querySelector('input, textarea');
            if (input) input.value = '';
            return;
        }
        div.remove();
        renumberItems(container.id);
    }

    function renumberItems(containerId) {
        const container = document.getElementById(containerId);
        const items = container.getElementsByClassName('dynamic-item');
        for (let i = 0; i < items.length; i++) {
            const label = items[i].querySelector('.num-label');
            if (label) label.textContent = (i+1) + '.';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        let data = [
            ['15 - COMMISSARIAT AUX COMPTES'],
            ['Période : <?= addslashes($lib_periode) ?>'],
            [],
            ['Un commissaire aux comptes a-t-il été désigné ?', '<?= $data['commissaire_designe'] == 'oui' ? 'Oui' : 'Non' ?>'],
            [],
            ['Nom des commissaires aux comptes ou des cabinets :'],
        ];
        // Récupérer les commissaires du formulaire
        const commInputs = document.querySelectorAll('input[name="commissaires[]"]');
        let commValues = [];
        commInputs.forEach(inp => { if (inp.value.trim() !== '') commValues.push(inp.value.trim()); });
        if (commValues.length === 0) commValues = ['', '', '', '', ''];
        commValues.forEach((nom, i) => { data.push([(i+1)+'.', nom]); });

        data.push([]);
        data.push(['Les comptes ont-ils été certifiés ?', '<?= $data['comptes_certifies'] == 'oui' ? 'Oui' : 'Non' ?>']);
        let avis_text = '';
        if ('<?= $data['comptes_certifies'] ?>' == 'oui' && !empty('<?= $data['avis'] ?>')) {
            switch ('<?= $data['avis'] ?>') {
                case 'sans_reserve': avis_text = 'Sans réserve'; break;
                case 'avec_reserve': avis_text = 'Avec réserves'; break;
                case 'defavorable': avis_text = 'Avis défavorable'; break;
                case 'impossible': avis_text = 'Impossibilité de certifier'; break;
                default: avis_text = '-';
            }
        } else {
            avis_text = 'Non certifié / Non applicable';
        }
        data.push(['Avec ou sans réserves :', avis_text]);
        data.push([]);
        data.push(['Liste des principales réserves :']);
        const resInputs = document.querySelectorAll('textarea[name="reserves[]"]');
        let resValues = [];
        resInputs.forEach(inp => { if (inp.value.trim() !== '') resValues.push(inp.value.trim()); });
        if (resValues.length === 0) resValues = ['', '', '', '', ''];
        resValues.forEach((res, i) => { data.push([(i+1)+'.', res]); });

        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), "COMMISSARIAT");
        XLSX.writeFile(wb, '15_COMMISSARIAT_AUX_COMPTES_<?= $exercice ?>.xlsx');
    }
</script>
</body>
</html>