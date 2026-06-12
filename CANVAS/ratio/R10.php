<?php
// R10.php - Financement des immobilisations et des participations
// Norme BCEAO: 0% à 100% (0 - 1)

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

// ------------------------- A1 - IMMOBILISATIONS NETTES (valeurs nettes des amortissements) -------------------------
// D24 - Immobilisations incorporelles en cours
$immobilisationsIncorpEnCours = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_achat - amortissement_total),0) as valeur_nette
        FROM immobilisations
        WHERE type_immobilisation = 'Immobilisations incorporelles'
          AND statut = 'actif' AND date_achat <= :date_fin
          AND (libelle LIKE '%en cours%' OR libelle LIKE '%projet%')
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $immobilisationsIncorpEnCours = (float)$stmt->fetch()['valeur_nette'];
} catch (PDOException $e) {}

// D25 - Immobilisations corporelles en cours
$immobilisationsCorpEnCours = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_achat - amortissement_total),0) as valeur_nette
        FROM immobilisations
        WHERE type_immobilisation = 'Immobilisations corporelles'
          AND statut = 'actif' AND date_achat <= :date_fin
          AND (libelle LIKE '%en cours%' OR libelle LIKE '%projet%')
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $immobilisationsCorpEnCours = (float)$stmt->fetch()['valeur_nette'];
} catch (PDOException $e) {}

// D31 - Immobilisations incorporelles d'exploitation
$immobilisationsIncorpExploit = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_achat - amortissement_total),0) as valeur_nette
        FROM immobilisations
        WHERE type_immobilisation = 'Immobilisations incorporelles'
          AND statut = 'actif' AND date_achat <= :date_fin
          AND (libelle NOT LIKE '%en cours%' AND libelle NOT LIKE '%projet%')
          AND (libelle NOT LIKE '%hors exploitation%')
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $immobilisationsIncorpExploit = (float)$stmt->fetch()['valeur_nette'];
} catch (PDOException $e) {}

// D36 - Immobilisations corporelles d'exploitation
$immobilisationsCorpExploit = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_achat - amortissement_total),0) as valeur_nette
        FROM immobilisations
        WHERE type_immobilisation = 'Immobilisations corporelles'
          AND statut = 'actif' AND date_achat <= :date_fin
          AND (libelle NOT LIKE '%en cours%' AND libelle NOT LIKE '%projet%')
          AND (libelle NOT LIKE '%terrain%' AND libelle NOT LIKE '%immobilier%' AND libelle NOT LIKE '%hors exploitation%')
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $immobilisationsCorpExploit = (float)$stmt->fetch()['valeur_nette'];
} catch (PDOException $e) {}

// D41 - Immobilisations incorporelles hors exploitation
$immobilisationsIncorpHorsExploit = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_achat - amortissement_total),0) as valeur_nette
        FROM immobilisations
        WHERE type_immobilisation = 'Immobilisations incorporelles'
          AND statut = 'actif' AND date_achat <= :date_fin
          AND (libelle LIKE '%hors exploitation%')
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $immobilisationsIncorpHorsExploit = (float)$stmt->fetch()['valeur_nette'];
} catch (PDOException $e) {}

// D45 - Immobilisations corporelles hors exploitation
$immobilisationsCorpHorsExploit = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_achat - amortissement_total),0) as valeur_nette
        FROM immobilisations
        WHERE type_immobilisation = 'Immobilisations corporelles'
          AND statut = 'actif' AND date_achat <= :date_fin
          AND (libelle LIKE '%terrain%' OR libelle LIKE '%immobilier%' OR libelle LIKE '%hors exploitation%')
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $immobilisationsCorpHorsExploit = (float)$stmt->fetch()['valeur_nette'];
} catch (PDOException $e) {}

// D46 - Immobilisations incorporelles par réalisation de garantie
$immobilisationsIncorpGarantie = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_achat - amortissement_total),0) as valeur_nette
        FROM immobilisations
        WHERE type_immobilisation = 'Immobilisations incorporelles'
          AND statut = 'actif' AND date_achat <= :date_fin
          AND libelle LIKE '%garantie%'
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $immobilisationsIncorpGarantie = (float)$stmt->fetch()['valeur_nette'];
} catch (PDOException $e) {}

