<?php
// DIMF_2900.php - Bilan consolidé (Actif, Passif, Hors Bilan)
// Déclaration SICS-BCEAO
// Version avec POST et Bootstrap 5 (design préservé)

session_start();

// ============================================================
// CONFIGURATION BDD
// ============================================================
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ============================================================
// PARAMÈTRES LUS EN POST AVEC DÉFAUTS
// ============================================================
$exercice     = isset($_POST['exercice'])     ? (int)$_POST['exercice']     : date('Y');
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode']      : 'mensuel';
$mois         = isset($_POST['mois'])         ? (int)$_POST['mois']         : 12;
$trimestre    = isset($_POST['trimestre'])    ? (int)$_POST['trimestre']    : 4;
$semestre     = isset($_POST['semestre'])     ? (int)$_POST['semestre']     : 2;
$format       = isset($_POST['format'])       ? $_POST['format']            : 'html';

// Calcul du mois en fonction du type de période
switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
    default:          $mois = isset($_POST['mois']) ? (int)$_POST['mois'] : 12;
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
// BILAN CONSOLIDÉ ACTIF
// ============================================================

// 010 - CAISSE
$caisse = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde_actuel), 0) as total FROM caisses WHERE statut = 'ouverte'");
    $stmt->execute();
    $caisse = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $caisse = 0; }

// 014 - CRÉANCES SUR LES INSTITUTIONS FINANCIÈRES
$creances_inst_fin = 0;
$creances_vue = 0;
$banque_centrale = 0;
$tresor_public = 0;
$autres_inst_fin = 0;
$creances_terme = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde), 0) as total FROM comptes WHERE solde > 0 AND statut = 'actif'");
    $stmt->execute();
    $creances_vue = (float)$stmt->fetch()['total'];
    $creances_inst_fin = $creances_vue;
} catch (PDOException $e) { $creances_vue = 0; }

// 030 - CRÉANCES SUR LES MEMBRES OU BÉNÉFICIAIRES
$creances_membres = 0;
$credits_ordinaires = 0;

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
        WHERE d.statut IN ('actif', 'approuve')
    ");
    $stmt->execute();
    $credits_ordinaires = (float)$stmt->fetch()['total'];
    $creances_membres = $credits_ordinaires;
} catch (PDOException $e) { $credits_ordinaires = 0; }

// 051 - CRÉDIT-BAIL ET OPÉRATIONS ASSIMILÉES
$credit_bail = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_achat - amortissement_total), 0) as total FROM immobilisations WHERE type_credit_bail IS NOT NULL AND statut = 'actif'");
    $stmt->execute();
    $credit_bail = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $credit_bail = 0; }

// 100 - TITRES DE PLACEMENT
$titres_placement = 0;

// 110 - IMMOBILISATIONS FINANCIÈRES
$immobilisations_financieres = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_achat - amortissement_total), 0) as total FROM immobilisations WHERE type_immobilisation = 'Immobilisations financieres' AND statut = 'actif'");
    $stmt->execute();
    $immobilisations_financieres = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $immobilisations_financieres = 0; }

// 120 - IMMOBILISATIONS FINANCIÈRES MISES EN ÉQUIVALENCE
$immobilisations_equivalence = 0;

// 140 - IMMOBILISATIONS INCORPORELLES
$immobilisations_incorporelles = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_achat - amortissement_total), 0) as total FROM immobilisations WHERE type_immobilisation = 'Immobilisations incorporelles' AND statut = 'actif'");
    $stmt->execute();
    $immobilisations_incorporelles = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $immobilisations_incorporelles = 0; }

// 145 - IMMOBILISATIONS CORPORELLES
$immobilisations_corporelles = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_achat - amortissement_total), 0) as total FROM immobilisations WHERE type_immobilisation = 'Immobilisations corporelles' AND statut = 'actif'");
    $stmt->execute();
    $immobilisations_corporelles = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $immobilisations_corporelles = 0; }

// 150 - ACTIONNAIRES, ASSOCIES OU MEMBRES
$actionnaires = 0;

// 155 - AUTRES ACTIFS
$autres_actifs = 0;

// 160 - COMPTES D'ORDRE ET DIVERS
$comptes_ordre_actif = 0;

