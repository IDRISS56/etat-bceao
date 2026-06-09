<?php
// R09.php - Limitation des prises de participation
// Norme BCEAO: 0% à 25% (0 - 0.25)

session_start();

// Configuration BDD
$host = 'localhost';
$dbname = 'mandigo';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Récupérer l'année et le mois
$exercice = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : date('m');
$periode = $exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT);
$date_fin_periode = $exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01';
$date_fin_periode = date('Y-m-t', strtotime($date_fin_periode));

// ============================================================
// A - TITRES DE PARTICIPATION (D1E)
// ============================================================

// Titres de participation dans d'autres sociétés
$titresParticipation = 0;
$detailsParticipation = [];

try {
    // Récupération depuis les écritures comptables
    $stmtTitres = $pdo->prepare("
        SELECT 
            e.compte_general,
            pc.libelle_compte,
            COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '26%'  -- Comptes de titres de participation (261, 262, ...)
          AND e.date_ecriture <= :date_fin
        GROUP BY e.compte_general, pc.libelle_compte
        HAVING solde > 0
    ");
    $stmtTitres->execute([':date_fin' => $date_fin_periode]);
    $detailsParticipation = $stmtTitres->fetchAll();
    
    // Calcul du total
    $stmtTotal = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '26%'
          AND e.date_ecriture <= :date_fin
    ");
    $stmtTotal->execute([':date_fin' => $date_fin_periode]);
    $resultTotal = $stmtTotal->fetch();
    $titresParticipation = $resultTotal['total'];
} catch (PDOException $e) {
    $titresParticipation = 0;
    $detailsParticipation = [];
}

// Titres de participation dans les établissements de crédit et SFD (à déduire)
$participationsSFD = 0;
try {
    $stmtSFD = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '261%'  -- Participations dans SFD
          AND e.date_ecriture <= :date_fin
    ");
    $stmtSFD->execute([':date_fin' => $date_fin_periode]);
    $resultSFD = $stmtSFD->fetch();
    $participationsSFD = $resultSFD['total'];
} catch (PDOException $e) {
    $participationsSFD = 0;
}

// Titres de participation nets (A)
$titresParticipationNets = $titresParticipation - $participationsSFD;

// ============================================================
// B - FONDS PROPRES (même calcul que R03/R08)
// ============================================================

// 1. Éléments positifs des fonds propres
$fondsPropPositifs = 0;

// L10 - Subventions d'investissement
$subventions = 0;
try {
    $stmtSubv = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '13%' AND e.date_ecriture <= :date_fin
    ");
    $stmtSubv->execute([':date_fin' => $date_fin_periode]);
    $resultSubv = $stmtSubv->fetch();
    $subventions = $resultSubv['solde'];
    $fondsPropPositifs += $subventions;
} catch (PDOException $e) { $subventions = 0; }

// L20 - Fonds affectés
$fondsAffectes = 0;
try {
    $stmtAffectes = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '14%' AND e.date_ecriture <= :date_fin
    ");
    $stmtAffectes->execute([':date_fin' => $date_fin_periode]);
    $resultAffectes = $stmtAffectes->fetch();
    $fondsAffectes = $resultAffectes['solde'];
    $fondsPropPositifs += $fondsAffectes;
} catch (PDOException $e) { $fondsAffectes = 0; }

// L27 - Fonds de crédit
$fondsCredit = 0;
try {
    $stmtCredit = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '15%' AND e.date_ecriture <= :date_fin
    ");
    $stmtCredit->execute([':date_fin' => $date_fin_periode]);
    $resultCredit = $stmtCredit->fetch();
    $fondsCredit = $resultCredit['solde'];
    $fondsPropPositifs += $fondsCredit;
} catch (PDOException $e) { $fondsCredit = 0; }

// L30 - Provisions pour risques et charges
$provisionsRisques = 0;
try {
    $stmtProvisions = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '17%' AND e.date_ecriture <= :date_fin
    ");
    $stmtProvisions->execute([':date_fin' => $date_fin_periode]);
    $resultProvisions = $stmtProvisions->fetch();
    $provisionsRisques = $resultProvisions['solde'];
    $fondsPropPositifs += $provisionsRisques;
} catch (PDOException $e) { $provisionsRisques = 0; }

// L35 - Provisions réglementées
$provisionsReglementees = 0;
try {
    $stmtReg = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '18%' AND e.date_ecriture <= :date_fin
    ");
    $stmtReg->execute([':date_fin' => $date_fin_periode]);
    $resultReg = $stmtReg->fetch();
    $provisionsReglementees = $resultReg['solde'];
    $fondsPropPositifs += $provisionsReglementees;
} catch (PDOException $e) { $provisionsReglementees = 0; }

// L41 - Emprunts et titres émis subordonnés
$empruntsSubordonnes = 0;
try {
    $stmtSubord = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '16%' AND e.date_ecriture <= :date_fin
    ");
    $stmtSubord->execute([':date_fin' => $date_fin_periode]);
    $resultSubord = $stmtSubord->fetch();
    $empruntsSubordonnes = $resultSubord['solde'];
    $fondsPropPositifs += $empruntsSubordonnes;
} catch (PDOException $e) { $empruntsSubordonnes = 0; }

// L45 - Fonds pour risques financiers généraux
$fondsRisques = 0;
try {
    $stmtFRG = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '19%' AND e.date_ecriture <= :date_fin
    ");
    $stmtFRG->execute([':date_fin' => $date_fin_periode]);
    $resultFRG = $stmtFRG->fetch();
    $fondsRisques = $resultFRG['solde'];
    $fondsPropPositifs += $fondsRisques;
} catch (PDOException $e) { $fondsRisques = 0; }

// L50 - Primes liées au capital
$primesCapital = 0;
try {
    $stmtPrimes = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '102%' AND e.date_ecriture <= :date_fin
    ");
    $stmtPrimes->execute([':date_fin' => $date_fin_periode]);
    $resultPrimes = $stmtPrimes->fetch();
    $primesCapital = $resultPrimes['solde'];
    $fondsPropPositifs += $primesCapital;
} catch (PDOException $e) { $primesCapital = 0; }

// L55 - Réserves
$reserves = 0;
try {
    $stmtReserves = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '106%' AND e.date_ecriture <= :date_fin
    ");
    $stmtReserves->execute([':date_fin' => $date_fin_periode]);
    $resultReserves = $stmtReserves->fetch();
    $reserves = $resultReserves['solde'];
    $fondsPropPositifs += $reserves;
} catch (PDOException $e) { $reserves = 0; }

// L59 - Écart de réévaluation des immobilisations
$ecartReeval = 0;
try {
    $stmtReeval = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '107%' AND e.date_ecriture <= :date_fin
    ");
    $stmtReeval->execute([':date_fin' => $date_fin_periode]);
    $resultReeval = $stmtReeval->fetch();
    $ecartReeval = $resultReeval['solde'];
    $fondsPropPositifs += $ecartReeval;
} catch (PDOException $e) { $ecartReeval = 0; }

// L60 - Capital
$capital = 0;
try {
    $stmtCapital = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '101%' AND e.date_ecriture <= :date_fin
    ");
    $stmtCapital->execute([':date_fin' => $date_fin_periode]);
    $resultCapital = $stmtCapital->fetch();
    $capital = $resultCapital['solde'];
    $fondsPropPositifs += $capital;
} catch (PDOException $e) {
    // Fallback sur table capital
    try {
        $stmtCapital2 = $pdo->prepare("
            SELECT COALESCE(SUM(montant), 0) as total
            FROM capital
            WHERE statut = 'valide' AND date_creation <= :date_fin
        ");
        $stmtCapital2->execute([':date_fin' => $date_fin_periode]);
        $resultCapital2 = $stmtCapital2->fetch();
        $capital = $resultCapital2['total'];
        $fondsPropPositifs += $capital;
    } catch (PDOException $e2) { $capital = 0; }
}

// L65 - Fonds de dotation
$fondsDotation = 0;
try {
    $stmtDotation = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '103%' AND e.date_ecriture <= :date_fin
    ");
    $stmtDotation->execute([':date_fin' => $date_fin_periode]);
    $resultDotation = $stmtDotation->fetch();
    $fondsDotation = $resultDotation['solde'];
    $fondsPropPositifs += $fondsDotation;
} catch (PDOException $e) { $fondsDotation = 0; }

// L70 - Report à nouveau positif
$reportPositif = 0;
try {
    $stmtReportPos = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN (e.montant_credit - e.montant_debit) > 0 
            THEN e.montant_credit - e.montant_debit ELSE 0 END), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '11%' AND e.date_ecriture <= :date_fin
    ");
    $stmtReportPos->execute([':date_fin' => $date_fin_periode]);
    $resultReportPos = $stmtReportPos->fetch();
    $reportPositif = $resultReportPos['solde'];
    $fondsPropPositifs += $reportPositif;
} catch (PDOException $e) { $reportPositif = 0; }

// L75 - Excédent des produits sur les charges
$excedentProduits = 0;
try {
    $stmtExcedent = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte = '120' AND e.date_ecriture <= :date_fin
    ");
    $stmtExcedent->execute([':date_fin' => $date_fin_periode]);
    $resultExcedent = $stmtExcedent->fetch();
    $excedentProduits = $resultExcedent['solde'];
    if ($excedentProduits > 0) {
        $fondsPropPositifs += $excedentProduits;
    }
} catch (PDOException $e) { $excedentProduits = 0; }

// L80 - Résultat excédentaire de l'exercice
$resultatExercice = 0;
try {
    $stmtResultat = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN pc.classe_compte = '7' THEN e.montant_credit - e.montant_debit ELSE 0 END), 0) as produits,
            COALESCE(SUM(CASE WHEN pc.classe_compte = '6' THEN e.montant_debit - e.montant_credit ELSE 0 END), 0) as charges
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte IN ('6', '7')
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmtResultat->execute([
        ':date_debut' => $exercice . '-01-01',
        ':date_fin' => $date_fin_periode
    ]);
    $resultResultat = $stmtResultat->fetch();
    $resultatBrut = $resultResultat['produits'] - $resultResultat['charges'];
    if ($resultatBrut > 0) {
        $resultatExercice = $resultatBrut;
        $fondsPropPositifs += $resultatExercice;
    }
} catch (PDOException $e) { $resultatExercice = 0; }

