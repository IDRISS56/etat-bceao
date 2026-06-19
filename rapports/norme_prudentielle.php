<?php
// Ratio_resume.php - Synthèse des ratios prudentiels R01 à R10 (BCEAO)
// Tableau complet conforme à NORME.xlsx
// Version avec POST et Bootstrap 5

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ------------------------- PARAMÈTRES LUS EN POST -------------------------
$exercice     = isset($_POST['exercice'])     ? (int)$_POST['exercice']     : date('Y');
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode']      : 'annuel';
$mois         = isset($_POST['mois'])         ? (int)$_POST['mois']         : 12;
$trimestre    = isset($_POST['trimestre'])    ? (int)$_POST['trimestre']    : 4;
$semestre     = isset($_POST['semestre'])     ? (int)$_POST['semestre']     : 2;

switch ($type_periode) {
    case 'mensuel': break;
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre': $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel': $mois = 12; break;
    default: $mois = 12;
}
$date_fin_periode = date('Y-m-t', strtotime("$exercice-" . str_pad($mois, 2, '0', STR_PAD_LEFT) . "-01"));
$date_debut_exercice = "$exercice-01-01";
$exercice_prec = $exercice - 1;
$date_fin_prec = "$exercice_prec-12-31";

// ============================================================
// CALCUL DE TOUTES LES VARIABLES NÉCESSAIRES
// ============================================================

// --- ACTIFS (comptes de la classe 2) ---
$A10 = 0; // Valeurs en caisse
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde_actuel), 0) as total FROM caisses WHERE statut = 'ouverte'");
    $stmt->execute(); $A10 = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

$A12 = 0; // Comptes ordinaires débiteurs
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(solde), 0) as total FROM comptes WHERE solde > 0 AND statut='actif'");
    $stmt->execute(); $A12 = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

$A2A = $A12; // Autres comptes de dépôts débiteurs
$A2J = 0; // Autres dépôts constitués
$A3A = 0; // Comptes de prêts
$A70 = 0; // Prêts en souffrance
$B2D = 0; // Crédits à court terme
$B2N = $A12; // Comptes ordinaires débiteurs des membres
$B30 = 0; // Crédits à moyen terme
$B40 = 0; // Crédits à long terme
$B70 = 0; // Crédits en souffrance
$C10 = 0; // Titres de placement
$C30 = 0; // Comptes de stocks
$C40 = 0; // Débiteurs divers
$C56 = 0; // Valeurs à l'encaissement
$D1E = 0; // Titres de participation
$D1L = 0; // Titres d'investissement
$D10 = 0; // Prêts et titres subordonnés
$D1S = 0; // Dépôts et cautionnements
$D23 = 0; // Immobilisation en cours
$D30 = 0; // Immobilisations d'exploitation
$D40 = 0; // Immobilisations hors exploitation
$D24 = 0; $D31 = 0; $D41 = 0; $D46 = 0; // Immobilisations incorporelles
$D25 = 0; $D36 = 0; $D45 = 0; $D47 = 0; // Immobilisations corporelles

// Récupération des crédits par durée
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif','approuve')");
    $stmt->execute(); $A3A = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut='impaye'");
    $stmt->execute(); $A70 = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif','approuve') AND d.duree <= 12");
    $stmt->execute(); $B2D = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif','approuve') AND d.duree BETWEEN 13 AND 60");
    $stmt->execute(); $B30 = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif','approuve') AND d.duree > 60");
    $stmt->execute(); $B40 = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
$B70 = $A70;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '50%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $C10 = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '26%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $D1E = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '27%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $D1L = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

// Engagements par signature
$engagements_signature = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valeur_nette), 0) as total FROM garanties WHERE statut='actif'");
    $stmt->execute(); $engagements_signature = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

// Immobilisations
$immobilisations_totales = 0;
$immobilisations_incorp = 0;
try {
    $stmt = $pdo->prepare("SELECT type_immobilisation, COALESCE(SUM(montant_achat - amortissement_total), 0) as total FROM immobilisations WHERE statut='actif' GROUP BY type_immobilisation");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $immobilisations_totales += $r['total'];
        if ($r['type_immobilisation'] == 'Immobilisations incorporelles') {
            $immobilisations_incorp += $r['total'];
        }
    }
    // Répartition fictive pour les postes D24, D31, D41, D46
    $part_incorp = ($immobilisations_incorp > 0) ? $immobilisations_incorp / 4 : 0;
    $D24 = $D31 = $D41 = $D46 = $part_incorp;
    // Pour les corporelles, on met tout dans D36
    $D25 = $D36 = $D45 = $D47 = 0;
    $D36 = $immobilisations_totales - $immobilisations_incorp;
} catch (PDOException $e) { }

// --- RESSOURCES (passif) ---
$F1A = 0; $F2A = 0; $F3A = 0; $F50 = 0; $G2A = 0; $G10 = 0; $G15 = 0; $G35 = 0; $G60 = 0; $G70 = 0; $L01 = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(solde)), 0) as total FROM comptes WHERE solde < 0 AND statut='actif'");
    $stmt->execute(); $F1A = (float)$stmt->fetch()['total'];
    $F2A = $F1A; $G10 = $F1A;
} catch (PDOException $e) { }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) as total FROM capital WHERE statut='valide' AND mode_paiement='BANQUE'");
    $stmt->execute(); $F3A = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

$F50 = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(c.solde), 0) as total FROM comptes c INNER JOIN produits p ON c.produit_id = p.produit_id INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id WHERE pf.categorie='Epargne' AND c.statut='actif' AND c.solde>0");
    $stmt->execute(); $G2A = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(capital_initial), 0) as total FROM comptes_dat WHERE statut='en cours'");
    $stmt->execute(); $G15 = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

$G35 = 0; $G60 = 0; $G70 = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte='1' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $L01 = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

// --- FONDS PROPRES DÉTAILLÉS ---
$subventions = 0; $fonds_affectes = 0; $fonds_credit = 0; $provisions_risques = 0; $provisions_reg = 0;
$emprunts_sub = 0; $fonds_risques = 0; $primes_cap = 0; $reserves = 0; $ecart_reeval = 0;
$capital = 0; $fonds_dotation = 0; $report_positif = 0; $excedent_produits = 0; $resultat_exercice = 0;
$capital_non_appele = 0; $excedent_charges = 0; $report_negatif = 0; $resultat_deficitaire = 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '13%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $subventions = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '14%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $fonds_affectes = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '15%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $fonds_credit = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '17%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $provisions_risques = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '18%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $provisions_reg = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '16%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $emprunts_sub = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '19%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $fonds_risques = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '102%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $primes_cap = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '106%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $reserves = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '107%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $ecart_reeval = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '101%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $capital = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '103%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $fonds_dotation = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN (e.montant_credit - e.montant_debit) > 0 THEN e.montant_credit - e.montant_debit ELSE 0 END), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '11%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $report_positif = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte = '120' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $excedent_produits = (float)$stmt->fetch()['total'];
    if ($excedent_produits < 0) $excedent_produits = 0;
} catch (PDOException $e) { }

$resultat_brut = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN pc.classe_compte='7' THEN e.montant_credit - e.montant_debit ELSE 0 END), 0) as produits, COALESCE(SUM(CASE WHEN pc.classe_compte='6' THEN e.montant_debit - e.montant_credit ELSE 0 END), 0) as charges FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte IN ('6','7') AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $res = $stmt->fetch();
    $resultat_brut = $res['produits'] - $res['charges'];
    $resultat_exercice = ($resultat_brut > 0) ? $resultat_brut : 0;
    $resultat_deficitaire = ($resultat_brut < 0) ? abs($resultat_brut) : 0;
} catch (PDOException $e) { }