// 165 - ÉCART D'ACQUISITION
$ecart_acquisition = 0;

// TOTAL ACTIF
$total_actif = $caisse + $creances_inst_fin + $creances_membres + $credit_bail + $titres_placement
             + $immobilisations_financieres + $immobilisations_equivalence + $immobilisations_incorporelles
             + $immobilisations_corporelles + $actionnaires + $autres_actifs + $comptes_ordre_actif
             + $ecart_acquisition;

// ============================================================
// BILAN CONSOLIDÉ PASSIF
// ============================================================

// 300 - DETTES À L'ÉGARD DES INSTITUTIONS FINANCIÈRES
$dettes_inst_fin = 0;
$dettes_vue = 0;
$dettes_tresor = 0;
$dettes_autres_inst = 0;
$dettes_terme = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(solde)), 0) as total FROM comptes WHERE solde < 0 AND statut = 'actif'");
    $stmt->execute();
    $dettes_vue = (float)$stmt->fetch()['total'];
    $dettes_inst_fin = $dettes_vue;
} catch (PDOException $e) { $dettes_vue = 0; }

// 330 - DETTES À L'ÉGARD DES MEMBRES OU BÉNÉFICIAIRES
$dettes_membres = 0;
$epargne_vue = 0;
$epargne_terme = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde), 0) as total
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0
    ");
    $stmt->execute();
    $epargne_vue = (float)$stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital_initial), 0) as total FROM comptes_dat WHERE statut = 'en cours'");
    $stmt->execute();
    $epargne_terme = (float)$stmt->fetch()['total'];
    
    $dettes_membres = $epargne_vue + $epargne_terme;
} catch (PDOException $e) {
    $epargne_vue = 0;
    $epargne_terme = 0;
    $dettes_membres = 0;
}

// 345 - AUTRES PASSIFS
$autres_passifs = 0;

// 350 - COMPTES D'ORDRE ET DIVERS
$comptes_ordre_passif = 0;

// 355 - ÉCART D'ACQUISITION
$ecart_acquisition_passif = 0;

// 360 - PROVISIONS POUR RISQUES ET CHARGES
$provisions_risques = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM provisions WHERE statut = 'actif'");
    $stmt->execute();
    $provisions_risques = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $provisions_risques = 0; }

// 362 - EMPRUNTS ET TITRES ÉMIS SUBORDONNÉS
$emprunts_subordonnes = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM capital WHERE statut = 'valide' AND mode_paiement = 'BANQUE'");
    $stmt->execute();
    $emprunts_subordonnes = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $emprunts_subordonnes = 0; }

// 365 - PROVISIONS RÉGLEMENTÉES
$provisions_reglementees = 0;

// 370 - SUBVENTIONS D'INVESTISSEMENT
$subventions_investissement = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM capital WHERE statut = 'valide' AND libelle LIKE '%subvention%'");
    $stmt->execute();
    $subventions_investissement = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $subventions_investissement = 0; }

// 375 - FONDS POUR RISQUES FINANCIERS GÉNÉRAUX
$fonds_risques_financiers = 0;

// 380 - CAPITAL
$capital = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM capital WHERE statut = 'valide' AND mode_paiement IN ('BANQUE', 'CASH')");
    $stmt->execute();
    $capital = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $capital = 0; }

// 385 - PRIMES LIÉES AU CAPITAL
$primes_capital = 0;

// 390 - RESERVES CONSOLIDÉES
$reserves_consolidees = 0;
$part_groupe = 0;
$interets_minoritaires = 0;

// 400 - REPORT À NOUVEAU
$report_nouveau = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '11%'
          AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $report_nouveau = (float)$stmt->fetch()['solde'];
} catch (PDOException $e) { $report_nouveau = 0; }

// 420 - EXCÉDENT OU DÉFICIT DE L'EXERCICE
$resultat_exercice = 0;
$resultat_groupe = 0;
$resultat_minoritaires = 0;

try {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN pc.classe_compte = '7' THEN e.montant_credit - e.montant_debit ELSE 0 END), 0) as produits,
            COALESCE(SUM(CASE WHEN pc.classe_compte = '6' THEN e.montant_debit - e.montant_credit ELSE 0 END), 0) as charges
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte IN ('6', '7')
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmt->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_periode
    ]);
    $result = $stmt->fetch();
    $resultat_exercice = (float)$result['produits'] - (float)$result['charges'];
} catch (PDOException $e) { $resultat_exercice = 0; }

