<?php
// R05.php - Norme de liquidité
// Norme BCEAO: ≥ 1 (doit être supérieur ou égal à 1)
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
$trimestre = isset($_POST['trimestre']) ? (int)$_POST['trimestre'] : 4;
$semestre = isset($_POST['semestre']) ? (int)$_POST['semestre'] : 2;

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

// ------------------------- INITIALISATION VARIABLES -------------------------
// A - Valeurs réalisables et disponibles (montants nets, durée < 3 mois)
$disponibilites = 0;           // A10 (caisse)
$comptesOrdDebiteurs = 0;      // A12
$autresDepotsCourtTerme = 0;   // A2J
$autresDepotsDebiteurs = 0;    // A2A
$pretsCourtTermeInstFin = 0;   // A3B
$creditsCourtTermeClients = 0; // B2D
$comptesOrdDebiteursMembres = 0; // B2N
$creditsMoyenTerme = 0;         // B30
$creditsLongTerme = 0;          // B40
$titresPlacement = 0;           // C10
$stocks = 0;                    // C30
$debiteursDivers = 0;           // C40
$valeursEncaissement = 0;       // C56
$creancesRattacheesInstFin = 0; // A60
$creancesRattacheesMembres = 0; // B65
$creancesRattacheesDivers = 0;  // C55
$engagementsRecusInstFin = 0;   // N1H
$engagementsRecusMembres = 0;   // N1K
$garantiesRecuesInstFin = 0;    // N2H
$garantiesRecuesMembres = 0;    // N2M

// B - Passif exigible (dettes à court terme < 3 mois)
$comptesCrediteursInstFin = 0;  // F1A
$autresDepotsCrediteursInstFin = 0; // F2A
$empruntsMoinsUnAn = 0;         // F3E
$empruntsTerme = 0;             // F3F
$autresSommesDuesInstFin = 0;   // F50
$comptesCrediteursMembres = 0;  // G10
$depotsTermeRecus = 0;          // G15
$epargneSpeciale = 0;           // G2A
$depotsGarantieRecus = 0;       // G30
$autresDepotsRecus = 0;         // G35
$empruntsRecusMembres = 0;      // G60
$autresSommesDuesMembres = 0;   // G70
$versementsRestant = 0;         // H10
$crediteursDivers = 0;          // H40
$dettesRattachees = 0;          // G90 + F60
$engagementsDonnesInstFin = 0;  // N1A
$engagementsDonnesMembres = 0;  // N1J
$garantiesDonneesInstFin = 0;   // N2A
$garantiesDonneesMembres = 0;   // N2J

// ------------------------- A - VALEURS RÉALISABLES ET DISPONIBLES -------------------------
// A10 - Valeurs en caisse
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde_actuel),0) as total FROM caisses WHERE statut='ouverte'");
    $stmt->execute();
    $disponibilites = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// A12 - Comptes ordinaires débiteurs chez les institutions financières
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde),0) as total FROM comptes WHERE solde > 0 AND statut='actif'");
    $stmt->execute();
    $comptesOrdDebiteurs = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// A2J - Dépôts à court terme constitués auprès des institutions financières (simulé)
$autresDepotsCourtTerme = 0;

// A2A - Autres comptes de dépôts débiteurs (simulé)
$autresDepotsDebiteurs = 0;

// A3B - Comptes de prêts à court terme aux institutions financières (simulé)
$pretsCourtTermeInstFin = 0;

// B2D - Crédits à court terme aux membres (durée ≤ 12 mois)
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree <= 12
    ");
    $stmt->execute();
    $creditsCourtTermeClients = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// B2N - Comptes ordinaires débiteurs des membres (simulé)
$comptesOrdDebiteursMembres = $comptesOrdDebiteurs;

// B30 - Crédits à moyen terme (on ne prend que la partie à moins de 3 mois, ici on simplifie à 0)
$creditsMoyenTerme = 0;

// B40 - Crédits à long terme (idem)
$creditsLongTerme = 0;

// C10 - Titres de placement
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

// C30 - Comptes de stocks
$stocks = 0;

// C40 - Débiteurs divers
$debiteursDivers = 0;

// C56 - Valeurs à l'encaissement avec crédit immédiat
$valeursEncaissement = 0;

// A60, B65, C55 - Créances rattachées
$creancesRattacheesInstFin = 0;
$creancesRattacheesMembres = 0;
$creancesRattacheesDivers = 0;

// N1H, N1K, N2H, N2M - Engagements reçus
$engagementsRecusInstFin = 0;
$engagementsRecusMembres = 0;
$garantiesRecuesInstFin = 0;
$garantiesRecuesMembres = 0;

