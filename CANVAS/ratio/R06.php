<?php
// R06.php - Limitation des opérations autres que les activités d'épargne et de crédit
// Norme BCEAO: 0% à 5% (0 - 0.05)

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ------------------------- CONNEXION BDD -------------------------
require_once('../../databases/database.php');

// ------------------------- PARAMÈTRES -------------------------
$exercice = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$type_periode = isset($_GET['type_periode']) ? $_GET['type_periode'] : 'annuel';
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : 12;
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4;
$semestre = isset($_GET['semestre']) ? (int)$_GET['semestre'] : 2;

switch ($type_periode) {
    case 'mensuel': break;
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre': $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel': $mois = 12; break;
    default: $mois = 12;
}
$date_fin_periode = date('Y-m-t', strtotime("$exercice-" . str_pad($mois, 2, '0', STR_PAD_LEFT) . "-01"));
$date_debut_exercice = "$exercice-01-01";
$date_fin_exercice = "$exercice-12-31";

// ------------------------- CALCUL DU DÉNOMINATEUR : RISQUES PORTÉS NETS (identique à R01) -------------------------
// On reprend les requêtes de R01 (version simplifiée mais fonctionnelle)
$comptesOrdDebiteurs = 0;
$autresDepots = 0;
$comptesPrets = 0;
$pretsSouffrance = 0;
$creditsCourtTerme = 0;
$comptesOrdMembres = 0;
$creditsMoyenTerme = 0;
$creditsLongTerme = 0;
$creditsSouffrance = 0;
$titresPlacement = 0;
$titresParticipation = 0;
$titresInvestissement = 0;
$engagementsSignature = 0;
$engagementsMembres = 0;
$engagementsGarantie = 0;
$autresEngagements = 0;
$depotsGarantieInstFin = 0;
$depotsGarantieMembres = 0;

// A12
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde),0) as total FROM comptes WHERE solde > 0 AND statut='actif'");
    $stmt->execute();
    $comptesOrdDebiteurs = (float)$stmt->fetch()['total'];
    $autresDepots = $comptesOrdDebiteurs;
} catch (PDOException $e) {}

// A3A
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve')
    ");
    $stmt->execute();
    $comptesPrets = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// A70
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut = 'impaye'
    ");
    $stmt->execute();
    $pretsSouffrance = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// B2D
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree <= 12
    ");
    $stmt->execute();
    $creditsCourtTerme = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

$comptesOrdMembres = $comptesOrdDebiteurs;

// B30
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree BETWEEN 13 AND 60
    ");
    $stmt->execute();
    $creditsMoyenTerme = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// B40
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree > 60
    ");
    $stmt->execute();
    $creditsLongTerme = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

$creditsSouffrance = $pretsSouffrance;

// C10
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit),0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '50%' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $titresPlacement = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// D1E
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit),0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '26%' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $titresParticipation = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// D1L
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit),0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '27%' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $titresInvestissement = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// Engagements par signature
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valeur_nette),0) as total FROM garanties WHERE statut='actif'");
    $stmt->execute();
    $engagementsSignature = (float)$stmt->fetch()['total'];
    $engagementsMembres = $engagementsSignature;
} catch (PDOException $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valeur_nette),0) as total FROM garanties WHERE code_type_garantie='04' AND statut='actif'");
    $stmt->execute();
    $engagementsGarantie = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

$autresEngagements = 0;

// Déductions (dépôts de garantie)
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(solde)),0) as total FROM comptes WHERE solde < 0 AND statut='actif'");
    $stmt->execute();
    $depotsGarantieInstFin = abs((float)$stmt->fetch()['total']);
    $depotsGarantieMembres = $depotsGarantieInstFin;
} catch (PDOException $e) {}

$totalA_brut = $comptesOrdDebiteurs + $autresDepots + $comptesPrets + $pretsSouffrance
             + $creditsCourtTerme + $comptesOrdMembres + $creditsMoyenTerme + $creditsLongTerme
             + $creditsSouffrance + $titresPlacement + $titresParticipation + $titresInvestissement
             + $engagementsSignature + $engagementsMembres + $engagementsGarantie + $autresEngagements;
$totalA_deductions = $depotsGarantieInstFin + $depotsGarantieMembres;
$risquesNets = $totalA_brut - $totalA_deductions;
if ($risquesNets <= 0) $risquesNets = 1; // Éviter division par zéro