// Déductions
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte = '109' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $capital_non_appele = abs((float)$stmt->fetch()['total']);
} catch (PDOException $e) { }

$excedent_charges = ($resultat_brut < 0) ? abs($resultat_brut) : 0;

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN (e.montant_credit - e.montant_debit) < 0 THEN ABS(e.montant_credit - e.montant_debit) ELSE 0 END), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '11%' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $report_negatif = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

$z52 = 0; $z53 = 0; // saisies manuelles

// Fonds propres nets
$fonds_propres_brut = $subventions + $fonds_affectes + $fonds_credit + $provisions_risques + $provisions_reg
                    + $emprunts_sub + $fonds_risques + $primes_cap + $reserves + $ecart_reeval
                    + $capital + $fonds_dotation + $report_positif + $excedent_produits + $resultat_exercice;
$deductions_total = $capital_non_appele + $excedent_charges + $immobilisations_incorp + $report_negatif + $resultat_deficitaire + $z52 + $z53;
$fonds_propres_net = $fonds_propres_brut - $deductions_total;
if ($fonds_propres_net <= 0) $fonds_propres_net = 1;

// Autres variables
$total_actif = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.classe_compte='2' AND e.date_ecriture <= :date_fin");
    $stmt->execute([':date_fin' => $date_fin_periode]); $total_actif = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
if ($total_actif <= 0) $total_actif = 1;

$plus_gros_emprunteur = 0;
try {
    $stmt = $pdo->prepare("SELECT c.client_id, SUM(encours) as total_encours FROM (SELECT d.compte_id, COALESCE(d.montant - COALESCE(e.rembourse, 0), d.montant) as encours FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id WHERE d.statut IN ('actif','approuve')) as encours_par_dossier INNER JOIN comptes cpt ON encours_par_dossier.compte_id = cpt.compte_id INNER JOIN clients c ON cpt.client_id = c.client_id GROUP BY c.client_id ORDER BY total_encours DESC LIMIT 1");
    $stmt->execute(); $row = $stmt->fetch(); $plus_gros_emprunteur = $row ? (float)$row['total_encours'] : 0;
} catch (PDOException $e) { }

// Prets aux dirigeants
$prets_dirigeants = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut='payee' GROUP BY dossier_id) e ON d.dossier_id = e.dossier_id INNER JOIN utilisateurs u ON d.utilisateur_id = u.utilisateur_id WHERE u.role IN ('Superviseur','Administrateur','Responsable','Directeur') AND d.statut IN ('actif','approuve')");
    $stmt->execute(); $prets_dirigeants = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

// R07 : dotation réserve
$dotation_reserve = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.montant_credit), 0) as total FROM ecritures_comptables e INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte WHERE pc.numero_compte LIKE '106%' AND e.date_ecriture BETWEEN :debut AND :fin");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]); $dotation_reserve = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }

// R05 : liquidité
$A3B = 0; $A2H = 0; $A2I = 0;
$creances_rattachees = 0;
$engagements_donnes = $engagements_signature;
$valeurs_realisables = $A10 + $A12 + $A2A + $A2J + $A3B + $B2D + $B2N + $B30 + $B40 + $C10 + $C30 + $C40 + $C56 + $creances_rattachees + $engagements_donnes;
$passif_exigible = $F1A + $F2A + $F3A + $F50 + $G10 + $G15 + $G2A + $G35 + $G60 + $G70;
if ($passif_exigible <= 0) $passif_exigible = 1;

// R02
$ressources_stables = $L01 + $F2A + $F3A + $F50 + $G15 + $G2A + $G35 + $G60 + $G70;
$emplois_mlt = $B30 + $B40;
if ($emplois_mlt <= 0) $emplois_mlt = 1;

// R01
$risques_brut = $A12 + $A2A + $A3A + $A70 + $B2D + $B2N + $B30 + $B40 + $B70 + $C10 + $D1E + $D1L + $engagements_signature;
$depots_garantie = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(solde)), 0) as total FROM comptes WHERE solde < 0 AND statut='actif'");
    $stmt->execute(); $depots_garantie = (float)$stmt->fetch()['total'];
} catch (PDOException $e) { }
$risques_net = $risques_brut - $depots_garantie;
$ressources_total = $F1A + $F2A + $F3A + $F50 + $G2A + $G10 + $G15 + $G35 + $G60 + $G70 + $L01;
if ($ressources_total <= 0) $ressources_total = 1;

// R06
$Z55 = 0; // à saisir manuellement

// Calculs des ratios
$ratio_r01 = $risques_net / $ressources_total;
$ratio_r02 = $ressources_stables / $emplois_mlt;
$ratio_r03 = $prets_dirigeants / $fonds_propres_net;
$ratio_r04 = $plus_gros_emprunteur / $fonds_propres_net;
$ratio_r05 = $valeurs_realisables / $passif_exigible;
$ratio_r06 = ($risques_net > 0) ? $Z55 / $risques_net : 0;
$ratio_r07 = ($resultat_exercice > 0) ? $dotation_reserve / $resultat_exercice : 0;
$ratio_r08 = $fonds_propres_net / $total_actif;
$ratio_r09 = $D1E / $fonds_propres_net;
$ratio_r10 = ($fonds_propres_net > 0) ? ($immobilisations_incorp + $D1E) / $fonds_propres_net : 0;

// ============================================================
// CONSTRUCTION DU TABLEAU COMPLET (NORME.xlsx)
// ============================================================

$tableau = [];

// R01
$tableau[] = ['code' => '', 'libelle' => 'LIMITATION DES RISQUES AUXQUELS EST EXPOSEE UNE INSTITUTION', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'RISQUES PORTES PAR UNE INSTITUTION', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'MONTANTS NETS DES PROVISIONS ET DES DEPOTS DE GARANTIE', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'A12', 'libelle' => 'Comptes ordinaires débiteurs chez les institutions financières', 'montant' => $A12, 'norme' => ''];
$tableau[] = ['code' => 'A2A', 'libelle' => 'Autres comptes de dépôts chez les institutions financières', 'montant' => $A2A, 'norme' => ''];
$tableau[] = ['code' => 'A3A', 'libelle' => 'Comptes de prêts', 'montant' => $A3A, 'norme' => ''];
$tableau[] = ['code' => 'A70', 'libelle' => 'Prêts en souffrance', 'montant' => $A70, 'norme' => ''];
$tableau[] = ['code' => 'B2D', 'libelle' => 'Crédits à court terme', 'montant' => $B2D, 'norme' => ''];
$tableau[] = ['code' => 'B2N', 'libelle' => 'Comptes ordinaires débiteurs des membres, bénéficiaires ou clients', 'montant' => $B2N, 'norme' => ''];
$tableau[] = ['code' => 'B30', 'libelle' => 'Crédits à moyen terme', 'montant' => $B30, 'norme' => ''];
$tableau[] = ['code' => 'B40', 'libelle' => 'Crédits à long terme', 'montant' => $B40, 'norme' => ''];
$tableau[] = ['code' => 'B70', 'libelle' => 'Crédits en souffrance', 'montant' => $B70, 'norme' => ''];
$tableau[] = ['code' => 'C10', 'libelle' => 'Titres de placement', 'montant' => $C10, 'norme' => ''];
$tableau[] = ['code' => 'D1E', 'libelle' => 'Titres de participation', 'montant' => $D1E, 'norme' => ''];
$tableau[] = ['code' => 'D1L', 'libelle' => 'Titres d\'investissement', 'montant' => $D1L, 'norme' => ''];
$tableau[] = ['code' => '(N1A+N1J+N3A+Q1A)', 'libelle' => 'Engagements par signature donnés', 'montant' => $engagements_signature, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $risques_brut, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'B - RESSOURCES', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'F1A', 'libelle' => 'Comptes ordinaires créditeurs des institutions financières', 'montant' => $F1A, 'norme' => ''];
$tableau[] = ['code' => 'F2A', 'libelle' => 'Autres comptes de dépôts créditeurs des institutions financières', 'montant' => $F2A, 'norme' => ''];
$tableau[] = ['code' => 'F3A', 'libelle' => 'Comptes d\'emprunts', 'montant' => $F3A, 'norme' => ''];
$tableau[] = ['code' => 'F50', 'libelle' => 'Autres sommes dues aux institutions financières', 'montant' => $F50, 'norme' => ''];
$tableau[] = ['code' => 'G2A', 'libelle' => 'Comptes d\'épargne à régime spécial', 'montant' => $G2A, 'norme' => ''];
$tableau[] = ['code' => 'G10', 'libelle' => 'Comptes ordinaires créditeurs des institutions financières', 'montant' => $G10, 'norme' => ''];
$tableau[] = ['code' => 'G15', 'libelle' => 'Dépôts à terme reçus des membres, bénéficiaires ou clients', 'montant' => $G15, 'norme' => ''];
$tableau[] = ['code' => 'G35', 'libelle' => 'Autres dépôts reçus des clients, membres ou bénéficiaires', 'montant' => $G35, 'norme' => ''];
$tableau[] = ['code' => 'G60', 'libelle' => 'Emprunts reçus des clients, membres ou bénéficiaires', 'montant' => $G60, 'norme' => ''];
$tableau[] = ['code' => 'G70', 'libelle' => 'Autres sommes dues aux membres, bénéficiaires ou clients', 'montant' => $G70, 'norme' => ''];
$tableau[] = ['code' => 'L01', 'libelle' => 'Provisions, fonds propres et assimilés', 'montant' => $L01, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $ressources_total, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'Norme', 'montant' => '', 'norme' => 'MAX. 200%'];
$tableau[] = ['code' => 'R01', 'libelle' => 'Ratio', 'montant' => number_format($ratio_r01 * 100, 2) . '%', 'norme' => ''];

