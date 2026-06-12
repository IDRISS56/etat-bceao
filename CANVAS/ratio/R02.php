<?php
// R02.php - Couverture des emplois à moyen et long terme par des ressources stables
// Norme BCEAO: ≥ 1 (doit être supérieur ou égal à 1)

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ------------------------- CONNEXION BDD -------------------------
$host = 'localhost';
$dbname = 'microfinances_dg';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

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

// ------------------------- INITIALISATION VARIABLES -------------------------
$subventions = $fondsAffectes = $fondsCredit = $provisionsRisques = $provisionsReglementees = 0;
$empruntsSubordonnes = $fondsRisques = $primesCapital = $reserves = $ecartReeval = 0;
$capital = $fondsDotation = $reportPositif = $excedentProduits = $resultatExercice = 0;
$capitalNonAppele = $excedentCharges = $immobilisationsIncorp = $reportNegatif = 0;
$provisionsNonConst = $participationsSFD = 0;
$autresDepotsCrediteurs = $empruntsTerme = $depotsTerme = $epargneSpeciale = $depotsGarantie = 0;
$autresDepotsRecus = $emprunts = $autresSommes = 0;
$depotsTermeConstitués = $depotsGarantieConstitués = $autresDepotsConstitués = $pretsTerme = 0;
$pretsSouffrance = $creditsMoyenTerme = $creditsLongTerme = $creditsSouffrance = 0;
$titresParticipation = $titresInvestissement = $pretsSubordonnes = $depotsCautionnements = 0;
$immobilisationsEnCours = $immobilisationsExploit = $immobilisationsHorsExploit = 0;

// ------------------------- CALCULS -------------------------
// A - RESSOURCES STABLES (durée résiduelle > 12 mois)
// L01 - Provisions, fonds propres et assimilés
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_credit - montant_debit),0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $fondsPropres = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $fondsPropres = 0; }

// Si aucune écriture, on essaie la table capital
if ($fondsPropres == 0) {
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant),0) as total FROM capital WHERE statut='valide' AND date_creation <= :date_fin");
        $stmt->execute([':date_fin' => $date_fin_periode]);
        $fondsPropres = (float)$stmt->fetch()['total'];
    } catch (PDOException $e) {}
}
// Les autres postes de ressources stables (F2A, F3F, F50, G15, G2A, G30, G35, G60, G70)
// Par simplification, on les initialise à 0. À adapter selon vos données.
$autresDepotsCrediteurs = 0; // F2A
$empruntsTerme = 0;          // F3F
$autresSommesDues = 0;       // F50
$depotsTerme = 0;            // G15
$epargneSpeciale = 0;        // G2A
$depotsGarantie = 0;         // G30
$autresDepotsRecus = 0;      // G35
$emprunts = 0;               // G60
$autresSommes = 0;           // G70

$ressourcesStables = $fondsPropres + $autresDepotsCrediteurs + $empruntsTerme + $autresSommesDues
                   + $depotsTerme + $epargneSpeciale + $depotsGarantie + $autresDepotsRecus
                   + $emprunts + $autresSommes;

// B - EMPLOIS À MOYEN ET LONG TERME (durée résiduelle > 12 mois, nets provisions)
// A2H, A2I, A2J
$depotsTermeConstitués = 0;
$depotsGarantieConstitués = 0;
$autresDepotsConstitués = 0;

// A3C - Comptes de prêts à terme (>12 mois) => dossiers avec duree > 12
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree > 12
    ");
    $stmt->execute();
    $pretsTerme = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $pretsTerme = 0; }

// A70 - Prêts en souffrance (>12 mois) – on prend tous les prêts en souffrance (sans filtre durée)
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut = 'impaye'
    ");
    $stmt->execute();
    $pretsSouffrance = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $pretsSouffrance = 0; }

// B30 - Crédits à moyen terme (13-60 mois)
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree BETWEEN 13 AND 60
    ");
    $stmt->execute();
    $creditsMoyenTerme = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $creditsMoyenTerme = 0; }

// B40 - Crédits à long terme (>60 mois)
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse,0)),0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif','approuve') AND d.duree > 60
    ");
    $stmt->execute();
    $creditsLongTerme = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $creditsLongTerme = 0; }

