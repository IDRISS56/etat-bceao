<?php
// DIMF_2980.php - Compte de résultat consolidé
// Déclaration SICS-BCEAO

session_start();

// ============================================================
// CONFIGURATION BDD
// ============================================================
require_once '../../databases/database.php';
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
// CALCUL DES CHARGES CONSOLIDÉES
// ============================================================

// 600 - INTÉRÊTS ET CHARGES ASSIMILÉES
$interets_charges = 0;
$interets_inst_fin = 0;
$interets_membres = 0;
$autres_interets_charges = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '66%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $interets_charges = (float)$stmt->fetch()['total'];
    $interets_inst_fin = $interets_charges;
} catch (PDOException $e) { $interets_charges = 0; }

// 607 - CHARGES SUR CRÉDIT-BAIL
$charges_credit_bail = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '668%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $charges_credit_bail = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $charges_credit_bail = 0; }

// 608 - COMMISSIONS
$commissions_charges = 0;

// 609 - CHARGES SUR OPÉRATIONS FINANCIÈRES
$charges_operations_financieres = 0;
$charges_titres_placement = 0;
$charges_change = 0;
$charges_hors_bilan = 0;
$charges_emprunts_subord = 0;

// 615 - CHARGES DIVERSES D'EXPLOITATION FINANCIÈRE
$charges_diverses_financieres = 0;

// 620 - ACHATS DE MARCHANDISES
$achats_marchandises = 0;

// 630 - FRAIS GÉNÉRAUX D'EXPLOITATION
$frais_generaux = 0;
$frais_personnel = 0;
$autres_frais_generaux = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '62%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $frais_personnel = (float)$stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE (pc.numero_compte LIKE '63%' OR pc.numero_compte LIKE '64%') 
          AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $autres_frais_generaux = (float)$stmt->fetch()['total'];
    
    $frais_generaux = $frais_personnel + $autres_frais_generaux;
} catch (PDOException $e) {
    $frais_personnel = 0;
    $autres_frais_generaux = 0;
    $frais_generaux = 0;
}

// 640 - DOTATIONS AUX AMORTISSEMENTS ET PROVISIONS
$dotations_amortissements = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '681%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $dotations_amortissements = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $dotations_amortissements = 0; }

// 645 - SOLDE EN PERTE DES CORRECTIONS DE VALEURS
$solde_perte_corrections = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '687%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $solde_perte_corrections = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $solde_perte_corrections = 0; }

// 655 - CHARGES EXCEPTIONNELLES
$charges_exceptionnelles = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '67%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $charges_exceptionnelles = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $charges_exceptionnelles = 0; }

// 660 - PERTES SUR EXERCICES ANTÉRIEURS
$pertes_anterieures = 0;

// 670 - IMPOT SUR LES EXCÉDENTS
$impot_excedents = 0;

// TOTAL CHARGES
$total_charges = $interets_charges + $charges_credit_bail + $commissions_charges 
               + $charges_operations_financieres + $charges_diverses_financieres 
               + $achats_marchandises + $frais_generaux + $dotations_amortissements 
               + $solde_perte_corrections + $charges_exceptionnelles + $pertes_anterieures 
               + $impot_excedents;

// ============================================================
// CALCUL DES PRODUITS CONSOLIDÉS
// ============================================================

// 700 - INTÉRÊTS ET PRODUITS ASSIMILÉS
$interets_produits = 0;
$interets_creances_inst = 0;
$interets_creances_membres = 0;
$interets_titres_invest = 0;
$autres_interets_produits = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '76%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $interets_produits = (float)$stmt->fetch()['total'];
    $interets_creances_membres = $interets_produits;
} catch (PDOException $e) { $interets_produits = 0; }

// 707 - PRODUITS SUR CRÉDIT-BAIL
$produits_credit_bail = 0;

// 708 - COMMISSIONS
$commissions_produits = 0;

