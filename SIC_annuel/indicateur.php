<?php
// INDICATEURS_FINANCIERS_ACTIVI.php - Indicateurs financiers d'activité
// Déclaration SICS-BCEAO

session_start();

// Configuration BDD
require_once '../databases/database.php';

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
$date_fin_exercice = $exercice . '-12-31';
$exercice_prec = $exercice - 1;
$date_fin_prec = $exercice_prec . '-12-31';

// Libellé de la période pour l'affichage
switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Annee ' . $exercice;
}

// ============================================================
// I-1 - INDICATEURS DE QUALITÉ DU PORTEFEUILLE
// ============================================================

// Portefeuille total (encours brut des crédits)
$portefeuille_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e 
        ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
    ");
    $stmt->execute();
    $portefeuille_total = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $portefeuille_total = 0; }

// Créances en souffrance
$encours_souffrance = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e 
        ON d.dossier_id = e.dossier_id
        WHERE d.statut = 'impaye'
    ");
    $stmt->execute();
    $encours_souffrance = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $encours_souffrance = 0; }

// Provisions constituées
$provisions_creances = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM provisions WHERE statut = 'actif' AND type_provision = 'CREANCES'");
    $stmt->execute();
    $provisions_creances = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $provisions_creances = 0; }

$par_30 = ($portefeuille_total > 0) ? $encours_souffrance / $portefeuille_total : 0;
$taux_provision = ($encours_souffrance > 0) ? $provisions_creances / $encours_souffrance : 0;

// Pertes sur créances
$pertes_creances = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '657%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $pertes_creances = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $pertes_creances = 0; }

$taux_perte = ($portefeuille_total > 0) ? $pertes_creances / $portefeuille_total : 0;

// ============================================================
// I-2 - INDICATEURS D'ACTIVITÉS
// ============================================================

$total_decaissements = 0;
$nb_decaissements = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as nb, COALESCE(SUM(montant), 0) as total
        FROM dossiers
        WHERE date_octroi BETWEEN :debut AND :fin
          AND statut IN ('actif', 'approuve')
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $nb_decaissements = (int)$result['nb'];
    $total_decaissements = (float)$result['total'];
} catch (PDOException $e) { $nb_decaissements = 0; $total_decaissements = 0; }

$montant_moyen_credit = ($nb_decaissements > 0) ? $total_decaissements / $nb_decaissements : 0;

$total_epargne = 0;
$nb_epargnants = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde), 0) as total
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0
    ");
    $stmt->execute();
    $total_epargne = (float)$stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT c.client_id) as nb
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0
    ");
    $stmt->execute();
    $nb_epargnants = (int)$stmt->fetch()['nb'];
} catch (PDOException $e) { $total_epargne = 0; $nb_epargnants = 0; }

$montant_moyen_epargne = ($nb_epargnants > 0) ? $total_epargne / $nb_epargnants : 0;

$nb_emprunteurs = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT c.client_id) as nb
        FROM dossiers d
        INNER JOIN comptes cpt ON d.compte_id = cpt.compte_id
        INNER JOIN clients c ON cpt.client_id = c.client_id
        WHERE d.statut IN ('actif', 'approuve')
    ");
    $stmt->execute();
    $nb_emprunteurs = (int)$stmt->fetch()['nb'];
} catch (PDOException $e) { $nb_emprunteurs = 0; }

$encours_moyen_emprunteur = ($nb_emprunteurs > 0) ? $portefeuille_total / $nb_emprunteurs : 0;

// ============================================================
// I-3 - INDICATEURS D'EFFICACITÉ/PRODUCTIVITÉ
// ============================================================

$nb_agents_credit = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as nb FROM utilisateurs WHERE role IN ('Gestionnaire', 'Caisse') AND etat = 'actif'");
    $stmt->execute();
    $nb_agents_credit = (int)$stmt->fetch()['nb'];
} catch (PDOException $e) { $nb_agents_credit = 0; }

$productivite_agents = ($nb_agents_credit > 0) ? $nb_emprunteurs / $nb_agents_credit : 0;

$nb_employes = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as nb FROM utilisateurs WHERE role != 'Client' AND etat = 'actif'");
    $stmt->execute();
    $nb_employes = (int)$stmt->fetch()['nb'];
} catch (PDOException $e) { $nb_employes = 0; }