// B70 - Crédits en souffrance (identique à pretsSouffrance)
$creditsSouffrance = $pretsSouffrance;

// D1E - Titres de participation
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit),0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '26%' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $titresParticipation = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $titresParticipation = 0; }

// D1L - Titres d'investissement
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit),0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '27%' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $titresInvestissement = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $titresInvestissement = 0; }

// D10, D1S, D23, D30, D40
$pretsSubordonnes = 0;
$depotsCautionnements = 0;

// D23 - Immobilisations en cours
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_achat - amortissement_total),0) as valeur_nette
        FROM immobilisations
        WHERE statut='actif' AND (libelle LIKE '%en cours%' OR libelle LIKE '%projet%')
          AND date_achat <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $immobilisationsEnCours = (float)$stmt->fetch()['valeur_nette'];
} catch (PDOException $e) { $immobilisationsEnCours = 0; }

// D30 - Immobilisations d'exploitation (corporelles et incorporelles, hors en cours)
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_achat - amortissement_total),0) as valeur_nette
        FROM immobilisations
        WHERE type_immobilisation IN ('Immobilisations corporelles','Immobilisations incorporelles')
          AND statut='actif' AND date_achat <= :date_fin
          AND (libelle NOT LIKE '%en cours%' AND libelle NOT LIKE '%projet%')
          AND (libelle NOT LIKE '%hors exploitation%' AND libelle NOT LIKE '%terrain%' AND libelle NOT LIKE '%immobilier%')
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $immobilisationsExploit = (float)$stmt->fetch()['valeur_nette'];
} catch (PDOException $e) { $immobilisationsExploit = 0; }

// D40 - Immobilisations hors exploitation
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_achat - amortissement_total),0) as valeur_nette
        FROM immobilisations
        WHERE type_immobilisation IN ('Immobilisations corporelles','Immobilisations incorporelles')
          AND statut='actif' AND date_achat <= :date_fin
          AND (libelle LIKE '%hors exploitation%' OR libelle LIKE '%terrain%' OR libelle LIKE '%immobilier%')
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $immobilisationsHorsExploit = (float)$stmt->fetch()['valeur_nette'];
} catch (PDOException $e) { $immobilisationsHorsExploit = 0; }

// Total emplois MLT (B)
$emploisMLT = $depotsTermeConstitués + $depotsGarantieConstitués + $autresDepotsConstitués
            + $pretsTerme + $pretsSouffrance + $creditsMoyenTerme + $creditsLongTerme
            + $creditsSouffrance + $titresParticipation + $titresInvestissement
            + $pretsSubordonnes + $depotsCautionnements + $immobilisationsEnCours
            + $immobilisationsExploit + $immobilisationsHorsExploit;

// Ratio
if ($emploisMLT <= 0) $emploisMLT = 1;
$ratioR02 = $ressourcesStables / $emploisMLT;
$normeMin = 1;
$conformite = ($ratioR02 >= $normeMin) ? 'CONFORME' : 'NON_CONFORME';

// ------------------------- PRÉPARATION DES DONNÉES POUR TABLEAUX -------------------------
$lignesRessources = [
    ['code'=>'L01','lib'=>'Provisions, fonds propres et assimilés','montant'=>$fondsPropres],
    ['code'=>'F2A','lib'=>'Autres comptes de dépôts créditeurs','montant'=>$autresDepotsCrediteurs],
    ['code'=>'F3F','lib'=>'Comptes d\'emprunts à terme (>12 mois)','montant'=>$empruntsTerme],
    ['code'=>'F50','lib'=>'Autres sommes dues aux institutions financières','montant'=>$autresSommesDues],
    ['code'=>'G15','lib'=>'Dépôts à terme reçus (>12 mois)','montant'=>$depotsTerme],
    ['code'=>'G2A','lib'=>'Comptes d\'épargne à régime spécial','montant'=>$epargneSpeciale],
    ['code'=>'G30','lib'=>'Autres dépôts de garantie reçus (>12 mois)','montant'=>$depotsGarantie],
    ['code'=>'G35','lib'=>'Autres dépôts reçus (>12 mois)','montant'=>$autresDepotsRecus],
    ['code'=>'G60','lib'=>'Emprunts (>12 mois)','montant'=>$emprunts],
    ['code'=>'G70','lib'=>'Autres sommes (>12 mois)','montant'=>$autresSommes],
];