$montantA = $disponibilites + $comptesOrdDebiteurs + $autresDepotsCourtTerme + $autresDepotsDebiteurs
          + $pretsCourtTermeInstFin + $creditsCourtTermeClients + $comptesOrdDebiteursMembres
          + $creditsMoyenTerme + $creditsLongTerme + $titresPlacement + $stocks + $debiteursDivers
          + $valeursEncaissement + $creancesRattacheesInstFin + $creancesRattacheesMembres
          + $creancesRattacheesDivers + $engagementsRecusInstFin + $engagementsRecusMembres
          + $garantiesRecuesInstFin + $garantiesRecuesMembres;

// ------------------------- B - PASSIF EXIGIBLE -------------------------
// F1A - Comptes ordinaires créditeurs des institutions financières
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(solde)),0) as total FROM comptes WHERE solde < 0 AND statut='actif'");
    $stmt->execute();
    $comptesCrediteursInstFin = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// F2A - Autres comptes de dépôts créditeurs (simulé)
$autresDepotsCrediteursInstFin = $comptesCrediteursInstFin;

// F3E - Emprunts à moins d'un an
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant),0) as total
        FROM capital
        WHERE statut='valide' AND mode_paiement='BANQUE'
          AND date_creation BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_exercice]);
    $empruntsMoinsUnAn = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// F3F - Emprunts à terme (à échéance >1 an, donc pas dans passif exigible)
$empruntsTerme = 0;

// F50 - Autres sommes dues aux institutions financières
$autresSommesDuesInstFin = 0;

// G10 - Comptes ordinaires créditeurs des membres
$comptesCrediteursMembres = $comptesCrediteursInstFin;

// G15 - Dépôts à terme reçus (à échéance <3 mois)
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(capital_initial),0) as total
        FROM comptes_dat
        WHERE statut='en cours' AND date_echeance <= DATE_ADD(:date_fin, INTERVAL 3 MONTH)
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $depotsTermeRecus = (float)$stmt->fetch()['total'];
} catch (PDOException $e) {}

// G2A - Comptes d'épargne à régime spécial
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

// G30 - Autres dépôts de garantie reçus
$depotsGarantieRecus = 0;

// G35 - Autres dépôts reçus
$autresDepotsRecus = 0;

// G60 - Emprunts de l'institution auprès des membres
$empruntsRecusMembres = 0;

// G70 - Autres sommes dues aux membres
$autresSommesDuesMembres = 0;

// H10 - Versements restant à effectuer à court terme
$versementsRestant = 0;

// H40 - Créditeurs divers à court terme
$crediteursDivers = 0;

// G90 / F60 - Dettes rattachées
$dettesRattachees = 0;

// N1A, N1J, N2A, N2J - Engagements donnés
$engagementsDonnesInstFin = 0;
$engagementsDonnesMembres = 0;
$garantiesDonneesInstFin = 0;
$garantiesDonneesMembres = 0;

$montantB = $comptesCrediteursInstFin + $autresDepotsCrediteursInstFin + $empruntsMoinsUnAn
          + $empruntsTerme + $autresSommesDuesInstFin + $comptesCrediteursMembres
          + $depotsTermeRecus + $epargneSpeciale + $depotsGarantieRecus + $autresDepotsRecus
          + $empruntsRecusMembres + $autresSommesDuesMembres + $versementsRestant
          + $crediteursDivers + $dettesRattachees + $engagementsDonnesInstFin
          + $engagementsDonnesMembres + $garantiesDonneesInstFin + $garantiesDonneesMembres;

if ($montantB <= 0) $montantB = 1;
$ratioR05 = $montantA / $montantB;
$normeMin = 1;
$conformite = ($ratioR05 >= $normeMin) ? 'CONFORME' : 'NON_CONFORME';