// R02
$tableau[] = ['code' => '', 'libelle' => 'COUVERTURE DES EMPLOIS A MOYEN ET LONG TERME PAR DES RESSOURCES STABLES', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'A - RESSOURCES STABLES', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'L01', 'libelle' => 'Provisions, fonds propres et assimilés', 'montant' => $L01, 'norme' => ''];
$tableau[] = ['code' => 'F2A', 'libelle' => 'Autres comptes de dépôts créditeurs à moyen et long terme', 'montant' => $F2A, 'norme' => ''];
$tableau[] = ['code' => 'F3F', 'libelle' => 'Comptes d\'emprunts à terme auprès des institutions financières', 'montant' => $F3A, 'norme' => ''];
$tableau[] = ['code' => 'F50', 'libelle' => 'Autres sommes dues aux institutions financières à moyen et long terme', 'montant' => $F50, 'norme' => ''];
$tableau[] = ['code' => 'G15', 'libelle' => 'Dépôts à terme reçus à moyen et long terme', 'montant' => $G15, 'norme' => ''];
$tableau[] = ['code' => 'G2A', 'libelle' => 'Comptes d\'épargne à régime spécial des membres, bénéficiaires ou clients à moyen et long terme', 'montant' => $G2A, 'norme' => ''];
$tableau[] = ['code' => 'G30', 'libelle' => 'Autres dépôts de garantie reçus des membres, bénéficiaires ou clients à moyen et long terme', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'G35', 'libelle' => 'Autres dépôts reçus des membres, bénéficiaires ou clients à moyen et long terme', 'montant' => $G35, 'norme' => ''];
$tableau[] = ['code' => 'G60', 'libelle' => 'Emprunts reçus des membres; bénéficiaires ou clients à moyen et long terme', 'montant' => $G60, 'norme' => ''];
$tableau[] = ['code' => 'G70', 'libelle' => 'Autres sommes dues aux membres, bénéficiaires ou clients à moyen et long terme', 'montant' => $G70, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $ressources_stables, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'B - EMPLOIS A MOYEN ET LONG TERME', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'A2H', 'libelle' => 'Dépôts à terme constitués auprès des institutions financières à plus d\'un an', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'A2I', 'libelle' => 'Dépôts de garantie constitués auprès des institutions financières à plus d\'un an', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'A2J', 'libelle' => 'Autres dépôts constitués auprès des institutions financières à plus d\'un an', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'A3C', 'libelle' => 'Comptes de prêts à terme auprès des institutions financières à plus d\'un an', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'A70', 'libelle' => 'Prêts en souffrance nets des provisions auprès des institutions financières', 'montant' => $A70, 'norme' => ''];
$tableau[] = ['code' => 'B30', 'libelle' => 'Crédits à moyen terme aux membres, bénéficiaires ou clients', 'montant' => $B30, 'norme' => ''];
$tableau[] = ['code' => 'B40', 'libelle' => 'Crédits à long terme aux membres, bénéficiaires ou clients', 'montant' => $B40, 'norme' => ''];
$tableau[] = ['code' => 'B70', 'libelle' => 'Crédits en souffrance nets des provisions des membres, bénéficiaires ou clients', 'montant' => $B70, 'norme' => ''];
$tableau[] = ['code' => 'D1E', 'libelle' => 'Titres de participation', 'montant' => $D1E, 'norme' => ''];
$tableau[] = ['code' => 'D1L', 'libelle' => 'Titres d\'investissement', 'montant' => $D1L, 'norme' => ''];
$tableau[] = ['code' => 'D10', 'libelle' => 'Prêts et titres subordonnés', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'D1S', 'libelle' => 'Dépôts et cautionnements', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'D23', 'libelle' => 'Immobilisation en cours', 'montant' => $D23, 'norme' => ''];
$tableau[] = ['code' => 'D30', 'libelle' => 'Immobilisations d\'exploitation', 'montant' => $D30, 'norme' => ''];
$tableau[] = ['code' => 'D40', 'libelle' => 'Immobilisations hors exploitation', 'montant' => $D40, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $emplois_mlt, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'Norme', 'montant' => '', 'norme' => 'MIN. 100%'];
$tableau[] = ['code' => 'R02', 'libelle' => 'Ratio', 'montant' => number_format($ratio_r02, 2), 'norme' => ''];

