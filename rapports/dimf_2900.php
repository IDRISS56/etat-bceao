<?php
// DIMF_2900.php - Bilan consolidé (Actif, Passif, Hors Bilan)
// Version conforme au fichier Excel DIMF_2900.xlsx
// Utilise les tables existantes, ne crée aucune nouvelle table

session_start();

require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ============================================================
// PARAMÈTRES (POST / GET)
// ============================================================
$exercice     = isset($_POST['exercice'])     ? (int)$_POST['exercice']     : (isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode']      : (isset($_GET['type_periode']) ? $_GET['type_periode'] : 'mensuel');
$mois         = isset($_POST['mois'])         ? (int)$_POST['mois']         : (isset($_GET['mois']) ? (int)$_GET['mois'] : 12);
$trimestre    = isset($_POST['trimestre'])    ? (int)$_POST['trimestre']    : (isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4);
$semestre     = isset($_POST['semestre'])     ? (int)$_POST['semestre']     : (isset($_GET['semestre']) ? (int)$_GET['semestre'] : null);
$format       = isset($_POST['format'])       ? $_POST['format']            : (isset($_GET['format']) ? $_GET['format'] : 'html');

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
}
$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$date_debut_exercice = $exercice . '-01-01';

$lib_periode = match($type_periode) {
    'mensuel'   => 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice,
    'trimestre' => $trimestre . 'e Trim. ' . $exercice,
    'semestre'  => $semestre . 'er Sem. ' . $exercice,
    default     => 'Année ' . $exercice,
};

// ============================================================
// FONCTIONS D'EXTRACTION DES DONNÉES
// ============================================================

/** Récupère le solde total des caisses ouvertes */
function getCaisse() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT COALESCE(SUM(solde_actuel), 0) as total FROM caisses WHERE statut = 'ouverte'");
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère le solde total des comptes positifs (créances) */
function getComptesPositifs() {
    global $pdo, $date_fin_periode;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde), 0) as total FROM comptes WHERE solde > 0 AND statut = 'actif' AND date_ouverture <= :date_fin");
        $stmt->execute([':date_fin' => $date_fin_periode]);
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère le solde total des comptes négatifs (dettes) */
function getComptesNegatifs() {
    global $pdo, $date_fin_periode;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(solde)), 0) as total FROM comptes WHERE solde < 0 AND statut = 'actif' AND date_ouverture <= :date_fin");
        $stmt->execute([':date_fin' => $date_fin_periode]);
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère l'encours des crédits (dossiers actifs) */
function getCreditsOrdinaires() {
    global $pdo, $date_fin_periode;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
            FROM dossiers d
            LEFT JOIN (
                SELECT dossier_id, SUM(montant) as rembourse
                FROM echeances
                WHERE statut = 'payee'
                GROUP BY dossier_id
            ) e ON d.dossier_id = e.dossier_id
            WHERE d.statut IN ('actif', 'approuve') AND d.date_octroi <= :date_fin
        ");
        $stmt->execute([':date_fin' => $date_fin_periode]);
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère les immobilisations par type */
function getImmobilisations($type) {
    global $pdo, $date_fin_periode;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(montant_achat - amortissement_total), 0) as total
            FROM immobilisations
            WHERE type_immobilisation LIKE :type AND statut = 'actif' AND date_achat <= :date_fin
        ");
        $stmt->execute([':type' => '%' . $type . '%', ':date_fin' => $date_fin_periode]);
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère le capital (total des apports) */
function getCapitalTotal() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT COALESCE(SUM(montant), 0) as total FROM capital WHERE statut = 'valide'");
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère les subventions d'investissement (capital avec libellé 'subvention') */
function getSubventions() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM capital WHERE statut = 'valide' AND LOWER(libelle) LIKE '%subvention%'");
        $stmt->execute();
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère les emprunts (capital avec mode_paiement = 'BANQUE') */
function getEmprunts() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM capital WHERE statut = 'valide' AND mode_paiement = 'BANQUE'");
        $stmt->execute();
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère les provisions actives */
function getProvisions() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT COALESCE(SUM(montant), 0) as total FROM provisions WHERE statut = 'actif'");
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère l'épargne à vue (comptes d'épargne actifs) */
function getEpargneVue() {
    global $pdo, $date_fin_periode;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(c.solde), 0) as total
            FROM comptes c
            INNER JOIN produits p ON c.produit_id = p.produit_id
            INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
            WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0 AND c.date_ouverture <= :date_fin
        ");
        $stmt->execute([':date_fin' => $date_fin_periode]);
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère l'épargne à terme (comptes DAT) */
function getEpargneTerme() {
    global $pdo, $date_fin_periode;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital_initial), 0) as total FROM comptes_dat WHERE statut = 'en cours' AND date_ouverture <= :date_fin");
        $stmt->execute([':date_fin' => $date_fin_periode]);
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère le report à nouveau (compte 11) */
function getReportNouveau() {
    global $pdo, $date_fin_periode;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
            FROM ecritures_comptables e
            INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
            WHERE pc.numero_compte LIKE '11%' AND e.date_ecriture <= :date_fin AND e.statut = 'VALIDÉE'
        ");
        $stmt->execute([':date_fin' => $date_fin_periode]);
        return (float)$stmt->fetch()['solde'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère le résultat de l'exercice (comptes 6 et 7) */
function getResultatExercice() {
    global $pdo, $date_debut_exercice, $date_fin_periode;
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN pc.classe_compte = '7' THEN e.montant_credit - e.montant_debit ELSE 0 END), 0) as produits,
                COALESCE(SUM(CASE WHEN pc.classe_compte = '6' THEN e.montant_debit - e.montant_credit ELSE 0 END), 0) as charges
            FROM ecritures_comptables e
            INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
            WHERE pc.classe_compte IN ('6', '7') AND e.date_ecriture BETWEEN :debut AND :fin AND e.statut = 'VALIDÉE'
        ");
        $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
        $r = $stmt->fetch();
        return (float)$r['produits'] - (float)$r['charges'];
    } catch (PDOException $e) { return 0; }
}

