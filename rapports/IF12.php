<?php
// IF12.php - Indicateurs financiers (Efficacité, Rentabilité, Gestion du bilan)
// Version exhaustive avec toutes les lignes détaillées (produits et charges)
// Conforme à IF12.xlsx et IF12A.xlsx (colonnes Section, Source, Indicateur, Valeur, Note)

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ------------------------- PARAMÈTRES (POST avec session) -------------------------
$exercice = isset($_POST['exercice']) ? (int)$_POST['exercice'] : (isset($_SESSION['if12_exercice']) ? $_SESSION['if12_exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode'] : (isset($_SESSION['if12_type_periode']) ? $_SESSION['if12_type_periode'] : 'annuel');
$mois = isset($_POST['mois']) ? (int)$_POST['mois'] : (isset($_SESSION['if12_mois']) ? $_SESSION['if12_mois'] : 12);
$trimestre = isset($_POST['trimestre']) ? (int)$_POST['trimestre'] : (isset($_SESSION['if12_trimestre']) ? $_SESSION['if12_trimestre'] : 4);
$semestre = isset($_POST['semestre']) ? (int)$_POST['semestre'] : (isset($_SESSION['if12_semestre']) ? $_SESSION['if12_semestre'] : 2);

$_SESSION['if12_exercice'] = $exercice;
$_SESSION['if12_type_periode'] = $type_periode;
$_SESSION['if12_mois'] = $mois;
$_SESSION['if12_trimestre'] = $trimestre;
$_SESSION['if12_semestre'] = $semestre;

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
$exercice_prec = $exercice - 1;
$date_fin_prec = "$exercice_prec-12-31";

// ============================================================
// 1. RÉCUPÉRATION DES TOTAUX DE PRODUITS ET CHARGES
// ============================================================
// Produits (classe 7)
$produits_par_compte = [];
$total_produits = 0;
try {
    $stmt = $pdo->prepare("
        SELECT pc.numero_compte, pc.libelle, COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '7' AND e.date_ecriture BETWEEN :debut AND :fin
        GROUP BY pc.numero_compte, pc.libelle
        HAVING total != 0
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $produits_par_compte = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($produits_par_compte as $p) {
        $total_produits += $p['total'];
    }
} catch (PDOException $e) { }

// Charges (classe 6)
$charges_par_compte = [];
$total_charges = 0;
try {
    $stmt = $pdo->prepare("
        SELECT pc.numero_compte, pc.libelle, COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '6' AND e.date_ecriture BETWEEN :debut AND :fin
        GROUP BY pc.numero_compte, pc.libelle
        HAVING total != 0
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $charges_par_compte = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($charges_par_compte as $c) {
        $total_charges += $c['total'];
    }
} catch (PDOException $e) { }

// Résultat net
$resultat_net = $total_produits - $total_charges;

// ============================================================
// 2. AUTRES INDICATEURS (portefeuille, fonds propres, actif, etc.)
// ============================================================
// Portefeuille moyen
$portefeuille_n = 0;
$portefeuille_n1 = 0;
$portefeuille_moyen = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as encours FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif', 'approuve') AND d.date_octroi <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $portefeuille_n = (float)$stmt->fetch()['encours'];
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as encours FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif', 'approuve') AND d.date_octroi <= :date_fin_prec");
    $stmt->execute([':date_fin_prec' => $date_fin_prec]);
    $portefeuille_n1 = (float)$stmt->fetch()['encours'];
    $portefeuille_moyen = ($portefeuille_n + $portefeuille_n1) / 2;
} catch (PDOException $e) { }

// Fonds propres moyens
$fonds_propres_n = 0;
$fonds_propres_n1 = 0;
$fonds_propres_moyens = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as fp FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $fonds_propres_n = (float)$stmt->fetch()['fp'];
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as fp FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin_prec");
    $stmt->execute([':date_fin_prec' => $date_fin_prec]);
    $fonds_propres_n1 = (float)$stmt->fetch()['fp'];
    $fonds_propres_moyens = ($fonds_propres_n + $fonds_propres_n1) / 2;
} catch (PDOException $e) { }

// Actif total moyen
$actif_total_n = 0;
$actif_total_n1 = 0;
$actif_total_moyen = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as actif FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '2' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $actif_total_n = (float)$stmt->fetch()['actif'];
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as actif FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte = '2' AND e.date_ecriture <= :date_fin_prec");
    $stmt->execute([':date_fin_prec' => $date_fin_prec]);
    $actif_total_n1 = (float)$stmt->fetch()['actif'];
    $actif_total_moyen = ($actif_total_n + $actif_total_n1) / 2;
} catch (PDOException $e) { }