// 2. Éléments à déduire des fonds propres
$fondsPropDeductions = 0;

// L62 - Capital non appelé
$capitalNonAppele = 0;
try {
    $stmtNonAppele = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte = '109' AND e.date_ecriture <= :date_fin
    ");
    $stmtNonAppele->execute([':date_fin' => $date_fin_periode]);
    $resultNonAppele = $stmtNonAppele->fetch();
    $capitalNonAppele = abs($resultNonAppele['solde']);
    $fondsPropDeductions += $capitalNonAppele;
} catch (PDOException $e) { $capitalNonAppele = 0; }

// E05 - Excédent des charges sur les produits
$excedentCharges = 0;
if (isset($resultatBrut) && $resultatBrut < 0) {
    $excedentCharges = abs($resultatBrut);
    $fondsPropDeductions += $excedentCharges;
}

// D24, D31, D41, D46 - Immobilisations incorporelles nettes
$immobilisationsIncorp = 0;
try {
    $stmtImmob = $pdo->prepare("
        SELECT COALESCE(SUM(i.montant_achat - i.amortissement_total), 0) as valeur_nette
        FROM immobilisations i
        WHERE i.type_immobilisation = 'Immobilisations incorporelles'
          AND i.statut = 'actif'
          AND i.date_achat <= :date_fin
    ");
    $stmtImmob->execute([':date_fin' => $date_fin_periode]);
    $resultImmob = $stmtImmob->fetch();
    $immobilisationsIncorp = $resultImmob['valeur_nette'];
    $fondsPropDeductions += $immobilisationsIncorp;
} catch (PDOException $e) { $immobilisationsIncorp = 0; }

// L70 - Report à nouveau négatif
$reportNegatif = 0;
try {
    $stmtReportNeg = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN (e.montant_credit - e.montant_debit) < 0 
            THEN ABS(e.montant_credit - e.montant_debit) ELSE 0 END), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '11%' AND e.date_ecriture <= :date_fin
    ");
    $stmtReportNeg->execute([':date_fin' => $date_fin_periode]);
    $resultReportNeg = $stmtReportNeg->fetch();
    $reportNegatif = $resultReportNeg['solde'];
    $fondsPropDeductions += $reportNegatif;
} catch (PDOException $e) { $reportNegatif = 0; }

