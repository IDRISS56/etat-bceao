<?php
// IDENTIFIANT.php - Informations générales du SFD
// Version avec design identique à R01 (PDF inclus) et sans création de table

session_start();
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ============================================================
// PARAMÈTRES (POST ou défauts)
// ============================================================
$exercice     = isset($_POST['exercice'])     ? (int)$_POST['exercice']     : date('Y');
$trimestre    = isset($_POST['trimestre'])    ? (int)$_POST['trimestre']    : 4;
$mois         = isset($_POST['mois'])         ? (int)$_POST['mois']         : 12;
$version      = isset($_POST['version'])      ? trim($_POST['version'])     : '1';
$format       = isset($_POST['format'])       ? $_POST['format']            : 'html';

// ============================================================
// RÉCUPÉRATION DES DONNÉES DEPUIS LES TABLES EXISTANTES
// ============================================================
$nom_sfd = '';
$sigle_sfd = '';
$numero_agrement = '';

try {
    // Nom du SFD depuis la table societes
    $stmt = $pdo->prepare("SELECT nom_societe, sigle_societe FROM societes WHERE etat_societe = 'Actif' LIMIT 1");
    $stmt->execute();
    $societe = $stmt->fetch();
    if ($societe) {
        $nom_sfd = $societe['nom_societe'] ?? '';
        $sigle_sfd = $societe['sigle_societe'] ?? '';
    }
    
    // Numéro d'agrément depuis la table agences (code_agence_bceao)
    $stmt = $pdo->prepare("SELECT code_agence_bceao FROM agences WHERE statut = 'active' AND code_agence_bceao IS NOT NULL LIMIT 1");
    $stmt->execute();
    $agrement = $stmt->fetch();
    if ($agrement) $numero_agrement = $agrement['code_agence_bceao'];
} catch (PDOException $e) {
    // Ignorer les erreurs silencieusement
}

// Si des valeurs ont été soumises en POST (pour l'aperçu)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['format'])) {
    $nom_sfd = trim($_POST['nom_sfd'] ?? $nom_sfd);
    $numero_agrement = trim($_POST['numero_agrement'] ?? $numero_agrement);
    $exercice = (int)($_POST['exercice'] ?? $exercice);
    $trimestre = (int)($_POST['trimestre'] ?? $trimestre);
    $mois = (int)($_POST['mois'] ?? $mois);
    $version = trim($_POST['version'] ?? $version);
}

// Date de fin de période pour l'affichage (dernier jour du mois)
$date_fin_periode = date('Y-m-t', strtotime("$exercice-" . str_pad($mois, 2, '0', STR_PAD_LEFT) . "-01"));

