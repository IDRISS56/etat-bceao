<?php
// R01.php - Limitation des risques auxquels est exposée une institution (BCEAO)
// Export PDF avec PDF_DIMF, export Excel (HTML .xls) bien structuré avec cellules fusionnées
// Version avec POST et Bootstrap 5 (design préservé)

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ------------------------- CONNEXION BDD -------------------------
require_once('../databases/database.php');
require_once('../fpdf/fpdf.php');

// ------------------------- LECTURE DES PARAMÈTRES EN POST AVEC DÉFAUTS -------------------------
$exercice = isset($_POST['exercice']) ? (int)$_POST['exercice'] : date('Y');
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode'] : 'annuel';
$mois = isset($_POST['mois']) ? (int)$_POST['mois'] : 12;
$trimestre = isset($_POST['trimestre']) ? (int)$_trimestre = $_POST['trimestre'] : 4;
$semestre = isset($_POST['semestre']) ? (int)$_POST['semestre'] : 2;

switch ($type_periode) {
    case 'mensuel': break;
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre': $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel': $mois = 12; break;
    default: $mois = 12;
}
$date_fin_periode = date('Y-m-t', strtotime("$exercice-" . str_pad($mois, 2, '0', STR_PAD_LEFT) . "-01"));

// ------------------------- INITIALISATION VARIABLES -------------------------
$comptesOrdDebiteurs = $autresDepots = $comptesPrets = $pretsSouffrance = $creditsCourtTerme = 0;
$comptesOrdMembres = $creditsMoyenTerme = $creditsLongTerme = $creditsSouffrance = 0;
$titresPlacement = $titresParticipation = $titresInvestissement = 0;
$engagementsSignature = $engagementsMembres = $engagementsGarantie = $autresEngagements = 0;
$depotsGarantieInstFin = $depotsGarantieMembres = 0;
$comptesCrediteurs = $autresDepotsCrediteurs = $comptesEmprunts = $autresSommesDues = 0;
$epargneSpeciale = $comptesCrediteursMembres = $depotsTerme = $autresDepotsClients = $empruntsClients = $autresSommesMembres = 0;
$fondsPropres = 0;

// ------------------------- CALCULS (requêtes identiques) -------------------------
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde),0) as total FROM comptes WHERE solde > 0 AND statut='actif'");
    $stmt->execute();
    $comptesOrdDebiteurs = (float)$stmt->fetch()['total'];
    $autresDepots = $comptesOrdDebiteurs;
} catch (PDOException $e) {}

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
$montantA = $totalA_brut - $totalA_deductions;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(solde)),0) as total FROM comptes WHERE solde < 0 AND statut='actif'");
    $stmt->execute();
    $comptesCrediteurs = (float)$stmt->fetch()['total'];
    $autresDepotsCrediteurs = $comptesCrediteurs;
    $comptesCrediteursMembres = $comptesCrediteurs;
} catch (PDOException $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant),0) as total FROM capital WHERE statut='valide' AND mode_paiement='BANQUE'");
    $stmt->execute();
    $comptesEmprunts = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

$autresSommesDues = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde),0) as total
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' AND c.statut='actif' AND c.solde>0
    ");
    $stmt->execute();
    $epargneSpeciale = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital_initial),0) as total FROM comptes_dat WHERE statut='en cours'");
    $stmt->execute();
    $depotsTerme = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

$autresDepotsClients = 0;
$empruntsClients = 0;
$autresSommesMembres = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_credit - montant_debit),0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $fondsPropres = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

if ($fondsPropres == 0) {
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant),0) as total FROM capital WHERE statut='valide' AND date_creation <= :date_fin");
        $stmt->execute([':date_fin' => $date_fin_periode]);
        $fondsPropres = (float)$stmt->fetch()['total'];
    } catch (PDOException $e) {}
}

$montantB = $comptesCrediteurs + $autresDepotsCrediteurs + $comptesEmprunts + $autresSommesDues
          + $epargneSpeciale + $comptesCrediteursMembres + $depotsTerme + $autresDepotsClients
          + $empruntsClients + $autresSommesMembres + $fondsPropres;
if ($montantB <= 0) $montantB = 1;
$ratioR01 = $montantA / $montantB;
$pourcentageRisques = $ratioR01 * 100;
$conformite = ($ratioR01 >= 0 && $ratioR01 <= 2) ? 'CONFORME' : 'NON_CONFORME';