$lignesEmplois = [
    ['code'=>'A2H','lib'=>'Dépôts à terme constitués (>12 mois)','montant'=>$depotsTermeConstitués],
    ['code'=>'A2I','lib'=>'Dépôts de garantie constitués (>12 mois)','montant'=>$depotsGarantieConstitués],
    ['code'=>'A2J','lib'=>'Autres dépôts constitués (>12 mois)','montant'=>$autresDepotsConstitués],
    ['code'=>'A3C','lib'=>'Comptes de prêts à terme (>12 mois)','montant'=>$pretsTerme],
    ['code'=>'A70','lib'=>'Prêts en souffrance (>12 mois)','montant'=>$pretsSouffrance],
    ['code'=>'B30','lib'=>'Crédits à moyen terme (13-60 mois)','montant'=>$creditsMoyenTerme],
    ['code'=>'B40','lib'=>'Crédits à long terme (>60 mois)','montant'=>$creditsLongTerme],
    ['code'=>'B70','lib'=>'Crédits en souffrance','montant'=>$creditsSouffrance],
    ['code'=>'D1E','lib'=>'Titres de participation','montant'=>$titresParticipation],
    ['code'=>'D1L','lib'=>'Titres d\'investissement','montant'=>$titresInvestissement],
    ['code'=>'D10','lib'=>'Prêts et titres subordonnés','montant'=>$pretsSubordonnes],
    ['code'=>'D1S','lib'=>'Dépôts et cautionnements','montant'=>$depotsCautionnements],
    ['code'=>'D23','lib'=>'Immobilisations en cours','montant'=>$immobilisationsEnCours],
    ['code'=>'D30','lib'=>'Immobilisations d\'exploitation','montant'=>$immobilisationsExploit],
    ['code'=>'D40','lib'=>'Immobilisations hors exploitation','montant'=>$immobilisationsHorsExploit],
];

