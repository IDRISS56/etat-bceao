<?php
// INDICATEURS_FINANCIERS_ACTIVI.php - Indicateurs financiers d'activité
// Déclaration SICS-BCEAO
// Version avec POST et Bootstrap 5
// Tableau complet conforme à IND.xlsx

session_start();

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

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
    default:          $mois = isset($_POST['mois']) ? (int)$_POST['mois'] : 12;
}

$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$date_debut_exercice = $exercice . '-01-01';
$date_fin_exercice = $exercice . '-12-31';
$exercice_prec = $exercice - 1;
$date_fin_prec = $exercice_prec . '-12-31';

// Libellé de la période
switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Annee ' . $exercice;
}

// ============================================================
// CALCUL DES INDICATEURS (tous les montants nécessaires)
// ============================================================

// Portefeuille total (encours brut des crédits sains + souffrance)
$portefeuille_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e 
        ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve', 'impaye')
    ");
    $stmt->execute();
    $portefeuille_total = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $portefeuille_total = 0; }

// Créances en souffrance (impayés)
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

// Provisions constituées sur créances en souffrance
$provisions_creances = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM provisions WHERE statut = 'actif' AND type_provision = 'CREANCES'");
    $stmt->execute();
    $provisions_creances = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $provisions_creances = 0; }

// Pertes sur créances enregistrées (comptes 657)
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

// Portefeuille précédent (pour les moyennes)
$portefeuille_prec = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' AND date_echeance <= :date_fin GROUP BY dossier_id) e 
        ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve', 'impaye') AND d.date_octroi <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_prec]);
    $portefeuille_prec = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $portefeuille_prec = 0; }
$portefeuille_moyen = ($portefeuille_total + $portefeuille_prec) / 2;

// Activités
$total_decaissements = 0;
$nb_decaissements = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as nb, COALESCE(SUM(montant), 0) as total FROM dossiers WHERE date_octroi BETWEEN :debut AND :fin AND statut IN ('actif', 'approuve')");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $nb_decaissements = (int)$result['nb'];
    $total_decaissements = (float)$result['total'];
} catch (PDOException $e) { }
$montant_moyen_credit = ($nb_decaissements > 0) ? $total_decaissements / $nb_decaissements : 0;

// Épargne
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
} catch (PDOException $e) { }
$montant_moyen_epargne = ($nb_epargnants > 0) ? $total_epargne / $nb_epargnants : 0;

// Emprunteurs actifs
$nb_emprunteurs = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT c.client_id) as nb
        FROM dossiers d
        INNER JOIN comptes cpt ON d.compte_id = cpt.compte_id
        INNER JOIN clients c ON cpt.client_id = c.client_id
        WHERE d.statut IN ('actif', 'approuve', 'impaye')
    ");
    $stmt->execute();
    $nb_emprunteurs = (int)$stmt->fetch()['nb'];
} catch (PDOException $e) { $nb_emprunteurs = 0; }
$encours_moyen_emprunteur = ($nb_emprunteurs > 0) ? $portefeuille_total / $nb_emprunteurs : 0;

// Efficacité / Productivité
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
$nb_clients_actifs = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT client_id) as nb FROM clients WHERE statut = 'actif'");
    $stmt->execute();
    $nb_clients_actifs = (int)$stmt->fetch()['nb'];
} catch (PDOException $e) { $nb_clients_actifs = 0; }
$productivite_personnel = ($nb_employes > 0) ? $nb_clients_actifs / $nb_employes : 0;

// Charges d'exploitation (classe 6)
$charges_exploitation = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '6' AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $charges_exploitation = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $charges_exploitation = 0; }

$ratio_charges_portefeuille = ($portefeuille_moyen > 0) ? $charges_exploitation / $portefeuille_moyen : 0;

// Frais généraux (62, 63, 64)
$frais_generaux = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE (pc.numero_compte LIKE '62%' OR pc.numero_compte LIKE '63%' OR pc.numero_compte LIKE '64%') AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $frais_generaux = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $frais_generaux = 0; }
$ratio_frais_generaux = ($portefeuille_moyen > 0) ? $frais_generaux / $portefeuille_moyen : 0;