/** Récupère les engagements de garantie (garanties actives) */
function getGarantiesActives() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT COALESCE(SUM(valeur_nette), 0) as total FROM garanties WHERE statut = 'actif'");
        return (float)$stmt->fetch()['total'];
    } catch (PDOException $e) { return 0; }
}

// ============================================================
// CALCUL DES POSTES DU BILAN
// ============================================================

// --- ACTIF ---
$caisse = getCaisse();
$creances_vue = getComptesPositifs(); // total des comptes positifs (créances)
$banque_centrale = 0;
$tresor_public = 0;
$autres_inst_fin = 0;
$creances_terme = 0;
$creances_inst_fin = $creances_vue + $banque_centrale + $tresor_public + $autres_inst_fin + $creances_terme;

$credits_ordinaires = getCreditsOrdinaires();
$autres_concours = 0; // non disponible
$creances_membres = $credits_ordinaires + $autres_concours;

$credit_bail = 0; // non disponible (on pourrait le déduire des immobilisations, mais pas de champ type_credit_bail)
$titres_placement = 0;
$immobilisations_financieres = getImmobilisations('financieres');
$immobilisations_equivalence = 0;
$immobilisations_incorporelles = getImmobilisations('incorporelles');
$immobilisations_corporelles = getImmobilisations('corporelles');
$actionnaires = 0;
$autres_actifs = 0;
$comptes_ordre_actif = 0;
$ecart_acquisition_actif = 0;

$total_actif = $caisse + $creances_inst_fin + $creances_membres + $credit_bail + $titres_placement
             + $immobilisations_financieres + $immobilisations_equivalence + $immobilisations_incorporelles
             + $immobilisations_corporelles + $actionnaires + $autres_actifs + $comptes_ordre_actif
             + $ecart_acquisition_actif;

// --- PASSIF ---
$dettes_vue = getComptesNegatifs(); // total des comptes négatifs (dettes)
$dettes_tresor = 0;
$dettes_autres_inst = 0;
$dettes_terme = 0;
$dettes_inst_fin = $dettes_vue + $dettes_tresor + $dettes_autres_inst + $dettes_terme;

$epargne_vue = getEpargneVue();
$epargne_terme = getEpargneTerme();
$autres_dettes_vue = 0;
$autres_dettes_terme = 0;
$dettes_membres = $epargne_vue + $epargne_terme + $autres_dettes_vue + $autres_dettes_terme;

$autres_passifs = 0;
$comptes_ordre_passif = 0;
$ecart_acquisition_passif = 0;
$provisions_risques = getProvisions();
$emprunts_subordonnes = getEmprunts();
$provisions_reglementees = 0;
$subventions_investissement = getSubventions();
$fonds_risques_financiers = 0;
$capital = getCapitalTotal();
$primes_capital = 0;
$reserves_consolidees = 0;
$part_groupe = 0;
$interets_minoritaires = 0;
$report_nouveau = getReportNouveau();
$resultat_exercice = getResultatExercice();
$resultat_groupe = 0;
$resultat_minoritaires = 0;

$total_passif = $dettes_inst_fin + $dettes_membres + $autres_passifs + $comptes_ordre_passif
              + $ecart_acquisition_passif + $provisions_risques + $emprunts_subordonnes
              + $provisions_reglementees + $subventions_investissement + $fonds_risques_financiers
              + $capital + $primes_capital + $reserves_consolidees + $report_nouveau + $resultat_exercice;

// --- HORS BILAN ---
$engagements_donnes = 0;
$engagements_financement_inst = 0;
$engagements_financement_membres = 0;
$engagements_garantie_inst = 0;
$engagements_garantie_membres = getGarantiesActives(); // garanties données = garanties actives
$engagements_titres = 0;