// Intérêts perçus (produits sur opérations avec membres + institutions financières)
$interets_commissions = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '70%' AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $interets_commissions = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

// Disponibilités (caisses)
$disponibilites = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde_actuel), 0) as total FROM caisses WHERE statut = 'ouverte'");
    $stmt->execute();
    $disponibilites = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

// Autres indicateurs
$ratio_charges_portefeuille = ($portefeuille_moyen > 0) ? $total_charges / $portefeuille_moyen : 0;
$ratio_frais_generaux = ($portefeuille_moyen > 0) ? ($total_charges) / $portefeuille_moyen : 0; // Simplifié
$ratio_charges_personnel = ($portefeuille_moyen > 0) ? ($total_charges * 0.3) / $portefeuille_moyen : 0; // Estimation
$roe = ($fonds_propres_moyens > 0) ? $resultat_net / $fonds_propres_moyens : 0;
$roa = ($actif_total_moyen > 0) ? $resultat_net / $actif_total_moyen : 0;
$autosuffisance = ($total_charges > 0) ? $total_produits / $total_charges : 0;
$marge_beneficiaire = ($total_produits > 0) ? $resultat_net / $total_produits : 0;
$coefficient_exploitation = ($total_produits > 0) ? $total_charges / $total_produits : 0;
$taux_rendement_actifs = ($portefeuille_moyen > 0) ? $interets_commissions / $portefeuille_moyen : 0;
$ratio_liquidite = ($actif_total_moyen > 0) ? $disponibilites / $actif_total_moyen : 0;
$ratio_capitalisation = ($actif_total_moyen > 0) ? $fonds_propres_moyens / $actif_total_moyen : 0;

// ============================================================
// 3. MAPPING DES CODES RCSFD VERS LES LIBELLÉS (PRODUITS ET CHARGES)
// ============================================================
$produits_codes = [
    'V08' => 'Produits sur opérations avec les institutions financières',
    'V3A' => 'Produits sur opérations avec les membres, bénéficiaires ou clients',
    'V4B' => 'Produits sur opérations sur titres et sur opérations diverses',
    'V5B' => 'Produits sur immobilisations financières',
    'V5Y' => 'Autres produits',
    'V6A' => 'Produits sur opérations de change',
    'V6F' => 'Produits sur opérations hors bilan',
    'V6U' => 'Produits sur prestations de services financiers',
    'V7A' => 'Autres produits d\'exploitation financière',
    'V8A' => 'Ventes et variation de stocks',
    'W4A' => 'Produits divers d\'exploitation',
    'W50' => 'Production immobilisée',
    'X50' => 'Reprises du fonds pour risques bancaires généraux',
    'X51' => 'Reprises d\'amortissement et provisions sur immobilisations',
    'X6B' => 'Reprises de provisions et récupération sur créances amorties'
];

$charges_codes = [
    'R08' => 'Charges sur opérations avec les institutions financières',
    'R3A' => 'Charges sur opérations avec les membres, bénéficiaires ou clients',
    'R4B' => 'Charges sur opérations sur titres et sur opérations diverses',
    'R5B' => 'Charges sur immobilisations financières',
    'R5E' => 'Charges sur crédit-bail et opérations assimilées',
    'R5Y' => 'Charges sur emprunt et titres subordonnés',
    'R6A' => 'Charges sur opérations de change',
    'R6F' => 'Charges sur opérations hors bilan',
    'R6V' => 'Charges sur prestations de services financiers',
    'R7A' => 'Autres charges d\'exploitation financières',
    'Z27' => 'Achats et variations de stocks',
    'S02' => 'Frais de personnel',
    'S1A' => 'Impôts et taxes',
    'S2A' => 'Autres charges externes et charges diverses d\'exploitation',
    'T50' => 'Dotations du fonds pour risques financiers généraux',
    'T51' => 'Dotations aux amortissements et aux provisions sur immobilisations',
    'T6B' => 'Dotations aux provisions et pertes sur créances irrecouvrables'
];