// Z52 - Complément de provisions non constituées
$provisionsNonConst = isset($_GET['provisions_non_const']) ? (float)$_GET['provisions_non_const'] : 0;
$fondsPropDeductions += $provisionsNonConst;

// TOTAL FONDS PROPRES
$fondsPropres = $fondsPropPositifs - $fondsPropDeductions;

// ============================================================
// CALCUL DU RATIO R09
// ============================================================

if ($fondsPropres <= 0) {
    $fondsPropres = 1;
    $ratioR09 = 0;
} else {
    $ratioR09 = $titresParticipationNets / $fondsPropres;
}

// Normes : 0% à 25%
$normeMin = 0;
$normeMax = 0.25;
$conformite = ($ratioR09 >= $normeMin && $ratioR09 <= $normeMax) ? 'CONFORME' : 'NON_CONFORME';
$pourcentageParticipation = $ratioR09 * 100;

// Détail des fonds propres pour affichage
$detailsFondsProp = [
    ['code' => 'L10', 'libelle' => 'Subventions d\'investissement', 'montant' => $subventions],
    ['code' => 'L20', 'libelle' => 'Fonds affectés', 'montant' => $fondsAffectes],
    ['code' => 'L27', 'libelle' => 'Fonds de crédit', 'montant' => $fondsCredit],
    ['code' => 'L30', 'libelle' => 'Provisions pour risques et charges', 'montant' => $provisionsRisques],
    ['code' => 'L35', 'libelle' => 'Provisions réglementées', 'montant' => $provisionsReglementees],
    ['code' => 'L41', 'libelle' => 'Emprunts et titres émis subordonnés', 'montant' => $empruntsSubordonnes],
    ['code' => 'L45', 'libelle' => 'Fonds pour risques financiers généraux', 'montant' => $fondsRisques],
    ['code' => 'L50', 'libelle' => 'Primes liées au capital', 'montant' => $primesCapital],
    ['code' => 'L55', 'libelle' => 'Réserves', 'montant' => $reserves],
    ['code' => 'L59', 'libelle' => 'Écart de réévaluation des immobilisations', 'montant' => $ecartReeval],
    ['code' => 'L60', 'libelle' => 'Capital', 'montant' => $capital],
    ['code' => 'L65', 'libelle' => 'Fonds de dotation', 'montant' => $fondsDotation],
    ['code' => 'L70', 'libelle' => 'Report à nouveau positif', 'montant' => $reportPositif],
    ['code' => 'L75', 'libelle' => 'Excédent des produits sur les charges', 'montant' => $excedentProduits],
    ['code' => 'L80', 'libelle' => 'Résultat excédentaire de l\'exercice', 'montant' => $resultatExercice],
];