// ------------------------- NUMÉRATEUR Z55 (activités hors épargne/crédit) -------------------------
// Saisie manuelle (par défaut 0)
$Z55 = isset($_GET['Z55']) ? (float)$_GET['Z55'] : 0;

// Optionnel : On peut aussi calculer automatiquement à partir de certaines tables
// Par exemple, immobilisations hors exploitation, titres de participation non financiers, etc.
// Mais par défaut on laisse la saisie manuelle comme dans le canevas.

// ------------------------- RATIO R06 -------------------------
$ratioR06 = $Z55 / $risquesNets;
$pourcentage = $ratioR06 * 100;
$normeMin = 0;
$normeMax = 0.05;
$conformite = ($ratioR06 >= $normeMin && $ratioR06 <= $normeMax) ? 'CONFORME' : 'NON_CONFORME';

// ------------------------- PRÉPARATION DES DONNÉES POUR TABLEAUX -------------------------
$lignesZ55 = [
    ['code'=>'Z55','lib'=>'Montant consacré par l\'institution aux opérations autres que les activités d\'épargne et de crédit','montant'=>$Z55],
];

$lignesRisques = [
    ['code'=>'A12','lib'=>'Comptes ordinaires débiteurs chez les institutions financières','montant'=>$comptesOrdDebiteurs],
    ['code'=>'A2A','lib'=>'Autres comptes de dépôts chez les institutions financières','montant'=>$autresDepots],
    ['code'=>'A3A','lib'=>'Comptes de prêts','montant'=>$comptesPrets],
    ['code'=>'A70','lib'=>'Prêts en souffrance','montant'=>$pretsSouffrance],
    ['code'=>'B2D','lib'=>'Crédits à court terme','montant'=>$creditsCourtTerme],
    ['code'=>'B2N','lib'=>'Comptes ordinaires débiteurs des membres','montant'=>$comptesOrdMembres],
    ['code'=>'B30','lib'=>'Crédits à moyen terme','montant'=>$creditsMoyenTerme],
    ['code'=>'B40','lib'=>'Crédits à long terme','montant'=>$creditsLongTerme],
    ['code'=>'B70','lib'=>'Crédits en souffrance','montant'=>$creditsSouffrance],
    ['code'=>'C10','lib'=>'Titres de placement','montant'=>$titresPlacement],
    ['code'=>'D1E','lib'=>'Titres de participation','montant'=>$titresParticipation],
    ['code'=>'D1L','lib'=>'Titres d\'investissement','montant'=>$titresInvestissement],
    ['code'=>'N1A','lib'=>'Engagements par signature (inst. financières)','montant'=>$engagementsSignature],
    ['code'=>'N1J','lib'=>'Engagements par signature (membres)','montant'=>$engagementsMembres],
    ['code'=>'N3A','lib'=>'Engagements de garantie sur titre à livrer','montant'=>$engagementsGarantie],
    ['code'=>'Q1A','lib'=>'Autres engagements donnés','montant'=>$autresEngagements],
];
$lignesDeductions = [
    ['code'=>'F2C','lib'=>'Dépôts de Garantie sur les prêts aux institutions financières','montant'=>$depotsGarantieInstFin],
    ['code'=>'G30','lib'=>'Dépôts de Garantie sur les crédits aux membres/clients','montant'=>$depotsGarantieMembres],
];