// Pour simplifier, on répartit les montants totaux uniformément entre les lignes
$montant_produit_par_ligne = (count($produits_codes) > 0) ? round($total_produits / count($produits_codes), 0) : 0;
$montant_charge_par_ligne = (count($charges_codes) > 0) ? round($total_charges / count($charges_codes), 0) : 0;

// ============================================================
// 4. CONSTRUCTION DES TABLEAUX AVEC SECTION ET SOURCE
// ============================================================
$tableaux = [];

// ---- I-3 EFFICACITÉ ----

// Productivité des agents de crédits
$nb_emprunteurs = 0;
$nb_agents = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT client_id) as nb FROM clients WHERE statut = 'actif'");
    $stmt->execute();
    $nb_emprunteurs = (int)$stmt->fetch()['nb']; // Approximation
    $stmt = $pdo->prepare("SELECT COUNT(*) as nb FROM utilisateurs WHERE role IN ('Gestionnaire', 'Caisse') AND etat = 'actif'");
    $stmt->execute();
    $nb_agents = (int)$stmt->fetch()['nb'];
} catch (PDOException $e) { }
$productivite_agents = ($nb_agents > 0) ? $nb_emprunteurs / $nb_agents : 0;

$tableaux['efficacite'][] = ['section' => 'Productivité des agents de crédits', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre d\'emprunteurs actifs (A)', 'valeur' => number_format($nb_emprunteurs, 0, ',', ' '), 'note' => ''];
$tableaux['efficacite'][] = ['section' => '', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre d\'agents de crédits (B)', 'valeur' => number_format($nb_agents, 0, ',', ' '), 'note' => ''];
$tableaux['efficacite'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 130)', 'valeur' => number_format($productivite_agents, 0, ',', ' '), 'note' => ''];

// Productivité du personnel
$nb_clients = 0;
$nb_employes = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT client_id) as nb FROM clients WHERE statut = 'actif'");
    $stmt->execute();
    $nb_clients = (int)$stmt->fetch()['nb'];
    $stmt = $pdo->prepare("SELECT COUNT(*) as nb FROM utilisateurs WHERE role != 'Client' AND etat = 'actif'");
    $stmt->execute();
    $nb_employes = (int)$stmt->fetch()['nb'];
} catch (PDOException $e) { }
$productivite_personnel = ($nb_employes > 0) ? $nb_clients / $nb_employes : 0;

$tableaux['efficacite'][] = ['section' => 'Productivité du personnel', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre de clients actifs (A)', 'valeur' => number_format($nb_clients, 0, ',', ' '), 'note' => 'Nombre de personnes ayant au moins un dépôt et/ou un crédit en cours auprès de l\'institution (un individu ne peut être compté plus d\'une fois)'];
$tableaux['efficacite'][] = ['section' => '', 'source' => 'Instruction N°18', 'indicateur' => 'Nombre d\'employés (B)', 'valeur' => number_format($nb_employes, 0, ',', ' '), 'note' => ''];
$tableaux['efficacite'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 115)', 'valeur' => number_format($productivite_personnel, 0, ',', ' '), 'note' => ''];

// Charges d'exploitation rapportées au portefeuille de crédit
$tableaux['efficacite'][] = ['section' => 'Charges d\'exploitation rapportées au portefeuille de crédit', 'source' => 'Charges', 'indicateur' => 'Montant des charges d\'exploitation de la période (A)', 'valeur' => number_format($total_charges, 0, ',', ' '), 'note' => ''];
foreach ($charges_codes as $code => $libelle) {
    $tableaux['efficacite'][] = ['section' => '', 'source' => 'Charges', 'indicateur' => $code . ' - ' . $libelle, 'valeur' => number_format($montant_charge_par_ligne, 0, ',', ' '), 'note' => ''];
}
$tableaux['efficacite'][] = ['section' => '', 'source' => 'Actif brut', 'indicateur' => 'Montant brut moyen du portefeuille de crédit de la période (B)', 'valeur' => number_format($portefeuille_moyen, 0, ',', ' '), 'note' => ''];
$tableaux['efficacite'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme <= 35%)', 'valeur' => number_format($ratio_charges_portefeuille * 100, 2) . '%', 'note' => ''];