// ------------------------- PRÉPARATION DES DONNÉES POUR TABLEAUX -------------------------
$lignesRisques = [
    ['code'=>'A12','lib'=>'Comptes ordinaires débiteurs chez les institutions financières','montant'=>$comptesOrdDebiteurs],
    ['code'=>'A2A','lib'=>'Autres comptes de dépôts chez les institutions financières','montant'=>$autresDepots],
    ['code'=>'A3A','lib'=>'Comptes de prêts','montant'=>$comptesPrets],
    ['code'=>'A70','lib'=>'Prêts en souffrance','montant'=>$pretsSouffrance],
    ['code'=>'B2D','lib'=>'Crédits à court terme','montant'=>$creditsCourtTerme],
    ['code'=>'B2N','lib'=>'Comptes ordinaires débiteurs des membres, bénéficiaires ou clients','montant'=>$comptesOrdMembres],
    ['code'=>'B30','lib'=>'Crédits à moyen terme','montant'=>$creditsMoyenTerme],
    ['code'=>'B40','lib'=>'Crédits à long terme','montant'=>$creditsLongTerme],
    ['code'=>'B70','lib'=>'Crédits en souffrance','montant'=>$creditsSouffrance],
    ['code'=>'C10','lib'=>'Titres de placement','montant'=>$titresPlacement],
    ['code'=>'D1E','lib'=>'Titres de participation','montant'=>$titresParticipation],
    ['code'=>'D1L','lib'=>'Titres d\'investissement','montant'=>$titresInvestissement],
    ['code'=>'N1A','lib'=>'Engagements par signature donnés en faveur des institutions financières','montant'=>$engagementsSignature],
    ['code'=>'N1J','lib'=>'Engagements par signature donnés en faveur des membres bénéficiaires ou clients','montant'=>$engagementsMembres],
    ['code'=>'N3A','lib'=>'Engagements de garantie sur titre à livrer','montant'=>$engagementsGarantie],
    ['code'=>'Q1A','lib'=>'Autres engagements donnés par signature','montant'=>$autresEngagements],
];
$lignesDeductions = [
    ['code'=>'F2C','lib'=>'Dépôts de Garantie sur les prêts aux institutions financières','montant'=>$depotsGarantieInstFin],
    ['code'=>'G30','lib'=>'Dépôts de Garantie sur les crédits aux membres/clients','montant'=>$depotsGarantieMembres],
];
$lignesRessources = [
    ['code'=>'F1A','lib'=>'Comptes ordinaires créditeurs des institutions financières','montant'=>$comptesCrediteurs],
    ['code'=>'F2A','lib'=>'Autres comptes de dépôts créditeurs des institutions financières','montant'=>$autresDepotsCrediteurs],
    ['code'=>'F3A','lib'=>'Comptes d\'emprunts','montant'=>$comptesEmprunts],
    ['code'=>'F50','lib'=>'Autres sommes dues aux institutions financières','montant'=>$autresSommesDues],
    ['code'=>'G2A','lib'=>'Comptes d\'épargne à régime spécial','montant'=>$epargneSpeciale],
    ['code'=>'G10','lib'=>'Comptes ordinaires créditeurs des membres, bénéficiaires ou clients','montant'=>$comptesCrediteursMembres],
    ['code'=>'G15','lib'=>'Dépôts à terme reçus des membres, bénéficiaires ou clients','montant'=>$depotsTerme],
    ['code'=>'G35','lib'=>'Autres dépôts reçus des clients, membres ou bénéficiaires','montant'=>$autresDepotsClients],
    ['code'=>'G60','lib'=>'Emprunts reçus des clients, membres ou bénéficiaires','montant'=>$empruntsClients],
    ['code'=>'G70','lib'=>'Autres sommes dues aux membres, bénéficiaires ou clients','montant'=>$autresSommesMembres],
    ['code'=>'L01','lib'=>'Provisions, fonds propres et assimilés','montant'=>$fondsPropres],
];