// D47 - Immobilisations corporelles par réalisation de garantie
$immobilisationsCorpGarantie = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_achat - amortissement_total),0) as valeur_nette
        FROM immobilisations
        WHERE type_immobilisation = 'Immobilisations corporelles'
          AND statut = 'actif' AND date_achat <= :date_fin
          AND libelle LIKE '%garantie%'
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $immobilisationsCorpGarantie = (float)$stmt->fetch()['valeur_nette'];
} catch (PDOException $e) {}

$totalImmobilisationsNettes = $immobilisationsIncorpEnCours + $immobilisationsCorpEnCours
                            + $immobilisationsIncorpExploit + $immobilisationsCorpExploit
                            + $immobilisationsIncorpHorsExploit + $immobilisationsCorpHorsExploit
                            + $immobilisationsIncorpGarantie + $immobilisationsCorpGarantie;

// ------------------------- A2 - TITRES DE PARTICIPATION (nets) -------------------------
// D1E - Titres de participation (comptes 26)
$titresParticipation = 0;
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

// Participations dans les SFD (à déduire)
$participationsSFD = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit),0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '261%' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $participationsSFD = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $participationsSFD = 0; }

$titresParticipationNets = $titresParticipation - $participationsSFD;
if ($titresParticipationNets < 0) $titresParticipationNets = 0;

// Numérateur total (A)
$montantA = $totalImmobilisationsNettes + $titresParticipationNets;

// ------------------------- B - FONDS PROPRES (identique à R08/R09) -------------------------
$fondsPropPositifs = 0;

// L10
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '13%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $subventions = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $subventions;
} catch (PDOException $e) {}
// L20
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '14%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $fondsAffectes = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $fondsAffectes;
} catch (PDOException $e) {}
// L27
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '15%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $fondsCredit = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $fondsCredit;
} catch (PDOException $e) {}
// L30
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '17%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $provisionsRisques = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $provisionsRisques;
} catch (PDOException $e) {}
// L35
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '18%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $provisionsReglementees = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $provisionsReglementees;
} catch (PDOException $e) {}
// L41
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '16%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $empruntsSubordonnes = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $empruntsSubordonnes;
} catch (PDOException $e) {}
// L45
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '19%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $fondsRisques = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $fondsRisques;
} catch (PDOException $e) {}
// L50
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '102%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $primesCapital = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $primesCapital;
} catch (PDOException $e) {}
// L55
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '106%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $reserves = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $reserves;
} catch (PDOException $e) {}
// L59
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '107%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $ecartReeval = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $ecartReeval;
} catch (PDOException $e) {}
// L60
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '101%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $capital = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $capital;
} catch (PDOException $e) {
    try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant),0) as total FROM capital WHERE statut='valide' AND date_creation <= :date_fin"); $stmt->execute([':date_fin'=>$date_fin_periode]); $capital = (float)$stmt->fetch()['total']; $fondsPropPositifs += $capital; } catch (PDOException $e2) {}
}
// L65
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '103%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $fondsDotation = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $fondsDotation;
} catch (PDOException $e) {}
// L70 report positif
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN (montant_credit - montant_debit) > 0 THEN montant_credit - montant_debit ELSE 0 END),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '11%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $reportPositif = (float)$stmt->fetch()['solde']; $fondsPropPositifs += $reportPositif;
} catch (PDOException $e) {}
// L75 (compte 120)
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_credit - montant_debit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte = '120' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $excedentProduits = (float)$stmt->fetch()['solde']; if ($excedentProduits > 0) $fondsPropPositifs += $excedentProduits;
} catch (PDOException $e) {}
// L80 bénéfice
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN pc.classe_compte='7' THEN montant_credit - montant_debit ELSE 0 END),0) as produits, COALESCE(SUM(CASE WHEN pc.classe_compte='6' THEN montant_debit - montant_credit ELSE 0 END),0) as charges FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte IN ('6','7') AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut'=>$date_debut_exercice, ':fin'=>$date_fin_exercice]); $res = $stmt->fetch(); $resultatBrut = $res['produits'] - $res['charges']; if ($resultatBrut > 0) { $resultatExercice = (float)$resultatBrut; $fondsPropPositifs += $resultatExercice; }
} catch (PDOException $e) {}