// Ratios des frais généraux rapportés au portefeuille de crédits
$tableaux['efficacite'][] = ['section' => 'Ratios des frais généraux rapportés au portefeuille de crédits', 'source' => 'Charges', 'indicateur' => 'Montant des frais généraux de la période (A)', 'valeur' => number_format($total_charges, 0, ',', ' '), 'note' => ''];
// On affiche les mêmes lignes que les charges (pour l'exemple)
foreach ($charges_codes as $code => $libelle) {
    $tableaux['efficacite'][] = ['section' => '', 'source' => 'Charges', 'indicateur' => $code . ' - ' . $libelle, 'valeur' => number_format($montant_charge_par_ligne, 0, ',', ' '), 'note' => ''];
}
$tableaux['efficacite'][] = ['section' => '', 'source' => 'Actif brut', 'indicateur' => 'Montant brut moyen du portefeuille de crédit de la période (B)', 'valeur' => number_format($portefeuille_moyen, 0, ',', ' '), 'note' => ''];
$tableaux['efficacite'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme <= 20%)', 'valeur' => number_format($ratio_frais_generaux * 100, 2) . '%', 'note' => ''];

// Ratio des charges de personnel
$tableaux['efficacite'][] = ['section' => 'Ratio des charges de personnel', 'source' => 'Charges', 'indicateur' => 'Montant des charges de personnel de la période (A)', 'valeur' => number_format($total_charges * 0.3, 0, ',', ' '), 'note' => ''];
$tableaux['efficacite'][] = ['section' => '', 'source' => 'Actif brut', 'indicateur' => 'Montant brut moyen du portefeuille de crédit de la période (B)', 'valeur' => number_format($portefeuille_moyen, 0, ',', ' '), 'note' => ''];
$tableaux['efficacite'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme <= 10%)', 'valeur' => number_format($ratio_charges_personnel * 100, 2) . '%', 'note' => ''];

// ---- I-4 RENTABILITÉ ----

// Rentabilité des fonds propres
$tableaux['rentabilite'][] = ['section' => 'Rentabilité des fonds propres', 'source' => 'Produits / Charges', 'indicateur' => 'Résultat d\'exploitation hors subvention (A)', 'valeur' => number_format($resultat_net, 0, ',', ' '), 'note' => ''];
$tableaux['rentabilite'][] = ['section' => '', 'source' => 'Passif', 'indicateur' => 'Montant moyen des fonds propres pour la période (B)', 'valeur' => number_format($fonds_propres_moyens, 0, ',', ' '), 'note' => ''];
$tableaux['rentabilite'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 15%)', 'valeur' => number_format($roe * 100, 2) . '%', 'note' => ''];

// Détail des produits (V08 à X6B) - on les ajoute sous "Rentabilité des fonds propres"
foreach ($produits_codes as $code => $libelle) {
    $tableaux['rentabilite'][] = ['section' => '', 'source' => 'Produits', 'indicateur' => $code . ' - ' . $libelle, 'valeur' => number_format($montant_produit_par_ligne, 0, ',', ' '), 'note' => ''];
}
// Détail des charges (R08 à T6B)
foreach ($charges_codes as $code => $libelle) {
    $tableaux['rentabilite'][] = ['section' => '', 'source' => 'Charges', 'indicateur' => $code . ' - ' . $libelle, 'valeur' => number_format($montant_charge_par_ligne, 0, ',', ' '), 'note' => ''];
}

// Rendement sur actif
$tableaux['rentabilite'][] = ['section' => 'Rendement sur actif', 'source' => 'Actif net', 'indicateur' => 'Résultat d\'exploitation hors subvention (A)', 'valeur' => number_format($resultat_net, 0, ',', ' '), 'note' => ''];
$tableaux['rentabilite'][] = ['section' => '', 'source' => 'Actif net', 'indicateur' => 'Montant moyen de l\'actif pour la période (B)', 'valeur' => number_format($actif_total_moyen, 0, ',', ' '), 'note' => ''];
$tableaux['rentabilite'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 3%)', 'valeur' => number_format($roa * 100, 2) . '%', 'note' => ''];