$engagements_recus = 0;
$engagements_recus_inst = 0;
$engagements_recus_membres = 0;
$engagements_garantie_recus_inst = 0;
$engagements_garantie_recus_membres = 0;
$engagements_titres_recus = 0;

$total_hors_bilan = $engagements_donnes + $engagements_recus;

// Vérification équilibre
$equilibre_ok = (abs($total_actif - $total_passif) < 1);
$difference = $total_actif - $total_passif;

// ============================================================
// STRUCTURE DÉTAILLÉE POUR L'AFFICHAGE (conforme au fichier Excel)
// ============================================================

// Définition des postes avec leur hiérarchie (indent = niveau d'indentation)
$postes_actif = [
    ['code' => '010', 'libelle' => 'CAISSE', 'indent' => 0, 'valeur' => $caisse],
    ['code' => '014', 'libelle' => 'CRÉANCES SUR LES INSTITUTIONS FINANCIÈRES', 'indent' => 0, 'valeur' => $creances_inst_fin, 'is_subtotal' => true],
    ['code' => '015', 'libelle' => 'A vue', 'indent' => 1, 'valeur' => $creances_vue],
    ['code' => '016', 'libelle' => 'Banque centrale', 'indent' => 2, 'valeur' => $banque_centrale],
    ['code' => '017', 'libelle' => 'Trésor Public, CCP', 'indent' => 2, 'valeur' => $tresor_public],
    ['code' => '018', 'libelle' => 'Autres institutions financières', 'indent' => 2, 'valeur' => $autres_inst_fin],
    ['code' => '019', 'libelle' => 'A terme', 'indent' => 1, 'valeur' => $creances_terme],
    ['code' => '030', 'libelle' => 'CRÉANCES SUR LES MEMBRES OU BÉNÉFICIAIRES', 'indent' => 0, 'valeur' => $creances_membres, 'is_subtotal' => true],
    ['code' => '035', 'libelle' => 'Autres concours aux membres, bénéficiaires ou clients', 'indent' => 1, 'valeur' => $autres_concours],
    ['code' => '037', 'libelle' => 'Crédits ordinaires', 'indent' => 1, 'valeur' => $credits_ordinaires],
    ['code' => '051', 'libelle' => 'CRÉDIT-BAIL ET OPÉRATIONS ASSIMILÉES', 'indent' => 0, 'valeur' => $credit_bail],
    ['code' => '100', 'libelle' => 'TITRES DE PLACEMENT', 'indent' => 0, 'valeur' => $titres_placement],
    ['code' => '110', 'libelle' => 'IMMOBILISATIONS FINANCIÈRES', 'indent' => 0, 'valeur' => $immobilisations_financieres],
    ['code' => '120', 'libelle' => 'IMMOBILISATIONS FINANCIÈRES MISES EN ÉQUIVALENCE', 'indent' => 0, 'valeur' => $immobilisations_equivalence],
    ['code' => '140', 'libelle' => 'IMMOBILISATIONS INCORPORELLES', 'indent' => 0, 'valeur' => $immobilisations_incorporelles],
    ['code' => '145', 'libelle' => 'IMMOBILISATIONS CORPORELLES', 'indent' => 0, 'valeur' => $immobilisations_corporelles],
    ['code' => '150', 'libelle' => 'ACTIONNAIRES, ASSOCIÉS OU MEMBRES', 'indent' => 0, 'valeur' => $actionnaires],
    ['code' => '155', 'libelle' => 'AUTRES ACTIFS', 'indent' => 0, 'valeur' => $autres_actifs],
    ['code' => '160', 'libelle' => 'COMPTES D\'ORDRE ET DIVERS', 'indent' => 0, 'valeur' => $comptes_ordre_actif],
    ['code' => '165', 'libelle' => 'ÉCART D\'ACQUISITION', 'indent' => 0, 'valeur' => $ecart_acquisition_actif],
    ['code' => '250', 'libelle' => 'TOTAL ACTIF', 'indent' => 0, 'valeur' => $total_actif, 'is_total' => true],
];