// Déductions
$fondsPropDeductions = 0;
// L62
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_debit - montant_credit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte = '109' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $capitalNonAppele = abs((float)$stmt->fetch()['solde']); $fondsPropDeductions += $capitalNonAppele;
} catch (PDOException $e) {}
// E05
if (isset($resultatBrut) && $resultatBrut < 0) { $excedentCharges = abs($resultatBrut); $fondsPropDeductions += $excedentCharges; }
// Immobilisations incorporelles nettes (à déduire des fonds propres)
$immobilisationsIncorpNettes = $immobilisationsIncorpEnCours + $immobilisationsIncorpExploit + $immobilisationsIncorpHorsExploit + $immobilisationsIncorpGarantie;
$fondsPropDeductions += $immobilisationsIncorpNettes;
// Report négatif
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN (montant_credit - montant_debit) < 0 THEN ABS(montant_credit - montant_debit) ELSE 0 END),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '11%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $reportNegatif = (float)$stmt->fetch()['solde']; $fondsPropDeductions += $reportNegatif;
} catch (PDOException $e) {}
// Z52
$provisionsNonConst = isset($_GET['provisions_non_const']) ? (float)$_GET['provisions_non_const'] : 0;
$fondsPropDeductions += $provisionsNonConst;
// Z53 (participations dans d'autres SFD) – déduction des fonds propres
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_debit - montant_credit),0) as solde FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '261%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin'=>$date_fin_periode]); $participationsSFD_fp = (float)$stmt->fetch()['solde']; $fondsPropDeductions += $participationsSFD_fp;
} catch (PDOException $e) {}

$fondsPropres = $fondsPropPositifs - $fondsPropDeductions;
if ($fondsPropres < 0) $fondsPropres = 0;
if ($fondsPropres <= 0) $fondsPropres = 1;

// ------------------------- RATIO R10 -------------------------
$ratioR10 = $montantA / $fondsPropres;
$pourcentage = $ratioR10 * 100;
$normeMin = 0;
$normeMax = 1;
$conformite = ($ratioR10 >= $normeMin && $ratioR10 <= $normeMax) ? 'CONFORME' : 'NON_CONFORME';