// Charges de personnel (62)
$charges_personnel = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '62%' AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $charges_personnel = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $charges_personnel = 0; }
$ratio_charges_personnel = ($portefeuille_moyen > 0) ? $charges_personnel / $portefeuille_moyen : 0;

// Produits d'exploitation (classe 7)
$produits_exploitation = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '7' AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $produits_exploitation = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $produits_exploitation = 0; }

$resultat_exploitation = $produits_exploitation - $charges_exploitation;

// Fonds propres moyens
$fonds_propres = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $fonds_propres = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $fonds_propres = 0; }
$fonds_propres_prec = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin_prec");
    $stmt->execute([':date_fin_prec' => $date_fin_prec]);
    $fonds_propres_prec = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $fonds_propres_prec = 0; }
$fonds_propres_moyens = ($fonds_propres + $fonds_propres_prec) / 2;
$roe = ($fonds_propres_moyens > 0) ? $resultat_exploitation / $fonds_propres_moyens : 0;

// Actif total moyen
$actif_total_n = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '2' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $actif_total_n = abs((float)$stmt->fetch()['total']);
} catch (PDOException $e) { $actif_total_n = 0; }
$actif_total_prec = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '2' AND e.date_ecriture <= :date_fin_prec");
    $stmt->execute([':date_fin_prec' => $date_fin_prec]);
    $actif_total_prec = abs((float)$stmt->fetch()['total']);
} catch (PDOException $e) { $actif_total_prec = 0; }
$actif_total_moyen = ($actif_total_n + $actif_total_prec) / 2;
$roa = ($actif_total_moyen > 0) ? $resultat_exploitation / $actif_total_moyen : 0;

// Autosuffisance
$autosuffisance = ($charges_exploitation > 0) ? $produits_exploitation / $charges_exploitation : 0;

// Marge bénéficiaire
$marge_beneficiaire = ($produits_exploitation > 0) ? $resultat_exploitation / $produits_exploitation : 0;

// Produits financiers nets (intérêts perçus - charges financières)
$produits_financiers_net = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '70%' AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $produits_financiers_net = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $produits_financiers_net = 0; }
// On déduit les charges financières (R08 à R7A) approximativement
$charges_financieres = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE (pc.numero_compte LIKE 'R08%' OR pc.numero_compte LIKE 'R3A%' OR pc.numero_compte LIKE 'R4B%' OR pc.numero_compte LIKE 'R5B%' OR pc.numero_compte LIKE 'R5E%' OR pc.numero_compte LIKE 'R5Y%' OR pc.numero_compte LIKE 'R6A%' OR pc.numero_compte LIKE 'R6F%' OR pc.numero_compte LIKE 'R6V%' OR pc.numero_compte LIKE 'R7A%') AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $charges_financieres = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $charges_financieres = 0; }
$produits_financiers_net = max(0, $produits_financiers_net - $charges_financieres);
$coefficient_exploitation = ($produits_financiers_net > 0) ? $frais_generaux / $produits_financiers_net : 0;

// Taux de rendement des actifs
$taux_rendement = ($portefeuille_moyen > 0) ? $produits_financiers_net / $portefeuille_moyen : 0;

// Liquidité de l'actif
$disponibilites = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde_actuel), 0) as total FROM caisses WHERE statut = 'ouverte'");
    $stmt->execute();
    $disponibilites = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { $disponibilites = 0; }
$ratio_liquidite = ($actif_total_moyen > 0) ? $disponibilites / $actif_total_moyen : 0;

// Capitalisation
$ratio_capitalisation = ($actif_total_moyen > 0) ? $fonds_propres / $actif_total_moyen : 0;

// ============================================================
// CONSTRUCTION DU TABLEAU D'INDICATEURS (conforme à IND.xlsx)
// ============================================================

$indicateurs = [];