// ------------------------- EXPORT PDF AVEC PDF_DIMF -------------------------
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once('../../fpdf/fpdf.php');

    class PDF_DIMF extends FPDF {
        public $codeDimf  = 'R06';
        public $titreDimf = 'LIMITATION DES OPERATIONS AUTRES QUE LES ACTIVITES D EPARGNE ET DE CREDIT';
        public $nomSfd    = 'SFD';
        public $periode   = '';
        public $exercice  = '';

        static function u($str) {
            return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str);
        }

        function Header() {
            $this->SetFillColor(156, 163, 175);
            $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(8, 3);
            $this->Cell(0, 4, self::u('République de Côte d\'Ivoire  •  Ministère de l\'Economie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
            $this->SetFont('Arial', 'B', 13);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 7, self::u($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
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
            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(0, 0, 0);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 7, self::u('  ' . strtoupper($label)), 0, 1, 'L', true);
            $this->SetTextColor(0, 0, 0);
            $this->Ln(1);
        }

        function TableHeader($cols) {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(248, 250, 252);
            $this->SetTextColor(30, 41, 59);
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.2);
            foreach ($cols as $col) {
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 6, self::u($col['label']), 1, 0, $align, true);
            }
            $this->Ln();
        }

        function TableRow($cols, $data, $style = '') {
            switch ($style) {
                case 'subtotal':
                    $this->SetFillColor(248, 250, 252);
                    $this->SetFont('Arial', 'B', 8);
                    $fill = true; break;
                case 'total':
                    $this->SetFillColor(240, 253, 244);
                    $this->SetFont('Arial', 'B', 8.5);
                    $fill = true; break;
                default:
                    $this->SetFillColor(255, 255, 255);
                    $this->SetFont('Arial', '', 7.5);
                    $fill = false; break;
            }
            $this->SetTextColor(15, 23, 42);
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.1);
            foreach ($cols as $i => $col) {
                $val   = isset($data[$i]) ? $data[$i] : '';
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 5.5, self::u($val), 1, 0, $align, $fill);
            }
            $this->Ln();
        }

        static function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }

    $pdf = new PDF_DIMF();
    $pdf->AliasNbPages();
    $pdf->codeDimf  = 'R06';
    $pdf->titreDimf = 'LIMITATION DES OPERATIONS AUTRES QUE LES ACTIVITES D EPARGNE ET DE CREDIT';
    $pdf->nomSfd    = 'SFD';
    $pdf->periode   = ucfirst($type_periode);
    $pdf->exercice  = $exercice;
    $pdf->AddPage();

    $cols = [
        ['w' => 30, 'label' => 'Code', 'align' => 'L'],
        ['w' => 100, 'label' => 'Libellé', 'align' => 'L'],
        ['w' => 50, 'label' => 'Montant (FCFA)', 'align' => 'R']
    ];

    // Section A – Montant consacré aux activités hors épargne/crédit
    $pdf->SectionTitle("A - MONTANT CONSACRE PAR L'INSTITUTION AUX ACTIVITES AUTRES QUE L'EPARGNE ET LE CREDIT");
    $pdf->TableHeader($cols);
    foreach ($lignesZ55 as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['lib'], PDF_DIMF::montant($row['montant'])]);
    }
    $pdf->TableRow($cols, ['', 'TOTAL (A)', PDF_DIMF::montant($Z55)], 'total');

    $pdf->Ln(5);

    // Section B – Risques portés par l'institution (nets)
    $pdf->SectionTitle("B - RISQUES PORTES PAR L'INSTITUTION (MONTANTS NETS DES PROVISIONS ET DES DEPOTS DE GARANTIE)");
    $pdf->TableHeader($cols);
    foreach ($lignesRisques as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['lib'], PDF_DIMF::montant($row['montant'])]);
    }
    $pdf->TableRow($cols, ['', 'TOTAL BRUT', PDF_DIMF::montant($totalA_brut)], 'subtotal');
    foreach ($lignesDeductions as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['lib'], PDF_DIMF::montant($row['montant'])]);
    }
    $pdf->TableRow($cols, ['', 'TOTAL DÉDUCTIONS', PDF_DIMF::montant($totalA_deductions)], 'subtotal');
    $pdf->TableRow($cols, ['', 'TOTAL RISQUES NETS (B)', PDF_DIMF::montant($risquesNets)], 'total');

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, PDF_DIMF::u("RATIO R06 = A / B = " . number_format($pourcentage, 2) . "%"), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, PDF_DIMF::u("Norme BCEAO : 0% ≤ Ratio ≤ 5%\nConformité : " . $conformite));

    $pdf->Output('I', 'R06_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ------------------------- EXPORT EXCEL (HTML .xls) -------------------------
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="R06_' . $exercice . '_' . $type_periode . '.xls"');
    header('Cache-Control: max-age=0');
    echo '<html><head><meta charset="UTF-8"><style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2 { color: #1a3a5c; font-size: 16pt; }
        h3 { color: #1a3a5c; font-size: 14pt; margin-top: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; font-size: 10pt; }
        th, td { border: 1px solid #999; padding: 8px; vertical-align: top; }
        th { background: #f2f2f2; text-align: center; font-weight: bold; }
        .text-right { text-align: right; }
        .total-row { background: #e8f5e9; font-weight: bold; }
        .subtotal-row { background: #f0f7ff; font-weight: bold; }
    </style></head><body>';
    echo '<h2>R06 - LIMITATION DES OPERATIONS AUTRES QUE LES ACTIVITES D\'EPARGNE ET DE CREDIT</h2>';
    echo '<p><strong>Période :</strong> ' . $exercice . ' - ' . ucfirst($type_periode) . ' (arrêtée au ' . date('d/m/Y', strtotime($date_fin_periode)) . ')</p>';

    // Tableau A
    echo '<h3>A - MONTANT CONSACRE AUX ACTIVITES AUTRES QUE L\'EPARGNE ET LE CREDIT</h3>';
    echo '<tr>';
    echo '<tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>';
    foreach ($lignesZ55 as $r) {
        echo '<tr><td style="width:15%">' . $r['code'] . '</td><td style="width:70%">' . $r['lib'] . '</td><td class="text-right" style="width:15%">' . number_format($r['montant'], 0, ',', ' ') . '</td></tr>';
    }
    echo '<tr class="total-row"><td colspan="2">TOTAL (A)</td><td class="text-right">' . number_format($Z55, 0, ',', ' ') . '</td></tr>';
    echo '</table>';

    // Tableau B
    echo '<h3>B - RISQUES PORTES PAR L\'INSTITUTION (nets des provisions et dépôts de garantie)</h3>';
    echo '<table>';
    echo '<tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>';
    foreach ($lignesRisques as $r) {
        echo '<tr><td style="width:15%">' . $r['code'] . '</td><td style="width:70%">' . $r['lib'] . '</td><td class="text-right">' . number_format($r['montant'], 0, ',', ' ') . '</td></tr>';
    }
    echo '<tr class="subtotal-row"><td colspan="2">TOTAL BRUT</td><td class="text-right">' . number_format($totalA_brut, 0, ',', ' ') . '</td></tr>';
    foreach ($lignesDeductions as $d) {
        echo '<tr><td style="width:15%">' . $d['code'] . '</td><td style="width:70%">' . $d['lib'] . '</td><td class="text-right">' . number_format($d['montant'], 0, ',', ' ') . '</td></tr>';
    }
    echo '<tr class="subtotal-row"><td colspan="2">TOTAL DÉDUCTIONS</td><td class="text-right">' . number_format($totalA_deductions, 0, ',', ' ') . '</td></tr>';
    echo '<tr class="total-row"><td colspan="2">TOTAL RISQUES NETS (B)</td><td class="text-right">' . number_format($risquesNets, 0, ',', ' ') . '</td></tr>';
    echo '</table>';

    echo '<p><strong>RATIO R06 = A / B = ' . number_format($pourcentage, 2) . '%</strong></p>';
    echo '<p>Norme BCEAO : 0% à 5%<br>Conformité : ' . $conformite . '</p>';
    echo '</body></html>';
    exit;
}

// ------------------------- AFFICHAGE WEB (INTERFACE DIMF_2000) -------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>R06 - Limitation des opérations hors épargne/crédit (BCEAO)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Styles DIMF_2000 (identiques aux précédents) */
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',system-ui,sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; display:flex; align-items:center; gap:10px; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; display:inline-block; margin-top:8px; }
        .btn-group { display:flex; gap:12px; }
        .btn-excel, .btn-pdf { padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; }
        .btn-excel { background:#10b981; color:white; }
        .btn-pdf { background:#ef4444; color:white; }
        .card { background:white; border-radius:20px; padding:20px 24px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
        .card-header { display:flex; align-items:center; gap:10px; border-bottom:1px solid #eef2f6; padding-bottom:12px; margin-bottom:16px; font-weight:600; color:#1e40af; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select, .filter-item input { padding:8px 14px; border:1px solid #d1d5db; border-radius:12px; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .ratio-card { background:linear-gradient(145deg,#f8fafc,#fff); border-radius:20px; padding:24px; margin-bottom:24px; border:1px solid #e2e8f0; }
        .ratio-value { font-size:3rem; font-weight:800; }
        .ratio-value.conforme { color:#10b981; }
        .ratio-value.non-conforme { color:#ef4444; }
        .norme-box { background:#f1f5f9; border-radius:16px; padding:12px 20px; text-align:center; }
        .progress-bar { background:#e2e8f0; border-radius:50px; height:24px; overflow:hidden; margin-top:20px; }
        .progress-fill { background:linear-gradient(90deg,#3b82f6,#60a5fa); height:100%; border-radius:50px; text-align:center; color:white; font-size:0.75rem; line-height:24px; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px 16px; text-align:left; border-bottom:1px solid #f1f5f9; }
        th { background:#f8fafc; font-weight:600; }
        .text-right { text-align:right; }
        .total-row { background:#f0fdf4; font-weight:700; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .two-columns { display:flex; gap:24px; flex-wrap:wrap; }
        .two-columns .card { flex:1; min-width:320px; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .filters-row, #filtersCard { display:none; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-store"></i> R06 - OPÉRATIONS HORS ÉPARGNE/CRÉDIT</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">Norme BCEAO : 0% ≤ Ratio ≤ 5%</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="location.href='?<?=http_build_query(array_merge($_GET,['export'=>'excel']))?>'"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" onclick="location.href='?<?=http_build_query(array_merge($_GET,['export'=>'pdf']))?>'"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Filtres période + saisie manuelle de Z55 -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres de période et saisie Z55</div>
        <div class="filters-row">
            <div class="filter-item"><label>Année</label><select id="exerciceSelect"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
            <div class="filter-item"><label>Type de période</label><select id="typePeriodeSelect"><option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option><option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option><option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option><option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option></select></div>
            <div class="filter-item" id="dynamicSelectContainer"><?php if($type_periode=='mensuel'): ?><label>Mois</label><select id="moisSelect"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m==$mois?'selected':''?>><?=str_pad($m,2,'0',STR_PAD_LEFT)?> - <?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor; ?></select><?php elseif($type_periode=='trimestre'): ?><label>Trimestre</label><select id="trimestreSelect"><?php for($t=1;$t<=4;$t++): ?><option value="<?=$t?>" <?=$t==$trimestre?'selected':''?>><?=$t?><?=$t==1?'er':'ème'?> Trimestre</option><?php endfor; ?></select><?php elseif($type_periode=='semestre'): ?><label>Semestre</label><select id="semestreSelect"><?php for($s=1;$s<=2;$s++): ?><option value="<?=$s?>" <?=$s==$semestre?'selected':''?>><?=$s?><?=$s==1?'er':'e'?> semestre</option><?php endfor; ?></select><?php else: ?><label>Période</label><input type="text" disabled value="Année complète"><?php endif; ?></div>
            <button class="btn-apply" onclick="appliquerFiltres()">Appliquer</button>
        </div>
        <div style="margin-top:12px; padding:8px; background:#fefce8; border-radius:12px;">
            <form method="get" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
                <div class="filter-item"><label>Z55 - Activités hors épargne/crédit</label><input type="number" name="Z55" value="<?=$Z55?>" step="1000" style="width:200px;"></div>
                <input type="hidden" name="exercice" value="<?=$exercice?>">
                <input type="hidden" name="type_periode" value="<?=$type_periode?>">
                <?php if($type_periode=='mensuel') echo '<input type="hidden" name="mois" value="'.$mois.'">'; ?>
                <?php if($type_periode=='trimestre') echo '<input type="hidden" name="trimestre" value="'.$trimestre.'">'; ?>
                <?php if($type_periode=='semestre') echo '<input type="hidden" name="semestre" value="'.$semestre.'">'; ?>
                <button type="submit" class="btn-apply" style="background:#eab308;">Mettre à jour</button>
            </form>
            <div style="font-size:0.7rem; color:#6b7280;">Saisir le montant total des opérations autres que l’épargne et le crédit (ex: commerce, immobilier, etc.)</div>
        </div>
    </div>

    <!-- Carte ratio -->
    <div class="ratio-card">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:20px;">
            <div><div class="card-header" style="padding:0;">Ratio R06 – Opérations hors épargne/crédit</div><div class="ratio-value <?=$conformite=='CONFORME'?'conforme':'non-conforme'?>"><?=number_format($pourcentage,2)?>%</div><div>Z55 / Risques portés nets</div></div>
            <div class="norme-box"><div><strong>Norme BCEAO</strong></div><div style="font-size:1.5rem;">0% → 5%</div><div>Seuil maximal : 5%</div></div>
            <div><span class="badge" style="background:<?=$conformite=='CONFORME'?'#10b981':'#ef4444'?>;"><?=$conformite?></span></div>
        </div>
        <div class="progress-bar"><div class="progress-fill <?=$conformite!='CONFORME'?'non-conforme':''?>" style="width:<?=min($pourcentage,100)?>%;"><?=number_format($pourcentage,1)?>%</div></div>
        <div style="margin-top:16px;"><i class="fas fa-calculator"></i> R06 = <?=number_format($Z55,0,',',' ')?> / <?=number_format($risquesNets,0,',',' ')?> = <?=number_format($pourcentage,2)?>%</div>
    </div>

    <!-- Deux colonnes web -->
    <div class="two-columns">
        <div class="card"><div class="card-header"><i class="fas fa-chart-simple"></i> A – ACTIVITÉS HORS ÉPARGNE/CRÉDIT</div><div class="table-wrapper"><table><thead><tr><th>Code</th><th>Libellé</th><th class="text-right">Montant</th></tr></thead><tbody><?php foreach($lignesZ55 as $r): ?><tr><td><?=$r['code']?></td><td><?=$r['lib']?></td><td class="text-right"><?=number_format($r['montant'],0,',',' ')?></td></tr><?php endforeach; ?><tr class="total-row"><td colspan="2">TOTAL (A)</td><td class="text-right"><?=number_format($Z55,0,',',' ')?></td></tr></tbody></table></div></div>
        <div class="card"><div class="card-header"><i class="fas fa-exclamation-triangle"></i> B – RISQUES PORTÉS NETS</div><div class="table-wrapper"><table><thead><tr><th>Code</th><th>Libellé</th><th class="text-right">Montant</th></tr></thead><tbody><?php foreach($lignesRisques as $r): ?><tr><td><?=$r['code']?></td><td><?=$r['lib']?></td><td class="text-right"><?=number_format($r['montant'],0,',',' ')?></td></tr><?php endforeach; ?><tr class="total-row"><td colspan="2">TOTAL RISQUES NETS (B)</td><td class="text-right"><?=number_format($risquesNets,0,',',' ')?></td></tr></tbody></table></div></div>
    </div>

    <!-- Interprétation -->
    <div class="card"><div class="card-header">Interprétation</div><div class="info-box"><i class="fas fa-gavel"></i><div><?=($conformite=='CONFORME')?'✓ Conforme – Les activités hors épargne/crédit représentent '.number_format($pourcentage,2).'% des risques portés, ≤5%.':'⚠️ Non conforme – Ce taux dépasse 5%, l\'institution doit réduire ses activités non financières.'?></div></div></div>

    <div class="page-footer"><i class="fas fa-calendar-alt"></i> Généré le <?=date('d/m/Y à H:i:s')?> – Période <?=$exercice?> (<?=ucfirst($type_periode)?>) arrêtée au <?=date('d/m/Y',strtotime($date_fin_periode))?></div>
</div>
<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const currentMois = <?=$mois?>;
        const currentTrimestre = <?=$trimestre?>;
        const currentSemestre = <?=json_encode($semestre)?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select id="moisSelect">';
            for (let m = 1; m <= 12; m++) { html += `<option value="${m}" ${m===currentMois?'selected':''}>${String(m).padStart(2,'0')} - ${new Date(2000,m-1,1).toLocaleString('fr',{month:'long'})}</option>`; }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select id="trimestreSelect">';
            for (let t = 1; t <= 4; t++) { html += `<option value="${t}" ${t===currentTrimestre?'selected':''}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select id="semestreSelect">';
            for (let s = 1; s <= 2; s++) { html += `<option value="${s}" ${s===currentSemestre?'selected':''}>${s}${s===1?'er':'e'} semestre</option>`; }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" disabled value="Année complète">';
        }
        container.innerHTML = html;
    }
    function appliquerFiltres() {
        let url = 'R06.php?exercice=' + document.getElementById('exerciceSelect').value + '&type_periode=' + document.getElementById('typePeriodeSelect').value;
        let type = document.getElementById('typePeriodeSelect').value;
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        else if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        else if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        // Conserver Z55
        let z55 = document.querySelector('input[name="Z55"]') ? document.querySelector('input[name="Z55"]').value : <?=$Z55?>;
        if (z55 > 0) url += '&Z55=' + z55;
        window.location.href = url;
    }
    document.addEventListener('DOMContentLoaded', function() { updateDynamicSelect(); document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect); });
</script>
</body>
</html>