// Détails pour l'affichage
$lignesImmobilisations = [
    ['code'=>'D24','lib'=>'Immobilisations incorporelles en cours','montant'=>$immobilisationsIncorpEnCours],
    ['code'=>'D25','lib'=>'Immobilisations corporelles en cours','montant'=>$immobilisationsCorpEnCours],
    ['code'=>'D31','lib'=>'Immobilisations incorporelles d\'exploitation','montant'=>$immobilisationsIncorpExploit],
    ['code'=>'D36','lib'=>'Immobilisations corporelles d\'exploitation','montant'=>$immobilisationsCorpExploit],
    ['code'=>'D41','lib'=>'Immobilisations incorporelles hors exploitation','montant'=>$immobilisationsIncorpHorsExploit],
    ['code'=>'D45','lib'=>'Immobilisations corporelles hors exploitation','montant'=>$immobilisationsCorpHorsExploit],
    ['code'=>'D46','lib'=>'Immobilisations incorporelles par réalisation de garantie','montant'=>$immobilisationsIncorpGarantie],
    ['code'=>'D47','lib'=>'Immobilisations corporelles par réalisation de garantie','montant'=>$immobilisationsCorpGarantie],
];
$lignesParticipations = [
    ['code'=>'D1E','lib'=>'Titres de participation','montant'=>$titresParticipation],
    ['code'=>'','lib'=>'Participations dans SFD (déduction)','montant'=>$participationsSFD],
];
$lignesPositifsFP = [
    ['code'=>'L10','lib'=>'Subventions d\'investissement','montant'=>$subventions],
    ['code'=>'L20','lib'=>'Fonds affectés','montant'=>$fondsAffectes],
    ['code'=>'L27','lib'=>'Fonds de crédit','montant'=>$fondsCredit],
    ['code'=>'L30','lib'=>'Provisions pour risques et charges','montant'=>$provisionsRisques],
    ['code'=>'L35','lib'=>'Provisions réglementées','montant'=>$provisionsReglementees],
    ['code'=>'L41','lib'=>'Emprunts et titres émis subordonnés','montant'=>$empruntsSubordonnes],
    ['code'=>'L45','lib'=>'Fonds pour risques financiers généraux','montant'=>$fondsRisques],
    ['code'=>'L50','lib'=>'Primes liées au capital','montant'=>$primesCapital],
    ['code'=>'L55','lib'=>'Réserves','montant'=>$reserves],
    ['code'=>'L59','lib'=>'Écart de réévaluation des immobilisations','montant'=>$ecartReeval],
    ['code'=>'L60','lib'=>'Capital','montant'=>$capital],
    ['code'=>'L65','lib'=>'Fonds de dotation','montant'=>$fondsDotation],
    ['code'=>'L70','lib'=>'Report à nouveau positif','montant'=>$reportPositif],
    ['code'=>'L75','lib'=>'Excédent des produits sur les charges','montant'=>$excedentProduits>0?$excedentProduits:0],
    ['code'=>'L80','lib'=>'Résultat excédentaire de l\'exercice','montant'=>$resultatExercice],
];
$lignesDeductionsFP = [
    ['code'=>'L62','lib'=>'Capital non appelé','montant'=>$capitalNonAppele],
    ['code'=>'E05','lib'=>'Excédent des charges sur les produits','montant'=>$excedentCharges],
    ['code'=>'D24/31/41/46','lib'=>'Immobilisations incorporelles nettes','montant'=>$immobilisationsIncorpNettes],
    ['code'=>'L70','lib'=>'Report à nouveau négatif','montant'=>$reportNegatif],
    ['code'=>'Z52','lib'=>'Complément de provisions non constituées','montant'=>$provisionsNonConst],
    ['code'=>'Z53','lib'=>'Participations dans d\'autres SFD','montant'=>$participationsSFD_fp??0],
];