// ============================================================
// EXPORT PDF AVEC DESIGN IDENTIQUE À R01
// ============================================================
if ($format === 'pdf') {
    if (ob_get_length()) ob_end_clean();
    
    class PDF_IDENTIF extends FPDF {
        public $nomSfd    = '';
        public $exercice  = '';
        public $periode   = '';

        // Fonction de conversion des caractères accentués
        static function u($str) {
            return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str);
        }

        function Header() {
            // Bandeau gris en haut (identique à R01)
            $this->SetFillColor(156, 163, 175);
            $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
            
            // Ligne 1 : République...
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(8, 3);
            $this->Cell(0, 4, self::u('République de Côte d\'Ivoire  •  Ministère de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
            
            // Ligne 2 : Titre principal
            $this->SetFont('Arial', 'B', 13);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 7, self::u('IDENTIFIANT - INFORMATIONS GÉNÉRALES DU SFD'), 0, 1, 'L');
            
            // Ligne 3 : Informations complémentaires (SFD, période, etc.)
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, self::u(
                'SFD : ' . $this->nomSfd .
                '   |   Période : ' . $this->periode .
                '   |   Exercice : ' . $this->exercice .
                '   |   Arrêté au : ' . date('d/m/Y', strtotime($GLOBALS['date_fin_periode']))
            ), 0, 1, 'L');
            
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }

        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, self::u(
                'SICS-BCEAO  •  Généré le ' . date('d/m/Y H:i:s') .
                '  •  Page ' . $this->PageNo() . '/{nb}'),
                0, 0, 'C');
        }

        function SectionTitle($label) {
            $this->SetFont('Arial', 'B', 10);
            $this->SetFillColor(0, 0, 0);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 7, self::u('  ' . strtoupper($label)), 0, 1, 'L', true);
            $this->SetTextColor(0, 0, 0);
            $this->Ln(2);
        }

        function IdentifTable($data) {
            $this->SetFont('Arial', '', 9);
            $w = array(60, 80);
            $this->SetFillColor(240, 240, 240);
            $this->SetDrawColor(0, 0, 0);
            $this->SetLineWidth(0.2);
            foreach ($data as $row) {
                $this->Cell($w[0], 8, self::u($row[0]), 1, 0, 'L', true);
                $this->Cell($w[1], 8, self::u($row[1]), 1, 1, 'L');
            }
        }
    }

    $pdf = new PDF_IDENTIF();
    $pdf->AliasNbPages();
    $pdf->nomSfd   = $nom_sfd;
    $pdf->exercice = $exercice;
    $pdf->periode  = 'Mois ' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '/' . $exercice; // Simplifié
    $pdf->AddPage();

    // Titre de section
    $pdf->SectionTitle('IDENTIFICATION DU SFD');
    $pdf->Ln(2);

    // Données du tableau
    $data = [
        ['LIBELLE', 'DONNÉES À REMPLIR'],
        ['NOM SFD', $nom_sfd],
        ['NUMÉRO D\'AGRÉMENT', $numero_agrement],
        ['ANNÉE', $exercice],
        ['TRIMESTRE', $trimestre],
        ['MOIS', $mois],
        ['VERSION', $version]
    ];
    $pdf->IdentifTable($data);

    $pdf->Output('I', 'IDENTIFIANT_SFD.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL (JavaScript via XLSX)
// ============================================================
// Géré côté client

// ============================================================
// AFFICHAGE WEB (design identique à R01)
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>IDENTIFIANT - SFD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        /* Styles copiés de R01 */
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',system-ui,sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; display:flex; align-items:center; gap:10px; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; display:inline-block; margin-top:8px; }
        .btn-group { display:flex; gap:12px; }
        .btn-excel, .btn-pdf, .btn-save { padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; transition:0.2s; }
        .btn-excel { background:#10b981; color:white; }
        .btn-excel:hover { background:#059669; }
        .btn-pdf { background:#ef4444; color:white; }
        .btn-pdf:hover { background:#dc2626; }
        .btn-save { background:#3b82f6; color:white; }
        .btn-save:hover { background:#2563eb; }
        .card { background:white; border-radius:20px; padding:20px 24px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
        .card-header { display:flex; align-items:center; gap:10px; border-bottom:1px solid #eef2f6; padding-bottom:12px; margin-bottom:16px; font-weight:600; color:#1e40af; }
        .table-wrapper { overflow-x:auto; }
        .table-identif { width:100%; border-collapse:collapse; }
        .table-identif td, .table-identif th { border:1px solid #d1d5db; padding:10px 15px; vertical-align:middle; }
        .table-identif th { background:#f1f5f9; font-weight:600; color:#1e293b; text-align:left; width:40%; }
        .table-identif td { width:60%; }
        .table-identif input, .table-identif select { width:100%; padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:0.9rem; background:white; }
        .table-identif input:focus, .table-identif select:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,0.1); }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .card { display:none; } }
        @media (max-width: 768px) { body { padding:12px; } }
    </style>
</head>
<body>
<div class="dashboard">
    <!-- HEADER identique à R01 -->
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-id-card"></i> IDENTIFIANT - INFORMATIONS GÉNÉRALES DU SFD</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO - Déclaration annuelle</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button type="submit" form="identifForm" class="btn-pdf" name="format" value="pdf"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- CARD principal avec le formulaire -->
    <div class="card">
        <div class="card-header"><i class="fas fa-building"></i> IDENTIFICATION DU SFD</div>
        <div class="card-body">
            <form method="post" id="identifForm">
                <div class="table-wrapper">
                    <table class="table-identif">
                        <tr>
                            <th>LIBELLE</th>
                            <th>DONNÉES À REMPLIR</th>
                        </tr>
                        <tr>
                            <td>NOM SFD</td>
                            <td><input type="text" name="nom_sfd" value="<?= htmlspecialchars($nom_sfd) ?>"></td>
                        </tr>
                        <tr>
                            <td>NUMÉRO D'AGRÉMENT</td>
                            <td><input type="text" name="numero_agrement" value="<?= htmlspecialchars($numero_agrement) ?>"></td>
                        </tr>
                        <tr>
                            <td>ANNÉE</td>
                            <td>
                                <select name="exercice">
                                    <?php for ($y = 2020; $y <= date('Y')+1; $y++): ?>
                                        <option value="<?= $y ?>" <?= $y == $exercice ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td>TRIMESTRE</td>
                            <td>
                                <select name="trimestre">
                                    <?php for ($t=1; $t<=4; $t++): ?>
                                        <option value="<?= $t ?>" <?= $t == $trimestre ? 'selected' : '' ?>><?= $t ?></option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td>MOIS</td>
                            <td>
                                <select name="mois">
                                    <?php for ($m=1; $m<=12; $m++): ?>
                                        <option value="<?= $m ?>" <?= $m == $mois ? 'selected' : '' ?>><?= str_pad($m,2,'0',STR_PAD_LEFT) ?></option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td>VERSION</td>
                            <td><input type="text" name="version" value="<?= htmlspecialchars($version) ?>"></td>
                        </tr>
                    </table>
                </div>
                <div style="text-align:center; margin-top:20px;">
                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Mettre à jour l'affichage</button>
                    <span style="font-size:0.75rem; color:#6b7280; margin-left:15px;">(Les données ne sont pas enregistrées en base)</span>
                </div>
            </form>
        </div>
    </div>

    <!-- Note d'information -->
    <div class="card">
        <div class="card-header"><i class="fas fa-info-circle"></i> Objectif</div>
        <div class="info-box">
            <i class="fas fa-bullseye"></i>
            <div>Ce formulaire permet de renseigner les informations d'identification du SFD (nom, numéro d'agrément, période de déclaration) sans les stocker en base. Les champs sont pré‑remplis automatiquement depuis les tables <strong>societes</strong> et <strong>agences</strong>.</div>
        </div>
    </div>

    <div class="page-footer">
        <i class="fas fa-calendar-alt"></i> Généré le <?= date('d/m/Y à H:i:s') ?> – Données extraites de la base
    </div>
</div>

<script>
    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        const data = [
            ['IDENTIFIANT'],
            ['SICS-BCEAO'],
            [],
            ['LIBELLE', 'DONNÉES À REMPLIR'],
            ['NOM SFD', document.querySelector('input[name="nom_sfd"]').value],
            ['NUMÉRO D\'AGRÉMENT', document.querySelector('input[name="numero_agrement"]').value],
            ['ANNÉE', document.querySelector('select[name="exercice"]').value],
            ['TRIMESTRE', document.querySelector('select[name="trimestre"]').value],
            ['MOIS', document.querySelector('select[name="mois"]').value],
            ['VERSION', document.querySelector('input[name="version"]').value]
        ];
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), "IDENTIFIANT");
        XLSX.writeFile(wb, 'IDENTIFIANT_SFD.xlsx');
    }
</script>
</body>
</html>