$detailsDeductions = [
    ['code' => 'L62', 'libelle' => 'Capital non appelé', 'montant' => $capitalNonAppele],
    ['code' => 'E05', 'libelle' => 'Excédent des charges sur les produits', 'montant' => $excedentCharges],
    ['code' => 'D24/31/41/46', 'libelle' => 'Immobilisations incorporelles nettes', 'montant' => $immobilisationsIncorp],
    ['code' => 'L70', 'libelle' => 'Report à nouveau négatif', 'montant' => $reportNegatif],
    ['code' => 'Z52', 'libelle' => 'Complément de provisions non constituées', 'montant' => $provisionsNonConst],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>R09 - Limitation des prises de participation</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #1a3a5c, #0d2137);
            color: white;
            padding: 25px 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 8px;
        }
        
        .header .subtitle {
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .badge {
            display: inline-block;
            background: #ffc107;
            color: #1a3a5c;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            margin-top: 10px;
        }
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #555;
        }
        
        .filter-group select, .filter-group input {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #1a3a5c;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0d2137;
        }
        
        .ratio-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .ratio-title {
            font-size: 1.1rem;
            color: #555;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .ratio-value-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .ratio-value {
            text-align: center;
        }
        
        .ratio-value .value {
            font-size: 3rem;
            font-weight: bold;
        }
        
        .ratio-value .label {
            color: #777;
            font-size: 0.85rem;
        }
        
        .conforme {
            color: #2e7d32;
        }
        
        .non-conforme {
            color: #c62828;
        }
        
        .norme {
            background: #f5f5f5;
            padding: 10px 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .norme .title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .norme .range {
            font-size: 1.3rem;
            font-weight: bold;
            color: #1a3a5c;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.85rem;
        }
        
        .status-conforme {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-non-conforme {
            background: #ffebee;
            color: #c62828;
        }
        
        .data-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .data-table h3 {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            font-size: 1rem;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #777;
            font-size: 0.8rem;
        }
        
        .warning {
            background: #fff3e0;
            border-left: 4px solid #ff9800;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        
        .info {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .progress-bar {
            background: #e0e0e0;
            border-radius: 10px;
            height: 20px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            background: linear-gradient(90deg, #2e7d32, #4caf50);
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s ease;
            text-align: center;
            color: white;
            font-size: 0.7rem;
            line-height: 20px;
        }
        
        .progress-fill.non-conforme {
            background: linear-gradient(90deg, #c62828, #f44336);
        }
        
        .two-columns {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .two-columns > div {
            flex: 1;
            min-width: 300px;
        }
        
        .manual-input {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
        }
        
        @media (max-width: 768px) {
            .ratio-value-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            table {
                font-size: 0.8rem;
            }
            
            th, td {
                padding: 8px 10px;
            }
            
            .two-columns {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>R09 - Limitation des prises de participation</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Norme BCEAO : 0% à 25% des fonds propres</div>
    </div>
    
    <div class="filters">
        <div class="filter-group">
            <label>Exercice</label>
            <select name="exercice" id="exercice">
                <?php for($y = 2020; $y <= date('Y')+1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $exercice ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Mois</label>
            <select name="mois" id="mois">
                <?php for($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == $mois ? 'selected' : '' ?>>
                        <?= str_pad($m, 2, '0', STR_PAD_LEFT) ?> - 
                        <?= date('F', mktime(0,0,0,$m,1)) ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="filter-group">
            <button class="btn btn-primary" onclick="appliquerFiltres()">Appliquer</button>
        </div>
        <div class="filter-group">
            <button class="btn" onclick="exporterPDF()" style="background:#f5f5f5;">📄 Exporter PDF</button>
        </div>
    </div>
    
    <!-- Zone de saisie manuelle pour Z52 -->
    <div class="manual-input">
        <form method="get" action="">
            <label>📝 Z52 - Complément de provisions non constituées exigées par les autorités :</label>
            <input type="number" name="provisions_non_const" id="provisions_non_const" 
                   value="<?= $provisionsNonConst ?>" placeholder="Montant en FCFA">
            <input type="hidden" name="exercice" value="<?= $exercice ?>">
            <input type="hidden" name="mois" value="<?= $mois ?>">
            <button type="submit" class="btn btn-primary" style="margin-left: 10px;">Mettre à jour</button>
        </form>
        <div style="font-size: 0.8rem; color: #666; margin-top: 10px;">
            ⓘ Saisir le montant des provisions supplémentaires exigées par le superviseur (BCEAO).
        </div>
    </div>
    
    <div class="ratio-card">
        <div class="ratio-title">📊 Ratio R09 - Limitation des prises de participation</div>
        <div class="ratio-value-container">
            <div class="ratio-value">
                <div class="value <?= ($ratioR09 >= $normeMin && $ratioR09 <= $normeMax) ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($pourcentageParticipation, 2) ?>%
                </div>
                <div class="label">Titres de participation / Fonds propres</div>
            </div>
            <div class="norme">
                <div class="title">Norme réglementaire</div>
                <div class="range">0% ≤ Ratio ≤ 25%</div>
                <div class="label">Conformité requise</div>
            </div>
            <div>
                <span class="status-badge <?= $conformite == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                    <?= $conformite ?>
                </span>
            </div>
        </div>
        <div class="progress-bar" style="margin-top: 20px;">
            <div class="progress-fill <?= ($ratioR09 >= $normeMin && $ratioR09 <= $normeMax) ? '' : 'non-conforme' ?>" 
                 style="width: <?= min($pourcentageParticipation, 100) ?>%;">
                <?= number_format($pourcentageParticipation, 1) ?>%
            </div>
        </div>
    </div>
    
    <div class="two-columns">
        <div class="data-table">
            <h3>📈 A - TITRES DE PARTICIPATION (D1E)</h3>
            <table>
                <thead>
                    <tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>
                </thead>
                <tbody>
                    <?php if(!empty($detailsParticipation)): ?>
                        <?php foreach($detailsParticipation as $part): ?>
                        <tr>
                            <td><?= htmlspecialchars($part['compte_general']) ?></td>
                            <td><?= htmlspecialchars($part['libelle_compte']) ?></td>
                            <td class="text-right"><?= number_format($part['solde'], 0, ',', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="text-center">Aucun titre de participation trouvé</td>
                        </tr>
                    <?php endif; ?>
                    <tr style="background:#f5f5f5;">
                        <td colspan="2">Titres de participation bruts</td>
                        <td class="text-right"><?= number_format($titresParticipation, 0, ',', ' ') ?></td>
                    </tr>
                    <tr style="background:#ffebee;">
                        <td colspan="2">- Participations dans les SFD/établissements de crédit</td>
                        <td class="text-right"><?= number_format($participationsSFD, 0, ',', ' ') ?></td>
                    </tr>
                    <tr style="background:#e8f5e9; font-weight:bold;">
                        <td colspan="2">TITRES DE PARTICIPATION NETS (A)</td>
                        <td class="text-right"><?= number_format($titresParticipationNets, 0, ',', ' ') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="data-table">
            <h3>💰 B - FONDS PROPRES</h3>
            <table>
                <thead>
                    <tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>
                </thead>
                <tbody>
                    <?php foreach($detailsFondsProp as $item): ?>
                    <tr>
                        <td><?= $item['code'] ?></td>
                        <td><?= $item['libelle'] ?></td>
                        <td class="text-right"><?= number_format($item['montant'], 0, ',', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="background:#e8f5e9; font-weight:bold;">
                        <td colspan="2">TOTAL ÉLÉMENTS POSITIFS</td>
                        <td class="text-right"><?= number_format($fondsPropPositifs, 0, ',', ' ') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="data-table">
        <h3>📉 Éléments à déduire des fonds propres</h3>
        <table style="width: 60%; margin: 0 auto;">
            <thead>
                <tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>
            </thead>
            <tbody>
                <?php foreach($detailsDeductions as $item): ?>
                <tr>
                    <td><?= $item['code'] ?></td>
                    <td><?= $item['libelle'] ?></td>
                    <td class="text-right"><?= number_format($item['montant'], 0, ',', ' ') ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#ffebee; font-weight:bold;">
                    <td colspan="2">TOTAL DÉDUCTIONS</td>
                    <td class="text-right"><?= number_format($fondsPropDeductions, 0, ',', ' ') ?></td>
                </tr>
                <tr style="background:#f0f7ff; font-weight:bold;">
                    <td colspan="2">FONDS PROPRES (B)</td>
                    <td class="text-right"><strong><?= number_format($fondsPropres, 0, ',', ' ') ?></strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="data-table">
        <h3>📊 Synthèse du calcul du ratio R09</h3>
        <table style="width: 60%; margin: 0 auto;">
            <tbody>
                <tr>
                    <td style="width: 60%;"><strong>A - Titres de participation nets</strong></td>
                    <td class="text-right"><strong><?= number_format($titresParticipationNets, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr>
                    <td><strong>B - Fonds propres nets</strong></td>
                    <td class="text-right"><strong><?= number_format($fondsPropres, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr style="background:#f0f7ff;">
                    <td><strong>RATIO R09 = A / B</strong></td>
                    <td class="text-right"><strong><?= number_format($pourcentageParticipation, 2) ?>%</strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="data-table">
        <h3>📖 Interprétation du ratio R09 - Limitation des prises de participation</h3>
        <div style="padding: 15px; line-height: 1.6;">
            <p><strong>Ratio calculé :</strong> <?= number_format($pourcentageParticipation, 2) ?>%</p>
            <p><strong>Formule :</strong> R09 = (Titres de participation nets) / (Fonds propres nets)</p>
            <p><strong>Norme BCEAO :</strong> Le ratio doit être compris entre <strong>0% et 25%</strong>.</p>
            <p><strong>Interprétation :</strong></p>
            <ul style="margin-left: 25px; margin-top: 10px;">
                <?php if($ratioR09 >= $normeMin && $ratioR09 <= $normeMax): ?>
                    <li style="color:#2e7d32;">✓ Le ratio est <strong>CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>Les prises de participation représentent <?= number_format($pourcentageParticipation, 2) ?>% des fonds propres, soit dans la limite autorisée de 25%.</li>
                    <li>L'institution maîtrise bien son exposition aux participations.</li>
                <?php else: ?>
                    <li style="color:#c62828;">✗ Le ratio est <strong>NON CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>Les prises de participation représentent <?= number_format($pourcentageParticipation, 2) ?>% des fonds propres, ce qui dépasse la limite autorisée de 25%.</li>
                    <li>Il est recommandé de :</li>
                    <ul style="margin-left: 25px;">
                        <li>Réduire les participations dans d'autres sociétés</li>
                        <li>Augmenter les fonds propres</li>
                        <li>Céder certaines participations non stratégiques</li>
                    </ul>
                <?php endif; ?>
            </ul>
            <p style="margin-top: 15px; font-size: 0.9rem; color: #666; border-top: 1px solid #eee; padding-top: 10px;">
                <strong>Note :</strong> Sont exclus du calcul les titres de participation dans les établissements de crédit et les SFD.
            </p>
        </div>
    </div>
    
    <div class="footer">
        Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base Mandigo<br>
        Période : <?= $periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
    </div>
</div>

<script>
    function appliquerFiltres() {
        let exercice = document.getElementById('exercice').value;
        let mois = document.getElementById('mois').value;
        let provisions = document.getElementById('provisions_non_const').value;
        window.location.href = 'R09.php?exercice=' + exercice + '&mois=' + mois + '&provisions_non_const=' + provisions;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>