// ------------------------- EXPORT PDF -------------------------
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once('../../fpdf/fpdf.php');
    class PDF_DIMF extends FPDF {
        public $codeDimf  = 'R10';
        public $titreDimf = 'FINANCEMENT DES IMMOBILISATIONS ET DES PARTICIPATIONS';
        public $nomSfd    = 'SFD';
        public $periode   = '';
        public $exercice  = '';
        static function u($str) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str); }
        function Header() {
            $this->SetFillColor(156,163,175); $this->Rect(0,0,$this->GetPageWidth(),28,'F');
            $this->SetFont('Arial','',7); $this->SetTextColor(255,255,255); $this->SetXY(8,3);
            $this->Cell(0,4,self::u('République de Côte d\'Ivoire  •  Ministère de l\'Economie et des Finances  -  DGTCP / DSFD'),0,1,'L');
            $this->SetFont('Arial','B',13); $this->SetTextColor(255,255,255); $this->SetX(8);
            $this->Cell(0,7,self::u($this->codeDimf.'  -  '.$this->titreDimf),0,1,'L');
            $this->SetFont('Arial','',8); $this->SetTextColor(255,255,255); $this->SetX(8);
            $this->Cell(0,5,self::u('SFD : '.$this->nomSfd.'   |   Période : '.$this->periode.'   |   Exercice : '.$this->exercice.'   |   Arrêté au : '.date('d/m/Y',strtotime($GLOBALS['date_fin_periode']))),0,1,'L');
            $this->SetTextColor(0,0,0); $this->Ln(4);
        }
        function Footer() {
            $this->SetY(-12); $this->SetFont('Arial','I',7); $this->SetTextColor(100,116,139);
            $this->Cell(0,4,self::u('SICS-BCEAO  •  Généré le '.date('d/m/Y H:i:s').'  •  Page '.$this->PageNo().'/{nb}'),0,0,'C');
        }
        function SectionTitle($label) {
            $this->SetFont('Arial','B',9); $this->SetFillColor(0,0,0); $this->SetTextColor(255,255,255);
            $this->Cell(0,7,self::u('  '.strtoupper($label)),0,1,'L',true); $this->SetTextColor(0,0,0); $this->Ln(1);
        }
        function TableHeader($cols) {
            $this->SetFont('Arial','B',8); $this->SetFillColor(248,250,252); $this->SetTextColor(30,41,59);
            $this->SetDrawColor(226,232,240); $this->SetLineWidth(0.2);
            foreach ($cols as $col) { $this->Cell($col['w'],6,self::u($col['label']),1,0,$col['align']??'L',true); }
            $this->Ln();
        }
        function TableRow($cols, $data, $style='') {
            $fill = false;
            if ($style=='subtotal') { $this->SetFillColor(248,250,252); $this->SetFont('Arial','B',8); $fill = true; }
            elseif ($style=='total') { $this->SetFillColor(240,253,244); $this->SetFont('Arial','B',8.5); $fill = true; }
            else { $this->SetFillColor(255,255,255); $this->SetFont('Arial','',7.5); $fill = false; }
            $this->SetTextColor(15,23,42); $this->SetDrawColor(226,232,240); $this->SetLineWidth(0.1);
            foreach ($cols as $i=>$col) {
                $val = isset($data[$i]) ? $data[$i] : '';
                $this->Cell($col['w'],5.5,self::u($val),1,0,$col['align']??'L',$fill);
            }
            $this->Ln();
        }
        static function montant($val) { return number_format((float)$val,0,',',' ').' F'; }
    }
    $pdf = new PDF_DIMF(); $pdf->AliasNbPages();
    $pdf->codeDimf = 'R10'; $pdf->titreDimf = 'FINANCEMENT DES IMMOBILISATIONS ET DES PARTICIPATIONS';
    $pdf->nomSfd = 'SFD'; $pdf->periode = ucfirst($type_periode); $pdf->exercice = $exercice;
    $pdf->AddPage();
    $cols = [['w'=>30,'label'=>'Code','align'=>'L'],['w'=>100,'label'=>'Libellé','align'=>'L'],['w'=>50,'label'=>'Montant (FCFA)','align'=>'R']];
    
    // Section A – Immobilisations
    $pdf->SectionTitle("A - IMMOBILISATIONS NETTES (valeurs nettes)");
    $pdf->TableHeader($cols);
    foreach ($lignesImmobilisations as $r) { $pdf->TableRow($cols, [$r['code'], $r['lib'], PDF_DIMF::montant($r['montant'])]); }
    $pdf->TableRow($cols, ['', 'TOTAL IMMOBILISATIONS NETTES', PDF_DIMF::montant($totalImmobilisationsNettes)], 'subtotal');
    
    // Titres de participation
    $pdf->SectionTitle("Titres de participation");
    $pdf->TableHeader($cols);
    foreach ($lignesParticipations as $r) { $pdf->TableRow($cols, [$r['code'], $r['lib'], PDF_DIMF::montant($r['montant'])]); }
    $pdf->TableRow($cols, ['', 'TITRES DE PARTICIPATION NETS', PDF_DIMF::montant($titresParticipationNets)], 'subtotal');
    $pdf->TableRow($cols, ['', 'TOTAL (A) = Immobilisations nettes + Participations nettes', PDF_DIMF::montant($montantA)], 'total');
    $pdf->Ln(5);
    
    // Section B – Fonds propres
    $pdf->SectionTitle("B - FONDS PROPRES");
    $pdf->TableHeader($cols);
    foreach ($lignesPositifsFP as $r) { $pdf->TableRow($cols, [$r['code'], $r['lib'], PDF_DIMF::montant($r['montant'])]); }
    $pdf->TableRow($cols, ['', 'TOTAL ÉLÉMENTS POSITIFS', PDF_DIMF::montant($fondsPropPositifs)], 'subtotal');
    foreach ($lignesDeductionsFP as $r) { $pdf->TableRow($cols, [$r['code'], $r['lib'], PDF_DIMF::montant($r['montant'])]); }
    $pdf->TableRow($cols, ['', 'TOTAL DÉDUCTIONS', PDF_DIMF::montant($fondsPropDeductions)], 'subtotal');
    $pdf->TableRow($cols, ['', 'FONDS PROPRES (B)', PDF_DIMF::montant($fondsPropres)], 'total');
    $pdf->Ln(5);
    $pdf->SetFont('Arial','B',10); $pdf->Cell(0,7,PDF_DIMF::u("RATIO R10 = A / B = ".number_format($pourcentage,2)."%"),0,1);
    $pdf->SetFont('Arial','',9); $pdf->MultiCell(0,5,PDF_DIMF::u("Norme BCEAO : 0% ≤ Ratio ≤ 100%\nConformité : ".$conformite));
    $pdf->Output('I','R10_'.$exercice.'_'.$type_periode.'.pdf'); exit;
}