$postes_passif = [
    ['code' => '300', 'libelle' => 'DETTES À L\'ÉGARD DES INSTITUTIONS FINANCIÈRES', 'indent' => 0, 'valeur' => $dettes_inst_fin, 'is_subtotal' => true],
    ['code' => '310', 'libelle' => 'A vue', 'indent' => 1, 'valeur' => $dettes_vue],
    ['code' => '311', 'libelle' => 'Trésor Public, CCP', 'indent' => 2, 'valeur' => $dettes_tresor],
    ['code' => '312', 'libelle' => 'Autres institutions financières', 'indent' => 2, 'valeur' => $dettes_autres_inst],
    ['code' => '320', 'libelle' => 'A terme', 'indent' => 1, 'valeur' => $dettes_terme],
    ['code' => '330', 'libelle' => 'DETTES À L\'ÉGARD DES MEMBRES OU BÉNÉFICIAIRES', 'indent' => 0, 'valeur' => $dettes_membres, 'is_subtotal' => true],
    ['code' => '331', 'libelle' => 'Comptes d\'épargne à vue', 'indent' => 1, 'valeur' => $epargne_vue],
    ['code' => '332', 'libelle' => 'Comptes d\'épargne à terme', 'indent' => 1, 'valeur' => $epargne_terme],
    ['code' => '334', 'libelle' => 'Autres dettes à vue', 'indent' => 1, 'valeur' => $autres_dettes_vue],
    ['code' => '335', 'libelle' => 'Autres dettes à terme', 'indent' => 1, 'valeur' => $autres_dettes_terme],
    ['code' => '345', 'libelle' => 'AUTRES PASSIFS', 'indent' => 0, 'valeur' => $autres_passifs],
    ['code' => '350', 'libelle' => 'COMPTES D\'ORDRE ET DIVERS', 'indent' => 0, 'valeur' => $comptes_ordre_passif],
    ['code' => '355', 'libelle' => 'ÉCART D\'ACQUISITION', 'indent' => 0, 'valeur' => $ecart_acquisition_passif],
    ['code' => '360', 'libelle' => 'PROVISIONS POUR RISQUES ET CHARGES', 'indent' => 0, 'valeur' => $provisions_risques],
    ['code' => '362', 'libelle' => 'EMPRUNTS ET TITRES ÉMIS SUBORDONNÉS', 'indent' => 0, 'valeur' => $emprunts_subordonnes],
    ['code' => '365', 'libelle' => 'PROVISIONS RÉGLEMENTÉES', 'indent' => 0, 'valeur' => $provisions_reglementees],
    ['code' => '370', 'libelle' => 'SUBVENTIONS D\'INVESTISSEMENT', 'indent' => 0, 'valeur' => $subventions_investissement],
    ['code' => '375', 'libelle' => 'FONDS POUR RISQUES FINANCIERS GÉNÉRAUX', 'indent' => 0, 'valeur' => $fonds_risques_financiers],
    ['code' => '380', 'libelle' => 'CAPITAL', 'indent' => 0, 'valeur' => $capital],
    ['code' => '385', 'libelle' => 'PRIMES LIÉES AU CAPITAL', 'indent' => 0, 'valeur' => $primes_capital],
    ['code' => '390', 'libelle' => 'RESERVES CONSOLIDÉES, ÉCART DE RÉÉVALUATION, ÉCART DE CONVERSION, DIFFÉRENCE SUR TITRES MIS EN ÉQUIVALENCE', 'indent' => 0, 'valeur' => $reserves_consolidees, 'is_subtotal' => true],
    ['code' => '391', 'libelle' => 'Part du groupe', 'indent' => 1, 'valeur' => $part_groupe],
    ['code' => '392', 'libelle' => 'Part des intérêts minoritaires', 'indent' => 1, 'valeur' => $interets_minoritaires],
    ['code' => '400', 'libelle' => 'REPORT À NOUVEAU (+/-)', 'indent' => 0, 'valeur' => $report_nouveau],
    ['code' => '420', 'libelle' => 'EXCÉDENT OU DÉFICIT DE L\'EXERCICE (+/-)', 'indent' => 0, 'valeur' => $resultat_exercice, 'is_subtotal' => true],
    ['code' => '421', 'libelle' => 'Part du groupe', 'indent' => 1, 'valeur' => $resultat_groupe],
    ['code' => '422', 'libelle' => 'Part des intérêts minoritaires', 'indent' => 1, 'valeur' => $resultat_minoritaires],
    ['code' => '450', 'libelle' => 'TOTAL PASSIF', 'indent' => 0, 'valeur' => $total_passif, 'is_total' => true],
];

$postes_hors_bilan = [
    ['code' => '', 'libelle' => 'ENGAGEMENTS DONNÉS', 'indent' => 0, 'valeur' => $engagements_donnes, 'is_subtotal' => true],
    ['code' => '', 'libelle' => '  ENGAGEMENTS DE FINANCEMENT', 'indent' => 1, 'valeur' => 0],
    ['code' => '465', 'libelle' => '    En faveur des institutions financières', 'indent' => 2, 'valeur' => $engagements_financement_inst],
    ['code' => '470', 'libelle' => '    En faveur des membres, bénéficiaires ou clients', 'indent' => 2, 'valeur' => $engagements_financement_membres],
    ['code' => '', 'libelle' => '  ENGAGEMENTS DE GARANTIE', 'indent' => 1, 'valeur' => 0],
    ['code' => '475', 'libelle' => '    D\'ordre des institutions financières', 'indent' => 2, 'valeur' => $engagements_garantie_inst],
    ['code' => '480', 'libelle' => '    D\'ordre des membres, bénéficiaires ou clients', 'indent' => 2, 'valeur' => $engagements_garantie_membres],
    ['code' => '485', 'libelle' => '  ENGAGEMENTS SUR TITRES', 'indent' => 1, 'valeur' => $engagements_titres],
    ['code' => '', 'libelle' => 'ENGAGEMENTS REÇUS', 'indent' => 0, 'valeur' => $engagements_recus, 'is_subtotal' => true],
    ['code' => '', 'libelle' => '  ENGAGEMENTS DE FINANCEMENT', 'indent' => 1, 'valeur' => 0],
    ['code' => '490', 'libelle' => '    Reçus des institutions financières', 'indent' => 2, 'valeur' => $engagements_recus_inst],
    ['code' => '495', 'libelle' => '    Reçus des membres, bénéficiaires ou clients', 'indent' => 2, 'valeur' => $engagements_recus_membres],
    ['code' => '', 'libelle' => '  ENGAGEMENTS DE GARANTIE', 'indent' => 1, 'valeur' => 0],
    ['code' => '500', 'libelle' => '    Reçus des institutions financières', 'indent' => 2, 'valeur' => $engagements_garantie_recus_inst],
    ['code' => '505', 'libelle' => '    Reçus des membres, bénéficiaires ou clients', 'indent' => 2, 'valeur' => $engagements_garantie_recus_membres],
    ['code' => '510', 'libelle' => '  ENGAGEMENTS SUR TITRES', 'indent' => 1, 'valeur' => $engagements_titres_recus],
    ['code' => '', 'libelle' => 'TOTAL HORS BILAN', 'indent' => 0, 'valeur' => $total_hors_bilan, 'is_total' => true],
];