// 709 - PRODUITS SUR OPÉRATIONS FINANCIÈRES
$produits_operations_financieres = 0;
$produits_titres_placement = 0;
$dividendes = 0;
$produits_change = 0;
$produits_hors_bilan = 0;
$produits_prets_subord = 0;

// 715 - PRODUITS DIVERS D'EXPLOITATION FINANCIÈRE
$produits_divers_financiers = 0;

// 720 - MARGES COMMERCIALES
$marges_commerciales = 0;

// 730 - PRODUITS GÉNÉRAUX D'EXPLOITATION
$produits_generaux = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '78%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $produits_generaux = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $produits_generaux = 0; }

// 740 - REPRISES D'AMORTISSEMENTS ET PROVISIONS
$reprises_amortissements = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '781%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $reprises_amortissements = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $reprises_amortissements = 0; }

// 745 - SOLDE EN BÉNÉFICE DES CORRECTIONS DE VALEURS
$solde_benefice_corrections = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '787%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $solde_benefice_corrections = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $solde_benefice_corrections = 0; }

// 755 - PRODUITS EXCEPTIONNELS
$produits_exceptionnels = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '77%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $produits_exceptionnels = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $produits_exceptionnels = 0; }

// 760 - PROFITS SUR EXERCICES ANTÉRIEURS
$profits_anterieures = 0;

// TOTAL PRODUITS
$total_produits = $interets_produits + $produits_credit_bail + $commissions_produits 
                + $produits_operations_financieres + $produits_divers_financiers 
                + $marges_commerciales + $produits_generaux + $reprises_amortissements 
                + $solde_benefice_corrections + $produits_exceptionnels + $profits_anterieures;

// RÉSULTAT DE L'EXERCICE
$resultat_exercice = $total_produits - $total_charges;
$resultat_type = ($resultat_exercice >= 0) ? "EXCEDENT" : "DEFICIT";
$marge_nette = ($total_produits > 0) ? ($resultat_exercice / $total_produits) * 100 : 0;