$productivite_personnel = ($nb_employes > 0) ? ($nb_emprunteurs + $nb_epargnants) / $nb_employes : 0;

$charges_exploitation = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '6' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $charges_exploitation = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $charges_exploitation = 0; }

$portefeuille_prec = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' AND date_echeance <= :date_fin GROUP BY dossier_id) e 
        ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve') AND d.date_octroi <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $portefeuille_prec = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $portefeuille_prec = 0; }

$portefeuille_moyen = ($portefeuille_total + $portefeuille_prec) / 2;
$ratio_charges_portefeuille = ($portefeuille_moyen > 0) ? $charges_exploitation / $portefeuille_moyen : 0;

$frais_generaux = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE (pc.numero_compte LIKE '62%' OR pc.numero_compte LIKE '63%' OR pc.numero_compte LIKE '64%') 
          AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $frais_generaux = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $frais_generaux = 0; }

$ratio_frais_generaux = ($portefeuille_moyen > 0) ? $frais_generaux / $portefeuille_moyen : 0;

$charges_personnel = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '62%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $charges_personnel = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $charges_personnel = 0; }

$ratio_charges_personnel = ($portefeuille_moyen > 0) ? $charges_personnel / $portefeuille_moyen : 0;

// ============================================================
// I-4 - INDICATEURS DE RENTABILITÉ
// ============================================================

$produits_exploitation = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '7' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $produits_exploitation = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $produits_exploitation = 0; }

$resultat_exploitation = $produits_exploitation - $charges_exploitation;

$fonds_propres = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $fonds_propres = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $fonds_propres = 0; }

$fonds_propres_prec = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin_prec
    ");
    $stmt->execute([':date_fin_prec' => $date_fin_prec]);
    $fonds_propres_prec = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $fonds_propres_prec = 0; }

$fonds_propres_moyens = ($fonds_propres + $fonds_propres_prec) / 2;
$roe = ($fonds_propres_moyens > 0) ? $resultat_exploitation / $fonds_propres_moyens : 0;

$total_actif = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '2' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $total_actif = abs((float)$stmt->fetch()['total']);
} catch (PDOException $e) { $total_actif = 0; }

$roa = ($total_actif > 0) ? $resultat_exploitation / $total_actif : 0;
$autosuffisance = ($charges_exploitation > 0) ? $produits_exploitation / $charges_exploitation : 0;
$marge_beneficiaire = ($produits_exploitation > 0) ? $resultat_exploitation / $produits_exploitation : 0;

$produits_financiers_net = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE (pc.numero_compte LIKE '70%' OR pc.numero_compte LIKE '76%') 
          AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $produits_financiers_net = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $produits_financiers_net = 0; }

$coefficient_exploitation = ($produits_financiers_net > 0) ? $frais_generaux / $produits_financiers_net : 0;

// ============================================================
// I-5 - INDICATEURS DE GESTION DU BILAN
// ============================================================

$taux_rendement = ($portefeuille_moyen > 0) ? $produits_financiers_net / $portefeuille_moyen : 0;

$disponibilites = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde_actuel), 0) as total FROM caisses WHERE statut = 'ouverte'");
    $stmt->execute();
    $disponibilites = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $disponibilites = 0; }

$ratio_liquidite = ($total_actif > 0) ? $disponibilites / $total_actif : 0;
$ratio_capitalisation = ($total_actif > 0) ? $fonds_propres / $total_actif : 0;