// ============================================================
// EXPORT PDF (FPDF)
// ============================================================
if ($format === 'pdf') {
    class PDF_DIMF extends FPDF {
        public $codeDimf = 'DIMF_2900';
        public $titreDimf = "Bilan consolidé";
        public $nomSfd = 'SFD';
        public $periode = '';
        public $exercice = '';

        static function u($str) {
            return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
        }

        function Header() {
            $this->SetFillColor(156, 163, 175);
            $this->Rect(0, 0, $this->GetPageWidth(), 28, 'F');
            $this->SetFont('Arial', '', 7);
            $this->SetTextColor(255, 255, 255);
            $this->SetXY(8, 3);
            $this->Cell(0, 4, self::u('République de Côte d\'Ivoire  •  Ministère de l\'Économie et des Finances  -  DGTCP / DSFD'), 0, 1, 'L');
            $this->SetFont('Arial', 'B', 13);
            $this->SetX(8);
            $this->Cell(0, 7, self::u($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetX(8);
            $this->Cell(0, 5, self::u('SFD : ' . $this->nomSfd . '   |   Période : ' . $this->periode . '   |   Exercice : ' . $this->exercice . '   |   Arrêté au : ' . date('d/m/Y')), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(4);
        }

        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, self::u('SICS-BCEAO  •  Généré le ' . date('d/m/Y H:i:s') . '  •  Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
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
            foreach ($cols as $col) {
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 6, self::u($col['label']), 1, 0, $align, true);
            }
            $this->Ln();
        }

        function TableRow($cols, $data, $style = '') {
            $fill = false;
            if ($style == 'subtotal') {
                $this->SetFillColor(248, 250, 252);
                $this->SetFont('Arial', 'B', 8);
                $fill = true;
            } elseif ($style == 'total') {
                $this->SetFillColor(240, 253, 244);
                $this->SetFont('Arial', 'B', 8.5);
                $fill = true;
            } else {
                $this->SetFont('Arial', '', 7.5);
                $fill = false;
            }
            $this->SetTextColor(15, 23, 42);
            $this->SetDrawColor(226, 232, 240);
            foreach ($cols as $i => $col) {
                $val = isset($data[$i]) ? $data[$i] : '';
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 5.5, self::u($val), 1, 0, $align, $fill);
            }
            $this->Ln();
        }

        static function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }

    if (ob_get_length()) ob_end_clean();

    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->nomSfd = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['label' => 'CODE', 'w' => 28, 'align' => 'L'],
        ['label' => 'POSTE', 'w' => 110, 'align' => 'L'],
        ['label' => 'Montant (FCFA)', 'w' => 60, 'align' => 'R'],
    ];

    // ACTIF
    $pdf->SectionTitle('BILAN CONSOLIDÉ ACTIF');
    $pdf->TableHeader($cols);
    foreach ($postes_actif as $p) {
        $style = '';
        if (isset($p['is_subtotal']) && $p['is_subtotal']) $style = 'subtotal';
        if (isset($p['is_total']) && $p['is_total']) $style = 'total';
        $indent = str_repeat(' ', $p['indent'] * 2);
        $pdf->TableRow($cols, [
            $p['code'],
            $indent . $p['libelle'],
            PDF_DIMF::montant($p['valeur'])
        ], $style);
    }

    $pdf->Ln(8);

    // PASSIF
    $pdf->SectionTitle('BILAN CONSOLIDÉ PASSIF');
    $pdf->TableHeader($cols);
    foreach ($postes_passif as $p) {
        $style = '';
        if (isset($p['is_subtotal']) && $p['is_subtotal']) $style = 'subtotal';
        if (isset($p['is_total']) && $p['is_total']) $style = 'total';
        $indent = str_repeat(' ', $p['indent'] * 2);
        $pdf->TableRow($cols, [
            $p['code'],
            $indent . $p['libelle'],
            PDF_DIMF::montant($p['valeur'])
        ], $style);
    }

    $pdf->Ln(8);

    // HORS BILAN
    $pdf->SectionTitle('HORS BILAN CONSOLIDÉ');
    $pdf->TableHeader($cols);
    foreach ($postes_hors_bilan as $p) {
        $style = '';
        if (isset($p['is_subtotal']) && $p['is_subtotal']) $style = 'subtotal';
        if (isset($p['is_total']) && $p['is_total']) $style = 'total';
        $indent = str_repeat(' ', $p['indent'] * 2);
        $pdf->TableRow($cols, [
            $p['code'],
            $indent . $p['libelle'],
            PDF_DIMF::montant($p['valeur'])
        ], $style);
    }

    $pdf->Ln(8);

    // VÉRIFICATION ÉQUILIBRE
    $pdf->SectionTitle('VÉRIFICATION DE L\'ÉQUILIBRE');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(80, 6, PDF_DIMF::u('Total Actif :'), 0, 0);
    $pdf->Cell(0, 6, PDF_DIMF::montant($total_actif), 0, 1);
    $pdf->Cell(80, 6, PDF_DIMF::u('Total Passif :'), 0, 0);
    $pdf->Cell(0, 6, PDF_DIMF::montant($total_passif), 0, 1);

    if ($equilibre_ok) {
        $pdf->SetTextColor(22, 163, 74);
        $pdf->Cell(0, 6, PDF_DIMF::u('✓ ÉQUILIBRE - Le total actif est égal au total passif.'), 0, 1);
    } else {
        $pdf->SetTextColor(220, 38, 38);
        $pdf->Cell(0, 6, PDF_DIMF::u('✗ DÉSÉQUILIBRE - Écart de ' . PDF_DIMF::montant(abs($difference)) . ' FCFA.'), 0, 1);
    }

    $pdf->Output('I', 'DIMF_2900_' . $exercice . '.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL
// ============================================================
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="DIMF_2900_' . $exercice . '.xls"');
    echo '<html><head><meta charset="UTF-8"><style>
        body { font-family: Arial; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #999; padding: 6px; }
        th { background: #f2f2f2; }
        .text-right { text-align: right; }
        .subtotal { background: #f8fafc; font-weight: bold; }
        .total { background: #e8f5e9; font-weight: bold; }
        .indent1 { padding-left: 20px; }
        .indent2 { padding-left: 40px; }
        .indent3 { padding-left: 60px; }
    </style></head><body>';
    echo '<h2>DIMF_2900 - Bilan consolidé</h2>';
    echo '<p>Période : ' . htmlspecialchars($lib_periode) . '</p>';

    // ACTIF
    echo '<h3>BILAN CONSOLIDÉ ACTIF</h3>';
    echo '<table><thead><tr><th>CODE</th><th>POSTE</th><th class="text-right">Montant (FCFA)</th></tr></thead><tbody>';
    foreach ($postes_actif as $p) {
        $class = '';
        if (isset($p['is_subtotal']) && $p['is_subtotal']) $class = 'subtotal';
        if (isset($p['is_total']) && $p['is_total']) $class = 'total';
        $indent = 'indent' . $p['indent'];
        echo '<tr class="' . $class . '"><td>' . $p['code'] . '</td><td class="' . $indent . '">' . htmlspecialchars($p['libelle']) . '</td><td class="text-right">' . number_format($p['valeur'],0,',',' ') . '</td></tr>';
    }
    echo '</tbody></table><br/>';

    // PASSIF
    echo '<h3>BILAN CONSOLIDÉ PASSIF</h3>';
    echo '<table><thead><tr><th>CODE</th><th>POSTE</th><th class="text-right">Montant (FCFA)</th></tr></thead><tbody>';
    foreach ($postes_passif as $p) {
        $class = '';
        if (isset($p['is_subtotal']) && $p['is_subtotal']) $class = 'subtotal';
        if (isset($p['is_total']) && $p['is_total']) $class = 'total';
        $indent = 'indent' . $p['indent'];
        echo '<tr class="' . $class . '"><td>' . $p['code'] . '</td><td class="' . $indent . '">' . htmlspecialchars($p['libelle']) . '</td><td class="text-right">' . number_format($p['valeur'],0,',',' ') . '</td></tr>';
    }
    echo '</tbody></table><br/>';

    // HORS BILAN
    echo '<h3>HORS BILAN CONSOLIDÉ</h3>';
    echo '<table><thead><tr><th>CODE</th><th>POSTE</th><th class="text-right">Montant (FCFA)</th></tr></thead><tbody>';
    foreach ($postes_hors_bilan as $p) {
        $class = '';
        if (isset($p['is_subtotal']) && $p['is_subtotal']) $class = 'subtotal';
        if (isset($p['is_total']) && $p['is_total']) $class = 'total';
        $indent = 'indent' . $p['indent'];
        echo '<tr class="' . $class . '"><td>' . $p['code'] . '</td><td class="' . $indent . '">' . htmlspecialchars($p['libelle']) . '</td><td class="text-right">' . number_format($p['valeur'],0,',',' ') . '</td></tr>';
    }
    echo '</tbody></table><br/>';

    // Vérification
    echo '<h3>VÉRIFICATION DE L\'ÉQUILIBRE</h3>';
    echo '<table><tr><td>Total Actif</td><td class="text-right">' . number_format($total_actif,0,',',' ') . ' FCFA</td></tr>';
    echo '<tr><td>Total Passif</td><td class="text-right">' . number_format($total_passif,0,',',' ') . ' FCFA</td></tr>';
    echo '<tr><td>Écart</td><td class="text-right">' . number_format($difference,0,',',' ') . ' FCFA</td></tr>';
    echo '<tr><td colspan="2"><strong>' . ($equilibre_ok ? '✓ ÉQUILIBRE' : '✗ DÉSÉQUILIBRE') . '</strong></td></tr>';
    echo '</table>';
    echo '</body></html>';
    exit;
}

// ============================================================
// AFFICHAGE WEB (HTML)
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2900 - Bilan consolidé</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter', system-ui, sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; display:flex; align-items:center; gap:10px; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; display:inline-block; margin-top:8px; }
        .btn-group { display:flex; gap:12px; }
        .btn-excel, .btn-pdf { display:inline-flex; align-items:center; gap:8px; padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; }
        .btn-excel { background:#10b981; color:white; }
        .btn-excel:hover { background:#059669; }
        .btn-pdf { background:#ef4444; color:white; }
        .btn-pdf:hover { background:#dc2626; }
        .card { background:white; border-radius:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px; overflow:hidden; }
        .card-header { display:flex; align-items:center; gap:10px; padding:16px 24px; background:#f8fafc; border-bottom:1px solid #eef2f6; font-weight:600; color:#1e40af; }
        .card-body { padding:20px 24px; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select { background:white; border:1px solid #d1d5db; border-radius:12px; padding:8px 14px; font-size:0.85rem; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        th { padding:12px 16px; background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left; font-weight:600; }
        td { padding:10px 16px; border-bottom:1px solid #f1f5f9; }
        .text-right { text-align:right; font-family:'Courier New',monospace; font-weight:500; }
        .subtotal-row { background:#f8fafc; font-weight:600; }
        .total-row { background:#f0fdf4; font-weight:700; border-top:2px solid #bbf7d0; }
        .indent1 { padding-left: 30px; }
        .indent2 { padding-left: 50px; }
        .indent3 { padding-left: 70px; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px 20px; border-radius:16px; display:flex; align-items:center; gap:14px; margin-bottom:20px; }
        .equilibre-ok { color:#16a34a; }
        .equilibre-ko { color:#dc2626; }
        .three-cols { display:grid; grid-template-columns:repeat(auto-fit,minmax(350px,1fr)); gap:20px; }
        .footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; padding:16px; }
        @media (max-width:768px) { body { padding:12px; } .filters-row { flex-direction:column; } .btn-group { flex-wrap:wrap; } .three-cols { grid-template-columns:1fr; } }
        @media print { .btn-group, .footer, .filters-row, #filtersCard { display:none; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-simple"></i> DIMF_2900 - BILAN CONSOLIDÉ</h1>
            <div class="subtitle">République de Côte d'Ivoire / Ministère de l'Économie et des Finances – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • États consolidés</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Filtres -->
    <form method="post" class="card" id="filtersForm">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
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
                    <?php
                    if ($type_periode == 'mensuel') {
                        echo '<label>Mois</label><select name="mois" id="moisSelect">';
                        for ($m=1;$m<=12;$m++) echo '<option value="'.$m.'" '.($m==$mois?'selected':'').'>'.str_pad($m,2,'0',STR_PAD_LEFT).' - '.date('F',mktime(0,0,0,$m,1)).'</option>';
                        echo '</select>';
                    } elseif ($type_periode == 'trimestre') {
                        echo '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
                        for ($t=1;$t<=4;$t++) echo '<option value="'.$t.'" '.($t==$trimestre?'selected':'').'>'.$t.($t==1?'er':'ème').' Trimestre</option>';
                        echo '</select>';
                    } elseif ($type_periode == 'semestre') {
                        echo '<label>Semestre</label><select name="semestre" id="semestreSelect">';
                        for ($s=1;$s<=2;$s++) echo '<option value="'.$s.'" '.($s==$semestre?'selected':'').'>'.$s.($s==1?'er':'e').' semestre</option>';
                        echo '</select>';
                    } else {
                        echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
                    }
                    ?>
                </div>
                <div class="filter-item">
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Période : <?= $lib_periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </form>

    <!-- Note -->
    <div class="card">
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div><strong>Note :</strong> Le bilan consolidé présente la situation financière du groupe. L'équilibre Actif = Passif doit être vérifié.</div>
            </div>
        </div>
    </div>

    <!-- Trois colonnes -->
    <div class="three-cols">
        <!-- ACTIF -->
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-line"></i> BILAN CONSOLIDÉ ACTIF</div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>CODE</th><th>ACTIF</th><th class="text-right">Montant (FCFA)</th></tr></thead>
                        <tbody>
                            <?php foreach ($postes_actif as $p): 
                                $class = '';
                                if (isset($p['is_subtotal']) && $p['is_subtotal']) $class = 'subtotal-row';
                                if (isset($p['is_total']) && $p['is_total']) $class = 'total-row';
                                $indent = 'indent' . $p['indent'];
                            ?>
                            <tr class="<?= $class ?>">
                                <td><?= $p['code'] ?></td>
                                <td class="<?= $indent ?>"><?= htmlspecialchars($p['libelle']) ?></td>
                                <td class="text-right"><?= number_format($p['valeur'],0,',',' ') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PASSIF -->
        <div class="card">
            <div class="card-header"><i class="fas fa-wallet"></i> BILAN CONSOLIDÉ PASSIF</div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>CODE</th><th>PASSIF</th><th class="text-right">Montant (FCFA)</th></tr></thead>
                        <tbody>
                            <?php foreach ($postes_passif as $p): 
                                $class = '';
                                if (isset($p['is_subtotal']) && $p['is_subtotal']) $class = 'subtotal-row';
                                if (isset($p['is_total']) && $p['is_total']) $class = 'total-row';
                                $indent = 'indent' . $p['indent'];
                            ?>
                            <tr class="<?= $class ?>">
                                <td><?= $p['code'] ?></td>
                                <td class="<?= $indent ?>"><?= htmlspecialchars($p['libelle']) ?></td>
                                <td class="text-right"><?= number_format($p['valeur'],0,',',' ') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- HORS BILAN -->
        <div class="card">
            <div class="card-header"><i class="fas fa-clipboard-list"></i> HORS BILAN CONSOLIDÉ</div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>CODE</th><th>HORS BILAN</th><th class="text-right">Montant (FCFA)</th></tr></thead>
                        <tbody>
                            <?php foreach ($postes_hors_bilan as $p): 
                                $class = '';
                                if (isset($p['is_subtotal']) && $p['is_subtotal']) $class = 'subtotal-row';
                                if (isset($p['is_total']) && $p['is_total']) $class = 'total-row';
                                $indent = 'indent' . $p['indent'];
                            ?>
                            <tr class="<?= $class ?>">
                                <td><?= $p['code'] ?></td>
                                <td class="<?= $indent ?>"><?= htmlspecialchars($p['libelle']) ?></td>
                                <td class="text-right"><?= number_format($p['valeur'],0,',',' ') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Vérification équilibre -->
    <div class="card">
        <div class="card-header"><i class="fas fa-check-circle"></i> VÉRIFICATION DE L'ÉQUILIBRE</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-balance-scale"></i>
                <div>
                    <strong>Total Actif :</strong> <?= number_format($total_actif,0,',',' ') ?> FCFA<br>
                    <strong>Total Passif :</strong> <?= number_format($total_passif,0,',',' ') ?> FCFA<br>
                    <?php if($equilibre_ok): ?>
                        <span class="equilibre-ok">✓ ÉQUILIBRE - Le total actif est égal au total passif.</span>
                    <?php else: ?>
                        <span class="equilibre-ko">✗ DÉSÉQUILIBRE - Écart de <?= number_format(abs($difference),0,',',' ') ?> FCFA.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const currentMois = <?= $mois ?>;
        const currentTrimestre = <?= $trimestre ?>;
        const currentSemestre = <?= json_encode($semestre) ?>;
        let html = '';
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois" id="moisSelect">';
            for (let m=1;m<=12;m++) {
                const s = (m===currentMois)?'selected':'';
                const n = new Date(2000,m-1,1).toLocaleString('fr',{month:'long'});
                html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
            for (let t=1;t<=4;t++) {
                const s = (t===currentTrimestre)?'selected':'';
                html += `<option value="${t}" ${s}>${t}${t===1?'er':'ème'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
            for (let s=1;s<=2;s++) {
                const sel = (s===currentSemestre)?'selected':'';
                html += `<option value="${s}" ${sel}>${s}${s===1?'er':'e'} semestre</option>`;
            }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
        }
        container.innerHTML = html;
    }

    function exporterPDF() {
        const form = document.getElementById('filtersForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'format';
        input.value = 'pdf';
        form.appendChild(input);
        // PDF dans la même fenêtre
        form.submit();
        form.removeChild(input);
    }

    function exporterExcel() {
        const form = document.getElementById('filtersForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'format';
        input.value = 'excel';
        form.appendChild(input);
        form.submit();
        form.removeChild(input);
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);
    });
</script>
</body>
</html>