// TOTAL PASSIF
$total_passif = $dettes_inst_fin + $dettes_membres + $autres_passifs + $comptes_ordre_passif
              + $ecart_acquisition_passif + $provisions_risques + $emprunts_subordonnes
              + $provisions_reglementees + $subventions_investissement + $fonds_risques_financiers
              + $capital + $primes_capital + $reserves_consolidees + $report_nouveau + $resultat_exercice;

// ============================================================
// BILAN CONSOLIDÉ HORS BILAN
// ============================================================
$engagements_donnes = 0;
$engagements_financement_inst = 0;
$engagements_financement_membres = 0;
$engagements_garantie_inst = 0;
$engagements_garantie_membres = 0;
$engagements_titres = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valeur_nette), 0) as total FROM garanties WHERE statut = 'actif'");
    $stmt->execute();
    $engagements_garantie_membres = (float)$stmt->fetch()['total'];
    $engagements_donnes = $engagements_garantie_membres;
} catch (PDOException $e) { $engagements_garantie_membres = 0; }

$engagements_recus = 0;
$engagements_recus_inst = 0;
$engagements_recus_membres = 0;
$engagements_garantie_recus_inst = 0;
$engagements_garantie_recus_membres = 0;

$total_hors_bilan = $engagements_donnes + $engagements_recus;

// Vérification de l'équilibre
$equilibre_ok = ($total_actif == $total_passif);
$difference = $total_actif - $total_passif;

// Fonction utilitaire pour formater les montants
function format_montant($val) {
    return number_format((float)$val, 0, ',', ' ') . ' F';
}