// R03
$tableau[] = ['code' => '', 'libelle' => 'LIMITATION DES PRETS AUX DIRIGEANTS ET AU PERSONNEL AINSI QU\'AUX PERSONNES LIEES', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'A - PRETS ET ENGAGEMENTS PAR SIGNATURE', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'Z51', 'libelle' => 'Encours brut prêts et engagements par signature donnés aux dirigeants ou employés', 'montant' => $prets_dirigeants, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $prets_dirigeants, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'B - FONDS PROPRES', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'L10', 'libelle' => '+ Subventions d\'investissement', 'montant' => $subventions, 'norme' => ''];
$tableau[] = ['code' => 'L20', 'libelle' => '+ Fonds affectés', 'montant' => $fonds_affectes, 'norme' => ''];
$tableau[] = ['code' => 'L27', 'libelle' => '+ Fonds de crédit', 'montant' => $fonds_credit, 'norme' => ''];
$tableau[] = ['code' => 'L30', 'libelle' => '+ Provisions pour risques et charges', 'montant' => $provisions_risques, 'norme' => ''];
$tableau[] = ['code' => 'L35', 'libelle' => '+ Provisions réglementées', 'montant' => $provisions_reg, 'norme' => ''];
$tableau[] = ['code' => 'L41', 'libelle' => '+ Emprunts et titres émis subordonnés', 'montant' => $emprunts_sub, 'norme' => ''];
$tableau[] = ['code' => 'L45', 'libelle' => '+ Fonds pour risques financiers généraux', 'montant' => $fonds_risques, 'norme' => ''];
$tableau[] = ['code' => 'L50', 'libelle' => '+ Primes liées au capital', 'montant' => $primes_cap, 'norme' => ''];
$tableau[] = ['code' => 'L55', 'libelle' => '+ Réserves', 'montant' => $reserves, 'norme' => ''];
$tableau[] = ['code' => 'L59', 'libelle' => '+ Écart de réévaluation des immobilisations', 'montant' => $ecart_reeval, 'norme' => ''];
$tableau[] = ['code' => 'L60', 'libelle' => '+ Capital', 'montant' => $capital, 'norme' => ''];
$tableau[] = ['code' => 'L65', 'libelle' => '+ Fonds de dotation', 'montant' => $fonds_dotation, 'norme' => ''];
$tableau[] = ['code' => 'L70', 'libelle' => '+ Report à nouveau positif', 'montant' => $report_positif, 'norme' => ''];
$tableau[] = ['code' => 'L75', 'libelle' => '+ Excédent des produits sur les charges', 'montant' => $excedent_produits, 'norme' => ''];
$tableau[] = ['code' => 'L80', 'libelle' => '+ Résultat de l\'exercice', 'montant' => $resultat_exercice, 'norme' => ''];
$tableau[] = ['code' => 'L62', 'libelle' => '- Capital non appelé', 'montant' => -$capital_non_appele, 'norme' => ''];
$tableau[] = ['code' => 'E05', 'libelle' => '- Excédent des charges sur les produits', 'montant' => -$excedent_charges, 'norme' => ''];
$tableau[] = ['code' => '(D24+D31+D41+D46)', 'libelle' => '- Immobilisations incorporelles nettes', 'montant' => -$immobilisations_incorp, 'norme' => ''];
$tableau[] = ['code' => 'L70', 'libelle' => '- Report à nouveau négatif', 'montant' => -$report_negatif, 'norme' => ''];
$tableau[] = ['code' => 'L80', 'libelle' => '- Résultat déficitaire de l\'exercice', 'montant' => -$resultat_deficitaire, 'norme' => ''];
$tableau[] = ['code' => 'Z52', 'libelle' => '- Complément de provisions non constituées et exigées par les autorités de contrôle', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'Z53', 'libelle' => '- Toutes participations constituant des fonds propres dans d\'autres SFD ou établissements de crédit', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $fonds_propres_net, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'Norme', 'montant' => '', 'norme' => 'MAX. 10%'];
$tableau[] = ['code' => 'R03', 'libelle' => 'Ratio', 'montant' => number_format($ratio_r03 * 100, 2) . '%', 'norme' => ''];

// R04
$tableau[] = ['code' => '', 'libelle' => 'LIMITATION DES RISQUES PRIS SUR UNE SEULE SIGNATURE', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'A - PRETS ET ENGAGEMENTS PAR SIGNATURE', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'Z54', 'libelle' => 'Montant brut des prêts et engagements par signature à un plus gros emprunteur', 'montant' => $plus_gros_emprunteur, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $plus_gros_emprunteur, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'B - FONDS PROPRES', 'montant' => '', 'norme' => ''];
// Les lignes de fonds propres sont les mêmes que pour R03, on les réutilise
$tableau[] = ['code' => 'L10', 'libelle' => '+ Subventions d\'investissement', 'montant' => $subventions, 'norme' => ''];
$tableau[] = ['code' => 'L20', 'libelle' => '+ Fonds affectés', 'montant' => $fonds_affectes, 'norme' => ''];
$tableau[] = ['code' => 'L27', 'libelle' => '+ Fonds de crédit', 'montant' => $fonds_credit, 'norme' => ''];
$tableau[] = ['code' => 'L30', 'libelle' => '+ Provisions pour risques et charges', 'montant' => $provisions_risques, 'norme' => ''];
$tableau[] = ['code' => 'L35', 'libelle' => '+ Provisions réglementées', 'montant' => $provisions_reg, 'norme' => ''];
$tableau[] = ['code' => 'L41', 'libelle' => '+ Emprunts et titres émis subordonnés', 'montant' => $emprunts_sub, 'norme' => ''];
$tableau[] = ['code' => 'L45', 'libelle' => '+ Fonds pour risques financiers généraux', 'montant' => $fonds_risques, 'norme' => ''];
$tableau[] = ['code' => 'L50', 'libelle' => '+ Primes liées au capital', 'montant' => $primes_cap, 'norme' => ''];
$tableau[] = ['code' => 'L55', 'libelle' => '+ Réserves', 'montant' => $reserves, 'norme' => ''];
$tableau[] = ['code' => 'L59', 'libelle' => '+ Écart de réévaluation des immobilisations', 'montant' => $ecart_reeval, 'norme' => ''];
$tableau[] = ['code' => 'L60', 'libelle' => '+ Capital', 'montant' => $capital, 'norme' => ''];
$tableau[] = ['code' => 'L65', 'libelle' => '+ Fonds de dotation', 'montant' => $fonds_dotation, 'norme' => ''];
$tableau[] = ['code' => 'L70', 'libelle' => '+ Report à nouveau positif', 'montant' => $report_positif, 'norme' => ''];
$tableau[] = ['code' => 'L75', 'libelle' => '+ Excédent des produits sur les charges', 'montant' => $excedent_produits, 'norme' => ''];
$tableau[] = ['code' => 'L80', 'libelle' => '+ Résultat de l\'exercice', 'montant' => $resultat_exercice, 'norme' => ''];
$tableau[] = ['code' => 'L62', 'libelle' => '- Capital non appelé', 'montant' => -$capital_non_appele, 'norme' => ''];
$tableau[] = ['code' => 'E05', 'libelle' => '- Excédent des charges sur les produits', 'montant' => -$excedent_charges, 'norme' => ''];
$tableau[] = ['code' => '(D24+D31+D41+D46)', 'libelle' => '- Immobilisations incorporelles nettes', 'montant' => -$immobilisations_incorp, 'norme' => ''];
$tableau[] = ['code' => 'L70', 'libelle' => '- Report à nouveau négatif', 'montant' => -$report_negatif, 'norme' => ''];
$tableau[] = ['code' => 'L80', 'libelle' => '- Résultat déficitaire de l\'exercice', 'montant' => -$resultat_deficitaire, 'norme' => ''];
$tableau[] = ['code' => 'Z52', 'libelle' => '- Complément de provisions non constituées et exigées par les autorités de contrôle', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'Z53', 'libelle' => '- Toutes participations constituant des fonds propres dans d\'autres SFD ou établissements de crédit', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $fonds_propres_net, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'Norme', 'montant' => '', 'norme' => 'MAX. 10%'];
$tableau[] = ['code' => 'R04', 'libelle' => 'Ratio', 'montant' => number_format($ratio_r04 * 100, 2) . '%', 'norme' => ''];