// ------------------------- PRÉPARATION DES DONNÉES POUR TABLEAUX -------------------------
$lignesActifLiquide = [
    ['code'=>'A10','lib'=>'Valeurs en caisse','montant'=>$disponibilites],
    ['code'=>'A12','lib'=>'Comptes ordinaires débiteurs chez les institutions financières','montant'=>$comptesOrdDebiteurs],
    ['code'=>'A2J','lib'=>'Dépôts à court terme constitués auprès des institutions financières','montant'=>$autresDepotsCourtTerme],
    ['code'=>'A2A','lib'=>'Autres comptes de dépôts débiteurs chez les institutions financières','montant'=>$autresDepotsDebiteurs],
    ['code'=>'A3B','lib'=>'Comptes de prêts à court terme aux institutions financières','montant'=>$pretsCourtTermeInstFin],
    ['code'=>'B2D','lib'=>'Crédits à court terme aux membres, bénéficiaires ou clients','montant'=>$creditsCourtTermeClients],
    ['code'=>'B2N','lib'=>'Comptes ordinaires débiteurs des membres, bénéficiaires ou clients','montant'=>$comptesOrdDebiteursMembres],
    ['code'=>'B30','lib'=>'Crédits à moyen terme','montant'=>$creditsMoyenTerme],
    ['code'=>'B40','lib'=>'Crédits à long terme','montant'=>$creditsLongTerme],
    ['code'=>'C10','lib'=>'Titres de placement','montant'=>$titresPlacement],
    ['code'=>'C30','lib'=>'Comptes de stocks','montant'=>$stocks],
    ['code'=>'C40','lib'=>'Débiteurs divers','montant'=>$debiteursDivers],
    ['code'=>'C56','lib'=>'Valeurs à l\'encaissement avec crédit immédiat','montant'=>$valeursEncaissement],
    ['code'=>'A60','lib'=>'Créances rattachées sur les institutions financières','montant'=>$creancesRattacheesInstFin],
    ['code'=>'B65','lib'=>'Créances rattachées sur les membres bénéficiaires et clients','montant'=>$creancesRattacheesMembres],
    ['code'=>'C55','lib'=>'Créances rattachées sur les opérations sur titres et opérations diverses','montant'=>$creancesRattacheesDivers],
    ['code'=>'N1H','lib'=>'Engagements de financement reçus des institutions financières','montant'=>$engagementsRecusInstFin],
    ['code'=>'N1K','lib'=>'Engagements de financement reçus des membres bénéficiaires ou clients','montant'=>$engagementsRecusMembres],
    ['code'=>'N2H','lib'=>'Engagements de garantie reçus des institutions financières','montant'=>$garantiesRecuesInstFin],
    ['code'=>'N2M','lib'=>'Engagements de garantie reçus des membres bénéficiaires ou clients','montant'=>$garantiesRecuesMembres],
];

$lignesPassifExigible = [
    ['code'=>'F1A','lib'=>'Comptes ordinaires créditeurs des institutions financières auprès du SFD','montant'=>$comptesCrediteursInstFin],
    ['code'=>'F2A','lib'=>'Autres comptes de dépôts créditeurs des institutions financières','montant'=>$autresDepotsCrediteursInstFin],
    ['code'=>'F3E','lib'=>'Emprunts à moins d\'un an auprès des institutions financières','montant'=>$empruntsMoinsUnAn],
    ['code'=>'F3F','lib'=>'Emprunts à terme','montant'=>$empruntsTerme],
    ['code'=>'F50','lib'=>'Autres sommes dues aux institutions financières','montant'=>$autresSommesDuesInstFin],
    ['code'=>'G10','lib'=>'Comptes ordinaires créditeurs des membres, bénéficiaires ou clients auprès de l\'institution','montant'=>$comptesCrediteursMembres],
    ['code'=>'G15','lib'=>'Dépôts à terme reçus','montant'=>$depotsTermeRecus],
    ['code'=>'G2A','lib'=>'Comptes d\'épargne à régime spécial','montant'=>$epargneSpeciale],
    ['code'=>'G30','lib'=>'Autres dépôts de garantie reçus des membres, bénéficiaires ou clients','montant'=>$depotsGarantieRecus],
    ['code'=>'G35','lib'=>'Autres dépôts des membres, bénéficiaires ou clients auprès de l\'institution','montant'=>$autresDepotsRecus],
    ['code'=>'G60','lib'=>'Emprunts de l\'institution auprès des membres','montant'=>$empruntsRecusMembres],
    ['code'=>'G70','lib'=>'Autres sommes dues aux membres, bénéficiaires ou clients','montant'=>$autresSommesDuesMembres],
    ['code'=>'H10','lib'=>'Versements restant à effectuer à court terme','montant'=>$versementsRestant],
    ['code'=>'H40','lib'=>'Créditeurs divers à court terme','montant'=>$crediteursDivers],
    ['code'=>'G90/F60','lib'=>'Dettes rattachées (membres / institutions financières)','montant'=>$dettesRattachees],
    ['code'=>'N1A','lib'=>'Engagements de financement donnés aux institutions financières','montant'=>$engagementsDonnesInstFin],
    ['code'=>'N1J','lib'=>'Engagements de financement donnés aux membres, clients et bénéficiaires','montant'=>$engagementsDonnesMembres],
    ['code'=>'N2A','lib'=>'Engagements de garantie donnés aux institutions financières','montant'=>$garantiesDonneesInstFin],
    ['code'=>'N2J','lib'=>'Engagements de garantie donnés aux membres, clients et bénéficiaires','montant'=>$garantiesDonneesMembres],
];