// ------------------------- EXPORT EXCEL -------------------------
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="R10_'.$exercice.'_'.$type_periode.'.xls"');
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
        .col-code { width: 15%; }
        .col-libelle { width: 70%; }
        .col-montant { width: 15%; }
    </style></head><body>';
    echo '<h2>R10 - FINANCEMENT DES IMMOBILISATIONS ET DES PARTICIPATIONS</h2>';
    echo '<p><strong>Période :</strong> ' . $exercice . ' - ' . ucfirst($type_periode) . ' (arrêtée au ' . date('d/m/Y', strtotime($date_fin_periode)) . ')</p>';
    
    // Tableau A – Immobilisations
    echo '<h3>A - IMMOBILISATIONS NETTES</h3>';
    echo '<table>';
    echo '<tr><th class="col-code">Code</th><th class="col-libelle">Libellé</th><th class="col-montant text-right">Montant (FCFA)</th></tr>';
    foreach ($lignesImmobilisations as $r) {
        echo '<tr><td class="col-code">'.$r['code'].'</td><td class="col-libelle">'.$r['lib'].'</td><td class="col-montant text-right">'.number_format($r['montant'],0,',',' ').'</td></tr>';
    }
    echo '<tr class="subtotal-row"><td colspan="2">TOTAL IMMOBILISATIONS NETTES</td><td class="text-right">'.number_format($totalImmobilisationsNettes,0,',',' ').'</td></tr>';
    echo '</table>';
    
    // Tableau A2 – Titres de participation
    echo '<h3>Titres de participation</h3>';
    echo '<table>';
    echo '<tr><th class="col-code">Code</th><th class="col-libelle">Libellé</th><th class="col-montant text-right">Montant (FCFA)</th></tr>';
    foreach ($lignesParticipations as $r) {
        echo '<tr><td class="col-code">'.$r['code'].'</td><td class="col-libelle">'.$r['lib'].'</td><td class="col-montant text-right">'.number_format($r['montant'],0,',',' ').'</td></tr>';
    }
    echo '<tr class="subtotal-row"><td colspan="2">TITRES DE PARTICIPATION NETS</td><td class="text-right">'.number_format($titresParticipationNets,0,',',' ').'</td></tr>';
    echo '<tr class="total-row"><td colspan="2">TOTAL (A) = Immobilisations nettes + Participations nettes</td><td class="text-right">'.number_format($montantA,0,',',' ').'</td></tr>';
    echo '</table>';
    
    // Tableau B – Fonds propres
    echo '<h3>B - FONDS PROPRES</h3>';
    echo '<td>';
    echo '<tr><th class="col-code">Code</th><th class="col-libelle">Libellé</th><th class="col-montant text-right">Montant (FCFA)</th></tr>';
    foreach ($lignesPositifsFP as $r) {
        echo '<tr><td class="col-code">'.$r['code'].'</td><td class="col-libelle">'.$r['lib'].'</td><td class="col-montant text-right">'.number_format($r['montant'],0,',',' ').'</td></tr>';
    }
    echo '<tr class="subtotal-row"><td colspan="2">TOTAL ÉLÉMENTS POSITIFS</td><td class="text-right">'.number_format($fondsPropPositifs,0,',',' ').'</td></tr>';
    foreach ($lignesDeductionsFP as $r) {
        echo '<tr><td class="col-code">'.$r['code'].'</td><td class="col-libelle">'.$r['lib'].'</td><td class="col-montant text-right">'.number_format($r['montant'],0,',',' ').'</td></tr>';
    }
    echo '<tr class="subtotal-row"><td colspan="2">TOTAL DÉDUCTIONS</td><td class="text-right">'.number_format($fondsPropDeductions,0,',',' ').'</td></tr>';
    echo '<tr class="total-row"><td colspan="2">FONDS PROPRES (B)</td><td class="text-right">'.number_format($fondsPropres,0,',',' ').'</td></tr>';
    echo '</table>';
    
    echo '<p><strong>RATIO R10 = A / B = '.number_format($pourcentage,2).'%</strong></p>';
    echo '<p>Norme BCEAO : 0% à 100% (ne doit pas dépasser 100%)<br>Conformité : '.$conformite.'</p>';
    echo '</body></html>'; exit;
}