// ------------------------- EXPORT PDF AVEC PDF_DIMF -------------------------
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once('../../fpdf/fpdf.php');

    class PDF_DIMF extends FPDF {
        public $codeDimf  = 'R02';
        public $titreDimf = 'COUVERTURE DES EMPLOIS A MOYEN ET LONG TERME PAR DES RESSOURCES STABLES';
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
    $pdf->codeDimf  = 'R02';
    $pdf->titreDimf = 'COUVERTURE DES EMPLOIS A MOYEN ET LONG TERME PAR DES RESSOURCES STABLES';
    $pdf->nomSfd    = 'SFD';
    $pdf->periode   = ucfirst($type_periode);
    $pdf->exercice  = $exercice;
    $pdf->AddPage();

    $cols = [
        ['w' => 30, 'label' => 'Code', 'align' => 'L'],
        ['w' => 100, 'label' => 'Libellé', 'align' => 'L'],
        ['w' => 50, 'label' => 'Montant (FCFA)', 'align' => 'R']
    ];

    // Section A – Ressources stables
    $pdf->SectionTitle("A - RESSOURCES STABLES (durée résiduelle > 12 mois)");
    $pdf->TableHeader($cols);
    foreach ($lignesRessources as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['lib'], PDF_DIMF::montant($row['montant'])]);
    }
    $pdf->TableRow($cols, ['', 'TOTAL RESSOURCES STABLES (A)', PDF_DIMF::montant($ressourcesStables)], 'total');

    $pdf->Ln(5);

    // Section B – Emplois à moyen et long terme
    $pdf->SectionTitle("B - EMPLOIS A MOYEN ET LONG TERME (durée résiduelle > 12 mois, nets provisions)");
    $pdf->TableHeader($cols);
    foreach ($lignesEmplois as $row) {
        $pdf->TableRow($cols, [$row['code'], $row['lib'], PDF_DIMF::montant($row['montant'])]);
    }
    $pdf->TableRow($cols, ['', 'TOTAL EMPLOIS MLT (B)', PDF_DIMF::montant($emploisMLT)], 'total');

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, PDF_DIMF::u("RATIO R02 = A / B = " . number_format($ratioR02, 2)), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, PDF_DIMF::u("Norme BCEAO : Ratio ≥ 1\nConformité : " . $conformite));

    $pdf->Output('I', 'R02_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ------------------------- EXPORT EXCEL (HTML .xls) AVEC FUSIONS -------------------------
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="R02_' . $exercice . '_' . $type_periode . '.xls"');
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
    echo '<h2>R02 - COUVERTURE DES EMPLOIS A MOYEN ET LONG TERME PAR DES RESSOURCES STABLES</h2>';
    echo '<p><strong>Période :</strong> ' . $exercice . ' - ' . ucfirst($type_periode) . ' (arrêtée au ' . date('d/m/Y', strtotime($date_fin_periode)) . ')</p>';

    // Tableau A
    echo '<h3>A - RESSOURCES STABLES (durée résiduelle > 12 mois)</h3>';
    echo '</table>';
    echo '<tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>';
    foreach ($lignesRessources as $r) {
        echo '<tr><td style="width:15%">' . $r['code'] . '</td><td style="width:70%">' . $r['lib'] . '</td><td class="text-right" style="width:15%">' . number_format($r['montant'], 0, ',', ' ') . '</td></tr>';
    }
    echo '<tr class="total-row"><td colspan="2">TOTAL RESSOURCES STABLES (A)</td><td class="text-right">' . number_format($ressourcesStables, 0, ',', ' ') . '</td></tr>';
    echo '</table>';

    // Tableau B
    echo '<h3>B - EMPLOIS A MOYEN ET LONG TERME (durée résiduelle > 12 mois, nets provisions)</h3>';
    echo '<table>';
    echo '<tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>';
    foreach ($lignesEmplois as $e) {
        echo '<tr><td style="width:15%">' . $e['code'] . '</td><td style="width:70%">' . $e['lib'] . '</td><td class="text-right" style="width:15%">' . number_format($e['montant'], 0, ',', ' ') . '</td></tr>';
    }
    echo '<tr class="total-row"><td colspan="2">TOTAL EMPLOIS MLT (B)</td><td class="text-right">' . number_format($emploisMLT, 0, ',', ' ') . '</td></tr>';
    echo '</table>';

    // Ratio
    echo '<p><strong>RATIO R02 = A / B = ' . number_format($ratioR02, 2) . '</strong></p>';
    echo '<p>Norme BCEAO : Ratio ≥ 1<br>Conformité : ' . $conformite . '</p>';
    echo '</body></html>';
    exit;
}