// ============================================================
// CLASSE FPDF (export PDF via POST)
// ============================================================
if ($format === 'pdf') {
    if (ob_get_length()) {
        ob_end_clean();
    }

    class PDF_DIMF extends FPDF {
        public $codeDimf = 'DIMF_2900';
        public $titreDimf = "Bilan consolide";
        public $nomSfd = 'SFD';
        public $periode = '';
        public $exercice = '';

        function convert($str) {
            $str = str_replace(array('é', 'è', 'ê', 'ë', 'à', 'â', 'ä', 'î', 'ï', 'ô', 'ö', 'ù', 'û', 'ü', 'ç', 'É', 'È', 'Ê', 'Ë', 'À', 'Â', 'Ä', 'Î', 'Ï', 'Ô', 'Ö', 'Ù', 'Û', 'Ü', 'Ç'), 
                              array('e', 'e', 'e', 'e', 'a', 'a', 'a', 'i', 'i', 'o', 'o', 'u', 'u', 'u', 'c', 'E', 'E', 'E', 'E', 'A', 'A', 'A', 'I', 'I', 'O', 'O', 'U', 'U', 'U', 'C'), $str);
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
            $this->Cell(0, 7, $this->convert($this->codeDimf . '  -  ' . $this->titreDimf), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, $this->convert('SFD : ' . $this->nomSfd . '   |   Periode : ' . $this->periode . '   |   Exercice : ' . $this->exercice), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(10);
        }

        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, $this->convert('SICS-BCEAO  •  Genere le ' . date('d/m/Y H:i:s') . '  •  Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
        }

        function SectionTitle($label) {
            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(0, 0, 0);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 7, $this->convert('  ' . strtoupper($label)), 0, 1, 'L', true);
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
                $this->Cell($col['w'], 6, $this->convert($col['label']), 1, 0, $align, true);
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
                $this->Cell($col['w'], 5.5, $this->convert($val), 1, 0, $align, $fill);
            }
            $this->Ln();
        }
        
        function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }

    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->nomSfd = isset($_SESSION['nom_sfd']) ? $_SESSION['nom_sfd'] : 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    // ACTIF
    $cols = [
        ['label' => 'CODE', 'w' => 25, 'align' => 'L'],
        ['label' => 'POSTE', 'w' => 100, 'align' => 'L'],
        ['label' => 'Montant (FCFA)', 'w' => 60, 'align' => 'R'],
    ];
    
    $pdf->SectionTitle('BILAN CONSOLIDE ACTIF');
    $pdf->TableHeader($cols);
    
    $pdf->TableRow($cols, ['010', 'CAISSE', $pdf->montant($caisse)]);
    $pdf->TableRow($cols, ['014', 'CREANCES SUR LES INSTITUTIONS FINANCIERES', $pdf->montant($creances_inst_fin)], 'subtotal');
    $pdf->TableRow($cols, ['015', '  - A vue', $pdf->montant($creances_vue)]);
    $pdf->TableRow($cols, ['030', 'CREANCES SUR LES MEMBRES OU BENEFICIAIRES', $pdf->montant($creances_membres)], 'subtotal');
    $pdf->TableRow($cols, ['037', '  - Credits ordinaires', $pdf->montant($credits_ordinaires)]);
    $pdf->TableRow($cols, ['051', 'CREDIT-BAIL ET OPERATIONS ASSIMILEES', $pdf->montant($credit_bail)]);
    $pdf->TableRow($cols, ['110', 'IMMOBILISATIONS FINANCIERES', $pdf->montant($immobilisations_financieres)]);
    $pdf->TableRow($cols, ['140', 'IMMOBILISATIONS INCORPORELLES', $pdf->montant($immobilisations_incorporelles)]);
    $pdf->TableRow($cols, ['145', 'IMMOBILISATIONS CORPORELLES', $pdf->montant($immobilisations_corporelles)]);
    $pdf->TableRow($cols, ['250', 'TOTAL ACTIF', $pdf->montant($total_actif)], 'total');
    
    $pdf->Ln(8);
    
    // PASSIF
    $pdf->SectionTitle('BILAN CONSOLIDE PASSIF');
    $pdf->TableHeader($cols);
    
    $pdf->TableRow($cols, ['300', 'DETTES A L\'EGARD DES INSTITUTIONS FINANCIERES', $pdf->montant($dettes_inst_fin)], 'subtotal');
    $pdf->TableRow($cols, ['310', '  - A vue', $pdf->montant($dettes_vue)]);
    $pdf->TableRow($cols, ['330', 'DETTES A L\'EGARD DES MEMBRES OU BENEFICIAIRES', $pdf->montant($dettes_membres)], 'subtotal');
    $pdf->TableRow($cols, ['331', '  - Comptes d\'epargne a vue', $pdf->montant($epargne_vue)]);
    $pdf->TableRow($cols, ['332', '  - Comptes d\'epargne a terme', $pdf->montant($epargne_terme)]);
    $pdf->TableRow($cols, ['360', 'PROVISIONS POUR RISQUES ET CHARGES', $pdf->montant($provisions_risques)]);
    $pdf->TableRow($cols, ['362', 'EMPRUNTS ET TITRES EMIS SUBORDONNES', $pdf->montant($emprunts_subordonnes)]);
    $pdf->TableRow($cols, ['370', 'SUBVENTIONS D\'INVESTISSEMENT', $pdf->montant($subventions_investissement)]);
    $pdf->TableRow($cols, ['380', 'CAPITAL', $pdf->montant($capital)]);
    $pdf->TableRow($cols, ['400', 'REPORT A NOUVEAU', $pdf->montant($report_nouveau)]);
    $pdf->TableRow($cols, ['420', 'EXCEDENT OU DEFICIT DE L\'EXERCICE', $pdf->montant($resultat_exercice)]);
    $pdf->TableRow($cols, ['450', 'TOTAL PASSIF', $pdf->montant($total_passif)], 'total');
    
    $pdf->Ln(8);
    
    // HORS BILAN
    $pdf->SectionTitle('HORS BILAN CONSOLIDE');
    $pdf->TableHeader($cols);
    
    $pdf->TableRow($cols, ['', 'ENGAGEMENTS DONNES', $pdf->montant($engagements_donnes)], 'subtotal');
    $pdf->TableRow($cols, ['465', '  - En faveur des institutions financieres', $pdf->montant($engagements_financement_inst)]);
    $pdf->TableRow($cols, ['470', '  - En faveur des membres, beneficiaires ou clients', $pdf->montant($engagements_financement_membres)]);
    $pdf->TableRow($cols, ['', '  - Engagements de garantie - D\'ordre des membres', $pdf->montant($engagements_garantie_membres)]);
    $pdf->TableRow($cols, ['', 'ENGAGEMENTS RECUS', $pdf->montant($engagements_recus)], 'subtotal');
    $pdf->TableRow($cols, ['', 'TOTAL HORS BILAN', $pdf->montant($total_hors_bilan)], 'total');
    
    $pdf->Ln(8);
    
    // VERIFICATION EQUILIBRE
    $pdf->SectionTitle('VERIFICATION DE L\'EQUILIBRE');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(80, 6, $pdf->convert('Total Actif :'), 0, 0);
    $pdf->Cell(0, 6, $pdf->montant($total_actif), 0, 1);
    $pdf->Cell(80, 6, $pdf->convert('Total Passif :'), 0, 0);
    $pdf->Cell(0, 6, $pdf->montant($total_passif), 0, 1);
    
    if ($equilibre_ok) {
        $pdf->SetTextColor(22, 163, 74);
        $pdf->Cell(0, 6, $pdf->convert('✓ EQUILIBRE - Le total actif est egal au total passif.'), 0, 1);
    } else {
        $pdf->SetTextColor(220, 38, 38);
        $pdf->Cell(0, 6, $pdf->convert('✗ DESEQUILIBRE - Ecart de ' . $pdf->montant(abs($difference)) . ' FCFA.'), 0, 1);
    }
    
    $pdf->Output('I', 'DIMF_2900_' . $exercice . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2900 - Bilan consolide</title>
    <!-- Bootstrap 5 CSS (intégré sans modification du design) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- XLSX library pour export Excel -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Styles personnalisés inchangés -->
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
        
        .three-columns { display: flex; gap: 20px; flex-wrap: wrap; }
        .three-columns > div { flex: 1; min-width: 350px; }
        
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 12px 16px; background: #f8fafc; font-weight: 600; color: #1e293b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
        .text-right { text-align: right; font-family: 'Courier New', monospace; font-weight: 500; }
        .subtotal-row { background: #f8fafc; font-weight: 600; }
        .total-row { background: #f0fdf4; font-weight: 700; border-top: 2px solid #bbf7d0; }
        .indent { padding-left: 30px; }
        
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        
        .equilibre-ok { color: #16a34a; }
        .equilibre-ko { color: #dc2626; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            .three-columns { flex-direction: column; }
            th, td { padding: 8px 12px; font-size: 0.75rem; }
        }
        
        @media print {
            body { background: white; padding: 0; }
            .btn-group, .footer, .filters-row, #filtersCard { display: none !important; }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-simple"></i> DIMF_2900 - BILAN CONSOLIDE</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Etats consolides</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="submitExport('excel')"><i class="fas fa-file-excel"></i> Excel</button>
            <!-- Bouton PDF avec soumission POST -->
            <button class="btn-pdf" onclick="submitExport('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Formulaire de filtres en POST -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
            <form method="post" id="filterForm">
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
                        <!-- Contenu dynamique généré par JS (noms des champs: 'mois', 'trimestre', 'semestre') -->
                    </div>
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
                <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                    <i class="fas fa-info-circle"></i> Periode : <?= $lib_periode ?> (arrete au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
                </div>
            </form>
        </div>
    </div>

    <!-- Note d'information -->
    <div class="card">
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div><strong>Note :</strong> Le bilan consolide presente la situation financiere du groupe (institution + ses filiales). L'equilibre Actif = Passif doit etre verifie.</div>
            </div>
        </div>
    </div>

    <!-- Trois colonnes : Actif, Passif, Hors Bilan -->
    <div class="three-columns">
        <!-- BILAN ACTIF -->
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-line"></i> BILAN CONSOLIDE ACTIF</div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>CODE POSTE</th><th>ACTIF</th><th class="text-right">Montant (FCFA)</th></tr></thead>
                        <tbody>
                            <tr><td>010</td><td>CAISSE</td><td class="text-right"><?= number_format($caisse, 0, ',', ' ') ?></td></tr>
                            <tr class="subtotal-row"><td>014</td><td colspan="2">CREANCES SUR LES INSTITUTIONS FINANCIERES</td></tr>
                            <tr><td>015</td><td class="indent">A vue</td><td class="text-right"><?= number_format($creances_vue, 0, ',', ' ') ?></td></tr>
                            <tr class="subtotal-row"><td>030</td><td colspan="2">CREANCES SUR LES MEMBRES OU BENEFICIAIRES</td></tr>
                            <tr><td>037</td><td class="indent">Credits ordinaires</td><td class="text-right"><?= number_format($credits_ordinaires, 0, ',', ' ') ?></td></tr>
                            <tr><td>051</td><td>CREDIT-BAIL ET OPERATIONS ASSIMILEES</td><td class="text-right"><?= number_format($credit_bail, 0, ',', ' ') ?></td></tr>
                            <tr><td>110</td><td>IMMOBILISATIONS FINANCIERES</td><td class="text-right"><?= number_format($immobilisations_financieres, 0, ',', ' ') ?></td></tr>
                            <tr><td>140</td><td>IMMOBILISATIONS INCORPORELLES</td><td class="text-right"><?= number_format($immobilisations_incorporelles, 0, ',', ' ') ?></td></tr>
                            <tr><td>145</td><td>IMMOBILISATIONS CORPORELLES</td><td class="text-right"><?= number_format($immobilisations_corporelles, 0, ',', ' ') ?></td></tr>
                            <tr class="total-row"><td>250</td><td><strong>TOTAL ACTIF</strong></td><td class="text-right"><strong><?= number_format($total_actif, 0, ',', ' ') ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- BILAN PASSIF -->
        <div class="card">
            <div class="card-header"><i class="fas fa-wallet"></i> BILAN CONSOLIDE PASSIF</div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>CODE POSTE</th><th>PASSIF</th><th class="text-right">Montant (FCFA)</th></tr></thead>
                        <tbody>
                            <tr class="subtotal-row"><td>300</td><td colspan="2">DETTES A L'EGARD DES INSTITUTIONS FINANCIERES</td></tr>
                            <tr><td>310</td><td class="indent">A vue</td><td class="text-right"><?= number_format($dettes_vue, 0, ',', ' ') ?></td></tr>
                            <tr class="subtotal-row"><td>330</td><td colspan="2">DETTES A L'EGARD DES MEMBRES OU BENEFICIAIRES</td></tr>
                            <tr><td>331</td><td class="indent">Comptes d'epargne a vue</td><td class="text-right"><?= number_format($epargne_vue, 0, ',', ' ') ?></td></tr>
                            <tr><td>332</td><td class="indent">Comptes d'epargne a terme</td><td class="text-right"><?= number_format($epargne_terme, 0, ',', ' ') ?></td></tr>
                            <tr><td>360</td><td>PROVISIONS POUR RISQUES ET CHARGES</td><td class="text-right"><?= number_format($provisions_risques, 0, ',', ' ') ?></td></tr>
                            <tr><td>362</td><td>EMPRUNTS ET TITRES EMIS SUBORDONNES</td><td class="text-right"><?= number_format($emprunts_subordonnes, 0, ',', ' ') ?></td></tr>
                            <tr><td>370</td><td>SUBVENTIONS D'INVESTISSEMENT</td><td class="text-right"><?= number_format($subventions_investissement, 0, ',', ' ') ?></td></tr>
                            <tr><td>380</td><td>CAPITAL</td><td class="text-right"><?= number_format($capital, 0, ',', ' ') ?></td></tr>
                            <tr><td>400</td><td>REPORT A NOUVEAU</td><td class="text-right"><?= number_format($report_nouveau, 0, ',', ' ') ?></td></tr>
                            <tr><td>420</td><td>EXCEDENT OU DEFICIT DE L'EXERCICE</td><td class="text-right"><?= number_format($resultat_exercice, 0, ',', ' ') ?></td></tr>
                            <tr class="total-row"><td>450</td><td><strong>TOTAL PASSIF</strong></td><td class="text-right"><strong><?= number_format($total_passif, 0, ',', ' ') ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- HORS BILAN -->
        <div class="card">
            <div class="card-header"><i class="fas fa-clipboard-list"></i> HORS BILAN CONSOLIDE</div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>CODE POSTE</th><th>HORS BILAN</th><th class="text-right">Montant (FCFA)</th></tr></thead>
                        <tbody>
                            <tr class="subtotal-row"><td colspan="2">ENGAGEMENTS DONNES</td><td class="text-right"><?= number_format($engagements_donnes, 0, ',', ' ') ?></td></tr>
                            <tr><td>465</td><td class="indent">En faveur des institutions financieres</td><td class="text-right"><?= number_format($engagements_financement_inst, 0, ',', ' ') ?></td></tr>
                            <tr><td>470</td><td class="indent">En faveur des membres, beneficiaires ou clients</td><td class="text-right"><?= number_format($engagements_financement_membres, 0, ',', ' ') ?></td></tr>
                            <tr><td></td><td class="indent">Engagements de garantie - D'ordre des membres</td><td class="text-right"><?= number_format($engagements_garantie_membres, 0, ',', ' ') ?></td></tr>
                            <tr class="subtotal-row"><td colspan="2">ENGAGEMENTS RECUS</td><td class="text-right"><?= number_format($engagements_recus, 0, ',', ' ') ?></td></tr>
                            <tr class="total-row"><td colspan="2"><strong>TOTAL HORS BILAN</strong></td><td class="text-right"><strong><?= number_format($total_hors_bilan, 0, ',', ' ') ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Vérification équilibre -->
    <div class="card">
        <div class="card-header"><i class="fas fa-check-circle"></i> VERIFICATION DE L'EQUILIBRE</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-balance-scale"></i>
                <div>
                    <strong>Total Actif :</strong> <?= number_format($total_actif, 0, ',', ' ') ?> FCFA<br>
                    <strong>Total Passif :</strong> <?= number_format($total_passif, 0, ',', ' ') ?> FCFA<br>
                    <?php if($equilibre_ok): ?>
                        <span class="equilibre-ok">✓ EQUILIBRE - Le total actif est egal au total passif.</span>
                    <?php else: ?>
                        <span class="equilibre-ko">✗ DESEQUILIBRE - Ecart de <?= number_format(abs($difference), 0, ',', ' ') ?> FCFA.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base Mandigo
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Mise à jour dynamique du select de période (mois, trimestre, semestre) pour les filtres
    function updateDynamicSelect() {
        const type = document.getElementById('typePeriodeSelect').value;
        const container = document.getElementById('dynamicSelectContainer');
        const currentMois = <?= $mois ?>;
        const currentTrimestre = <?= $trimestre ?>;
        const currentSemestre = <?= json_encode($semestre) ?>;
        let html = '';
        
        if (type === 'mensuel') {
            html = '<label>Mois</label><select name="mois" id="moisSelect" class="form-select">';
            for (let m = 1; m <= 12; m++) {
                const selected = (m === currentMois) ? 'selected' : '';
                const monthName = new Date(2000, m-1, 1).toLocaleString('fr', {month:'long'});
                html += `<option value="${m}" ${selected}>${String(m).padStart(2,'0')} - ${monthName}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect" class="form-select">';
            for (let t = 1; t <= 4; t++) {
                const selected = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${selected}>${t}${t === 1 ? 'er' : 'eme'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect" class="form-select">';
            for (let s = 1; s <= 2; s++) {
                const selected = (s === currentSemestre) ? 'selected' : '';
                html += `<option value="${s}" ${selected}>${s}${s === 1 ? 'er' : 'e'} semestre</option>`;
            }
            html += '</select>';
        } else {
            html = '<label>Periode</label><input type="text" class="form-control" disabled value="Annee complete">';
        }
        container.innerHTML = html;
    }

    // Soumission des exports en POST (réutilisation des valeurs du formulaire principal)
    function submitExport(type) {
        const form = document.getElementById('filterForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'format';
        input.value = type;
        form.appendChild(input);
        form.submit();
        form.removeChild(input);
    }

    // Fonction d'export Excel (appelée par le bouton Excel)
    function exporterExcel() {
        // Cette fonction est remplacée par submitExport('excel') pour utiliser le POST.
        // On garde le nom pour compatibilité, mais on appelle submitExport.
        submitExport('excel');
    }

    // Initialisation des événements
    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>