// ------------------------- AFFICHAGE WEB -------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>R10 - Financement des immobilisations et participations (BCEAO)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .progress-fill.non-conforme { background:linear-gradient(90deg,#ef4444,#f97316); }
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
        .col-code { width: 15%; }
        .col-libelle { width: 70%; }
        .col-montant { width: 15%; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-building"></i> R10 - FINANCEMENT DES IMMOBILISATIONS & PARTICIPATIONS</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">Norme BCEAO : 0% ≤ Ratio ≤ 100%</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="location.href='?<?=http_build_query(array_merge($_GET,['export'=>'excel']))?>'"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" onclick="location.href='?<?=http_build_query(array_merge($_GET,['export'=>'pdf']))?>'"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Filtres période + Z52 -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres de période</div>
        <div class="filters-row">
            <div class="filter-item"><label>Année</label><select id="exerciceSelect"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
            <div class="filter-item"><label>Type de période</label><select id="typePeriodeSelect"><option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option><option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option><option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option><option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option></select></div>
            <div class="filter-item" id="dynamicSelectContainer"><?php if($type_periode=='mensuel'): ?><label>Mois</label><select id="moisSelect"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m==$mois?'selected':''?>><?=str_pad($m,2,'0',STR_PAD_LEFT)?> - <?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor; ?></select><?php elseif($type_periode=='trimestre'): ?><label>Trimestre</label><select id="trimestreSelect"><?php for($t=1;$t<=4;$t++): ?><option value="<?=$t?>" <?=$t==$trimestre?'selected':''?>><?=$t?><?=$t==1?'er':'ème'?> Trimestre</option><?php endfor; ?></select><?php elseif($type_periode=='semestre'): ?><label>Semestre</label><select id="semestreSelect"><?php for($s=1;$s<=2;$s++): ?><option value="<?=$s?>" <?=$s==$semestre?'selected':''?>><?=$s?><?=$s==1?'er':'e'?> semestre</option><?php endfor; ?></select><?php else: ?><label>Période</label><input type="text" disabled value="Année complète"><?php endif; ?></div>
            <button class="btn-apply" onclick="appliquerFiltres()">Appliquer</button>
        </div>
        <div style="margin-top:12px; padding:8px; background:#fefce8; border-radius:12px;">
            <form method="get" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
                <div class="filter-item"><label>Z52 - Provisions non constituées</label><input type="number" name="provisions_non_const" value="<?=$provisionsNonConst?>" step="1000" style="width:180px;"></div>
                <input type="hidden" name="exercice" value="<?=$exercice?>">
                <input type="hidden" name="type_periode" value="<?=$type_periode?>">
                <?php if($type_periode=='mensuel') echo '<input type="hidden" name="mois" value="'.$mois.'">'; ?>
                <?php if($type_periode=='trimestre') echo '<input type="hidden" name="trimestre" value="'.$trimestre.'">'; ?>
                <?php if($type_periode=='semestre') echo '<input type="hidden" name="semestre" value="'.$semestre.'">'; ?>
                <button type="submit" class="btn-apply" style="background:#eab308;">Mettre à jour</button>
            </form>
            <div style="font-size:0.7rem; color:#6b7280;">Provisions supplémentaires exigées par le superviseur (BCEAO).</div>
        </div>
    </div>

    <!-- Carte ratio -->
    <div class="ratio-card">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:20px;">
            <div><div class="card-header" style="padding:0;">Ratio R10 – Financement des immobilisations et participations</div><div class="ratio-value <?=$conformite=='CONFORME'?'conforme':'non-conforme'?>"><?=number_format($pourcentage,2)?>%</div><div>(Immobilisations nettes + Participations) / Fonds propres</div></div>
            <div class="norme-box"><div><strong>Norme BCEAO</strong></div><div style="font-size:1.5rem;">0% → 100%</div><div>Ne doit pas dépasser 100%</div></div>
            <div><span class="badge" style="background:<?=$conformite=='CONFORME'?'#10b981':'#ef4444'?>;"><?=$conformite?></span></div>
        </div>
        <div class="progress-bar"><div class="progress-fill <?=$conformite!='CONFORME'?'non-conforme':''?>" style="width:<?=min($pourcentage,100)?>%;"><?=number_format($pourcentage,1)?>%</div></div>
        <div style="margin-top:16px;"><i class="fas fa-calculator"></i> R10 = <?=number_format($montantA,0,',',' ')?> / <?=number_format($fondsPropres,0,',',' ')?> = <?=number_format($pourcentage,2)?>%</div>
    </div>

    <!-- Deux colonnes web -->
    <div class="two-columns">
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-simple"></i> A – IMMOBILISATIONS NETTES + PARTICIPATIONS</div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th class="col-code">Catégorie</th><th class="col-libelle">Libellé</th><th class="col-montant text-right">Montant</th></tr></thead>
                    <tbody>
                        <?php foreach($lignesImmobilisations as $r): ?>
                        <tr><td class="col-code"><?=$r['code']?></td><td class="col-libelle"><?=$r['lib']?></td><td class="col-montant text-right"><?=number_format($r['montant'],0,',',' ')?></td></tr>
                        <?php endforeach; ?>
                        <tr class="subtotal-row"><td colspan="2">TOTAL IMMOBILISATIONS NETTES</td><td class="text-right"><?=number_format($totalImmobilisationsNettes,0,',',' ')?></td></tr>
                        <?php foreach($lignesParticipations as $r): ?>
                        <tr><td class="col-code"><?=$r['code']?></td><td class="col-libelle"><?=$r['lib']?></td><td class="col-montant text-right"><?=number_format($r['montant'],0,',',' ')?></td></tr>
                        <?php endforeach; ?>
                        <tr class="subtotal-row"><td colspan="2">TITRES DE PARTICIPATION NETS</td><td class="text-right"><?=number_format($titresParticipationNets,0,',',' ')?></td></tr>
                        <tr class="total-row"><td colspan="2">TOTAL (A)</td><td class="text-right"><?=number_format($montantA,0,',',' ')?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="fas fa-landmark"></i> B – FONDS PROPRES</div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th class="col-code">Code</th><th class="col-libelle">Libellé</th><th class="col-montant text-right">Montant</th></tr></thead>
                    <tbody>
                        <?php foreach($lignesPositifsFP as $r): ?>
                        <tr><td class="col-code"><?=$r['code']?></td><td class="col-libelle"><?=$r['lib']?></td><td class="col-montant text-right"><?=number_format($r['montant'],0,',',' ')?></td></tr>
                        <?php endforeach; ?>
                        <tr class="total-row"><td colspan="2">FONDS PROPRES (B)</td><td class="text-right"><?=number_format($fondsPropres,0,',',' ')?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Interprétation -->
    <div class="card"><div class="card-header">Interprétation</div><div class="info-box"><i class="fas fa-gavel"></i><div><?=($conformite=='CONFORME')?'✓ Conforme – Le financement des immobilisations et participations par les fonds propres représente '.number_format($pourcentage,2).'% (≤100%).':'⚠️ Non conforme – Ce taux dépasse 100%, l\'institution finance ses immobilisations par des dettes.'?></div></div></div>

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
        let url = 'R10.php?exercice=' + document.getElementById('exerciceSelect').value + '&type_periode=' + document.getElementById('typePeriodeSelect').value;
        let type = document.getElementById('typePeriodeSelect').value;
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        else if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        else if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        let prov = document.querySelector('input[name="provisions_non_const"]') ? document.querySelector('input[name="provisions_non_const"]').value : <?=$provisionsNonConst?>;
        if (prov > 0) url += '&provisions_non_const=' + prov;
        window.location.href = url;
    }
    document.addEventListener('DOMContentLoaded', function() { updateDynamicSelect(); document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect); });
</script>
</body>
</html>