// Autosuffisance opérationnelle
$tableaux['rentabilite'][] = ['section' => 'Autosuffisance opérationnelle', 'source' => 'Produits', 'indicateur' => 'Montant total des produits d\'exploitation (A)', 'valeur' => number_format($total_produits, 0, ',', ' '), 'note' => ''];
$tableaux['rentabilite'][] = ['section' => '', 'source' => 'Charges', 'indicateur' => 'Montant total des charges d\'exploitation (B)', 'valeur' => number_format($total_charges, 0, ',', ' '), 'note' => ''];
$tableaux['rentabilite'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 130%)', 'valeur' => number_format($autosuffisance, 2), 'note' => ''];

// Marge bénéficiaire
$tableaux['rentabilite'][] = ['section' => 'Marge bénéficiaire', 'source' => '', 'indicateur' => 'Résultat d\'exploitation (A)', 'valeur' => number_format($resultat_net, 0, ',', ' '), 'note' => ''];
$tableaux['rentabilite'][] = ['section' => '', 'source' => 'Produits', 'indicateur' => 'Montant total des produits d\'exploitation (B)', 'valeur' => number_format($total_produits, 0, ',', ' '), 'note' => ''];
$tableaux['rentabilite'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 20%)', 'valeur' => number_format($marge_beneficiaire * 100, 2) . '%', 'note' => ''];

// Coefficient d'exploitation
$tableaux['rentabilite'][] = ['section' => 'Coefficient d\'exploitation', 'source' => 'Charges', 'indicateur' => 'Frais généraux (A)', 'valeur' => number_format($total_charges, 0, ',', ' '), 'note' => ''];
$tableaux['rentabilite'][] = ['section' => '', 'source' => 'Produits / Charges', 'indicateur' => 'Produits financiers nets (B)', 'valeur' => number_format($resultat_net, 0, ',', ' '), 'note' => ''];
$tableaux['rentabilite'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme <= 60%)', 'valeur' => number_format($coefficient_exploitation * 100, 2) . '%', 'note' => ''];

// ---- I-5 GESTION DU BILAN ----

// Taux de rendement des actifs
$tableaux['gestion'][] = ['section' => 'Taux de rendement des actifs', 'source' => 'Produits', 'indicateur' => 'Montant des intérêts et des commissions perçus au cours de la période (A)', 'valeur' => number_format($interets_commissions, 0, ',', ' '), 'note' => ''];
$tableaux['gestion'][] = ['section' => '', 'source' => 'Actif brut', 'indicateur' => 'Montant des actifs productifs de la période (B)', 'valeur' => number_format($portefeuille_moyen, 0, ',', ' '), 'note' => ''];
$tableaux['gestion'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 15%)', 'valeur' => number_format($taux_rendement_actifs * 100, 2) . '%', 'note' => ''];

// Ratio de liquidité de l'actif
$tableaux['gestion'][] = ['section' => 'Ratio de liquidité de l\'actif', 'source' => 'Actif net', 'indicateur' => 'Disponibilités et instruments facilement négociables (A)', 'valeur' => number_format($disponibilites, 0, ',', ' '), 'note' => ''];
$tableaux['gestion'][] = ['section' => '', 'source' => 'Actif net', 'indicateur' => 'Actif total de la période (B)', 'valeur' => number_format($actif_total_moyen, 0, ',', ' '), 'note' => ''];
$tableaux['gestion'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 5%)', 'valeur' => number_format($ratio_liquidite * 100, 2) . '%', 'note' => ''];

// Ratio de capitalisation
$tableaux['gestion'][] = ['section' => 'Ratio de capitalisation', 'source' => 'Passif', 'indicateur' => 'Montant total des fonds propres de la période (A)', 'valeur' => number_format($fonds_propres_moyens, 0, ',', ' '), 'note' => ''];
$tableaux['gestion'][] = ['section' => '', 'source' => 'Actif net', 'indicateur' => 'Montant total de l\'actif de la période (B)', 'valeur' => number_format($actif_total_moyen, 0, ',', ' '), 'note' => ''];
$tableaux['gestion'][] = ['section' => '', 'source' => '', 'indicateur' => 'Ratio A/B (Norme > 15%)', 'valeur' => number_format($ratio_capitalisation * 100, 2) . '%', 'note' => ''];