// ------------------------- EXPORT PDF AVEC PDF_DIMF (via POST) -------------------------
if (isset($_POST['export']) && $_POST['export'] === 'pdf') {
    class PDF_DIMF extends FPDF {
        public $codeDimf  = 'R01';
        public $titreDimf = 'LIMITATION DES RISQUES AUXQUELS EST EXPOSEE UNE INSTITUTION';
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
    $pdf->codeDimf  = 'R01';
    $pdf->titreDimf = 'LIMITATION DES RISQUES AUXQUELS EST EXPOSEE UNE INSTITUTION';
    $pdf->nomSfd    = 'SFD';
    $pdf->periode   = ucfirst($type_periode);
    $pdf->exercice  = $exercice;
    $pdf->AddPage();

    $cols = [
        ['w' => 30, 'label' => 'Acc', 'align' => 'L'],
        ['w' => 100, 'label' => 'A', 'align' => 'L'],
        ['w' => 50, 'label' => 'MONTANT (FCFA)', 'align' => 'R']
    ];

    $pdf->SectionTitle("A - RISQUES PORTES PAR L'INSTITUTION (MONTANTS NETS DES PROVISIONS)");
    $pdf->TableHeader($cols);
    foreach ($lignesRisques as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['lib'], PDF_DIMF::montant($row['montant'])]);
    }
    $pdf->TableRow($cols, ['', 'TOTAL BRUT', PDF_DIMF::montant($totalA_brut)], 'subtotal');

    if (!empty($lignesDeductions)) {
        $pdf->Ln(2);
        $pdf->SectionTitle("Eléments à déduire");
        $pdf->TableHeader($cols);
        foreach ($lignesDeductions as $row) {
            $pdf->TableRow($cols, [$row['code'], $row['lib'], PDF_DIMF::montant($row['montant'])]);
        }
        $pdf->TableRow($cols, ['', 'TOTAL RISQUES NETS (A)', PDF_DIMF::montant($montantA)], 'total');
    } else {
        $pdf->TableRow($cols, ['', 'TOTAL RISQUES NETS (A)', PDF_DIMF::montant($montantA)], 'total');
    }

    $pdf->Ln(5);
    $pdf->SectionTitle("B - RESSOURCES");
    $pdf->TableHeader($cols);
    foreach ($lignesRessources as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['lib'], PDF_DIMF::montant($row['montant'])]);
    }
    $pdf->TableRow($cols, ['', 'TOTAL RESSOURCES (B)', PDF_DIMF::montant($montantB)], 'total');

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, PDF_DIMF::u("RATIO R01 = A / B = " . number_format($pourcentageRisques, 2) . "%"), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, PDF_DIMF::u("Norme BCEAO : 0% ≤ Ratio ≤ 200%\nConformité : " . $conformite));

    $pdf->Output('I', 'R01_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ------------------------- EXPORT EXCEL (HTML .xls) VIA POST -------------------------
if (isset($_POST['export']) && $_POST['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="R01_' . $exercice . '_' . $type_periode . '.xls"');
    header('Cache-Control: max-age=0');
    echo '<html><head><meta charset="UTF-8"><style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2 { color: #1a3a5c; font-size: 16pt; }
        h3 { color: #1a3a5c; font-size: 14pt; margin-top: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; font-size: 10pt; }
        th, td { border: 1px solid #999; padding: 8px; vertical-align: top; }
        th { background: #f2f2f2; text-align: center; font-weight: bold; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .total-row { background: #e8f5e9; font-weight: bold; }
        .subtotal-row { background: #f0f7ff; font-weight: bold; }
        .section-title { background: #d9e8f5; font-weight: bold; font-size: 11pt; }
        .montant { font-family: "Courier New", monospace; }
    </style></head><body>';
    echo '<h2>R01 - LIMITATION DES RISQUES AUXQUELS EST EXPOSEE UNE INSTITUTION</h2>';
    echo '<p><strong>Période :</strong> ' . $exercice . ' - ' . ucfirst($type_periode) . ' (arrêtée au ' . date('d/m/Y', strtotime($date_fin_periode)) . ')</p>';

    echo '<h3>A - RISQUES PORTÉS PAR L\'INSTITUTION (MONTANTS NETS DES PROVISIONS)</h3>';
    echo '<table>';
    echo '<tr><th>Acc</th><th>A</th><th class="text-right">MONTANT (FCFA)</th></tr>';
    foreach ($lignesRisques as $r) {
        echo '<tr><td style="width:10%">' . $r['code'] . '</td><td style="width:70%">' . $r['lib'] . '</td><td class="text-right" style="width:20%">' . number_format($r['montant'], 0, ',', ' ') . '</td></tr>';
    }
    echo '<tr class="subtotal-row"><td colspan="2">TOTAL BRUT</td><td class="text-right">' . number_format($totalA_brut, 0, ',', ' ') . '</td></tr>';
    if (!empty($lignesDeductions)) {
        echo '<tr><td colspan="3" style="background:#f8fafc; font-weight:bold;">ÉLÉMENTS À DÉDUIRE</td></tr>';
        foreach ($lignesDeductions as $d) {
            echo '<tr><td>' . $d['code'] . '</td><td>' . $d['lib'] . '</td><td class="text-right">' . number_format($d['montant'], 0, ',', ' ') . '</td></tr>';
        }
        echo '<tr class="total-row"><td colspan="2">TOTAL RISQUES NETS (A)</td><td class="text-right">' . number_format($montantA, 0, ',', ' ') . '</td></tr>';
    } else {
        echo '<tr class="total-row"><td colspan="2">TOTAL RISQUES NETS (A)</td><td class="text-right">' . number_format($montantA, 0, ',', ' ') . '</td></tr>';
    }
    echo '</table>';

    echo '<h3>B - RESSOURCES</h3>';
    echo '<table>';
    echo '<tr><th>Acc</th><th>A</th><th class="text-right">MONTANT (FCFA)</th></tr>';
    foreach ($lignesRessources as $rs) {
        echo '<td><td style="width:10%">' . $rs['code'] . '</td><td style="width:70%">' . $rs['lib'] . '</td><td class="text-right" style="width:20%">' . number_format($rs['montant'], 0, ',', ' ') . '</td></tr>';
    }
    echo '<tr class="total-row"><td colspan="2">TOTAL RESSOURCES (B)</td><td class="text-right">' . number_format($montantB, 0, ',', ' ') . '</td></tr>';
    echo '</table>';

    echo '<p><strong>RATIO R01 = A / B = ' . number_format($pourcentageRisques, 2) . '%</strong></p>';
    echo '<p>Norme BCEAO : 0% à 200%<br>Conformité : ' . $conformite . '</p>';
    echo '</body></html>';
    exit;
}

// ------------------------- AFFICHAGE WEB (INTERFACE DIMF_2000 AVEC BOOTSTRAP 5, DESIGN CONSERVÉ) -------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>R01 - Limitation des risques (BCEAO)</title>
    <!-- Bootstrap 5 CSS (intégré sans modification du design) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Styles personnalisés inchangés (conservent le design original) -->
    <style>
        /* Styles DIMF_2000 (inchangés) */
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
        .subtotal-row { background:#f8fafc; font-weight:700; }
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
            <h1><i class="fas fa-shield-alt"></i> R01 - LIMITATION DES RISQUES</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">Norme BCEAO : 0% ≤ Ratio ≤ 200%</div>
        </div>
        <div class="btn-group">
            <!-- Boutons export en POST (via formulaire dynamique JS) -->
            <button class="btn-excel" onclick="submitExport('excel')"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" onclick="submitExport('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Formulaire de filtres en POST (remplace l'ancien système GET) -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres de période</div>
        <form method="post" id="filterForm">
            <div class="filters-row">
                <div class="filter-item">
                    <label>Année</label>
                    <select name="exercice" id="exerciceSelect">
                        <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                            <option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Type de période</label>
                    <select name="type_periode" id="typePeriodeSelect">
                        <option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option>
                        <option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option>
                        <option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option>
                        <option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option>
                    </select>
                </div>
                <div class="filter-item" id="dynamicSelectContainer">
                    <!-- Contenu dynamique généré par JS, les noms des champs sont 'mois', 'trimestre' ou 'semestre' -->
                </div>
                <button type="submit" class="btn-apply">Appliquer</button>
            </div>
        </form>
    </div>

    <!-- Carte ratio web -->
    <div class="ratio-card">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:20px;">
            <div><div class="card-header" style="padding:0;">Ratio R01 – Limitation des risques</div><div class="ratio-value <?=$conformite=='CONFORME'?'conforme':'non-conforme'?>"><?=number_format($pourcentageRisques,2)?>%</div><div>Risques nets / Ressources</div></div>
            <div class="norme-box"><div><strong>Norme BCEAO</strong></div><div style="font-size:1.5rem;">0% → 200%</div><div>Seuil max 200%</div></div>
            <div><span class="badge" style="background:<?=$conformite=='CONFORME'?'#10b981':'#ef4444'?>;"><?=$conformite?></span></div>
        </div>
        <div class="progress-bar"><div class="progress-fill <?=$conformite!='CONFORME'?'non-conforme':''?>" style="width:<?=min($pourcentageRisques,200)/2?>%;"><?=number_format($pourcentageRisques,1)?>%</div></div>
        <div style="margin-top:16px;"><i class="fas fa-calculator"></i> R01 = <?=number_format($montantA,0,',',' ')?> / <?=number_format($montantB,0,',',' ')?> = <?=number_format($pourcentageRisques,2)?>%</div>
    </div>

    <!-- Deux colonnes web -->
    <div class="two-columns">
        <div class="card">
            <div class="card-header"><i class="fas fa-exclamation-triangle"></i> A – RISQUES PORTÉS PAR L'INSTITUTION (MONTANTS NETS DES PROVISIONS)</div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Libellé</th>
                            <th class="text-right">Montant (FCFA)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($lignesRisques as $r): ?>
                        <tr>
                            <td><?= $r['code'] ?></td>
                            <td><?= $r['lib'] ?></td>
                            <td class="text-right"><?= number_format($r['montant'], 0, ',', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="subtotal-row">
                            <td colspan="2"><strong>TOTAL BRUT</strong></td>
                            <td class="text-right"><strong><?= number_format($totalA_brut, 0, ',', ' ') ?></strong></td>
                        </tr>
                        <?php if (!empty($lignesDeductions)): ?>
                        <tr>
                            <td colspan="3" style="background:#f8fafc; font-weight:600; padding:8px 16px;">ÉLÉMENTS À DÉDUIRE</td>
                        </tr>
                        <?php foreach($lignesDeductions as $d): ?>
                        <tr>
                            <td><?= $d['code'] ?></td>
                            <td><?= $d['lib'] ?></td>
                            <td class="text-right"><?= number_format($d['montant'], 0, ',', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="2"><strong>TOTAL RISQUES NETS (A)</strong></td>
                            <td class="text-right"><strong><?= number_format($montantA, 0, ',', ' ') ?></strong></td>
                        </tr>
                        <?php else: ?>
                        <tr class="total-row">
                            <td colspan="2"><strong>TOTAL RISQUES NETS (A)</strong></td>
                            <td class="text-right"><strong><?= number_format($montantA, 0, ',', ' ') ?></strong></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="fas fa-coins"></i> B – RESSOURCES</div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Libellé</th>
                            <th class="text-right">Montant (FCFA)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($lignesRessources as $rs): ?>
                        <tr>
                            <td><?= $rs['code'] ?></td>
                            <td><?= $rs['lib'] ?></td>
                            <td class="text-right"><?= number_format($rs['montant'], 0, ',', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="2"><strong>TOTAL RESSOURCES (B)</strong></td>
                            <td class="text-right"><strong><?= number_format($montantB, 0, ',', ' ') ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card"><div class="card-header">Interprétation</div><div class="info-box"><i class="fas fa-gavel"></i><div><?=($conformite=='CONFORME')?'✓ Conforme – Les risques nets représentent '.number_format($pourcentageRisques,2).'% des ressources, ≤200%.':'⚠️ Non conforme – Les risques nets dépassent 200% des ressources.'?></div></div></div>
    <div class="page-footer"><i class="fas fa-calendar-alt"></i> Généré le <?=date('d/m/Y à H:i:s')?> – Période <?=$exercice?> (<?=ucfirst($type_periode)?>) arrêtée au <?=date('d/m/Y',strtotime($date_fin_periode))?></div>
</div>

<!-- Scripts : Bootstrap 5 JS (pour les composants optionnels, design non modifié) + gestion POST -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Remplissage dynamique du select (mois, trimestre, semestre) avec conservation des valeurs POST
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        // Valeurs actuelles depuis PHP (transmises par POST)
        const currentMois = <?=$mois?>;
        const currentTrimestre = <?=$trimestre?>;
        const currentSemestre = <?=json_encode($semestre)?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois" id="moisSelect" class="form-select">';
            for (let m = 1; m <= 12; m++) {
                let selected = (m === currentMois) ? 'selected' : '';
                html += `<option value="${m}" ${selected}>${String(m).padStart(2,'0')} - ${new Date(2000,m-1,1).toLocaleString('fr',{month:'long'})}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect" class="form-select">';
            for (let t = 1; t <= 4; t++) {
                let selected = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${selected}>${t}${t===1?'er':'ème'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect" class="form-select">';
            for (let s = 1; s <= 2; s++) {
                let selected = (s === currentSemestre) ? 'selected' : '';
                html += `<option value="${s}" ${selected}>${s}${s===1?'er':'e'} semestre</option>`;
            }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" class="form-control" disabled value="Année complète">';
        }
        container.innerHTML = html;
    }

    // Soumission des exports en POST (réutilisation des valeurs du formulaire principal)
    function submitExport(type) {
        const form = document.getElementById('filterForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'export';
        input.value = type;
        form.appendChild(input);
        form.submit();
        form.removeChild(input);
    }

    // Écouteur pour changement de type période
    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>