// ------------------------- AFFICHAGE WEB (INTERFACE DIMF_2000) -------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>R02 - Couverture des emplois MLT (BCEAO)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Styles DIMF_2000 (identiques à R01) */
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
            <h1><i class="fas fa-chart-line"></i> R02 - COUVERTURE DES EMPLOIS MLT</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">Norme BCEAO : Ratio ≥ 1</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="location.href='?<?=http_build_query(array_merge($_GET,['export'=>'excel']))?>'"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" onclick="location.href='?<?=http_build_query(array_merge($_GET,['export'=>'pdf']))?>'"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Filtres période (identique à R01) -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres de période</div>
        <div class="filters-row">
            <div class="filter-item"><label>Année</label><select id="exerciceSelect"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
            <div class="filter-item"><label>Type de période</label><select id="typePeriodeSelect"><option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option><option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option><option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option><option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option></select></div>
            <div class="filter-item" id="dynamicSelectContainer"><?php if($type_periode=='mensuel'): ?><label>Mois</label><select id="moisSelect"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m==$mois?'selected':''?>><?=str_pad($m,2,'0',STR_PAD_LEFT)?> - <?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor; ?></select><?php elseif($type_periode=='trimestre'): ?><label>Trimestre</label><select id="trimestreSelect"><?php for($t=1;$t<=4;$t++): ?><option value="<?=$t?>" <?=$t==$trimestre?'selected':''?>><?=$t?><?=$t==1?'er':'ème'?> Trimestre</option><?php endfor; ?></select><?php elseif($type_periode=='semestre'): ?><label>Semestre</label><select id="semestreSelect"><?php for($s=1;$s<=2;$s++): ?><option value="<?=$s?>" <?=$s==$semestre?'selected':''?>><?=$s?><?=$s==1?'er':'e'?> semestre</option><?php endfor; ?></select><?php else: ?><label>Période</label><input type="text" disabled value="Année complète"><?php endif; ?></div>
            <button class="btn-apply" onclick="appliquerFiltres()">Appliquer</button>
        </div>
    </div>

    <!-- Carte ratio -->
    <div class="ratio-card">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:20px;">
            <div><div class="card-header" style="padding:0;">Ratio R02 – Couverture des emplois MLT</div><div class="ratio-value <?=$conformite=='CONFORME'?'conforme':'non-conforme'?>"><?=number_format($ratioR02,2)?></div><div>Ressources stables / Emplois MLT</div></div>
            <div class="norme-box"><div><strong>Norme BCEAO</strong></div><div style="font-size:1.5rem;">≥ 1</div><div>Seuil minimal : 1</div></div>
            <div><span class="badge" style="background:<?=$conformite=='CONFORME'?'#10b981':'#ef4444'?>;"><?=$conformite?></span></div>
        </div>
        <div class="progress-bar"><div class="progress-fill <?=$conformite!='CONFORME'?'non-conforme':''?>" style="width:<?=min($ratioR02/2*100,100)?>%;"><?=number_format($ratioR02,2)?></div></div>
        <div style="margin-top:16px;"><i class="fas fa-calculator"></i> R02 = <?=number_format($ressourcesStables,0,',',' ')?> / <?=number_format($emploisMLT,0,',',' ')?> = <?=number_format($ratioR02,2)?></div>
    </div>

    <!-- Deux colonnes web -->
    <div class="two-columns">
        <div class="card"><div class="card-header"><i class="fas fa-piggy-bank"></i> A – RESSOURCES STABLES</div><div class="table-wrapper"><table><thead><tr><th>Code</th><th>Libellé</th><th class="text-right">Montant</th></tr></thead><tbody><?php foreach($lignesRessources as $r): ?><tr><td><?=$r['code']?></td><td><?=$r['lib']?></td><td class="text-right"><?=number_format($r['montant'],0,',',' ')?></td></tr><?php endforeach; ?><tr class="total-row"><td colspan="2">TOTAL RESSOURCES STABLES (A)</td><td class="text-right"><?=number_format($ressourcesStables,0,',',' ')?></td></tr></tbody></table></div></div>
        <div class="card"><div class="card-header"><i class="fas fa-chart-simple"></i> B – EMPLOIS MLT</div><div class="table-wrapper"><table><thead><tr><th>Code</th><th>Libellé</th><th class="text-right">Montant</th></tr></thead><tbody><?php foreach($lignesEmplois as $e): ?><tr><td><?=$e['code']?></td><td><?=$e['lib']?></td><td class="text-right"><?=number_format($e['montant'],0,',',' ')?></td></tr><?php endforeach; ?><tr class="total-row"><td colspan="2">TOTAL EMPLOIS MLT (B)</td><td class="text-right"><?=number_format($emploisMLT,0,',',' ')?></td></tr></tbody></table></div></div>
    </div>

    <!-- Interprétation -->
    <div class="card"><div class="card-header">Interprétation</div><div class="info-box"><i class="fas fa-gavel"></i><div><?=($conformite=='CONFORME')?'✓ Conforme – Les ressources stables couvrent les emplois à moyen et long terme (ratio '.number_format($ratioR02,2).' ≥ 1).':'⚠️ Non conforme – Les ressources stables sont insuffisantes (ratio '.number_format($ratioR02,2).' < 1).'?></div></div></div>

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
        let url = 'R02.php?exercice=' + document.getElementById('exerciceSelect').value + '&type_periode=' + document.getElementById('typePeriodeSelect').value;
        let type = document.getElementById('typePeriodeSelect').value;
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        else if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        else if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        window.location.href = url;
    }
    document.addEventListener('DOMContentLoaded', function() { updateDynamicSelect(); document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect); });
</script>
</body>
</html>