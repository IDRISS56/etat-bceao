<?php
// IDENTIFIANT.php - Informations générales du SFD
// Déclaration SICS-BCEAO

session_start();

// Configuration BDD
require_once('../databases/database.php');

// ============================================================
// PARAMÈTRES AVEC TYPES DE PÉRIODE
// ============================================================
$exercice     = isset($_GET['exercice'])     ? (int)$_GET['exercice']     : date('Y');
$type_periode = isset($_GET['type_periode']) ? $_GET['type_periode']      : 'mensuel';
$mois         = isset($_GET['mois'])         ? (int)$_GET['mois']         : 12;
$trimestre    = isset($_GET['trimestre'])    ? (int)$_GET['trimestre']    : 4;
$semestre     = isset($_GET['semestre'])     ? (int)$_GET['semestre']     : 2;
$format       = isset($_GET['format'])       ? $_GET['format']            : 'html';

// Calcul du mois en fonction du type de période
switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
    default:          $mois = isset($_GET['mois']) ? (int)$_GET['mois'] : 12;
}

$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$date_debut_exercice = $exercice . '-01-01';

// Libellé de la période pour l'affichage
switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Annee ' . $exercice;
}

// ============================================================
// RÉCUPÉRATION DES INFORMATIONS DU SFD
// ============================================================

// 1. Informations depuis la table societes
$nom_sfd = '';
$sigle_sfd = '';
$telephone_sfd = '';
$email_sfd = '';
$adresse_sfd = '';
$ville_sfd = '';
$pays_sfd = 'Côte d\'Ivoire';

try {
    $stmtSociete = $pdo->prepare("SELECT * FROM societes WHERE etat_societe = 'Actif' LIMIT 1");
    $stmtSociete->execute();
    $societe = $stmtSociete->fetch();
    if ($societe) {
        $nom_sfd = $societe['nom_societe'] ?? '';
        $sigle_sfd = $societe['sigle_societe'] ?? '';
        $telephone_sfd = $societe['telephone_societe'] ?? '';
        $email_sfd = $societe['email_societe'] ?? '';
        $adresse_sfd = $societe['adresse_societe'] ?? '';
        $ville_sfd = $societe['ville_societe'] ?? '';
        $pays_sfd = $societe['pays_societe'] ?? 'Côte d\'Ivoire';
    }
} catch (PDOException $e) { }

// 2. Numéro d'agrément
$numero_agrement = '';
try {
    $stmtAgrement = $pdo->prepare("SELECT code_agence_bceao as agrement FROM agences WHERE statut = 'active' AND code_agence_bceao IS NOT NULL LIMIT 1");
    $stmtAgrement->execute();
    $agrement = $stmtAgrement->fetch();
    if ($agrement) $numero_agrement = $agrement['agrement'];
} catch (PDOException $e) { }

// 3. Date de renseignement
$date_renseignement = date('d/m/Y');

// 4. Version
$version = '1';

// 5. Forme du SFD
$forme_sfd = '';
try {
    $stmtForme = $pdo->prepare("SELECT DISTINCT c.categorie as forme FROM clients c WHERE c.categorie IS NOT NULL LIMIT 1");
    $stmtForme->execute();
    $forme = $stmtForme->fetch();
    if ($forme) $forme_sfd = $forme['forme'];
} catch (PDOException $e) { }

// 6. Récupération des données de la déclaration existante
$declaration = [
    'nom_sfd' => $nom_sfd,
    'numero_agrement' => $numero_agrement,
    'annee' => $exercice,
    'trimestre' => $trimestre,
    'mois' => $mois,
    'type_periode' => $type_periode,
    'semestre' => $semestre,
    'version' => $version,
    'forme' => $forme_sfd,
    'date_renseignement' => $date_renseignement
];