// Normes pour vérification
function getNormeClass($valeur, $min, $max, $inverse = false) {
    if ($inverse) {
        $conforme = ($valeur <= $max);
    } else {
        $conforme = ($valeur >= $min);
    }
    return $conforme ? 'conforme' : 'non-conforme';
}

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
            $this->Cell(0, 7, $this->convert('INDICATEURS FINANCIERS D\'ACTIVITE'), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, $this->convert('Indicateurs de performance - Article 44'), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(10);
        }
        
        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, $this->convert('Page ' . $this->PageNo() . '/{nb} - Genere le ' . date('d/m/Y H:i:s')), 0, 0, 'C');
        }
        
        function SectionTitle($label) {
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(255, 255, 255);
            $this->SetFillColor(0, 0, 0);
            $this->Cell(0, 8, $this->convert($label), 0, 1, 'L', true);
            $this->Ln(2);
        }
        
        function IndicatorCard($title, $value, $norme, $class, $details = '') {
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(80, 7, $this->convert($title), 1, 0);
            $this->SetFont('Arial', '', 9);
            if ($class == 'conforme') {
                $this->SetTextColor(22, 163, 74);
            } else {
                $this->SetTextColor(220, 38, 38);
            }
            $this->Cell(60, 7, $this->convert($value), 1, 0);
            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 7, $this->convert($norme), 1, 1);
            if ($details) {
                $this->SetFont('Arial', '', 7);
                $this->Cell(0, 5, $this->convert($details), 1, 1);
            }
            // $this->Ln(2);
        }
        
        function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
    }
    
    $pdf = new PDF_DIMF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(12, 35, 12);
    $pdf->AddPage();
    
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, $pdf->convert('Periode : ' . $lib_periode), 0, 1, 'C');
    $pdf->Ln(5);
    
    // I-1 - Qualité du portefeuille
    $pdf->SectionTitle('I-1 - Indicateurs de qualite du portefeuille');
    $par_30_value = number_format($par_30 * 100, 2) . '%';
    $par_30_class = ($par_30 <= 0.05) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('PAR 30', $par_30_value, 'Norme : ≤ 5%', $par_30_class, 'Encours souffrance : ' . $pdf->montant($encours_souffrance));
    
    $taux_prov_value = number_format($taux_provision * 100, 2) . '%';
    $taux_prov_class = ($taux_provision >= 0.40) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Taux de provisions', $taux_prov_value, 'Norme : ≥ 40%', $taux_prov_class, 'Provisions : ' . $pdf->montant($provisions_creances));
    
    $taux_perte_value = number_format($taux_perte * 100, 2) . '%';
    $taux_perte_class = ($taux_perte <= 0.02) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Taux de perte sur creances', $taux_perte_value, 'Norme : ≤ 2%', $taux_perte_class, 'Pertes : ' . $pdf->montant($pertes_creances));
    $pdf->Ln(3);
    
    // I-2 - Indicateurs d'activités
    $pdf->SectionTitle('I-2 - Indicateurs d\'activites');
    $pdf->IndicatorCard('Montant moyen des credits', $pdf->montant($montant_moyen_credit), '', '', 'Total de caisses : ' . $pdf->montant($total_decaissements) . ' - Nb credits : ' . number_format($nb_decaissements, 0, ',', ' '));
    $pdf->IndicatorCard('Montant moyen de l\'epargne', $pdf->montant($montant_moyen_epargne), '', '', 'Total epargne : ' . $pdf->montant($total_epargne) . ' - Nb epargnants : ' . number_format($nb_epargnants, 0, ',', ' '));
    $pdf->IndicatorCard('Encours moyen par emprunteur', $pdf->montant($encours_moyen_emprunteur), '', '', 'Encours total : ' . $pdf->montant($portefeuille_total) . ' - Nb emprunteurs : ' . number_format($nb_emprunteurs, 0, ',', ' '));
    $pdf->Ln(3);
    
    // I-3 - Indicateurs d'efficacité
    $pdf->SectionTitle('I-3 - Indicateurs d\'efficacite/productivite');
    $prod_agents_value = number_format($productivite_agents, 0) . ' emp/agent';
    $prod_agents_class = ($productivite_agents >= 130) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Productivite des agents', $prod_agents_value, 'Norme : ≥ 130', $prod_agents_class, 'Agents de credit : ' . $nb_agents_credit);
    
    $prod_perso_value = number_format($productivite_personnel, 0) . ' clients/emp';
    $prod_perso_class = ($productivite_personnel >= 115) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Productivite du personnel', $prod_perso_value, 'Norme : ≥ 115', $prod_perso_class, 'Effectif personnel : ' . $nb_employes);
    
    $ratio_charges_value = number_format($ratio_charges_portefeuille * 100, 2) . '%';
    $ratio_charges_class = ($ratio_charges_portefeuille <= 0.35) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Charges d\'exploitation / Portefeuille', $ratio_charges_value, 'Norme : ≤ 35%', $ratio_charges_class);
    
    $ratio_frais_value = number_format($ratio_frais_generaux * 100, 2) . '%';
    $ratio_frais_class = ($ratio_frais_generaux <= 0.20) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Ratio des frais generaux', $ratio_frais_value, 'Norme : ≤ 20%', $ratio_frais_class);
    
    $ratio_perso_value = number_format($ratio_charges_personnel * 100, 2) . '%';
    $ratio_perso_class = ($ratio_charges_personnel <= 0.10) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Ratio des charges de personnel', $ratio_perso_value, 'Norme : ≤ 10%', $ratio_perso_class);
    $pdf->Ln(3);
    
    // I-4 - Indicateurs de rentabilité
    $pdf->SectionTitle('I-4 - Indicateurs de rentabilite');
    $roe_value = number_format($roe * 100, 2) . '%';
    $roe_class = ($roe >= 0.15) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Rentabilite des fonds propres (ROE)', $roe_value, 'Norme : ≥ 15%', $roe_class);
    
    $roa_value = number_format($roa * 100, 2) . '%';
    $roa_class = ($roa >= 0.03) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Rendement sur actif (ROA)', $roa_value, 'Norme : ≥ 3%', $roa_class);
    
    $auto_value = number_format($autosuffisance, 2);
    $auto_class = ($autosuffisance >= 1.30) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Autosuffisance operationnelle', $auto_value, 'Norme : ≥ 1.30', $auto_class);
    
    $marge_value = number_format($marge_beneficiaire * 100, 2) . '%';
    $marge_class = ($marge_beneficiaire >= 0.20) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Marge beneficiaire', $marge_value, 'Norme : ≥ 20%', $marge_class);
    
    $coeff_value = number_format($coefficient_exploitation * 100, 2) . '%';
    $coeff_class = ($coefficient_exploitation <= 0.60) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Coefficient d\'exploitation', $coeff_value, 'Norme : ≤ 60%', $coeff_class);
    $pdf->Ln(3);
    
    // I-5 - Indicateurs de gestion du bilan
    $pdf->SectionTitle('I-5 - Indicateurs de gestion du bilan');
    $taux_rend_value = number_format($taux_rendement * 100, 2) . '%';
    $taux_rend_class = ($taux_rendement >= 0.15) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Taux de rendement des actifs', $taux_rend_value, 'Norme : ≥ 15%', $taux_rend_class);
    
    $ratio_liq_value = number_format($ratio_liquidite * 100, 2) . '%';
    $ratio_liq_class = ($ratio_liquidite >= 0.05) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Ratio de liquidite de l\'actif', $ratio_liq_value, 'Norme : ≥ 5%', $ratio_liq_class, 'Disponibilites : ' . $pdf->montant($disponibilites));
    
    $ratio_cap_value = number_format($ratio_capitalisation * 100, 2) . '%';
    $ratio_cap_class = ($ratio_capitalisation >= 0.15) ? 'conforme' : 'non-conforme';
    $pdf->IndicatorCard('Ratio de capitalisation', $ratio_cap_value, 'Norme : ≥ 15%', $ratio_cap_class, 'Fonds propres : ' . $pdf->montant($fonds_propres));
    
    $pdf->Output('I', 'INDICATEURS_FINANCIERS_' . $exercice . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INDICATEURS_FINANCIERS_ACTIVI - Indicateurs financiers</title>
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
        
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
        .indicator-card { background: #f8fafc; border-radius: 16px; padding: 18px; border-left: 4px solid #3b82f6; transition: transform 0.2s; }
        .indicator-card:hover { transform: translateY(-2px); }
        .indicator-card .title { font-weight: 600; font-size: 0.85rem; color: #4b5563; margin-bottom: 8px; }
        .indicator-card .value { font-size: 1.6rem; font-weight: 700; margin-bottom: 8px; }
        .indicator-card .norme { font-size: 0.7rem; color: #6b7280; margin-bottom: 8px; }
        .indicator-card .details { font-size: 0.7rem; color: #6b7280; border-top: 1px solid #e2e8f0; margin-top: 8px; padding-top: 8px; }
        .conforme { color: #16a34a; }
        .non-conforme { color: #dc2626; }
        
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            .grid-2 { grid-template-columns: 1fr; }
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
            <h1><i class="fas fa-chart-line"></i> INDICATEURS FINANCIERS D'ACTIVITE</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge">Indicateurs de performance - Article 44</div>
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
                <div><strong>Note :</strong> Les indicateurs ci-dessous sont calcules automatiquement a partir des donnees de la base. Les valeurs en <span style="color:#16a34a;">vert</span> sont conformes aux normes, celles en <span style="color:#dc2626;">rouge</span> necessitent une attention particuliere.</div>
            </div>
        </div>
    </div>

    <!-- I-1 - Qualité du portefeuille -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-simple"></i> I-1 - Indicateurs de qualite du portefeuille</div>
        <div class="card-body">
            <div class="grid-2">
                <div class="indicator-card">
                    <div class="title">PAR 30</div>
                    <div class="value <?= $par_30 <= 0.05 ? 'conforme' : 'non-conforme' ?>"><?= number_format($par_30 * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≤ 5%</div>
                    <div class="details">Encours souffrance : <?= number_format($encours_souffrance, 0, ',', ' ') ?> FCFA</div>
                </div>
                <div class="indicator-card">
                    <div class="title">Taux de provisions pour creances en souffrance</div>
                    <div class="value <?= $taux_provision >= 0.40 ? 'conforme' : 'non-conforme' ?>"><?= number_format($taux_provision * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≥ 40%</div>
                    <div class="details">Provisions : <?= number_format($provisions_creances, 0, ',', ' ') ?> FCFA</div>
                </div>
                <div class="indicator-card">
                    <div class="title">Taux de perte sur creances</div>
                    <div class="value <?= $taux_perte <= 0.02 ? 'conforme' : 'non-conforme' ?>"><?= number_format($taux_perte * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≤ 2%</div>
                    <div class="details">Pertes : <?= number_format($pertes_creances, 0, ',', ' ') ?> FCFA</div>
                </div>
            </div>
        </div>
    </div>

    <!-- I-2 - Indicateurs d'activités -->
    <div class="card">
        <div class="card-header"><i class="fas fa-chart-line"></i> I-2 - Indicateurs d'activites</div>
        <div class="card-body">
            <div class="grid-2">
                <div class="indicator-card">
                    <div class="title">Montant moyen des credits decaisses</div>
                    <div class="value"><?= number_format($montant_moyen_credit, 0, ',', ' ') ?> FCFA</div>
                    <div class="details">Total decaisse : <?= number_format($total_decaissements, 0, ',', ' ') ?> FCFA<br>Nombre de credits : <?= number_format($nb_decaissements) ?></div>
                </div>
                <div class="indicator-card">
                    <div class="title">Montant moyen de l'epargne par epargnant</div>
                    <div class="value"><?= number_format($montant_moyen_epargne, 0, ',', ' ') ?> FCFA</div>
                    <div class="details">Total epargne : <?= number_format($total_epargne, 0, ',', ' ') ?> FCFA<br>Nombre d'epargnants : <?= number_format($nb_epargnants) ?></div>
                </div>
                <div class="indicator-card">
                    <div class="title">Encours moyen des credits par emprunteur</div>
                    <div class="value"><?= number_format($encours_moyen_emprunteur, 0, ',', ' ') ?> FCFA</div>
                    <div class="details">Encours total : <?= number_format($portefeuille_total, 0, ',', ' ') ?> FCFA<br>Emprunteurs actifs : <?= number_format($nb_emprunteurs) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- I-3 - Indicateurs d'efficacité -->
    <div class="card">
        <div class="card-header"><i class="fas fa-gauge-high"></i> I-3 - Indicateurs d'efficacite/productivite</div>
        <div class="card-body">
            <div class="grid-2">
                <div class="indicator-card">
                    <div class="title">Productivite des agents de credits</div>
                    <div class="value <?= $productivite_agents >= 130 ? 'conforme' : 'non-conforme' ?>"><?= number_format($productivite_agents, 0) ?> emp/agent</div>
                    <div class="norme">Norme : ≥ 130</div>
                    <div class="details">Agents de credit : <?= $nb_agents_credit ?></div>
                </div>
                <div class="indicator-card">
                    <div class="title">Productivite du personnel</div>
                    <div class="value <?= $productivite_personnel >= 115 ? 'conforme' : 'non-conforme' ?>"><?= number_format($productivite_personnel, 0) ?> clients/emp</div>
                    <div class="norme">Norme : ≥ 115</div>
                    <div class="details">Effectif personnel : <?= $nb_employes ?></div>
                </div>
                <div class="indicator-card">
                    <div class="title">Charges d'exploitation / Portefeuille credit</div>
                    <div class="value <?= $ratio_charges_portefeuille <= 0.35 ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_charges_portefeuille * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≤ 35%</div>
                </div>
                <div class="indicator-card">
                    <div class="title">Ratio des frais generaux</div>
                    <div class="value <?= $ratio_frais_generaux <= 0.20 ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_frais_generaux * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≤ 20%</div>
                </div>
                <div class="indicator-card">
                    <div class="title">Ratio des charges de personnel</div>
                    <div class="value <?= $ratio_charges_personnel <= 0.10 ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_charges_personnel * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≤ 10%</div>
                </div>
            </div>
        </div>
    </div>

    <!-- I-4 - Indicateurs de rentabilité -->
    <div class="card">
        <div class="card-header"><i class="fas fa-coins"></i> I-4 - Indicateurs de rentabilite</div>
        <div class="card-body">
            <div class="grid-2">
                <div class="indicator-card">
                    <div class="title">Rentabilite des fonds propres (ROE)</div>
                    <div class="value <?= $roe >= 0.15 ? 'conforme' : 'non-conforme' ?>"><?= number_format($roe * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≥ 15%</div>
                </div>
                <div class="indicator-card">
                    <div class="title">Rendement sur actif (ROA)</div>
                    <div class="value <?= $roa >= 0.03 ? 'conforme' : 'non-conforme' ?>"><?= number_format($roa * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≥ 3%</div>
                </div>
                <div class="indicator-card">
                    <div class="title">Autosuffisance operationnelle</div>
                    <div class="value <?= $autosuffisance >= 1.30 ? 'conforme' : 'non-conforme' ?>"><?= number_format($autosuffisance, 2) ?></div>
                    <div class="norme">Norme : ≥ 1.30</div>
                </div>
                <div class="indicator-card">
                    <div class="title">Marge beneficiaire</div>
                    <div class="value <?= $marge_beneficiaire >= 0.20 ? 'conforme' : 'non-conforme' ?>"><?= number_format($marge_beneficiaire * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≥ 20%</div>
                </div>
                <div class="indicator-card">
                    <div class="title">Coefficient d'exploitation</div>
                    <div class="value <?= $coefficient_exploitation <= 0.60 ? 'conforme' : 'non-conforme' ?>"><?= number_format($coefficient_exploitation * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≤ 60%</div>
                </div>
            </div>
        </div>
    </div>

    <!-- I-5 - Indicateurs de gestion du bilan -->
    <div class="card">
        <div class="card-header"><i class="fas fa-scale-balanced"></i> I-5 - Indicateurs de gestion du bilan</div>
        <div class="card-body">
            <div class="grid-2">
                <div class="indicator-card">
                    <div class="title">Taux de rendement des actifs</div>
                    <div class="value <?= $taux_rendement >= 0.15 ? 'conforme' : 'non-conforme' ?>"><?= number_format($taux_rendement * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≥ 15%</div>
                </div>
                <div class="indicator-card">
                    <div class="title">Ratio de liquidite de l'actif</div>
                    <div class="value <?= $ratio_liquidite >= 0.05 ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_liquidite * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≥ 5%</div>
                    <div class="details">Disponibilites : <?= number_format($disponibilites, 0, ',', ' ') ?> FCFA</div>
                </div>
                <div class="indicator-card">
                    <div class="title">Ratio de capitalisation</div>
                    <div class="value <?= $ratio_capitalisation >= 0.15 ? 'conforme' : 'non-conforme' ?>"><?= number_format($ratio_capitalisation * 100, 2) ?>%</div>
                    <div class="norme">Norme : ≥ 15%</div>
                    <div class="details">Fonds propres : <?= number_format($fonds_propres, 0, ',', ' ') ?> FCFA</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Récapitulatif -->
    <div class="card">
        <div class="card-header"><i class="fas fa-table-list"></i> RECAPITULATIF DES INDICATEURS CLES</div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-calculator"></i>
                <div>
                    <strong>Portefeuille de credits :</strong> <?= number_format($portefeuille_total, 0, ',', ' ') ?> FCFA<br>
                    <strong>Encours en souffrance :</strong> <?= number_format($encours_souffrance, 0, ',', ' ') ?> FCFA (<?= number_format($par_30 * 100, 2) ?>%)<br>
                    <strong>Fonds propres :</strong> <?= number_format($fonds_propres, 0, ',', ' ') ?> FCFA<br>
                    <strong>Resultat d'exploitation :</strong> <?= number_format($resultat_exploitation, 0, ',', ' ') ?> FCFA<br>
                    <strong>ROE :</strong> <?= number_format($roe * 100, 2) ?>% &nbsp;|&nbsp;
                    <strong>ROA :</strong> <?= number_format($roa * 100, 2) ?>% &nbsp;|&nbsp;
                    <strong>Autosuffisance :</strong> <?= number_format($autosuffisance, 2) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base Mandigo<br>
        Periode : <?= $exercice ?> - <?= $trimestre ?>eme trimestre (arrete au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
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
        let url = 'indicateur.php?exercice=' + exercice + '&type_periode=' + type;
        
        if (type === 'mensuel') url += '&mois=' + document.getElementById('moisSelect').value;
        if (type === 'trimestre') url += '&trimestre=' + document.getElementById('trimestreSelect').value;
        if (type === 'semestre') url += '&semestre=' + document.getElementById('semestreSelect').value;
        
        window.location.href = url;
    }

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        
        let data = [
            ['INDICATEURS FINANCIERS D\'ACTIVITE'],
            ['Periode : <?= addslashes($lib_periode) ?>'],
            [],
            ['I-1 - Indicateurs de qualite du portefeuille', ''],
            ['PAR 30', '<?= number_format($par_30 * 100, 2) ?>%'],
            ['Taux de provisions', '<?= number_format($taux_provision * 100, 2) ?>%'],
            ['Taux de perte sur creances', '<?= number_format($taux_perte * 100, 2) ?>%'],
            [],
            ['I-2 - Indicateurs d\'activites', ''],
            ['Montant moyen des credits decaisses', '<?= number_format($montant_moyen_credit, 0, '', '') ?>'],
            ['Montant moyen de l\'epargne', '<?= number_format($montant_moyen_epargne, 0, '', '') ?>'],
            ['Encours moyen par emprunteur', '<?= number_format($encours_moyen_emprunteur, 0, '', '') ?>'],
            [],
            ['I-3 - Indicateurs d\'efficacite', ''],
            ['Productivite des agents de credits', '<?= number_format($productivite_agents, 0) ?>'],
            ['Productivite du personnel', '<?= number_format($productivite_personnel, 0) ?>'],
            ['Charges d\'exploitation / Portefeuille', '<?= number_format($ratio_charges_portefeuille * 100, 2) ?>%'],
            ['Ratio des frais generaux', '<?= number_format($ratio_frais_generaux * 100, 2) ?>%'],
            ['Ratio des charges de personnel', '<?= number_format($ratio_charges_personnel * 100, 2) ?>%'],
            [],
            ['I-4 - Indicateurs de rentabilite', ''],
            ['Rentabilite des fonds propres (ROE)', '<?= number_format($roe * 100, 2) ?>%'],
            ['Rendement sur actif (ROA)', '<?= number_format($roa * 100, 2) ?>%'],
            ['Autosuffisance operationnelle', '<?= number_format($autosuffisance, 2) ?>'],
            ['Marge beneficiaire', '<?= number_format($marge_beneficiaire * 100, 2) ?>%'],
            ['Coefficient d\'exploitation', '<?= number_format($coefficient_exploitation * 100, 2) ?>%'],
            [],
            ['I-5 - Indicateurs de gestion du bilan', ''],
            ['Taux de rendement des actifs', '<?= number_format($taux_rendement * 100, 2) ?>%'],
            ['Ratio de liquidite de l\'actif', '<?= number_format($ratio_liquidite * 100, 2) ?>%'],
            ['Ratio de capitalisation', '<?= number_format($ratio_capitalisation * 100, 2) ?>%']
        ];
        
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), "INDICATEURS");
        XLSX.writeFile(wb, 'INDICATEURS_FINANCIERS_<?= $exercice ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>