// R05
$tableau[] = ['code' => '', 'libelle' => 'NORME DE LIQUIDITE', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'A - VALEURS REALISABLES ET DISPONIBLES - MONTANTS NETS', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'A10', 'libelle' => 'Valeurs en caisse', 'montant' => $A10, 'norme' => ''];
$tableau[] = ['code' => 'A12', 'libelle' => 'Comptes ordinaires débiteurs chez les institutions financières', 'montant' => $A12, 'norme' => ''];
$tableau[] = ['code' => 'A2A', 'libelle' => 'Autres comptes de dépôts débiteurs chez les institutions financières', 'montant' => $A2A, 'norme' => ''];
$tableau[] = ['code' => 'A2J', 'libelle' => 'Autres dépôts constitués', 'montant' => $A2J, 'norme' => ''];
$tableau[] = ['code' => 'A3B', 'libelle' => 'Comptes de prêts à court terme aux institutions financières', 'montant' => $A3B, 'norme' => ''];
$tableau[] = ['code' => 'B2D', 'libelle' => 'Crédits à court terme aux membres, bénéficiaires ou clients', 'montant' => $B2D, 'norme' => ''];
$tableau[] = ['code' => 'B2N', 'libelle' => 'Comptes ordinaires débiteurs des membres, bénéficiaires ou clients', 'montant' => $B2N, 'norme' => ''];
$tableau[] = ['code' => 'B30', 'libelle' => 'Crédits à moyen terme', 'montant' => $B30, 'norme' => ''];
$tableau[] = ['code' => 'B40', 'libelle' => 'Crédits à long terme', 'montant' => $B40, 'norme' => ''];
$tableau[] = ['code' => 'C10', 'libelle' => 'Titres de placement', 'montant' => $C10, 'norme' => ''];
$tableau[] = ['code' => 'C30', 'libelle' => 'Comptes de stocks', 'montant' => $C30, 'norme' => ''];
$tableau[] = ['code' => 'C40', 'libelle' => 'Débiteurs divers', 'montant' => $C40, 'norme' => ''];
$tableau[] = ['code' => 'C56', 'libelle' => 'Valeurs à l\'encaissement avec crédit immédiat', 'montant' => $C56, 'norme' => ''];
$tableau[] = ['code' => '(A60+B65+C55)', 'libelle' => 'Créances rattachées', 'montant' => $creances_rattachees, 'norme' => ''];
$tableau[] = ['code' => '(N1A+N1J+N2A+N2J)', 'libelle' => 'Engagements de financement et de garantie donnés', 'montant' => $engagements_donnes, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $valeurs_realisables, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'B - PASSIF EXIGIBLE', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'F1A', 'libelle' => 'Comptes ordinaires débiteurs chez les institutions financières auprès du SFD', 'montant' => $F1A, 'norme' => ''];
$tableau[] = ['code' => 'F2A', 'libelle' => 'Autres comptes de dépôts créditeurs des institutions financières', 'montant' => $F2A, 'norme' => ''];
$tableau[] = ['code' => 'F3E', 'libelle' => 'Emprunts à moins d\'un an auprès des institutions financières', 'montant' => $F3A, 'norme' => ''];
$tableau[] = ['code' => 'F3F', 'libelle' => 'Emprunts à terme', 'montant' => $F3A, 'norme' => ''];
$tableau[] = ['code' => 'F50', 'libelle' => 'Autres sommes dues aux institutions financières', 'montant' => $F50, 'norme' => ''];
$tableau[] = ['code' => 'G10', 'libelle' => 'Comptes ordinaires créditeurs des membres, bénéficiaires ou clients auprès de l\'institution', 'montant' => $G10, 'norme' => ''];
$tableau[] = ['code' => 'G15', 'libelle' => 'Dépôts à terme reçus à court terme', 'montant' => $G15, 'norme' => ''];
$tableau[] = ['code' => 'G2A', 'libelle' => 'Comptes d\'épargne à régime spécial', 'montant' => $G2A, 'norme' => ''];
$tableau[] = ['code' => 'G30', 'libelle' => 'Autres dépôts de garantie reçus des membres, bénéficiaires ou clients', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'G35', 'libelle' => 'Autres dépôts des membres, bénéficiaires ou clients auprès de l\'institution', 'montant' => $G35, 'norme' => ''];
$tableau[] = ['code' => 'G60', 'libelle' => 'Emprunts de l\'institution auprès des membres', 'montant' => $G60, 'norme' => ''];
$tableau[] = ['code' => 'G70', 'libelle' => 'Autres sommes dues aux membres, bénéficiaires ou clients', 'montant' => $G70, 'norme' => ''];
$tableau[] = ['code' => 'H10', 'libelle' => 'Versements restant à effectuer à court terme', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'H40', 'libelle' => 'Créditeurs divers à court terme', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => '(F60+G90)', 'libelle' => 'Dettes rattachées', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => '(N1H+N1K+N2H+N2M)', 'libelle' => 'Encours des engagements de financement et de garantie reçus', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $passif_exigible, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'Norme', 'montant' => '', 'norme' => 'MIN. 100%'];
$tableau[] = ['code' => 'R05', 'libelle' => 'Ratio', 'montant' => number_format($ratio_r05, 2), 'norme' => ''];

// R06
$tableau[] = ['code' => '', 'libelle' => 'LIMITATION DES OPERATIONS AUTRES QUE LES ACTIVITES D\'EPARGNE ET DE CREDIT', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'A - MONTANT CONSACRE PAR L\'INSTITUTION AUX ACTIVITES AUTRES QUE L\'EPARGNE ET LE CREDIT', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'Z55', 'libelle' => 'Montant consacré par l\'institution aux opérations autres que les activités d\'épargne et de crédit', 'montant' => $Z55, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $Z55, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'B - RISQUES PORTES PAR UNE INSTITUTION (MONTANT NET DES PROVISIONS ET DEPOTS DE GARANTIE)', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'A12', 'libelle' => 'Comptes ordinaires débiteurs chez les institutions financières', 'montant' => $A12, 'norme' => ''];
$tableau[] = ['code' => 'A3A', 'libelle' => 'Comptes de prêts', 'montant' => $A3A, 'norme' => ''];
$tableau[] = ['code' => 'A70', 'libelle' => 'Prêts en souffrance', 'montant' => $A70, 'norme' => ''];
$tableau[] = ['code' => 'B2D', 'libelle' => 'Crédits à court terme', 'montant' => $B2D, 'norme' => ''];
$tableau[] = ['code' => 'B2N', 'libelle' => 'Comptes ordinaires débiteurs des membres, bénéficiaires ou clients', 'montant' => $B2N, 'norme' => ''];
$tableau[] = ['code' => 'B30', 'libelle' => 'Crédits à moyen terme', 'montant' => $B30, 'norme' => ''];
$tableau[] = ['code' => 'B40', 'libelle' => 'Crédits à long terme', 'montant' => $B40, 'norme' => ''];
$tableau[] = ['code' => 'B70', 'libelle' => 'Crédits en souffrance', 'montant' => $B70, 'norme' => ''];
$tableau[] = ['code' => 'C10', 'libelle' => 'Titres de placement', 'montant' => $C10, 'norme' => ''];
$tableau[] = ['code' => 'D1E', 'libelle' => 'Titres de participation', 'montant' => $D1E, 'norme' => ''];
$tableau[] = ['code' => 'D1L', 'libelle' => 'Titres d\'investissement', 'montant' => $D1L, 'norme' => ''];
$tableau[] = ['code' => '(N1A+N1J+N3A+Q1A)', 'libelle' => 'Engagements par signature donnés', 'montant' => $engagements_signature, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $risques_net, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'Norme', 'montant' => '', 'norme' => 'MAX. 5%'];
$tableau[] = ['code' => 'R06', 'libelle' => 'Ratio', 'montant' => number_format($ratio_r06 * 100, 2) . '%', 'norme' => ''];

// R07
$tableau[] = ['code' => '', 'libelle' => 'CONSTITUTION DE LA RESERVE GENERALE', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'A - RESULTAT', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'L80', 'libelle' => 'Résultat bénéficiaire', 'montant' => $resultat_exercice, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $resultat_exercice, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'B - REPORT A NOUVEAU DEFICITAIRE', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'L70', 'libelle' => 'Report à nouveau déficitaire', 'montant' => -$report_negatif, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => -$report_negatif, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'NB: Base = résultat (L80) + report à nouveau déficitaire (L70)', 'montant' => max(0, $resultat_exercice - $report_negatif), 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'Norme', 'montant' => '', 'norme' => 'Base X 15% MIN.'];
$tableau[] = ['code' => 'R07', 'libelle' => 'Ratio (dotation)', 'montant' => number_format($dotation_reserve, 0, ',', ' ') . ' FCFA', 'norme' => ''];