// Sauvegarde des modifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {
    $declaration['nom_sfd'] = $_POST['nom_sfd'] ?? $nom_sfd;
    $declaration['numero_agrement'] = $_POST['numero_agrement'] ?? $numero_agrement;
    $declaration['annee'] = (int)$_POST['annee'] ?? $exercice;
    $declaration['trimestre'] = (int)$_POST['trimestre'] ?? $trimestre;
    $declaration['mois'] = (int)$_POST['mois'] ?? $mois;
    $declaration['type_periode'] = $_POST['type_periode'] ?? $type_periode;
    $declaration['semestre'] = (int)$_POST['semestre'] ?? $semestre;
    $declaration['version'] = $_POST['version'] ?? $version;
    $declaration['forme'] = $_POST['forme'] ?? $forme_sfd;
    $declaration['date_renseignement'] = $_POST['date_renseignement'] ?? $date_renseignement;
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS declaration_sfd (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exercice INT NOT NULL,
                trimestre INT NOT NULL,
                mois INT NOT NULL,
                type_periode VARCHAR(20),
                semestre INT,
                nom_sfd VARCHAR(200),
                numero_agrement VARCHAR(50),
                version VARCHAR(10),
                forme VARCHAR(100),
                date_renseignement DATE,
                date_mise_a_jour TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_exercice_trimestre (exercice, trimestre, type_periode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $stmtSave = $pdo->prepare("
            INSERT INTO declaration_sfd (exercice, trimestre, mois, type_periode, semestre, nom_sfd, numero_agrement, version, forme, date_renseignement)
            VALUES (:exercice, :trimestre, :mois, :type_periode, :semestre, :nom_sfd, :numero_agrement, :version, :forme, :date_renseignement)
            ON DUPLICATE KEY UPDATE
                mois = VALUES(mois),
                type_periode = VALUES(type_periode),
                semestre = VALUES(semestre),
                nom_sfd = VALUES(nom_sfd),
                numero_agrement = VALUES(numero_agrement),
                version = VALUES(version),
                forme = VALUES(forme),
                date_renseignement = VALUES(date_renseignement)
        ");
        
        $stmtSave->execute([
            ':exercice' => $declaration['annee'],
            ':trimestre' => $declaration['trimestre'],
            ':mois' => $declaration['mois'],
            ':type_periode' => $declaration['type_periode'],
            ':semestre' => $declaration['semestre'],
            ':nom_sfd' => $declaration['nom_sfd'],
            ':numero_agrement' => $declaration['numero_agrement'],
            ':version' => $declaration['version'],
            ':forme' => $declaration['forme'],
            ':date_renseignement' => date('Y-m-d', strtotime($declaration['date_renseignement']))
        ]);
        
        $message = "Informations enregistrees avec succes !";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
}

// Récupération de l'historique
$historique = [];
try {
    $stmtHisto = $pdo->prepare("SELECT * FROM declaration_sfd ORDER BY exercice DESC, trimestre DESC LIMIT 10");
    $stmtHisto->execute();
    $historique = $stmtHisto->fetchAll();
} catch (PDOException $e) { }

// Calcul de l'ID du SFD
$sfd_id = md5($nom_sfd . $numero_agrement);

// ============================================================
// CLASSE FPDF
// ============================================================
if ($format === 'pdf') {
    if (ob_get_length()) ob_end_clean();
    
    $fpdf_path = __DIR__ . '/fpdf/fpdf.php';
    $alt_fpdf_path = dirname(__DIR__) . '/fpdf/fpdf.php';
    
    if (file_exists($fpdf_path)) {
        require_once($fpdf_path);
    } elseif (file_exists($alt_fpdf_path)) {
        require_once($alt_fpdf_path);
    } else {
        die("Erreur: La bibliotheque FPDF n'est pas trouvee.");
    }
    
    class PDF_DIMF extends FPDF {
        public $nomSfd = '';
        public $exercice = '';
        public $periode = '';
        
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
            $this->Cell(0, 7, $this->convert('IDENTIFIANT - Informations generales du SFD'), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, $this->convert('SICS-BCEAO - Declaration annuelle'), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }
        
        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, $this->convert('Page ' . $this->PageNo() . '/{nb} - Genere le ' . date('d/m/Y H:i:s')), 0, 0, 'C');
        }
        
        function InfoRow($label, $value) {
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(0, 0, 0);
            $this->Cell(80, 8, $this->convert($label), 1, 0);
            $this->Cell(0, 8, $this->convert($value), 1, 1);
        }
        
        function SectionTitle($label) {
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(255, 255, 255);
            $this->SetFillColor(0, 0, 0);
            $this->Cell(0, 8, $this->convert($label), 0, 1, 'L', true);
            $this->Ln(2);
        }
    }
    
    $pdf = new PDF_DIMF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(15, 35, 15);
    $pdf->AddPage();
    
    // Identification du SFD
    $pdf->SectionTitle('IDENTIFICATION DU SFD');
    $pdf->Ln(3);
    $pdf->InfoRow('Identifiant du SFD :', $sfd_id);
    $pdf->InfoRow('Nom du SFD :', $nom_sfd);
    $pdf->InfoRow('Sigle :', $sigle_sfd);
    $pdf->InfoRow('Numero d\'agrement :', $numero_agrement);
    $pdf->InfoRow('Forme (Specifi cite) :', $forme_sfd);
    $pdf->InfoRow('Telephone :', $telephone_sfd);
    $pdf->InfoRow('Email :', $email_sfd);
    $pdf->InfoRow('Adresse :', $adresse_sfd);
    $pdf->InfoRow('Ville :', $ville_sfd);
    $pdf->InfoRow('Pays :', $pays_sfd);
    $pdf->Ln(5);
    
    // Periode de declaration
    $pdf->SectionTitle('PERIODE DE DECLARATION');
    $pdf->Ln(3);
    $pdf->InfoRow('Exercice :', $exercice);
    $pdf->InfoRow('Type de periode :', $type_periode);
    $pdf->InfoRow('Trimestre :', $trimestre . 'e trimestre');
    $pdf->InfoRow('Semestre :', $semestre . 'er semestre');
    $pdf->InfoRow('Mois :', $mois);
    $pdf->InfoRow('Date de renseignement :', $date_renseignement);
    $pdf->InfoRow('Version :', $version);
    
    $pdf->Output('I', 'IDENTIFIANT_' . $exercice . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IDENTIFIANT - Informations générales du SFD</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; padding: 24px; }
        .dashboard { max-width: 1400px; margin: 0 auto; }
        
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
        .form-group input, .form-group select { width: 100%; padding: 10px 15px; border: 1px solid #d1d5db; border-radius: 12px; font-size: 0.9rem; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }
        .form-group input[readonly] { background: #f3f4f6; cursor: default; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .alert { padding: 14px 20px; border-radius: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #ecfdf5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border-left: 4px solid #ef4444; }
        
        .legend { display: flex; flex-wrap: wrap; gap: 15px; padding: 15px 20px; background: #f8fafc; border-radius: 12px; margin-bottom: 20px; font-size: 0.75rem; }
        .legend-item { display: flex; align-items: center; gap: 8px; }
        .legend-color { width: 20px; height: 20px; border-radius: 4px; }
        
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eef2f6; }
        th { background: #f8fafc; font-weight: 600; color: #1e293b; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            .form-row { grid-template-columns: 1fr; }
        }
        
        @media print {
            body { background: white; padding: 0; }
            .btn-group, .footer, .filters-row, .btn-save, #filtersCard, .alert { display: none !important; }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-id-card"></i> IDENTIFIANT - Informations generales du SFD</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO - Declaration annuelle</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <?php
            $params = [
                'exercice' => $exercice,
                'type_periode' => $type_periode,
                'mois' => $mois,
                'trimestre' => $trimestre,
                'semestre' => $semestre,
                'format' => 'pdf'
            ];
            ?>
            <a class="btn-pdf" href="?<?= http_build_query($params) ?>" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
    </div>

    <!-- Filtres dynamiques -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
            <div class="filters-row">
                <div class="filter-item">
                    <label>Annee</label>
                    <select id="exerciceSelect">
                        <?php for ($y = 2020; $y <= date('Y')+1; $y++): ?>
                            <option value="<?= $y ?>" <?= $y==$exercice?'selected':'' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Type de periode</label>
                    <select id="typePeriodeSelect">
                        <option value="mensuel"   <?= $type_periode=='mensuel'  ?'selected':'' ?>>Mensuel</option>
                        <option value="trimestre" <?= $type_periode=='trimestre'?'selected':'' ?>>Trimestre</option>
                        <option value="semestre"  <?= $type_periode=='semestre' ?'selected':'' ?>>Semestre</option>
                        <option value="annuel"    <?= $type_periode=='annuel'   ?'selected':'' ?>>Annuel</option>
                    </select>
                </div>
                <div class="filter-item" id="dynamicSelectContainer">
                    <?php
                    if ($type_periode == 'mensuel') {
                        echo '<label>Mois</label><select id="moisSelect">';
                        for ($m=1;$m<=12;$m++) { $s=($m==$mois)?'selected':''; echo "<option value='$m' $s>".str_pad($m,2,'0',STR_PAD_LEFT)." - ".date('F',mktime(0,0,0,$m,1))."</option>"; }
                        echo '</select>';
                    } elseif ($type_periode == 'trimestre') {
                        echo '<label>Trimestre</label><select id="trimestreSelect">';
                        for ($t=1;$t<=4;$t++) { $s=($t==$trimestre)?'selected':''; echo "<option value='$t' $s>$t".($t==1?'er':'eme')." Trimestre</option>"; }
                        echo '</select>';
                    } elseif ($type_periode == 'semestre') {
                        echo '<label>Semestre</label><select id="semestreSelect">';
                        for ($s=1;$s<=2;$s++) { $sel=($s==$semestre)?'selected':''; echo "<option value='$s' $sel>$s".($s==1?'er':'e')." semestre</option>"; }
                        echo '</select>';
                    } else {
                        echo '<label>Periode</label><input type="text" disabled value="Annee complete" style="background:#f3f4f6;cursor:default;">';
                    }
                    ?>
                </div>
                <button class="btn-apply" onclick="appliquerFiltres()"><i class="fas fa-filter"></i> Appliquer</button>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Periode : <?= $lib_periode ?>
            </div>
        </div>
    </div>

    <?php if(isset($message)): ?>
        <div class="alert alert-<?= $message_type ?>">
            <i class="fas fa-<?= $message_type == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="legend">
        <div class="legend-item"><div class="legend-color" style="background: #1a3a5c;"></div><span>Information d'ordre general</span></div>
        <div class="legend-item"><div class="legend-color" style="background: #2e7d32;"></div><span>Donnee a recuperer directement dans les etats financiers</span></div>
        <div class="legend-item"><div class="legend-color" style="background: #ff9800;"></div><span>Donnee prenant en compte la valeur residuelle</span></div>
        <div class="legend-item"><div class="legend-color" style="background: #e0e0e0;"></div><span>Cellule contenant une valeur ou formule a ne pas modifier</span></div>
    </div>

    <form method="post" action="">
        <input type="hidden" name="action" value="save">
        
        <div class="card">
            <div class="card-header"><i class="fas fa-building"></i> IDENTIFICATION DU SFD</div>
            <div class="card-body">
                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    <div>Ce canevas permet de collecter les donnees non disponibles dans le canevas electronique SICS-BCEAO 29, a savoir les ratios prudentiels, indicateurs de performance, mouvements d'actifs, statistiques des points de services et conclusions du commissariat aux comptes.</div>
                </div>
                
                <div class="form-group">
                    <label>Date de renseignement</label>
                    <input type="date" name="date_renseignement" value="<?= date('Y-m-d', strtotime($declaration['date_renseignement'])) ?>">
                </div>
                
                <div class="form-group">
                    <label>Identifiant du SFD</label>
                    <input type="text" value="<?= htmlspecialchars($sfd_id) ?>" readonly style="background:#f3f4f6;">
                </div>
                
                <div class="form-group">
                    <label>Nom du SFD</label>
                    <input type="text" name="nom_sfd" value="<?= htmlspecialchars($declaration['nom_sfd']) ?>" placeholder="Nom complet du Systeme Financier Decentralise">
                </div>
                
                <div class="form-group">
                    <label>Numero d'agrement</label>
                    <input type="text" name="numero_agrement" value="<?= htmlspecialchars($declaration['numero_agrement']) ?>" placeholder="Ex: A11/10-24">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Annee d'exercice</label>
                        <select name="annee">
                            <?php for($y = 2020; $y <= date('Y')+1; $y++): ?>
                                <option value="<?= $y ?>" <?= $y == $declaration['annee'] ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Type de periode</label>
                        <select name="type_periode" id="formTypePeriode">
                            <option value="mensuel"   <?= $type_periode=='mensuel'  ?'selected':'' ?>>Mensuel</option>
                            <option value="trimestre" <?= $type_periode=='trimestre'?'selected':'' ?>>Trimestre</option>
                            <option value="semestre"  <?= $type_periode=='semestre' ?'selected':'' ?>>Semestre</option>
                            <option value="annuel"    <?= $type_periode=='annuel'   ?'selected':'' ?>>Annuel</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row" id="formDynamicContainer">
                    <?php
                    if ($type_periode == 'mensuel') {
                        echo '<div class="form-group"><label>Mois</label><select name="mois">';
                        for ($m=1;$m<=12;$m++) { $s=($m==$mois)?'selected':''; echo "<option value='$m' $s>".str_pad($m,2,'0',STR_PAD_LEFT)." - ".date('F',mktime(0,0,0,$m,1))."</option>"; }
                        echo '</select></div>';
                    } elseif ($type_periode == 'trimestre') {
                        echo '<div class="form-group"><label>Trimestre</label><select name="trimestre">';
                        for ($t=1;$t<=4;$t++) { $s=($t==$trimestre)?'selected':''; echo "<option value='$t' $s>$t".($t==1?'er':'eme')." Trimestre</option>"; }
                        echo '</select></div>';
                    } elseif ($type_periode == 'semestre') {
                        echo '<div class="form-group"><label>Semestre</label><select name="semestre">';
                        for ($s=1;$s<=2;$s++) { $sel=($s==$semestre)?'selected':''; echo "<option value='$s' $sel>$s".($s==1?'er':'e')." semestre</option>"; }
                        echo '</select></div>';
                    }
                    ?>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Version</label>
                        <input type="text" name="version" value="<?= htmlspecialchars($declaration['version']) ?>" readonly style="background:#f3f4f6;">
                    </div>
                    <div class="form-group">
                        <label>Forme (Specifi cite)</label>
                        <select name="forme">
                            <option value="Mutualiste (Faitiere)" <?= $declaration['forme'] == 'Mutualiste (Faitiere)' ? 'selected' : '' ?>>Mutualiste (Faitiere)</option>
                            <option value="Mutualiste (caisse affiliee)" <?= $declaration['forme'] == 'Mutualiste (caisse affiliee)' ? 'selected' : '' ?>>Mutualiste (caisse affiliee)</option>
                            <option value="Mutualiste (Caisse unitaire)" <?= $declaration['forme'] == 'Mutualiste (Caisse unitaire)' ? 'selected' : '' ?>>Mutualiste (Caisse unitaire)</option>
                            <option value="SA (Societe Anonyme)" <?= $declaration['forme'] == 'SA (Societe Anonyme)' ? 'selected' : '' ?>>SA (Societe Anonyme)</option>
                            <option value="SARL" <?= $declaration['forme'] == 'SARL' ? 'selected' : '' ?>>SARL</option>
                            <option value="Association" <?= $declaration['forme'] == 'Association' ? 'selected' : '' ?>>Association</option>
                            <option value="Cooperative" <?= $declaration['forme'] == 'Cooperative' ? 'selected' : '' ?>>Cooperative</option>
                            <option value="Autre" <?= $declaration['forme'] == 'Autre' ? 'selected' : '' ?>>Autre</option>
                        </select>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer les informations</button>
                </div>
            </div>
        </div>
    </form>

    <!-- Objectifs -->
    <div class="card">
        <div class="card-header"><i class="fas fa-bullseye"></i> OBJECTIFS DU CANEVAS</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-list-check"></i>
                <div>
                    <strong>Collecter les donnees non disponibles dans le canevas electronique SICS-BCEAO 29 :</strong>
                    <ul style="margin-left: 20px; margin-top: 8px;">
                        <li>✓ Donnees sur les ratios prudentiels</li>
                        <li>✓ Donnees sur les indicateurs de performance</li>
                        <li>✓ Donnees sur les mouvements d'actifs</li>
                        <li>✓ Statistiques des points de services</li>
                        <li>✓ Conclusions du commissariat aux comptes</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Historique -->
    <?php if(!empty($historique)): ?>
    <div class="card">
        <div class="card-header"><i class="fas fa-history"></i> HISTORIQUE DES DECLARATIONS</div>
        <div class="card-body">
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Exercice</th><th>Trimestre</th><th>Nom du SFD</th><th>N° agrement</th><th>Date mise a jour</th></tr></thead>
                    <tbody>
                        <?php foreach($historique as $h): ?>
                        <tr><td><?= $h['exercice'] ?></td><td><?= $h['trimestre'] ?>eme trimestre</td><td><?= htmlspecialchars($h['nom_sfd'] ?? '-') ?></td><td><?= htmlspecialchars($h['numero_agrement'] ?? '-') ?></td><td><?= date('d/m/Y H:i', strtotime($h['date_mise_a_jour'])) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Destinataires -->
    <div class="card">
        <div class="card-header"><i class="fas fa-envelope"></i> DESTINATAIRES</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-building"></i>
                <div>
                    <strong>SFD en activite en Cote d'Ivoire</strong><br>
                    Direction des Systemes Financiers Decentralises (DSFD)<br>
                    Direction Generale du Tresor et de la Comptabilite Publique (DGTCP)
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base Mandigo<br>
        Canevas annuel de donnees complementaires - Code: DRS X1 - Version 1
    </div>
</div>

<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const formContainer = document.getElementById('formDynamicContainer');
        const currentMois = <?= $mois ?>;
        const currentTrimestre = <?= $trimestre ?>;
        const currentSemestre = <?= json_encode($semestre) ?>;
        let html = '';
        let formHtml = '';
        
        if (type === 'mensuel') {
            html = '<label>Mois</label><select id="moisSelect">';
            formHtml = '<div class="form-group"><label>Mois</label><select name="mois">';
            for (let m = 1; m <= 12; m++) {
                const s = (m === currentMois) ? 'selected' : '';
                const n = new Date(2000, m-1, 1).toLocaleString('fr', {month:'long'});
                html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
                formHtml += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
            }
            html += '</select>';
            formHtml += '</select></div>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select id="trimestreSelect">';
            formHtml = '<div class="form-group"><label>Trimestre</label><select name="trimestre">';
            for (let t = 1; t <= 4; t++) {
                const s = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${s}>${t}${t === 1 ? 'er' : 'eme'} Trimestre</option>`;
                formHtml += `<option value="${t}" ${s}>${t}${t === 1 ? 'er' : 'eme'} Trimestre</option>`;
            }
            html += '</select>';
            formHtml += '</select></div>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select id="semestreSelect">';
            formHtml = '<div class="form-group"><label>Semestre</label><select name="semestre">';
            for (let s = 1; s <= 2; s++) {
                const sel = (s === currentSemestre) ? 'selected' : '';
                html += `<option value="${s}" ${sel}>${s}${s === 1 ? 'er' : 'e'} semestre</option>`;
                formHtml += `<option value="${s}" ${sel}>${s}${s === 1 ? 'er' : 'e'} semestre</option>`;
            }
            html += '</select>';
            formHtml += '</select></div>';
        } else {
            html = '<label>Periode</label><input type="text" disabled value="Annee complete" style="background:#f3f4f6;cursor:default;">';
            formHtml = '<input type="hidden" name="mois" value="12"><input type="hidden" name="trimestre" value="4"><input type="hidden" name="semestre" value="2">';
        }
        container.innerHTML = html;
        if (formContainer) formContainer.innerHTML = formHtml;
        
        // Synchroniser le select du formulaire avec le filtre
        const formTypePeriode = document.getElementById('formTypePeriode');
        if (formTypePeriode) formTypePeriode.value = type;
    }

    function appliquerFiltres() {
        const exercice = document.getElementById('exerciceSelect').value;
        const type = document.getElementById('typePeriodeSelect').value;
        let url = 'ind.php?exercice=' + exercice + '&type_periode=' + type;
        
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        
        window.location.href = url;
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        
        let data = [
            ['IDENTIFIANT - INFORMATIONS GENERALES DU SFD'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['INFORMATION', 'VALEUR'],
            ['Identifiant du SFD', '<?= addslashes($sfd_id) ?>'],
            ['Nom du SFD', '<?= addslashes($nom_sfd) ?>'],
            ['Sigle', '<?= addslashes($sigle_sfd) ?>'],
            ['Numero d\'agrement', '<?= addslashes($numero_agrement) ?>'],
            ['Forme (Specifi cite)', '<?= addslashes($forme_sfd) ?>'],
            ['Telephone', '<?= addslashes($telephone_sfd) ?>'],
            ['Email', '<?= addslashes($email_sfd) ?>'],
            ['Adresse', '<?= addslashes($adresse_sfd) ?>'],
            ['Ville', '<?= addslashes($ville_sfd) ?>'],
            ['Pays', '<?= addslashes($pays_sfd) ?>'],
            [],
            ['PERIODE DE DECLARATION', ''],
            ['Exercice', <?= $exercice ?>],
            ['Type de periode', '<?= $type_periode ?>'],
            ['Trimestre', <?= $trimestre ?>],
            ['Semestre', <?= $semestre ?>],
            ['Mois', <?= $mois ?>],
            ['Date de renseignement', '<?= $date_renseignement ?>'],
            ['Version', '<?= $version ?>']
        ];
        
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), "IDENTIFIANT_SFD");
        XLSX.writeFile(wb, 'IDENTIFIANT_SFD_<?= $exercice ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        
        const formTypePeriode = document.getElementById('formTypePeriode');
        if (formTypePeriode) {
            formTypePeriode.addEventListener('change', function() {
                const type = this.value;
                const formContainer = document.getElementById('formDynamicContainer');
                let html = '';
                if (type === 'mensuel') {
                    html = '<div class="form-group"><label>Mois</label><select name="mois">';
                    for (let m = 1; m <= 12; m++) {
                        const s = (m === <?= $mois ?>) ? 'selected' : '';
                        const n = new Date(2000, m-1, 1).toLocaleString('fr', {month:'long'});
                        html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
                    }
                    html += '</select></div>';
                } else if (type === 'trimestre') {
                    html = '<div class="form-group"><label>Trimestre</label><select name="trimestre">';
                    for (let t = 1; t <= 4; t++) {
                        const s = (t === <?= $trimestre ?>) ? 'selected' : '';
                        html += `<option value="${t}" ${s}>${t}${t === 1 ? 'er' : 'eme'} Trimestre</option>`;
                    }
                    html += '</select></div>';
                } else if (type === 'semestre') {
                    html = '<div class="form-group"><label>Semestre</label><select name="semestre">';
                    for (let s = 1; s <= 2; s++) {
                        const sel = (s === <?= $semestre ?>) ? 'selected' : '';
                        html += `<option value="${s}" ${sel}>${s}${s === 1 ? 'er' : 'e'} semestre</option>`;
                    }
                    html += '</select></div>';
                } else {
                    html = '<input type="hidden" name="mois" value="12"><input type="hidden" name="trimestre" value="4"><input type="hidden" name="semestre" value="2">';
                }
                if (formContainer) formContainer.innerHTML = html;
            });
        }
    });
</script>
</body>
</html>