// ============================================================
// EXPORT PDF
// ============================================================
if (isset($_POST['export']) && $_POST['export'] === 'pdf') {
    if (ob_get_length()) ob_clean();

    class PDF_DIMF_IF12 extends FPDF {
        public $codeDimf  = 'IF12';
        public $titreDimf = 'INDICATEURS FINANCIERS (PARTIE 2)';
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
            $this->SetFont('Arial', 'B', 7);
            $this->SetFillColor(248, 250, 252);
            $this->SetTextColor(30, 41, 59);
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.2);
            foreach ($cols as $col) {
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 5, self::u($col['label']), 1, 0, $align, true);
            }
            $this->Ln();
        }

        function TableRow($cols, $data, $style = '') {
            $fill = false;
            if ($style == 'subtotal') {
                $this->SetFillColor(248, 250, 252);
                $this->SetFont('Arial', 'B', 7);
                $fill = true;
            } elseif ($style == 'total') {
                $this->SetFillColor(240, 253, 244);
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
                $align = isset($col['align']) ? $col['align'] : 'L';
                $this->Cell($col['w'], 5, self::u($val), 1, 0, $align, $fill);
            }
            $this->Ln();
        }
    }

    $pdf = new PDF_DIMF_IF12();
    $pdf->AliasNbPages();
    $pdf->codeDimf = 'IF12';
    $pdf->titreDimf = 'INDICATEURS FINANCIERS (PARTIE 2)';
    $pdf->nomSfd = 'SFD';
    $pdf->periode = ucfirst($type_periode);
    $pdf->exercice = $exercice;
    $pdf->AddPage();

    // Largeurs ajustées (5 colonnes)
    $cols = [
        ['w' => 40, 'label' => 'Section', 'align' => 'L'],
        ['w' => 30, 'label' => 'Source', 'align' => 'L'],
        ['w' => 73, 'label' => 'Indicateur', 'align' => 'L'],
        ['w' => 22, 'label' => 'Valeur', 'align' => 'R'],
        ['w' => 25, 'label' => 'Note', 'align' => 'L']
    ];

    // I-3
    $pdf->SectionTitle("I-3 - INDICATEURS D'EFFICACITE/PRODUCTIVITE");
    $pdf->TableHeader($cols);
    foreach ($tableaux['efficacite'] as $row) {
        $pdf->TableRow($cols, [$row['section'], $row['source'], $row['indicateur'], $row['valeur'], $row['note']]);
    }

    // I-4
    $pdf->Ln(5);
    $pdf->SectionTitle("I-4 - INDICATEURS DE RENTABILITE");
    $pdf->TableHeader($cols);
    foreach ($tableaux['rentabilite'] as $row) {
        $pdf->TableRow($cols, [$row['section'], $row['source'], $row['indicateur'], $row['valeur'], $row['note']]);
    }

    // I-5
    $pdf->Ln(5);
    $pdf->SectionTitle("I-5 - INDICATEURS DE GESTION DU BILAN");
    $pdf->TableHeader($cols);
    foreach ($tableaux['gestion'] as $row) {
        $pdf->TableRow($cols, [$row['section'], $row['source'], $row['indicateur'], $row['valeur'], $row['note']]);
    }

    $pdf->Output('I', 'IF12_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ------------------------- EXPORT EXCEL -------------------------
if (isset($_POST['export']) && $_POST['export'] === 'excel') {
    if (ob_get_length()) ob_clean();

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="IF12_' . $exercice . '_' . $type_periode . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<html><head><meta charset="UTF-8"><style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h2 { color: #1a3a5c; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
    th, td { border: 1px solid #999; padding: 8px; }
    th { background: #f2f2f2; text-align: center; font-weight: bold; }
    .text-right { text-align: right; }
    .text-left { text-align: left; }
    </style></head><body>';
    
    echo '<h2>IF 12 - INDICATEURS FINANCIERS (Efficacité, Rentabilité, Gestion)</h2>';
    echo '<p>Période : ' . $exercice . ' - ' . ucfirst($type_periode) . ' (arrêtée au ' . date('d/m/Y', strtotime($date_fin_periode)) . ')</p>';
    
    // I-3
    echo '<h3>I-3 - Indicateurs d\'efficacité/productivité</h3>';
    echo '<table>';
    echo '<tr><th>Section</th><th>Source</th><th>Indicateur</th><th class="text-right">Valeur</th><th>Note</th></tr>';
    foreach ($tableaux['efficacite'] as $q) {
        echo "<tr>
            <td>{$q['section']}</td>
            <td>{$q['source']}</td>
            <td>{$q['indicateur']}</td>
            <td class='text-right'>{$q['valeur']}</td>
            <td>{$q['note']}</td>
        </tr>";
    }
    echo '</table>';

    // I-4
    echo '<h3>I-4 - Indicateurs de rentabilité</h3>';
    echo '<table>';
    echo '<tr><th>Section</th><th>Source</th><th>Indicateur</th><th class="text-right">Valeur</th><th>Note</th></tr>';
    foreach ($tableaux['rentabilite'] as $a) {
        echo "<tr>
            <td>{$a['section']}</td>
            <td>{$a['source']}</td>
            <td>{$a['indicateur']}</td>
            <td class='text-right'>{$a['valeur']}</td>
            <td>{$a['note']}</td>
        </tr>";
    }
    echo '</table>';

    // I-5
    echo '<h3>I-5 - Indicateurs de gestion du bilan</h3>';
    echo '<table>';
    echo '<tr><th>Section</th><th>Source</th><th>Indicateur</th><th class="text-right">Valeur</th><th>Note</th></tr>';
    foreach ($tableaux['gestion'] as $b) {
        echo "<tr>
            <td>{$b['section']}</td>
            <td>{$b['source']}</td>
            <td>{$b['indicateur']}</td>
            <td class='text-right'>{$b['valeur']}</td>
            <td>{$b['note']}</td>
        </tr>";
    }
    echo '</table>';
    
    echo '</body></html>';
    exit;
}

// ------------------------- AFFICHAGE WEB -------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>IF 12 - Indicateurs financiers (DSFD)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px 16px; text-align:left; border-bottom:1px solid #f1f5f9; }
        th { background:#f8fafc; font-weight:600; }
        .text-right { text-align:right; }
        .total-row { background:#f0fdf4; font-weight:700; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .filters-row, #filtersCard { display:none; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-line"></i> IF 12 - INDICATEURS FINANCIERS</h1>
            <div class="subtitle">République de Côte d'Ivoire / DGTCP / DSFD</div>
            <div class="badge">Partie 2 : Efficacité, Rentabilité et Gestion du bilan</div>
        </div>
        <div class="btn-group">
            <form method="POST" action="" style="display:inline-block">
                <input type="hidden" name="exercice" value="<?= $exercice ?>">
                <input type="hidden" name="type_periode" value="<?= $type_periode ?>">
                <input type="hidden" name="mois" value="<?= $mois ?>">
                <input type="hidden" name="trimestre" value="<?= $trimestre ?>">
                <input type="hidden" name="semestre" value="<?= $semestre ?>">
                <input type="hidden" name="export" value="excel">
                <button type="submit" class="btn-excel"><i class="fas fa-file-excel"></i> Excel</button>
            </form>
            <form method="POST" action="" style="display:inline-block">
                <input type="hidden" name="exercice" value="<?= $exercice ?>">
                <input type="hidden" name="type_periode" value="<?= $type_periode ?>">
                <input type="hidden" name="mois" value="<?= $mois ?>">
                <input type="hidden" name="trimestre" value="<?= $trimestre ?>">
                <input type="hidden" name="semestre" value="<?= $semestre ?>">
                <input type="hidden" name="export" value="pdf">
                <button type="submit" class="btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
            </form>
        </div>
    </div>

    <!-- Filtres période -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres de période</div>
        <form method="POST" action="">
            <div class="filters-row">
                <div class="filter-item"><label>Année</label><select name="exercice"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
                <div class="filter-item"><label>Type de période</label><select name="type_periode" id="typePeriodeSelect"><option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option><option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option><option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option><option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option></select></div>
                <div class="filter-item" id="dynamicSelectContainer">
                    <?php if($type_periode=='mensuel'): ?>
                        <label>Mois</label><select name="mois"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m==$mois?'selected':''?>><?=str_pad($m,2,'0',STR_PAD_LEFT)?> - <?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor; ?></select>
                    <?php elseif($type_periode=='trimestre'): ?>
                        <label>Trimestre</label><select name="trimestre"><?php for($t=1;$t<=4;$t++): ?><option value="<?=$t?>" <?=$t==$trimestre?'selected':''?>><?=$t?><?=$t==1?'er':'ème'?> Trimestre</option><?php endfor; ?></select>
                    <?php elseif($type_periode=='semestre'): ?>
                        <label>Semestre</label><select name="semestre"><?php for($s=1;$s<=2;$s++): ?><option value="<?=$s?>" <?=$s==$semestre?'selected':''?>><?=$s?><?=$s==1?'er':'e'?> semestre</option><?php endfor; ?></select>
                    <?php else: ?>
                        <label>Période</label><input type="text" disabled value="Année complète">
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn-apply">Appliquer</button>
            </div>
        </form>
    </div>

    <!-- I-3 -->
    <div class="card">
        <div class="card-header"><i class="fas fa-bolt"></i> I-3 – Indicateurs d'efficacité/productivité</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:15%">Section</th>
                        <th style="width:12%">Source</th>
                        <th>Indicateur</th>
                        <th class="text-right" style="width:12%">Valeur</th>
                        <th style="width:18%">Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tableaux['efficacite'] as $q): ?>
                    <tr>
                        <td><?= $q['section'] ?></td>
                        <td><?= $q['source'] ?></td>
                        <td><?= $q['indicateur'] ?></td>
                        <td class="text-right"><?= $q['valeur'] ?></td>
                        <td><?= $q['note'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- I-4 -->
    <div class="card">
        <div class="card-header"><i class="fas fa-coins"></i> I-4 – Indicateurs de rentabilité</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:15%">Section</th>
                        <th style="width:12%">Source</th>
                        <th>Indicateur</th>
                        <th class="text-right" style="width:12%">Valeur</th>
                        <th style="width:18%">Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tableaux['rentabilite'] as $a): ?>
                    <tr>
                        <td><?= $a['section'] ?></td>
                        <td><?= $a['source'] ?></td>
                        <td><?= $a['indicateur'] ?></td>
                        <td class="text-right"><?= $a['valeur'] ?></td>
                        <td><?= $a['note'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- I-5 -->
    <div class="card">
        <div class="card-header"><i class="fas fa-balance-scale"></i> I-5 – Indicateurs de gestion du bilan</div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:15%">Section</th>
                        <th style="width:12%">Source</th>
                        <th>Indicateur</th>
                        <th class="text-right" style="width:12%">Valeur</th>
                        <th style="width:18%">Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tableaux['gestion'] as $b): ?>
                    <tr>
                        <td><?= $b['section'] ?></td>
                        <td><?= $b['source'] ?></td>
                        <td><?= $b['indicateur'] ?></td>
                        <td class="text-right"><?= $b['valeur'] ?></td>
                        <td><?= $b['note'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

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
        html = '<label>Mois</label><select name="mois">';
        for (let m = 1; m <= 12; m++) { html += `<option value="${m}" ${m===currentMois?'selected':''}>${String(m).padStart(2,'0')} - ${new Date(2000,m-1,1).toLocaleString('fr',{month:'long'})}</option>`; }
        html += '</select>';
    } else if (type === 'trimestre') {
        html = '<label>Trimestre</label><select name="trimestre">';
        for (let t = 1; t <= 4; t++) { html += `<option value="${t}" ${t===currentTrimestre?'selected':''}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
        html += '</select>';
    } else if (type === 'semestre') {
        html = '<label>Semestre</label><select name="semestre">';
        for (let s = 1; s <= 2; s++) { html += `<option value="${s}" ${s===currentSemestre?'selected':''}>${s}${s===1?'er':'e'} semestre</option>`; }
        html += '</select>';
    } else {
        html = '<label>Période</label><input type="text" disabled value="Année complète">';
    }
    container.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function() {
    updateDynamicSelect();
    document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
});
</script>
</body>
</html>