// I-1 - QUALITÉ DU PORTEFEUILLE
$indicateurs[] = ['code' => 'INDIC_FINANC_01', 'nom' => 'Portefeuille classé à risque', 'normes' => '<5% pour x>=30 jours  <3% pour x>=90 jours  <2% pour x>180 jours', 'rcsfd' => '(B2D à B70) – B65', 'calcul' => 'Numérateur = Montant des crédits dont une échéance au moins est impayée depuis plus de x jours', 'valeur' => number_format($encours_souffrance, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_02', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'Dénominateur = Total des encours bruts de crédits, y compris ceux en souffrance', 'valeur' => number_format($portefeuille_total, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_03', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO PAR', 'valeur' => number_format($encours_souffrance / max(1, $portefeuille_total) * 100, 2) . '%'];

$indicateurs[] = ['code' => 'INDIC_FINANC_04', 'nom' => 'Taux de provisions pour créances en souffrance', 'normes' => '>=40%', 'rcsfd' => 'B70, 2ème colonne Amortissements et Provisions', 'calcul' => 'Numérateur = Montant des provisions constituées sur les créances en souffrance', 'valeur' => number_format($provisions_creances, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_05', 'nom' => '', 'normes' => '', 'rcsfd' => 'B70, 1ère colonne Montant brut', 'calcul' => 'Dénominateur = Montant total des créances en souffrance.', 'valeur' => number_format($encours_souffrance, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_06', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($provisions_creances / max(1, $encours_souffrance) * 100, 2) . '%'];

$indicateurs[] = ['code' => 'INDIC_FINANC_07', 'nom' => 'Taux de perte sur créances', 'normes' => '< 2 %', 'rcsfd' => 'Numérateur : T6K+T6L', 'calcul' => 'Numérateur = Montant des pertes enregistrées sur les créances au cours de la période', 'valeur' => number_format($pertes_creances, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_08', 'nom' => '', 'normes' => '', 'rcsfd' => 'Dénominateur : (B2D à B70) – B65', 'calcul' => 'Dénominateur = Total des encours bruts de crédits de la période, y compris ceux en souffrance', 'valeur' => number_format($portefeuille_total, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_09', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($pertes_creances / max(1, $portefeuille_total) * 100, 2) . '%'];

// I-2 - ACTIVITÉS
$indicateurs[] = ['code' => 'INDIC_FINANC_10', 'nom' => 'Montant moyen des crédits décaissés', 'normes' => 'Tendance haussière', 'rcsfd' => '', 'calcul' => 'Numérateur = Mouvements enregistrés sur la période au débit des comptes de crédits aux membres, bénéficiaires ou clients à court, moyen et long terme, au niveau de la balance générale', 'valeur' => number_format($total_decaissements, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_11', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'Dénominateur = Nombre total des crédits décaissés au cours de la période', 'valeur' => number_format($nb_decaissements, 0, ',', ' ')];
$indicateurs[] = ['code' => 'INDIC_FINANC_12', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($montant_moyen_credit, 0, ',', ' ') . ' FCFA'];

$indicateurs[] = ['code' => 'INDIC_FINANC_13', 'nom' => 'Montant moyen de l\'épargne par épargnant', 'normes' => 'Tendance haussière', 'rcsfd' => 'G10 à G35', 'calcul' => 'Numérateur = Dépôts des membres ou bénéficiaires', 'valeur' => number_format($total_epargne, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_14', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'Dénominateur = Nombre de personnes disposant d\'un ou de plusieurs dépôts auprès de l\'institution, y compris l\'épargne obligatoire. Un individu ne peut être pris en compte plus d\'une fois', 'valeur' => number_format($nb_epargnants, 0, ',', ' ')];
$indicateurs[] = ['code' => 'INDIC_FINANC_15', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($montant_moyen_epargne, 0, ',', ' ') . ' FCFA'];

$indicateurs[] = ['code' => 'INDIC_FINANC_16', 'nom' => 'Encours moyen des crédits par emprunteur', 'normes' => 'Tendance haussière', 'rcsfd' => '(B2D à B70) – B65', 'calcul' => 'Numérateur = Total des encours de crédits à la fin de la période (Crédits sains + crédits en souffrance)', 'valeur' => number_format($portefeuille_total, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_17', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'Dénominateur = Nombre de personnes ayant un encours de crédit vis-à-vis de l\'institution. Un individu ne peut être pris en compte plus d\'une fois', 'valeur' => number_format($nb_emprunteurs, 0, ',', ' ')];
$indicateurs[] = ['code' => 'INDIC_FINANC_18', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($encours_moyen_emprunteur, 0, ',', ' ') . ' FCFA'];

// I-3 - EFFICACITÉ / PRODUCTIVITÉ
$indicateurs[] = ['code' => 'INDIC_FINANC_19', 'nom' => 'Productivité des agents de crédit', 'normes' => '>= 130', 'rcsfd' => '', 'calcul' => 'Numérateur = Nombre de personnes ayant un ou plusieurs crédits en cours avec l\'institution. Un individu ne peut être pris en compte plus d\'une fois', 'valeur' => number_format($nb_emprunteurs, 0, ',', ' ')];
$indicateurs[] = ['code' => 'INDIC_FINANC_20', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'Dénominateur = Nombre d\'agents de crédit', 'valeur' => number_format($nb_agents_credit, 0, ',', ' ')];
$indicateurs[] = ['code' => 'INDIC_FINANC_21', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($productivite_agents, 0, ',', ' ')];

$indicateurs[] = ['code' => 'INDIC_FINANC_22', 'nom' => 'Productivité du personnel', 'normes' => '>115', 'rcsfd' => '', 'calcul' => 'Numérateur = Nombre de personnes ayant au moins un dépôt et/ou un crédit en cours auprès de l\'institution. Un individu ne peut être pris en compte plus d\'une fois', 'valeur' => number_format($nb_clients_actifs, 0, ',', ' ')];
$indicateurs[] = ['code' => 'INDIC_FINANC_23', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'Dénominateur = Nombre d\'employés', 'valeur' => number_format($nb_employes, 0, ',', ' ')];
$indicateurs[] = ['code' => 'INDIC_FINANC_24', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($productivite_personnel, 0, ',', ' ')];

$indicateurs[] = ['code' => 'INDIC_FINANC_25', 'nom' => 'Charges d\'exploitation rapportées au portefeuille de crédits', 'normes' => '<=35%', 'rcsfd' => '(R0S à T6B)', 'calcul' => 'Numérateur = Montant des charges d\'exploitation de la période', 'valeur' => number_format($charges_exploitation, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_26', 'nom' => '', 'normes' => '', 'rcsfd' => 'Moyenne (B2D à B70-B65)', 'calcul' => 'Dénominateur = Moyenne du total des encours bruts de crédits de la période, y compris ceux en souffrance', 'valeur' => number_format($portefeuille_moyen, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_27', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($ratio_charges_portefeuille * 100, 2) . '%'];

$indicateurs[] = ['code' => 'INDIC_FINANC_28', 'nom' => 'Ratio des frais généraux rapportés au portefeuille de crédits', 'normes' => '<15% pour crédit direct, <20% pour épargne/crédit', 'rcsfd' => 'S02 à T50', 'calcul' => 'Numérateur = Frais de personnel + impôts et taxes + autres charges externes et charges diverses d\'exploitation + dotations au fonds pour risques financiers généraux', 'valeur' => number_format($frais_generaux, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_29', 'nom' => '', 'normes' => '', 'rcsfd' => 'Moyenne [(B2D à B70) – B65]', 'calcul' => 'Dénominateur = Moyenne du total des encours bruts de crédits de la période, y compris ceux en souffrance', 'valeur' => number_format($portefeuille_moyen, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_30', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($ratio_frais_generaux * 100, 2) . '%'];

$indicateurs[] = ['code' => 'INDIC_FINANC_31', 'nom' => 'Ratio des charges de personnel', 'normes' => '<5% crédit direct, <20% épargne/crédit', 'rcsfd' => 'S02', 'calcul' => 'Numérateur = salaires et traitements + charges sociales + rémunérations versées aux stagiaires', 'valeur' => number_format($charges_personnel, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_32', 'nom' => '', 'normes' => '', 'rcsfd' => 'Moyenne [(B2D à B70) – B65]', 'calcul' => 'Dénominateur = Moyenne du total des encours bruts de crédits, y compris ceux en souffrance', 'valeur' => number_format($portefeuille_moyen, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_33', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($ratio_charges_personnel * 100, 2) . '%'];

// I-4 - RENTABILITÉ
$indicateurs[] = ['code' => 'INDIC_FINANC_34', 'nom' => 'Rentabilité des fonds propres', 'normes' => '>15%', 'rcsfd' => '(V08 à X6B – W53) – (R08 à T6B)', 'calcul' => 'Numérateur = RE = Produits d\'exploitation hors subventions (PE) – Charges d\'exploitation (CE)', 'valeur' => number_format($resultat_exploitation, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_35', 'nom' => '', 'normes' => '', 'rcsfd' => 'L01', 'calcul' => 'Dénominateur = Fonds propres moyens sur la période', 'valeur' => number_format($fonds_propres_moyens, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_36', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($roe * 100, 2) . '%'];

$indicateurs[] = ['code' => 'INDIC_FINANC_37', 'nom' => 'Rendement sur actif', 'normes' => '>3%', 'rcsfd' => 'E90', 'calcul' => 'Numérateur = Résultat d’exploitation hors subventions (RE) (voir «Rentabilité des fonds propres »)', 'valeur' => number_format($resultat_exploitation, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_38', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'Dénominateur = Montant moyen de l’actif', 'valeur' => number_format($actif_total_moyen, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_39', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($roa * 100, 2) . '%'];

$indicateurs[] = ['code' => 'INDIC_FINANC_40', 'nom' => 'Autosuffisance opérationnelle', 'normes' => '>130%', 'rcsfd' => '(V08 à X6B – W53)', 'calcul' => 'Numérateur = Montant total des produits d’exploitation (PE)', 'valeur' => number_format($produits_exploitation, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_41', 'nom' => '', 'normes' => '', 'rcsfd' => '(R08 à T6B)', 'calcul' => 'Dénominateur = Montant total des charges d’exploitation (CE)', 'valeur' => number_format($charges_exploitation, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_42', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($autosuffisance, 2)];

$indicateurs[] = ['code' => 'INDIC_FINANC_43', 'nom' => 'Marge bénéficiaire', 'normes' => '>20%', 'rcsfd' => '(V08 à X6B – W53)', 'calcul' => 'Numérateur = Résultat d’exploitation (RE)', 'valeur' => number_format($resultat_exploitation, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_44', 'nom' => '', 'normes' => '', 'rcsfd' => '– (R08 à T6B) (V08 à X6B – W53)', 'calcul' => 'Dénominateur = Montant total des produits d’exploitation (PE)', 'valeur' => number_format($produits_exploitation, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_45', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($marge_beneficiaire * 100, 2) . '%'];

$indicateurs[] = ['code' => 'INDIC_FINANC_46', 'nom' => 'Coefficient d\'exploitation', 'normes' => '<=40% crédit direct, <=60% épargne/crédit', 'rcsfd' => 'S02 à T50', 'calcul' => 'Numérateur = Frais généraux (FG)', 'valeur' => number_format($frais_generaux, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_47', 'nom' => '', 'normes' => '', 'rcsfd' => '(V08 à V7A) – (R08 à R7A)', 'calcul' => 'Dénominateur = Produits financiers nets (PFN)', 'valeur' => number_format($produits_financiers_net, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_48', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($coefficient_exploitation * 100, 2) . '%'];

// I-5 - GESTION DU BILAN
$indicateurs[] = ['code' => 'INDIC_FINANC_49', 'nom' => 'Taux de rendement des actifs', 'normes' => '>15%', 'rcsfd' => '(V0S à V7A)', 'calcul' => 'Numérateur = Montant des intérêts et des commissions perçus au cours de la période', 'valeur' => number_format($produits_financiers_net, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_50', 'nom' => '', 'normes' => '', 'rcsfd' => '(A01-A10-A60-A70) + (B01-B65-B70) + (C10+C56) + (D1A)', 'calcul' => 'Dénominateur = Opérations avec les institutions financières et assimilées + opérations avec les membres ou bénéficiaires + titres à court terme + immobilisations financières', 'valeur' => number_format($portefeuille_moyen, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_51', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($taux_rendement * 100, 2) . '%'];

$indicateurs[] = ['code' => 'INDIC_FINANC_52', 'nom' => 'Ratio de liquidité de l’actif', 'normes' => '>2% crédit direct, >5% épargne/crédit', 'rcsfd' => '(A10+A12+A2H+A2J+C10)', 'calcul' => 'Numérateur = Encaisses et comptes courants ordinaires + titres à court terme (Disponibilités et comptes courants bancaires + instruments financiers facilement négociables de la période)', 'valeur' => number_format($disponibilites, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_53', 'nom' => '', 'normes' => '', 'rcsfd' => 'E90', 'calcul' => 'Dénominateur = Actif total de la période', 'valeur' => number_format($actif_total_moyen, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_54', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($ratio_liquidite * 100, 2) . '%'];

$indicateurs[] = ['code' => 'INDIC_FINANC_55', 'nom' => 'Ratio de capitalisation', 'normes' => '>15%', 'rcsfd' => 'L01', 'calcul' => 'Numérateur = Fonds propres', 'valeur' => number_format($fonds_propres, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_56', 'nom' => '', 'normes' => '', 'rcsfd' => 'E90', 'calcul' => 'Dénominateur = Montant total de l’actif de la période', 'valeur' => number_format($actif_total_moyen, 0, ',', ' ') . ' FCFA'];
$indicateurs[] = ['code' => 'INDIC_FINANC_57', 'nom' => '', 'normes' => '', 'rcsfd' => '', 'calcul' => 'RATIO', 'valeur' => number_format($ratio_capitalisation * 100, 2) . '%'];

// ============================================================
// EXPORT PDF AVEC FPDF - Tableau complet
// ============================================================
if ($format === 'pdf') {
    if (ob_get_length()) ob_end_clean();
    
    class PDF_DIMF_IND extends FPDF {
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
            $this->Cell(0, 5, $this->convert('Periode : ' . $GLOBALS['lib_periode'] . ' - Arrete au ' . date('d/m/Y', strtotime($GLOBALS['date_fin_periode']))), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(10);
        }
        
        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, $this->convert('Page ' . $this->PageNo() . '/{nb} - Genere le ' . date('d/m/Y H:i:s')), 0, 0, 'C');
        }
        
        function TableHeader($cols) {
            $this->SetFont('Arial', 'B', 7);
            $this->SetFillColor(248, 250, 252);
            $this->SetTextColor(30, 41, 59);
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.2);
            foreach ($cols as $col) {
                $this->Cell($col['w'], 6, $this->convert($col['label']), 1, 0, $col['align'], true);
            }
            $this->Ln();
        }
        
        function TableRow($cols, $data, $style = '') {
            $fill = false;
            if ($style == 'section') {
                $this->SetFillColor(230, 240, 255);
                $this->SetFont('Arial', 'B', 7);
                $fill = true;
            } else {
                $this->SetFillColor(255, 255, 255);
                $this->SetFont('Arial', '', 7);
                $fill = false;
            }
            $this->SetTextColor(15, 23, 42);
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.1);
            foreach ($cols as $i => $col) {
                $val = isset($data[$i]) ? $data[$i] : '';
                $this->Cell($col['w'], 5.5, $this->convert($val), 1, 0, $col['align'], $fill);
            }
            $this->Ln();
        }
    }
    
    $pdf = new PDF_DIMF_IND('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(10, 35, 10);
    $pdf->AddPage();
    
    // Colonnes : Code, NOM, NORMES, CODE RCSFD, ELEMENTS CALCUL, VALEUR
    $cols = [
        ['w' => 22, 'label' => 'Code', 'align' => 'L'],
        ['w' => 32, 'label' => 'NOM DU RATIO', 'align' => 'L'],
        ['w' => 28, 'label' => 'NORMES', 'align' => 'L'],
        ['w' => 25, 'label' => 'CODE RCSFD', 'align' => 'L'],
        ['w' => 60, 'label' => 'ELEMENTS DE CALCUL', 'align' => 'L'],
        ['w' => 23, 'label' => 'VALEUR', 'align' => 'R']
    ];
    
    $pdf->TableHeader($cols);
    
    $section_titles = [
        'INDIC_FINANC_01' => 'I-1 - QUALITE DU PORTEFEUILLE',
        'INDIC_FINANC_10' => 'I-2 - ACTIVITES',
        'INDIC_FINANC_19' => 'I-3 - EFFICACITE/PRODUCTIVITE',
        'INDIC_FINANC_34' => 'I-4 - RENTABILITE',
        'INDIC_FINANC_49' => 'I-5 - GESTION DU BILAN'
    ];
    
    $current_section = '';
    $section_displayed = false;
    
    foreach ($indicateurs as $row) {
        // Détecter le début d'une nouvelle section (première ligne avec code non vide)
        if ($row['code'] != '') {
            // Trouver la section correspondante
            $section = '';
            foreach ($section_titles as $code => $title) {
                if ($row['code'] == $code) {
                    $section = $title;
                    break;
                }
            }
            if ($section && $section != $current_section) {
                // Insérer une ligne de titre de section
                $pdf->TableRow($cols, ['', $section, '', '', '', ''], 'section');
                $current_section = $section;
            }
        }
        $pdf->TableRow($cols, [$row['code'], $row['nom'], $row['normes'], $row['rcsfd'], $row['calcul'], $row['valeur']]);
    }
    
    $pdf->Output('I', 'INDICATEURS_FINANCIERS_' . $exercice . '.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL AVEC XLSX (via JavaScript) - Tableau complet
// ============================================================
// Nous utilisons la méthode JavaScript existante, mais nous allons générer le même tableau.

// ============================================================
// AFFICHAGE WEB - TABLEAU COMPLET
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INDICATEURS_FINANCIERS_ACTIVI - Indicateurs financiers</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- XLSX library pour export Excel -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background: #f1f5f9; padding: 24px; }
        .dashboard { max-width: 1400px; margin: 0 auto; }
        
        .page-header { background: linear-gradient(135deg, #3b82f6, #60a5fa); border-radius: 24px; padding: 20px 28px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; box-shadow: 0 4px 6px -2px rgba(0,0,0,0.05); }
        .header-left h1 { font-size: 1.6rem; font-weight: 600; color: white; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
        .subtitle { font-size: 0.8rem; color: #e0f2fe; line-height: 1.4; }
        .badge-custom { display: inline-block; background: #2563eb; color: white; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 500; margin-top: 8px; }
        
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
        
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        th, td { padding: 8px 12px; border: 1px solid #e2e8f0; vertical-align: top; }
        th { background: #f8fafc; font-weight: 600; color: #1e293b; text-align: center; }
        td { color: #0f172a; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .section-row { background: #d9e8f5; font-weight: 600; }
        .section-row td { background: #d9e8f5; }
        
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters-row { flex-direction: column; align-items: stretch; }
            .btn-group { flex-wrap: wrap; }
            .table-wrapper { overflow-x: auto; }
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
            <div class="badge-custom">Indicateurs de performance - Article 44</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <form method="post" id="pdfForm" style="display: inline;">
                <input type="hidden" name="format" value="pdf">
                <input type="hidden" name="exercice" value="<?= $exercice ?>">
                <input type="hidden" name="type_periode" value="<?= $type_periode ?>">
                <input type="hidden" name="mois" value="<?= $mois ?>">
                <input type="hidden" name="trimestre" value="<?= $trimestre ?>">
                <input type="hidden" name="semestre" value="<?= $semestre ?>">
                <button type="submit" class="btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
            </form>
        </div>
    </div>

    <!-- Filtres -->
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
                        <!-- Contenu dynamique -->
                    </div>
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
                <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                    <i class="fas fa-info-circle"></i> Periode : <?= $lib_periode ?> (arrete au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
                </div>
            </form>
        </div>
    </div>

    <!-- Tableau complet -->
    <div class="card">
        <div class="card-header"><i class="fas fa-table"></i> TABLEAU DES INDICATEURS FINANCIERS</div>
        <div class="card-body">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th style="width:8%">Code</th>
                            <th style="width:12%">NOM DU RATIO</th>
                            <th style="width:10%">NORMES</th>
                            <th style="width:10%">CODE DU RCSFD</th>
                            <th style="width:45%">ELEMENTS DE CALCUL</th>
                            <th style="width:15%">VALEUR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $section_titles = [
                            'INDIC_FINANC_01' => 'I-1 - QUALITE DU PORTEFEUILLE',
                            'INDIC_FINANC_10' => 'I-2 - ACTIVITES',
                            'INDIC_FINANC_19' => 'I-3 - EFFICACITE/PRODUCTIVITE',
                            'INDIC_FINANC_34' => 'I-4 - RENTABILITE',
                            'INDIC_FINANC_49' => 'I-5 - GESTION DU BILAN'
                        ];
                        $current_section = '';
                        foreach ($indicateurs as $row):
                            // Détecter début de section
                            if ($row['code'] != '') {
                                $section = '';
                                foreach ($section_titles as $code => $title) {
                                    if ($row['code'] == $code) {
                                        $section = $title;
                                        break;
                                    }
                                }
                                if ($section && $section != $current_section) {
                                    echo '<tr class="section-row"><td colspan="6"><strong>' . $section . '</strong></td></tr>';
                                    $current_section = $section;
                                }
                            }
                        ?>
                            <tr>
                                <td><?= $row['code'] ?></td>
                                <td><?= $row['nom'] ?></td>
                                <td><?= $row['normes'] ?></td>
                                <td><?= $row['rcsfd'] ?></td>
                                <td><?= $row['calcul'] ?></td>
                                <td class="text-right"><?= $row['valeur'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="footer">
        <i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base Microfinances_dg<br>
        Periode : <?= $exercice ?> - <?= $lib_periode ?> (arrete au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Mise à jour dynamique du select de période
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

    // Export Excel
    function exporterExcel() {
        // Construire les données à partir du tableau PHP
        const data = [
            ['Code', 'NOM DU RATIO', 'NORMES', 'CODE DU RCSFD', 'ELEMENTS DE CALCUL', 'VALEUR']
        ];
        <?php
        $current_section = '';
        foreach ($indicateurs as $row) {
            // Détecter section
            $section = '';
            if ($row['code'] != '') {
                foreach ($section_titles as $code => $title) {
                    if ($row['code'] == $code) {
                        $section = $title;
                        break;
                    }
                }
                if ($section && $section != $current_section) {
                    echo "data.push(['', '" . addslashes($section) . "', '', '', '', '']);\n";
                    $current_section = $section;
                }
            }
            echo "data.push(['" . addslashes($row['code']) . "', '" . addslashes($row['nom']) . "', '" . addslashes($row['normes']) . "', '" . addslashes($row['rcsfd']) . "', '" . addslashes($row['calcul']) . "', '" . addslashes($row['valeur']) . "']);\n";
        }
        ?>
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(data);
        XLSX.utils.book_append_sheet(wb, ws, "INDICATEURS");
        XLSX.writeFile(wb, 'INDICATEURS_FINANCIERS_<?= $exercice ?>.xlsx');
    }

    // Initialisation
    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    });
</script>
</body>
</html>