// R08
$tableau[] = ['code' => '', 'libelle' => 'NORME DE CAPITALISATION', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'A - FONDS PROPRES', 'montant' => '', 'norme' => ''];
// Mêmes lignes que R03
$tableau[] = ['code' => 'L10', 'libelle' => '+ Subventions d\'investissement', 'montant' => $subventions, 'norme' => ''];
$tableau[] = ['code' => 'L20', 'libelle' => '+ Fonds affectés', 'montant' => $fonds_affectes, 'norme' => ''];
$tableau[] = ['code' => 'L27', 'libelle' => '+ Fonds de crédit', 'montant' => $fonds_credit, 'norme' => ''];
$tableau[] = ['code' => 'L30', 'libelle' => '+ Provisions pour risques et charges', 'montant' => $provisions_risques, 'norme' => ''];
$tableau[] = ['code' => 'L35', 'libelle' => '+ Provisions réglementées', 'montant' => $provisions_reg, 'norme' => ''];
$tableau[] = ['code' => 'L41', 'libelle' => '+ Emprunts et titres émis subordonnés', 'montant' => $emprunts_sub, 'norme' => ''];
$tableau[] = ['code' => 'L45', 'libelle' => '+ Fonds pour risques financiers généraux', 'montant' => $fonds_risques, 'norme' => ''];
$tableau[] = ['code' => 'L50', 'libelle' => '+ Primes liées au capital', 'montant' => $primes_cap, 'norme' => ''];
$tableau[] = ['code' => 'L55', 'libelle' => '+ Réserves', 'montant' => $reserves, 'norme' => ''];
$tableau[] = ['code' => 'L59', 'libelle' => '+ Écart de réévaluation des immobilisations', 'montant' => $ecart_reeval, 'norme' => ''];
$tableau[] = ['code' => 'L60', 'libelle' => '+ Capital', 'montant' => $capital, 'norme' => ''];
$tableau[] = ['code' => 'L65', 'libelle' => '+ Fonds de dotation', 'montant' => $fonds_dotation, 'norme' => ''];
$tableau[] = ['code' => 'L70', 'libelle' => '+ Report à nouveau positif', 'montant' => $report_positif, 'norme' => ''];
$tableau[] = ['code' => 'L75', 'libelle' => '+ Excédent des produits sur les charges', 'montant' => $excedent_produits, 'norme' => ''];
$tableau[] = ['code' => 'L80', 'libelle' => '+ Résultat de l\'exercice', 'montant' => $resultat_exercice, 'norme' => ''];
$tableau[] = ['code' => 'L62', 'libelle' => '- Capital non appelé', 'montant' => -$capital_non_appele, 'norme' => ''];
$tableau[] = ['code' => 'E05', 'libelle' => '- Excédent des charges sur les produits', 'montant' => -$excedent_charges, 'norme' => ''];
$tableau[] = ['code' => '(D24+D31+D41+D46)', 'libelle' => '- Immobilisations incorporelles nettes', 'montant' => -$immobilisations_incorp, 'norme' => ''];
$tableau[] = ['code' => 'L70', 'libelle' => '- Report à nouveau négatif', 'montant' => -$report_negatif, 'norme' => ''];
$tableau[] = ['code' => 'L80', 'libelle' => '- Résultat déficitaire de l\'exercice', 'montant' => -$resultat_deficitaire, 'norme' => ''];
$tableau[] = ['code' => 'Z52', 'libelle' => '- Complément de provisions non constituées et exigées par les autorités de contrôle', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'Z53', 'libelle' => '- Toutes participations constituant des fonds propres dans d\'autres SFD ou établissements de crédit', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $fonds_propres_net, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'B - TOTAL ACTIF DE FIN DE PERIODE', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'E90', 'libelle' => 'Total actif de fin de période en montants nets', 'montant' => $total_actif, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $total_actif, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'Norme', 'montant' => '', 'norme' => 'MIN. 15%'];
$tableau[] = ['code' => 'R08', 'libelle' => 'Ratio', 'montant' => number_format($ratio_r08 * 100, 2) . '%', 'norme' => ''];

// R09
$tableau[] = ['code' => '', 'libelle' => 'LIMITATION DES PRISES DE PARTICIPATION', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'A - TITRES DE PARTICIPATION', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'D1E', 'libelle' => 'Titres de participation sauf participations dans les établissements de crédit et les SFD', 'montant' => $D1E, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $D1E, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'B - FONDS PROPRES', 'montant' => '', 'norme' => ''];
// Mêmes lignes que R03
$tableau[] = ['code' => 'L10', 'libelle' => '+ Subventions d\'investissement', 'montant' => $subventions, 'norme' => ''];
$tableau[] = ['code' => 'L20', 'libelle' => '+ Fonds affectés', 'montant' => $fonds_affectes, 'norme' => ''];
$tableau[] = ['code' => 'L27', 'libelle' => '+ Fonds de crédit', 'montant' => $fonds_credit, 'norme' => ''];
$tableau[] = ['code' => 'L30', 'libelle' => '+ Provisions pour risques et charges', 'montant' => $provisions_risques, 'norme' => ''];
$tableau[] = ['code' => 'L35', 'libelle' => '+ Provisions réglementées', 'montant' => $provisions_reg, 'norme' => ''];
$tableau[] = ['code' => 'L41', 'libelle' => '+ Emprunts et titres émis subordonnés', 'montant' => $emprunts_sub, 'norme' => ''];
$tableau[] = ['code' => 'L45', 'libelle' => '+ Fonds pour risques financiers généraux', 'montant' => $fonds_risques, 'norme' => ''];
$tableau[] = ['code' => 'L50', 'libelle' => '+ Primes liées au capital', 'montant' => $primes_cap, 'norme' => ''];
$tableau[] = ['code' => 'L55', 'libelle' => '+ Réserves', 'montant' => $reserves, 'norme' => ''];
$tableau[] = ['code' => 'L59', 'libelle' => '+ Écart de réévaluation des immobilisations', 'montant' => $ecart_reeval, 'norme' => ''];
$tableau[] = ['code' => 'L60', 'libelle' => '+ Capital', 'montant' => $capital, 'norme' => ''];
$tableau[] = ['code' => 'L65', 'libelle' => '+ Fonds de dotation', 'montant' => $fonds_dotation, 'norme' => ''];
$tableau[] = ['code' => 'L70', 'libelle' => '+ Report à nouveau positif', 'montant' => $report_positif, 'norme' => ''];
$tableau[] = ['code' => 'L75', 'libelle' => '+ Excédent des produits sur les charges', 'montant' => $excedent_produits, 'norme' => ''];
$tableau[] = ['code' => 'L80', 'libelle' => '+ Résultat de l\'exercice', 'montant' => $resultat_exercice, 'norme' => ''];
$tableau[] = ['code' => 'L62', 'libelle' => '- Capital non appelé', 'montant' => -$capital_non_appele, 'norme' => ''];
$tableau[] = ['code' => 'E05', 'libelle' => '- Excédent des charges sur les produits', 'montant' => -$excedent_charges, 'norme' => ''];
$tableau[] = ['code' => '(D24+D31+D41+D46)', 'libelle' => '- Immobilisations incorporelles nettes', 'montant' => -$immobilisations_incorp, 'norme' => ''];
$tableau[] = ['code' => 'L70', 'libelle' => '- Report à nouveau négatif', 'montant' => -$report_negatif, 'norme' => ''];
$tableau[] = ['code' => 'L80', 'libelle' => '- Résultat déficitaire de l\'exercice', 'montant' => -$resultat_deficitaire, 'norme' => ''];
$tableau[] = ['code' => 'Z52', 'libelle' => '- Complément de provisions non constituées et exigées par les autorités de contrôle', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'Z53', 'libelle' => '- Toutes participations constituant des fonds propres dans d\'autres SFD ou établissements de crédit', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $fonds_propres_net, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'Norme', 'montant' => '', 'norme' => 'MAX. 25%'];
$tableau[] = ['code' => 'R09', 'libelle' => 'Ratio', 'montant' => number_format($ratio_r09 * 100, 2) . '%', 'norme' => ''];