// ------------------------- EXPORT PDF AVEC PDF_DIMF (via POST) -------------------------
if (isset($_POST['export']) && $_POST['export'] === 'pdf') {
    // Nettoyer les buffers pour éviter les erreurs de headers
    if (ob_get_length()) ob_clean();

    class PDF_DIMF extends FPDF {
        public $codeDimf  = 'R05';
        public $titreDimf = 'NORME DE LIQUIDITE';
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
    $pdf->codeDimf  = 'R05';
    $pdf->titreDimf = 'NORME DE LIQUIDITE';
    $pdf->nomSfd    = 'SFD';
    $pdf->periode   = ucfirst($type_periode);
    $pdf->exercice  = $exercice;
    $pdf->AddPage();

    $cols = [
        ['w' => 30, 'label' => 'Code', 'align' => 'L'],
        ['w' => 100, 'label' => 'Libellé', 'align' => 'L'],
        ['w' => 50, 'label' => 'Montant (FCFA)', 'align' => 'R']
    ];

    // Section A – Valeurs réalisables et disponibles
    $pdf->SectionTitle("A - VALEURS REALISABLES ET DISPONIBLES (MONTANTS NETS, DUREE < 3 MOIS)");
    $pdf->TableHeader($cols);
    foreach ($lignesActifLiquide as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['lib'], PDF_DIMF::montant($row['montant'])]);
    }
    $pdf->TableRow($cols, ['', 'TOTAL VALEURS REALISABLES (A)', PDF_DIMF::montant($montantA)], 'total');

    $pdf->Ln(5);

    // Section B – Passif exigible
    $pdf->SectionTitle("B - PASSIF EXIGIBLE (DETTES A COURTS TERME, DUREE < 3 MOIS)");
    $pdf->TableHeader($cols);
    foreach ($lignesPassifExigible as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['lib'], PDF_DIMF::montant($row['montant'])]);
    }
    $pdf->TableRow($cols, ['', 'TOTAL PASSIF EXIGIBLE (B)', PDF_DIMF::montant($montantB)], 'total');

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, PDF_DIMF::u("RATIO R05 = A / B = " . number_format($ratioR05, 2)), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, PDF_DIMF::u("Norme BCEAO : Ratio ≥ 1\nConformité : " . $conformite));

    $pdf->Output('I', 'R05_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ------------------------- EXPORT EXCEL (HTML .xls) VIA POST -------------------------
if (isset($_POST['export']) && $_POST['export'] === 'excel') {
    if (ob_get_length()) ob_clean();

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="R05_' . $exercice . '_' . $type_periode . '.xls"');
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
    </style></head><body>';
    echo '<h2>R05 - NORME DE LIQUIDITE</h2>';
    echo '<p><strong>Période :</strong> ' . $exercice . ' - ' . ucfirst($type_periode) . ' (arrêtée au ' . date('d/m/Y', strtotime($date_fin_periode)) . ')</p>';

    // Tableau A
    echo '<h3>A - VALEURS REALISABLES ET DISPONIBLES (durée < 3 mois)</h3>';
    echo '<table>';
    echo '<tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>';
    foreach ($lignesActifLiquide as $r) {
        echo '<tr><td style="width:15%">' . $r['code'] . '</td><td style="width:70%">' . $r['lib'] . '</td><td class="text-right" style="width:15%">' . number_format($r['montant'], 0, ',', ' ') . '</td></tr>';
    }
    echo '<tr class="total-row"><td colspan="2">TOTAL VALEURS REALISABLES (A)</td><td class="text-right">' . number_format($montantA, 0, ',', ' ') . '</td></tr>';
    echo '</table>';

    // Tableau B
    echo '<h3>B - PASSIF EXIGIBLE (dettes à court terme, durée < 3 mois)</h3>';
    echo '<table>';
    echo '<tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>';
    foreach ($lignesPassifExigible as $r) {
        echo '<tr><td style="width:15%">' . $r['code'] . '</td><td style="width:70%">' . $r['lib'] . '</td><td class="text-right" style="width:15%">' . number_format($r['montant'], 0, ',', ' ') . '</td></tr>';
    }
    echo '<tr class="total-row"><td colspan="2">TOTAL PASSIF EXIGIBLE (B)</td><td class="text-right">' . number_format($montantB, 0, ',', ' ') . '</td></tr>';
    echo '</table>';

    echo '<p><strong>RATIO R05 = A / B = ' . number_format($ratioR05, 2) . '</strong></p>';
    echo '<p>Norme BCEAO : Ratio ≥ 1<br>Conformité : ' . $conformite . '</p>';
    echo '</body></html>';
    exit;
}

// ------------------------- AFFICHAGE WEB (INTERFACE DIMF_2000 AVEC BOOTSTRAP 5, DESIGN CONSERVÉ) -------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>R05 - Norme de liquidité (BCEAO)</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Styles personnalisés inchangés -->
    <style>
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
            <h1><i class="fas fa-tint"></i> R05 - NORME DE LIQUIDITÉ</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">Norme BCEAO : Ratio ≥ 1</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="submitExport('excel')"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" onclick="submitExport('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Formulaire de filtres en POST -->
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
                    <!-- Contenu dynamique généré par JS -->
                </div>
                <button type="submit" class="btn-apply">Appliquer</button>
            </div>
        </form>
    </div>

    <!-- Carte ratio -->
    <div class="ratio-card">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:20px;">
            <div><div class="card-header" style="padding:0;">Ratio R05 – Liquidité</div><div class="ratio-value <?=$conformite=='CONFORME'?'conforme':'non-conforme'?>"><?=number_format($ratioR05,2)?></div><div>Valeurs réalisables / Passif exigible</div></div>
            <div class="norme-box"><div><strong>Norme BCEAO</strong></div><div style="font-size:1.5rem;">≥ 1</div><div>Seuil minimal : 1</div></div>
            <div><span class="badge" style="background:<?=$conformite=='CONFORME'?'#10b981':'#ef4444'?>;"><?=$conformite?></span></div>
        </div>
        <div class="progress-bar"><div class="progress-fill <?=$conformite!='CONFORME'?'non-conforme':''?>" style="width:<?=min($ratioR05/2*100,100)?>%;"><?=number_format($ratioR05,2)?></div></div>
        <div style="margin-top:16px;"><i class="fas fa-calculator"></i> R05 = <?=number_format($montantA,0,',',' ')?> / <?=number_format($montantB,0,',',' ')?> = <?=number_format($ratioR05,2)?></div>
    </div>

    <!-- Deux colonnes web -->
    <div class="two-columns">
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-line"></i> A – VALEURS RÉALISABLES ET DISPONIBLES</div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Code</th><th>Libellé</th><th class="text-right">Montant</th></tr></thead>
                    <tbody>
                        <?php foreach($lignesActifLiquide as $r): ?>
                        <tr><td><?=$r['code']?></td><td><?=$r['lib']?></td><td class="text-right"><?=number_format($r['montant'],0,',',' ')?></td></tr>
                        <?php endforeach; ?>
                        <tr class="total-row"><td colspan="2">TOTAL (A)</td><td class="text-right"><?=number_format($montantA,0,',',' ')?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-line"></i> B – PASSIF EXIGIBLE</div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Code</th><th>Libellé</th><th class="text-right">Montant</th></tr></thead>
                    <tbody>
                        <?php foreach($lignesPassifExigible as $r): ?>
                        <tr><td><?=$r['code']?></td><td><?=$r['lib']?></td><td class="text-right"><?=number_format($r['montant'],0,',',' ')?></td></tr>
                        <?php endforeach; ?>
                        <tr class="total-row"><td colspan="2">TOTAL (B)</td><td class="text-right"><?=number_format($montantB,0,',',' ')?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Interprétation -->
    <div class="card">
        <div class="card-header">Interprétation</div>
        <div class="info-box">
            <i class="fas fa-gavel"></i>
            <div><?=($conformite=='CONFORME')?'✓ Conforme – L\'institution dispose de suffisamment d\'actifs liquides pour couvrir ses dettes à court terme (ratio '.number_format($ratioR05,2).' ≥ 1).':'⚠️ Non conforme – Les actifs liquides sont insuffisants (ratio '.number_format($ratioR05,2).' < 1). Risque de liquidité.'?></div>
        </div>
    </div>

    <div class="page-footer"><i class="fas fa-calendar-alt"></i> Généré le <?=date('d/m/Y à H:i:s')?> – Période <?=$exercice?> (<?=ucfirst($type_periode)?>) arrêtée au <?=date('d/m/Y',strtotime($date_fin_periode))?></div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
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

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>