// ============================================================
// CLASSE FPDF
// ============================================================
if ($format === 'pdf') {
    // Nettoyer le buffer de sortie
    if (ob_get_length()) {
        ob_end_clean();
    }
    
    // Recherche du fichier FPDF
    $fpdf_path = __DIR__ . '../../fpdf/fpdf.php';
    $alt_fpdf_path = dirname(__DIR__) . '../../fpdf/fpdf.php';
    
    if (file_exists($fpdf_path)) {
        require_once($fpdf_path);
    } elseif (file_exists($alt_fpdf_path)) {
        require_once($alt_fpdf_path);
    } else {
        die("Erreur: La bibliotheque FPDF n'est pas trouvee. Veuillez telecharger FPDF depuis http://www.fpdf.org/ et l'installer dans le dossier 'fpdf/'");
    }
    
    class PDF_DIMF extends FPDF {
        public $codeDimf = 'DIMF_2980';
        public $titreDimf = "Compte de resultat consolide";
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
            $this->Cell(0, 5, $this->convert('SFD : ' . $this->nomSfd . '   |   Periode : ' . $this->periode . '   |   Exercice : ' . $this->exercice), 0, 1, 'C');
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
            $this->Cell(0, 7, $this->convert('  ' . strtoupper($label)), 0, 1, 'C', true);
            $this->SetTextColor(0, 0, 0);
            $this->Ln(2);
        }

        function TableHeader($cols) {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(248, 250, 252);
            $this->SetTextColor(30, 41, 59);
            $this->SetDrawColor(226, 232, 240);
            foreach ($cols as $col) {
                $align = isset($col['align']) ? $col['align'] : 'C';
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
                $align = isset($col['align']) ? $col['align'] : 'C';
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

    // CHARGES
    $cols = [
        ['label' => 'CODE', 'w' => 25, 'align' => 'L'],
        ['label' => 'LIBELLE', 'w' => 130, 'align' => 'L'],
        ['label' => 'Montant (FCFA)', 'w' => 60, 'align' => 'R'],
    ];
    
    $pdf->SectionTitle('CHARGES CONSOLIDEES');
    $pdf->TableHeader($cols);
    
    $pdf->TableRow($cols, ['', 'CHARGES FINANCIERES', $pdf->montant($interets_charges + $charges_credit_bail + $commissions_charges + $charges_operations_financieres)], 'subtotal');
    $pdf->TableRow($cols, ['600', 'Interets et charges assimilees', $pdf->montant($interets_charges)]);
    $pdf->TableRow($cols, ['607', 'Charges sur credit-bail', $pdf->montant($charges_credit_bail)]);
    $pdf->TableRow($cols, ['608', 'Commissions', $pdf->montant($commissions_charges)]);
    $pdf->TableRow($cols, ['609', 'Charges sur operations financieres', $pdf->montant($charges_operations_financieres)]);
    
    $pdf->TableRow($cols, ['', 'CHARGES D\'EXPLOITATION', $pdf->montant($frais_generaux + $dotations_amortissements)], 'subtotal');
    $pdf->TableRow($cols, ['630', 'Frais generaux d\'exploitation', $pdf->montant($frais_generaux)]);
    $pdf->TableRow($cols, ['631', '  - Frais du personnel', $pdf->montant($frais_personnel)]);
    $pdf->TableRow($cols, ['632', '  - Autres frais generaux', $pdf->montant($autres_frais_generaux)]);
    $pdf->TableRow($cols, ['640', 'Dotations aux amortissements', $pdf->montant($dotations_amortissements)]);
    
    $pdf->TableRow($cols, ['', 'AUTRES CHARGES', $pdf->montant($solde_perte_corrections + $charges_exceptionnelles + $pertes_anterieures + $impot_excedents)], 'subtotal');
    $pdf->TableRow($cols, ['645', 'Solde en perte des corrections de valeurs', $pdf->montant($solde_perte_corrections)]);
    $pdf->TableRow($cols, ['655', 'Charges exceptionnelles', $pdf->montant($charges_exceptionnelles)]);
    $pdf->TableRow($cols, ['660', 'Pertes sur exercices anterieurs', $pdf->montant($pertes_anterieures)]);
    $pdf->TableRow($cols, ['670', 'Impot sur les excedents', $pdf->montant($impot_excedents)]);
    
    $pdf->TableRow($cols, ['', 'TOTAL CHARGES', $pdf->montant($total_charges)], 'total');
    
    $pdf->Ln(8);
    
    // PRODUITS
    $pdf->SectionTitle('PRODUITS CONSOLIDES');
    $pdf->TableHeader($cols);
    
    $pdf->TableRow($cols, ['', 'PRODUITS FINANCIERS', $pdf->montant($interets_produits + $produits_credit_bail + $commissions_produits + $produits_operations_financieres)], 'subtotal');
    $pdf->TableRow($cols, ['700', 'Interets et produits assimiles', $pdf->montant($interets_produits)]);
    $pdf->TableRow($cols, ['707', 'Produits sur credit-bail', $pdf->montant($produits_credit_bail)]);
    $pdf->TableRow($cols, ['708', 'Commissions', $pdf->montant($commissions_produits)]);
    $pdf->TableRow($cols, ['709', 'Produits sur operations financieres', $pdf->montant($produits_operations_financieres)]);
    
    $pdf->TableRow($cols, ['', 'AUTRES PRODUITS', $pdf->montant($produits_generaux + $reprises_amortissements + $solde_benefice_corrections + $produits_exceptionnels + $profits_anterieures)], 'subtotal');
    $pdf->TableRow($cols, ['730', 'Produits generaux d\'exploitation', $pdf->montant($produits_generaux)]);
    $pdf->TableRow($cols, ['740', 'Reprises d\'amortissements et provisions', $pdf->montant($reprises_amortissements)]);
    $pdf->TableRow($cols, ['745', 'Solde en benefice des corrections de valeurs', $pdf->montant($solde_benefice_corrections)]);
    $pdf->TableRow($cols, ['755', 'Produits exceptionnels', $pdf->montant($produits_exceptionnels)]);
    $pdf->TableRow($cols, ['760', 'Profits sur exercices anterieurs', $pdf->montant($profits_anterieures)]);
    
    $pdf->TableRow($cols, ['', 'TOTAL PRODUITS', $pdf->montant($total_produits)], 'total');
    
    $pdf->Ln(8);
    
    // RESULTAT
    $pdf->SectionTitle('RESULTAT DE L\'EXERCICE');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(80, 6, $pdf->convert('Resultat = Total Produits - Total Charges'), 0, 1);
    $pdf->Ln(3);
    
    if ($resultat_exercice >= 0) {
        $pdf->SetTextColor(22, 163, 74);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 7, $pdf->convert('EXCEDENT : ' . $pdf->montant($resultat_exercice)), 0, 1);
    } else {
        $pdf->SetTextColor(220, 38, 38);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 7, $pdf->convert('DEFICIT : ' . $pdf->montant(abs($resultat_exercice))), 0, 1);
    }
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Ln(3);
    $pdf->Cell(80, 6, $pdf->convert("Marge nette :"), 0, 0);
    $pdf->Cell(0, 6, number_format($marge_nette, 2) . '%', 0, 1);
    
    $pdf->Output('I', 'DIMF_2980_' . $exercice . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2980 - Compte de resultat consolide</title>
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
        
        .two-columns { display: flex; gap: 20px; flex-wrap: wrap; }
        .two-columns > div { flex: 1; min-width: 400px; }
        
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th { text-align: left; padding: 12px 16px; background: #f8fafc; font-weight: 600; color: #1e293b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
        .text-right { text-align: right; font-family: 'Courier New', monospace; font-weight: 500; }
        .subtotal-row { background: #f8fafc; font-weight: 600; }
        .total-row { background: #f0fdf4; font-weight: 700; border-top: 2px solid #bbf7d0; }
        .indent { padding-left: 30px; }
        
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .result-box { background: #f0fdf4; border-left: 4px solid #22c55e; padding: 20px 24px; border-radius: 16px; text-align: center; }
        
        .excedent { color: #16a34a; font-size: 1.8rem; font-weight: bold; }
        .deficit { color: #dc2626; font-size: 1.8rem; font-weight: bold; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            .two-columns { flex-direction: column; }
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
            <h1><i class="fas fa-chart-line"></i> DIMF_2980 - COMPTE DE RESULTAT CONSOLIDE</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Resultats consolides</div>
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
                <i class="fas fa-info-circle"></i> Periode : <?= $lib_periode ?> (arrete au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </div>

    <!-- Note d'information -->
    <div class="card">
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div><strong>Note :</strong> Le compte de resultat consolide presente la performance financiere du groupe (institution + ses filiales) sur la periode.</div>
            </div>
        </div>
    </div>

    <!-- Deux colonnes : CHARGES et PRODUITS -->
    <div class="two-columns">
        <!-- CHARGES -->
        <div class="card">
            <div class="card-header"><i class="fas fa-arrow-down"></i> CHARGES CONSOLIDEES</div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr><th>CODE</th><th>LIBELLE</th><th class="text-right">Montant (FCFA)</th></tr>
                        </thead>
                        <tbody>
                            <tr class="subtotal-row"><td colspan="2">CHARGES FINANCIERES</td><td class="text-right"><?= number_format($interets_charges + $charges_credit_bail + $commissions_charges + $charges_operations_financieres, 0, ',', ' ') ?></td></tr>
                            <tr><td>600</td><td>Interets et charges assimilees</td><td class="text-right"><?= number_format($interets_charges, 0, ',', ' ') ?></td></tr>
                            <tr><td>607</td><td>Charges sur credit-bail</td><td class="text-right"><?= number_format($charges_credit_bail, 0, ',', ' ') ?></td></tr>
                            <tr><td>608</td><td>Commissions</td><td class="text-right"><?= number_format($commissions_charges, 0, ',', ' ') ?></td></tr>
                            <tr><td>609</td><td>Charges sur operations financieres</td><td class="text-right"><?= number_format($charges_operations_financieres, 0, ',', ' ') ?></td></tr>
                            
                            <tr class="subtotal-row"><td colspan="2">CHARGES D'EXPLOITATION</td><td class="text-right"><?= number_format($frais_generaux + $dotations_amortissements, 0, ',', ' ') ?></td></tr>
                            <tr><td>630</td><td>Frais generaux d'exploitation</td><td class="text-right"><?= number_format($frais_generaux, 0, ',', ' ') ?></td></tr>
                            <tr><td>631</td><td class="indent">- Frais du personnel</td><td class="text-right"><?= number_format($frais_personnel, 0, ',', ' ') ?></td></tr>
                            <tr><td>632</td><td class="indent">- Autres frais generaux</td><td class="text-right"><?= number_format($autres_frais_generaux, 0, ',', ' ') ?></td></tr>
                            <tr><td>640</td><td>Dotations aux amortissements</td><td class="text-right"><?= number_format($dotations_amortissements, 0, ',', ' ') ?></td></tr>
                            
                            <tr class="subtotal-row"><td colspan="2">AUTRES CHARGES</td><td class="text-right"><?= number_format($solde_perte_corrections + $charges_exceptionnelles + $pertes_anterieures + $impot_excedents, 0, ',', ' ') ?></td></tr>
                            <tr><td>645</td><td>Solde en perte des corrections de valeurs</td><td class="text-right"><?= number_format($solde_perte_corrections, 0, ',', ' ') ?></td></tr>
                            <tr><td>655</td><td>Charges exceptionnelles</td><td class="text-right"><?= number_format($charges_exceptionnelles, 0, ',', ' ') ?></td></tr>
                            <tr><td>660</td><td>Pertes sur exercices anterieurs</td><td class="text-right"><?= number_format($pertes_anterieures, 0, ',', ' ') ?></td></tr>
                            <tr><td>670</td><td>Impot sur les excedents</td><td class="text-right"><?= number_format($impot_excedents, 0, ',', ' ') ?></td></tr>
                            
                            <tr class="total-row"><td colspan="2"><strong>TOTAL CHARGES</strong></td><td class="text-right"><strong><?= number_format($total_charges, 0, ',', ' ') ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- PRODUITS -->
        <div class="card">
            <div class="card-header"><i class="fas fa-arrow-up"></i> PRODUITS CONSOLIDES</div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr><th>CODE</th><th>LIBELLE</th><th class="text-right">Montant (FCFA)</th></tr>
                        </thead>
                        <tbody>
                            <tr class="subtotal-row"><td colspan="2">PRODUITS FINANCIERS</td><td class="text-right"><?= number_format($interets_produits + $produits_credit_bail + $commissions_produits + $produits_operations_financieres, 0, ',', ' ') ?></td></tr>
                            <tr><td>700</td><td>Interets et produits assimiles</td><td class="text-right"><?= number_format($interets_produits, 0, ',', ' ') ?></td></tr>
                            <tr><td>707</td><td>Produits sur credit-bail</td><td class="text-right"><?= number_format($produits_credit_bail, 0, ',', ' ') ?></td></tr>
                            <tr><td>708</td><td>Commissions</td><td class="text-right"><?= number_format($commissions_produits, 0, ',', ' ') ?></td></tr>
                            <tr><td>709</td><td>Produits sur operations financieres</td><td class="text-right"><?= number_format($produits_operations_financieres, 0, ',', ' ') ?></td></tr>
                            
                            <tr class="subtotal-row"><td colspan="2">AUTRES PRODUITS</td><td class="text-right"><?= number_format($produits_generaux + $reprises_amortissements + $solde_benefice_corrections + $produits_exceptionnels + $profits_anterieures, 0, ',', ' ') ?></td></tr>
                            <tr><td>730</td><td>Produits generaux d'exploitation</td><td class="text-right"><?= number_format($produits_generaux, 0, ',', ' ') ?></td></tr>
                            <tr><td>740</td><td>Reprises d'amortissements et provisions</td><td class="text-right"><?= number_format($reprises_amortissements, 0, ',', ' ') ?></td></tr>
                            <tr><td>745</td><td>Solde en benefice des corrections de valeurs</td><td class="text-right"><?= number_format($solde_benefice_corrections, 0, ',', ' ') ?></td></tr>
                            <tr><td>755</td><td>Produits exceptionnels</td><td class="text-right"><?= number_format($produits_exceptionnels, 0, ',', ' ') ?></td></tr>
                            <tr><td>760</td><td>Profits sur exercices anterieurs</td><td class="text-right"><?= number_format($profits_anterieures, 0, ',', ' ') ?></td></tr>
                            
                            <tr class="total-row"><td colspan="2"><strong>TOTAL PRODUITS</strong></td><td class="text-right"><strong><?= number_format($total_produits, 0, ',', ' ') ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Résultat de l'exercice -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-simple"></i> RESULTAT DE L'EXERCICE</div>
        <div class="card-body">
            <div class="result-box">
                <strong>Resultat = Total Produits - Total Charges</strong><br><br>
                <span class="<?= $resultat_type == 'EXCEDENT' ? 'excedent' : 'deficit' ?>">
                    <?= number_format(abs($resultat_exercice), 0, ',', ' ') ?> FCFA
                </span><br>
                <span style="font-size: 0.9rem;">
                    L'exercice <?= $exercice ?> se solde par un <strong><?= $resultat_type ?></strong> de 
                    <?= number_format(abs($resultat_exercice), 0, ',', ' ') ?> FCFA
                </span>
                <br><br>
                <strong>Marge nette :</strong> <?= number_format($marge_nette, 2) ?>%
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base Mandigo
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
            html = '<label>Mois</label><select id="moisSelect">';
            for (let m = 1; m <= 12; m++) {
                const s = (m === currentMois) ? 'selected' : '';
                const n = new Date(2000, m-1, 1).toLocaleString('fr', {month:'long'});
                html += `<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`;
            }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select id="trimestreSelect">';
            for (let t = 1; t <= 4; t++) {
                const s = (t === currentTrimestre) ? 'selected' : '';
                html += `<option value="${t}" ${s}>${t}${t === 1 ? 'er' : 'eme'} Trimestre</option>`;
            }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select id="semestreSelect">';
            for (let s = 1; s <= 2; s++) {
                const sel = (s === currentSemestre) ? 'selected' : '';
                html += `<option value="${s}" ${sel}>${s}${s === 1 ? 'er' : 'e'} semestre</option>`;
            }
            html += '</select>';
        } else {
            html = '<label>Periode</label><input type="text" disabled value="Annee complete" style="background:#f3f4f6;cursor:default;">';
        }
        container.innerHTML = html;
    }

    function appliquerFiltres() {
        const exercice = document.getElementById('exerciceSelect').value;
        const type = document.getElementById('typePeriodeSelect').value;
        let url = 'DIMF_2980.php?exercice=' + exercice + '&type_periode=' + type;
        
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        
        window.location.href = url;
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        
        // Onglet CHARGES
        let dataCharges = [
            ['DIMF_2980 - COMPTE DE RESULTAT CONSOLIDE'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['CHARGES CONSOLIDEES'],
            ['CODE', 'LIBELLE', 'Montant (FCFA)'],
            ['', 'CHARGES FINANCIERES', <?= $interets_charges + $charges_credit_bail + $commissions_charges + $charges_operations_financieres ?>],
            ['600', 'Interets et charges assimilees', <?= $interets_charges ?>],
            ['607', 'Charges sur credit-bail', <?= $charges_credit_bail ?>],
            ['608', 'Commissions', <?= $commissions_charges ?>],
            ['609', 'Charges sur operations financieres', <?= $charges_operations_financieres ?>],
            ['', 'CHARGES D\'EXPLOITATION', <?= $frais_generaux + $dotations_amortissements ?>],
            ['630', 'Frais generaux d\'exploitation', <?= $frais_generaux ?>],
            ['631', '  - Frais du personnel', <?= $frais_personnel ?>],
            ['632', '  - Autres frais generaux', <?= $autres_frais_generaux ?>],
            ['640', 'Dotations aux amortissements', <?= $dotations_amortissements ?>],
            ['', 'AUTRES CHARGES', <?= $solde_perte_corrections + $charges_exceptionnelles + $pertes_anterieures + $impot_excedents ?>],
            ['645', 'Solde en perte des corrections de valeurs', <?= $solde_perte_corrections ?>],
            ['655', 'Charges exceptionnelles', <?= $charges_exceptionnelles ?>],
            ['660', 'Pertes sur exercices anterieurs', <?= $pertes_anterieures ?>],
            ['670', 'Impot sur les excedents', <?= $impot_excedents ?>],
            ['', 'TOTAL CHARGES', <?= $total_charges ?>]
        ];
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(dataCharges), "CHARGES");
        
        // Onglet PRODUITS
        let dataProduits = [
            ['DIMF_2980 - COMPTE DE RESULTAT CONSOLIDE'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['PRODUITS CONSOLIDES'],
            ['CODE', 'LIBELLE', 'Montant (FCFA)'],
            ['', 'PRODUITS FINANCIERS', <?= $interets_produits + $produits_credit_bail + $commissions_produits + $produits_operations_financieres ?>],
            ['700', 'Interets et produits assimiles', <?= $interets_produits ?>],
            ['707', 'Produits sur credit-bail', <?= $produits_credit_bail ?>],
            ['708', 'Commissions', <?= $commissions_produits ?>],
            ['709', 'Produits sur operations financieres', <?= $produits_operations_financieres ?>],
            ['', 'AUTRES PRODUITS', <?= $produits_generaux + $reprises_amortissements + $solde_benefice_corrections + $produits_exceptionnels + $profits_anterieures ?>],
            ['730', 'Produits generaux d\'exploitation', <?= $produits_generaux ?>],
            ['740', 'Reprises d\'amortissements et provisions', <?= $reprises_amortissements ?>],
            ['745', 'Solde en benefice des corrections de valeurs', <?= $solde_benefice_corrections ?>],
            ['755', 'Produits exceptionnels', <?= $produits_exceptionnels ?>],
            ['760', 'Profits sur exercices anterieurs', <?= $profits_anterieures ?>],
            ['', 'TOTAL PRODUITS', <?= $total_produits ?>]
        ];
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(dataProduits), "PRODUITS");
        
        // Onglet RESULTAT
        let dataResultat = [
            ['DIMF_2980 - RESULTAT DE L\'EXERCICE'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['INDICATEUR', 'VALEUR'],
            ['Total Produits', <?= $total_produits ?>],
            ['Total Charges', <?= $total_charges ?>],
            ['Resultat de l\'exercice', <?= $resultat_exercice ?>],
            ['Nature du resultat', '<?= $resultat_type ?>'],
            ['Marge nette', <?= $marge_nette ?>]
        ];
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(dataResultat), "RESULTAT");
        
        XLSX.writeFile(wb, 'DIMF_2980_<?= $exercice ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>