// R10
$tableau[] = ['code' => '', 'libelle' => 'FINANCEMENT DES IMMOBILISATIONS ET DES PARTICIPATIONS', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'A - IMMOBILISATIONS NETTES', 'montant' => '', 'norme' => ''];
$tableau[] = ['code' => 'D24', 'libelle' => 'Immobilisations incorporelles en cours', 'montant' => $D24, 'norme' => ''];
$tableau[] = ['code' => 'D25', 'libelle' => 'Immobilisations corporelles en cours', 'montant' => $D25, 'norme' => ''];
$tableau[] = ['code' => 'D31', 'libelle' => 'Immobilisations incorporelles d\'exploitation, déduction faite des frais et valeurs immobilisés', 'montant' => $D31, 'norme' => ''];
$tableau[] = ['code' => 'D36', 'libelle' => 'Immobilisations corporelles d\'exploitation', 'montant' => $D36, 'norme' => ''];
$tableau[] = ['code' => 'D41', 'libelle' => 'Immobilisations incorporelles hors exploitation', 'montant' => $D41, 'norme' => ''];
$tableau[] = ['code' => 'D45', 'libelle' => 'Immobilisations corporelles hors exploitation', 'montant' => $D45, 'norme' => ''];
$tableau[] = ['code' => 'D46', 'libelle' => 'Immobilisations incorporelles hors exploitation par réalisation de garantie', 'montant' => $D46, 'norme' => ''];
$tableau[] = ['code' => 'D47', 'libelle' => 'Immobilisations corporelles hors exploitation acquises par réalisation de garantie', 'montant' => $D47, 'norme' => ''];
$tableau[] = ['code' => 'D1E', 'libelle' => 'Titres de participation', 'montant' => $D1E, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $immobilisations_incorp + $D1E + $D36, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'B - FONDS PROPRES', 'montant' => '', 'norme' => ''];
// Mêmes lignes que R03
$tableau[] = ['code' => 'L10', 'libelle' => '+ Subventions d\'investissement', 'montant' => $subventions, 'norme' => ''];
$tableau[] = ['code' => 'L20', 'libelle' => '+ Fonds affectés', 'montant' => $fonds_affectes, 'norme' => ''];
$tableau[] = ['code' => 'L27', 'libelle' => '+ Fonds de crédit', 'montant' => $fonds_credit, 'norme' => ''];
$tableau[] = ['code' => 'L30', 'libelle' => '+ Provisions pour risques et charges', 'montant' => $provisions_risques, 'norme' => ''];
$tableau[] = ['code' => 'L35', 'libelle' => '+ Provisions réglementées', 'montant' => $provisions_reg, 'norme' => ''];
$tableau[] = ['code' => 'L41', 'libelle' => '+ Emprunts et titres émis subordonnés', 'montant' => $emprunts_sub, 'norme' => ''];
$tableau[] = ['code' => 'L45', 'libelle' => '+ Fonds pour risques financiers généraux', 'montant' => $fonds_risques, 'norme' => ''];
$tableau[] = ['code' => 'L50', 'libelle' => '+ Primes liées au capital', 'montant' => $primes_cap, 'norme' => ''];
$tableau[] = ['code' => 'L55', 'libelle' => '+ Réserves', 'montant' => $reserves, 'norme' => ''];
$tableau[] = ['code' => 'L59', 'libelle' => '+ Écart de réévaluation des immobilisations', 'montant' => $ecart_reeval, 'norme' => ''];
$tableau[] = ['code' => 'L60', 'libelle' => '+ Capital', 'montant' => $capital, 'norme' => ''];
$tableau[] = ['code' => 'L65', 'libelle' => '+ Fonds de dotation', 'montant' => $fonds_dotation, 'norme' => ''];
$tableau[] = ['code' => 'L70', 'libelle' => '+ Report à nouveau positif', 'montant' => $report_positif, 'norme' => ''];
$tableau[] = ['code' => 'L75', 'libelle' => '+ Excédent des produits sur les charges', 'montant' => $excedent_produits, 'norme' => ''];
$tableau[] = ['code' => 'L80', 'libelle' => '+ Résultat de l\'exercice', 'montant' => $resultat_exercice, 'norme' => ''];
$tableau[] = ['code' => 'L62', 'libelle' => '- Capital non appelé', 'montant' => -$capital_non_appele, 'norme' => ''];
$tableau[] = ['code' => 'E05', 'libelle' => '- Excédent des charges sur les produits', 'montant' => -$excedent_charges, 'norme' => ''];
$tableau[] = ['code' => '(D24+D31+D41+D46)', 'libelle' => '- Immobilisations incorporelles nettes', 'montant' => -$immobilisations_incorp, 'norme' => ''];
$tableau[] = ['code' => 'L70', 'libelle' => '- Report à nouveau négatif', 'montant' => -$report_negatif, 'norme' => ''];
$tableau[] = ['code' => 'L80', 'libelle' => '- Résultat déficitaire de l\'exercice', 'montant' => -$resultat_deficitaire, 'norme' => ''];
$tableau[] = ['code' => 'Z52', 'libelle' => '- Complément de provisions non constituées et exigées par les autorités de contrôle', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => 'Z53', 'libelle' => '- Toutes participations constituant des fonds propres dans d\'autres SFD ou établissements de crédit', 'montant' => 0, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'TOTAL', 'montant' => $fonds_propres_net, 'norme' => ''];
$tableau[] = ['code' => '', 'libelle' => 'Norme', 'montant' => '', 'norme' => 'MAX. 100%'];
$tableau[] = ['code' => 'R10', 'libelle' => 'Ratio', 'montant' => number_format($ratio_r10 * 100, 2) . '%', 'norme' => ''];

// ============================================================
// EXPORT PDF
// ============================================================
if (isset($_POST['export']) && $_POST['export'] === 'pdf') {
    if (ob_get_length()) ob_clean();
    
    class PDF_DIMF_RESUME extends FPDF {
        function u($str) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $str); }
        
        function Header() {
            $this->SetFillColor(156,163,175);
            $this->Rect(0,0,$this->GetPageWidth(),28,'F');
            $this->SetFont('Arial','',7);
            $this->SetTextColor(255,255,255);
            $this->SetXY(8,3);
            $this->Cell(0,4,$this->u('République de Côte d\'Ivoire  •  Ministère de l\'Economie et des Finances  -  DGTCP / DSFD'),0,1,'L');
            $this->SetFont('Arial','B',13);
            $this->SetTextColor(255,255,255);
            $this->SetX(8);
            $this->Cell(0,7,$this->u('ETAT DE DETERMINATION DES RATIOS PRUDENTIELS'),0,1,'L');
            $this->SetFont('Arial','',8);
            $this->SetTextColor(255,255,255);
            $this->SetX(8);
            $this->Cell(0,5,$this->u('ARTICLE 44 - SICS-BCEAO  •  Période : ' . $GLOBALS['exercice'] . ' - ' . ucfirst($GLOBALS['type_periode'])),0,1,'L');
            $this->SetTextColor(0,0,0);
            $this->Ln(4);
        }
        
        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial','I',7);
            $this->SetTextColor(100,116,139);
            $this->Cell(0,4,$this->u('Généré le ' . date('d/m/Y H:i:s') . '  •  Page ' . $this->PageNo() . '/{nb}'),0,0,'C');
        }
        
        function TableHeader($cols) {
            $this->SetFont('Arial','B',7);
            $this->SetFillColor(248,250,252);
            $this->SetDrawColor(226,232,240);
            foreach ($cols as $col) {
                $this->Cell($col['w'],6,$this->u($col['label']),1,0,$col['align']??'L',true);
            }
            $this->Ln();
        }
        
        function TableRow($cols, $data, $style='') {
            $fill = ($style=='section' || $style=='total');
            if ($style=='section') {
                $this->SetFillColor(230,240,255);
                $this->SetFont('Arial','B',7);
            } elseif ($style=='total') {
                $this->SetFillColor(240,253,244);
                $this->SetFont('Arial','B',7);
            } else {
                $this->SetFillColor(255,255,255);
                $this->SetFont('Arial','',7);
            }
            foreach ($cols as $i=>$col) {
                $val = isset($data[$i]) ? $data[$i] : '';
                $this->Cell($col['w'],5.5,$this->u($val),1,0,$col['align']??'L',$fill);
            }
            $this->Ln();
        }
    }
    
    $pdf = new PDF_DIMF_RESUME('P','mm','A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(8,30,8);
    $pdf->AddPage();
    
    $cols = [
        ['w' => 18, 'label' => 'Code', 'align' => 'L'],
        ['w' => 85, 'label' => 'Libellé', 'align' => 'L'],
        ['w' => 40, 'label' => 'Montant (FCFA)', 'align' => 'R'],
        ['w' => 35, 'label' => 'Norme', 'align' => 'L']
    ];
    $pdf->TableHeader($cols);
    
    $is_section = false;
    foreach ($tableau as $row) {
        $style = '';
        if (strpos($row['libelle'], 'LIMITATION') === 0 || strpos($row['libelle'], 'COUVERTURE') === 0 || strpos($row['libelle'], 'NORME') === 0 || strpos($row['libelle'], 'CONSTITUTION') === 0 || strpos($row['libelle'], 'FINANCEMENT') === 0) {
            $style = 'section';
        } elseif ($row['libelle'] == 'TOTAL' || $row['libelle'] == 'Norme' || strpos($row['libelle'], 'TOTAL') === 0) {
            $style = 'total';
        }
        $montant = is_numeric($row['montant']) ? number_format($row['montant'], 0, ',', ' ') : $row['montant'];
        $pdf->TableRow($cols, [$row['code'], $row['libelle'], $montant, $row['norme']], $style);
    }
    
    $pdf->Output('I', 'RESUME_RATIOS_' . $exercice . '_' . $type_periode . '.pdf');
    exit;
}

// ============================================================
// EXPORT EXCEL
// ============================================================
if (isset($_POST['export']) && $_POST['export'] === 'excel') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="RESUME_RATIOS_' . $exercice . '_' . $type_periode . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<html><head><meta charset="UTF-8"><style>
    body { font-family: Arial; margin:20px; }
    table { border-collapse: collapse; width:100%; }
    th, td { border:1px solid #999; padding:6px; font-size:9pt; }
    th { background:#f2f2f2; font-weight:bold; text-align:center; }
    .section { background:#d9e8f5; font-weight:bold; }
    .total { background:#e8f5e9; font-weight:bold; }
    .text-right { text-align:right; }
    </style></head><body>';
    echo '<h2>ETAT DE DETERMINATION DES RATIOS PRUDENTIELS</h2>';
    echo '<p>Période : ' . $exercice . ' - ' . ucfirst($type_periode) . ' (arrêtée au ' . date('d/m/Y', strtotime($date_fin_periode)) . ')</p>';
    echo '<table>';
    echo '<tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th><th>Norme</th></tr>';
    foreach ($tableau as $row) {
        $class = '';
        if (strpos($row['libelle'], 'LIMITATION') === 0 || strpos($row['libelle'], 'COUVERTURE') === 0 || strpos($row['libelle'], 'NORME') === 0 || strpos($row['libelle'], 'CONSTITUTION') === 0 || strpos($row['libelle'], 'FINANCEMENT') === 0) {
            $class = 'section';
        } elseif ($row['libelle'] == 'TOTAL' || $row['libelle'] == 'Norme' || strpos($row['libelle'], 'TOTAL') === 0) {
            $class = 'total';
        }
        $montant = is_numeric($row['montant']) ? number_format($row['montant'], 0, ',', ' ') : $row['montant'];
        echo '<tr class="' . $class . '"><td>' . $row['code'] . '</td><td>' . $row['libelle'] . '</td><td class="text-right">' . $montant . '</td><td>' . $row['norme'] . '</td></tr>';
    }
    echo '</table>';
    echo '</body></html>';
    exit;
}

// ============================================================
// AFFICHAGE WEB
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Résumé des ratios prudentiels</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background:#f1f5f9; padding:24px; font-family:'Inter',system-ui,sans-serif; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .btn-excel, .btn-pdf { padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; }
        .btn-excel { background:#10b981; color:white; }
        .btn-pdf { background:#ef4444; color:white; }
        .card { background:white; border-radius:20px; padding:20px 24px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.78rem; }
        th, td { padding:6px 8px; border:1px solid #e2e8f0; vertical-align:top; }
        th { background:#f8fafc; font-weight:600; text-align:center; }
        .section { background:#d9e8f5; font-weight:700; }
        .total { background:#f0fdf4; font-weight:700; }
        .text-right { text-align:right; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select { padding:8px 14px; border:1px solid #d1d5db; border-radius:12px; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .filters-row, #filtersCard { display:none; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1>ETAT DE DETERMINATION DES RATIOS PRUDENTIELS</h1>
            <div class="subtitle">ARTICLE 44 - SICS-BCEAO</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="submitExport('excel')"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" onclick="submitExport('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <!-- Filtres -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres de période</div>
        <form method="post" id="filterForm">
            <div class="filters-row">
                <div class="filter-item"><label>Année</label><select name="exercice"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
                <div class="filter-item"><label>Type de période</label><select name="type_periode" id="typePeriodeSelect"><option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option><option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option><option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option><option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option></select></div>
                <div class="filter-item" id="dynamicSelectContainer"></div>
                <button type="submit" class="btn-apply">Appliquer</button>
            </div>
        </form>
    </div>

    <!-- Tableau complet -->
    <div class="card">
        <div class="card-header"><i class="fas fa-table"></i> ETAT DE DETERMINATION DES RATIOS PRUDENTIELS</div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th><th>Norme</th></tr></thead>
                <tbody>
                    <?php foreach ($tableau as $row): 
                        $class = '';
                        if (strpos($row['libelle'], 'LIMITATION') === 0 || strpos($row['libelle'], 'COUVERTURE') === 0 || strpos($row['libelle'], 'NORME') === 0 || strpos($row['libelle'], 'CONSTITUTION') === 0 || strpos($row['libelle'], 'FINANCEMENT') === 0) {
                            $class = 'section';
                        } elseif ($row['libelle'] == 'TOTAL' || $row['libelle'] == 'Norme' || strpos($row['libelle'], 'TOTAL') === 0) {
                            $class = 'total';
                        }
                        $montant = is_numeric($row['montant']) ? number_format($row['montant'], 0, ',', ' ') : $row['montant'];
                    ?>
                        <tr class="<?= $class ?>">
                            <td><?= $row['code'] ?></td>
                            <td><?= $row['libelle'] ?></td>
                            <td class="text-right"><?= $montant ?></td>
                            <td><?= $row['norme'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="footer">Généré le <?=date('d/m/Y à H:i:s')?> – Période <?=$exercice?> (<?=ucfirst($type_periode)?>) arrêtée au <?=date('d/m/Y',strtotime($date_fin_periode))?></div>
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