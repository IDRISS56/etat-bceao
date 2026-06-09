<?php
// R08.php - Norme de capitalisation
// Norme BCEAO: ≥ 15% (fonds propres / total actif)

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
// A - FONDS PROPRES (Net des déductions)
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
        WHERE pc.numero_compte LIKE '13%'  -- Comptes de subventions
          AND e.date_ecriture <= :date_fin
    ");
    $stmtSubv->execute([':date_fin' => $date_fin_periode]);
    $resultSubv = $stmtSubv->fetch();
    $subventions = $resultSubv['solde'];
    $fondsPropPositifs += $subventions;
} catch (PDOException $e) {
    $subventions = 0;
}

// L20 - Fonds affectés
$fondsAffectes = 0;
try {
    $stmtAffectes = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '14%'  -- Comptes de fonds affectés
          AND e.date_ecriture <= :date_fin
    ");
    $stmtAffectes->execute([':date_fin' => $date_fin_periode]);
    $resultAffectes = $stmtAffectes->fetch();
    $fondsAffectes = $resultAffectes['solde'];
    $fondsPropPositifs += $fondsAffectes;
} catch (PDOException $e) {
    $fondsAffectes = 0;
}

// L27 - Fonds de crédit
$fondsCredit = 0;
try {
    $stmtCredit = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '15%'  -- Comptes de fonds de crédit
          AND e.date_ecriture <= :date_fin
    ");
    $stmtCredit->execute([':date_fin' => $date_fin_periode]);
    $resultCredit = $stmtCredit->fetch();
    $fondsCredit = $resultCredit['solde'];
    $fondsPropPositifs += $fondsCredit;
} catch (PDOException $e) {
    $fondsCredit = 0;
}

// L30 - Provisions pour risques et charges
$provisionsRisques = 0;
try {
    $stmtProvisions = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '17%'  -- Comptes de provisions
          AND e.date_ecriture <= :date_fin
    ");
    $stmtProvisions->execute([':date_fin' => $date_fin_periode]);
    $resultProvisions = $stmtProvisions->fetch();
    $provisionsRisques = $resultProvisions['solde'];
    $fondsPropPositifs += $provisionsRisques;
} catch (PDOException $e) {
    $provisionsRisques = 0;
}

// L35 - Provisions réglementées
$provisionsReglementees = 0;
try {
    $stmtReg = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '18%'  -- Provisions réglementées
          AND e.date_ecriture <= :date_fin
    ");
    $stmtReg->execute([':date_fin' => $date_fin_periode]);
    $resultReg = $stmtReg->fetch();
    $provisionsReglementees = $resultReg['solde'];
    $fondsPropPositifs += $provisionsReglementees;
} catch (PDOException $e) {
    $provisionsReglementees = 0;
}

// L41 - Emprunts et titres émis subordonnés
$empruntsSubordonnes = 0;
try {
    $stmtSubord = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '16%'  -- Emprunts subordonnés
          AND e.date_ecriture <= :date_fin
    ");
    $stmtSubord->execute([':date_fin' => $date_fin_periode]);
    $resultSubord = $stmtSubord->fetch();
    $empruntsSubordonnes = $resultSubord['solde'];
    $fondsPropPositifs += $empruntsSubordonnes;
} catch (PDOException $e) {
    $empruntsSubordonnes = 0;
}

// L45 - Fonds pour risques financiers généraux
$fondsRisques = 0;
try {
    $stmtFRG = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '19%'  -- Fonds risques financiers
          AND e.date_ecriture <= :date_fin
    ");
    $stmtFRG->execute([':date_fin' => $date_fin_periode]);
    $resultFRG = $stmtFRG->fetch();
    $fondsRisques = $resultFRG['solde'];
    $fondsPropPositifs += $fondsRisques;
} catch (PDOException $e) {
    $fondsRisques = 0;
}

// L50 - Primes liées au capital
$primesCapital = 0;
try {
    $stmtPrimes = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '102%'  -- Primes d'émission
          AND e.date_ecriture <= :date_fin
    ");
    $stmtPrimes->execute([':date_fin' => $date_fin_periode]);
    $resultPrimes = $stmtPrimes->fetch();
    $primesCapital = $resultPrimes['solde'];
    $fondsPropPositifs += $primesCapital;
} catch (PDOException $e) {
    $primesCapital = 0;
}

// L55 - Réserves
$reserves = 0;
try {
    $stmtReserves = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '106%'  -- Comptes de réserves
          AND e.date_ecriture <= :date_fin
    ");
    $stmtReserves->execute([':date_fin' => $date_fin_periode]);
    $resultReserves = $stmtReserves->fetch();
    $reserves = $resultReserves['solde'];
    $fondsPropPositifs += $reserves;
} catch (PDOException $e) {
    $reserves = 0;
}

// L59 - Écart de réévaluation des immobilisations
$ecartReeval = 0;
try {
    $stmtReeval = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '107%'  -- Écarts de réévaluation
          AND e.date_ecriture <= :date_fin
    ");
    $stmtReeval->execute([':date_fin' => $date_fin_periode]);
    $resultReeval = $stmtReeval->fetch();
    $ecartReeval = $resultReeval['solde'];
    $fondsPropPositifs += $ecartReeval;
} catch (PDOException $e) {
    $ecartReeval = 0;
}

// L60 - Capital
$capital = 0;
try {
    $stmtCapital = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '101%'  -- Capital social
          AND e.date_ecriture <= :date_fin
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
            WHERE statut = 'valide'
              AND date_creation <= :date_fin
        ");
        $stmtCapital2->execute([':date_fin' => $date_fin_periode]);
        $resultCapital2 = $stmtCapital2->fetch();
        $capital = $resultCapital2['total'];
        $fondsPropPositifs += $capital;
    } catch (PDOException $e2) {
        $capital = 0;
    }
}

// L65 - Fonds de dotation
$fondsDotation = 0;
try {
    $stmtDotation = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '103%'  -- Fonds de dotation
          AND e.date_ecriture <= :date_fin
    ");
    $stmtDotation->execute([':date_fin' => $date_fin_periode]);
    $resultDotation = $stmtDotation->fetch();
    $fondsDotation = $resultDotation['solde'];
    $fondsPropPositifs += $fondsDotation;
} catch (PDOException $e) {
    $fondsDotation = 0;
}

// L70 - Report à nouveau positif
$reportPositif = 0;
try {
    $stmtReportPos = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN (e.montant_credit - e.montant_debit) > 0 
            THEN e.montant_credit - e.montant_debit ELSE 0 END), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '11%'  -- Report à nouveau
          AND e.date_ecriture <= :date_fin
    ");
    $stmtReportPos->execute([':date_fin' => $date_fin_periode]);
    $resultReportPos = $stmtReportPos->fetch();
    $reportPositif = $resultReportPos['solde'];
    $fondsPropPositifs += $reportPositif;
} catch (PDOException $e) {
    $reportPositif = 0;
}

// L75 - Excédent des produits sur les charges
$excedentProduits = 0;
try {
    $stmtExcedent = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte = '120'  -- Résultat bénéficiaire
          AND e.date_ecriture <= :date_fin
    ");
    $stmtExcedent->execute([':date_fin' => $date_fin_periode]);
    $resultExcedent = $stmtExcedent->fetch();
    $excedentProduits = $resultExcedent['solde'];
    if ($excedentProduits > 0) {
        $fondsPropPositifs += $excedentProduits;
    }
} catch (PDOException $e) {
    $excedentProduits = 0;
}

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
} catch (PDOException $e) {
    $resultatExercice = 0;
}

// 2. Éléments à déduire des fonds propres
$fondsPropDeductions = 0;

// L62 - Capital non appelé
$capitalNonAppele = 0;
try {
    $stmtNonAppele = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte = '109'  -- Capital non appelé
          AND e.date_ecriture <= :date_fin
    ");
    $stmtNonAppele->execute([':date_fin' => $date_fin_periode]);
    $resultNonAppele = $stmtNonAppele->fetch();
    $capitalNonAppele = abs($resultNonAppele['solde']);
    $fondsPropDeductions += $capitalNonAppele;
} catch (PDOException $e) {
    $capitalNonAppele = 0;
}

// E05 - Excédent des charges sur les produits (déficit)
$excedentCharges = 0;
try {
    if ($resultatBrut < 0) {
        $excedentCharges = abs($resultatBrut);
        $fondsPropDeductions += $excedentCharges;
    }
} catch (PDOException $e) {
    $excedentCharges = 0;
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
} catch (PDOException $e) {
    $immobilisationsIncorp = 0;
}

// L70 - Report à nouveau négatif
$reportNegatif = 0;
try {
    $stmtReportNeg = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN (e.montant_credit - e.montant_debit) < 0 
            THEN ABS(e.montant_credit - e.montant_debit) ELSE 0 END), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '11%'
          AND e.date_ecriture <= :date_fin
    ");
    $stmtReportNeg->execute([':date_fin' => $date_fin_periode]);
    $resultReportNeg = $stmtReportNeg->fetch();
    $reportNegatif = $resultReportNeg['solde'];
    $fondsPropDeductions += $reportNegatif;
} catch (PDOException $e) {
    $reportNegatif = 0;
}

// Z52 - Complément de provisions non constituées
$provisionsNonConst = isset($_GET['provisions_non_const']) ? (float)$_GET['provisions_non_const'] : 0;
$fondsPropDeductions += $provisionsNonConst;

// Z53 - Participations dans d'autres SFD
$participationsSFD = 0;
try {
    $stmtPart = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as solde
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '261%'  -- Participations
          AND e.date_ecriture <= :date_fin
    ");
    $stmtPart->execute([':date_fin' => $date_fin_periode]);
    $resultPart = $stmtPart->fetch();
    $participationsSFD = $resultPart['solde'];
    $fondsPropDeductions += $participationsSFD;
} catch (PDOException $e) {
    $participationsSFD = 0;
}

// TOTAL FONDS PROPRES
$fondsPropres = $fondsPropPositifs - $fondsPropDeductions;

// ============================================================
// B - TOTAL ACTIF DE FIN DE PÉRIODE (E90)
// ============================================================

$totalActif = 0;
try {
    $stmtActif = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total_actif
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '2'
          AND e.date_ecriture <= :date_fin
    ");
    $stmtActif->execute([':date_fin' => $date_fin_periode]);
    $resultActif = $stmtActif->fetch();
    $totalActif = $resultActif ? $resultActif['total_actif'] : 0;
    
    if ($totalActif == 0) {
        // Fallback: calcul à partir des soldes des comptes d'actif
        $stmtActif2 = $pdo->prepare("
            SELECT COALESCE(SUM(solde), 0) as total
            FROM comptes
            WHERE solde > 0 AND statut = 'actif'
        ");
        $stmtActif2->execute();
        $resultActif2 = $stmtActif2->fetch();
        $totalActif = $resultActif2['total'];
    }
} catch (PDOException $e) {
    $totalActif = 0;
}

// ============================================================
// CALCUL DU RATIO R08
// ============================================================

if ($totalActif <= 0) {
    $totalActif = 1;
    $ratioR08 = 0;
} else {
    $ratioR08 = $fondsPropres / $totalActif;
}

// Norme : ≥ 15%
$normeMin = 0.15;
$normeMax = null;
$conformite = ($ratioR08 >= $normeMin) ? 'CONFORME' : 'NON_CONFORME';
$pourcentageCapitalisation = $ratioR08 * 100;

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
    ['code' => 'Z53', 'libelle' => 'Participations dans d\'autres SFD', 'montant' => $participationsSFD],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>R08 - Norme de capitalisation</title>
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
        <h1>R08 - Norme de capitalisation</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Norme BCEAO : Fonds propres / Actif total ≥ 15%</div>
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
        <div class="ratio-title">📊 Ratio R08 - Norme de capitalisation</div>
        <div class="ratio-value-container">
            <div class="ratio-value">
                <div class="value <?= $ratioR08 >= $normeMin ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($pourcentageCapitalisation, 2) ?>%
                </div>
                <div class="label">Fonds propres / Actif total</div>
            </div>
            <div class="norme">
                <div class="title">Norme réglementaire</div>
                <div class="range">Ratio ≥ 15%</div>
                <div class="label">Conformité requise</div>
            </div>
            <div>
                <span class="status-badge <?= $conformite == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                    <?= $conformite ?>
                </span>
            </div>
        </div>
        <div class="progress-bar" style="margin-top: 20px;">
            <div class="progress-fill <?= $ratioR08 >= $normeMin ? '' : 'non-conforme' ?>" 
                 style="width: <?= min($pourcentageCapitalisation, 100) ?>%;">
                <?= number_format($pourcentageCapitalisation, 1) ?>%
            </div>
        </div>
    </div>
    
    <div class="two-columns">
        <div class="data-table">
            <h3>📈 A - FONDS PROPRES (L01)</h3>
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
        
        <div class="data-table">
            <h3>📉 Éléments à déduire des fonds propres</h3>
            <table>
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
                        <td colspan="2">FONDS PROPRES (A)</td>
                        <td class="text-right"><strong><?= number_format($fondsPropres, 0, ',', ' ') ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="data-table">
        <h3>🏦 B - TOTAL ACTIF DE FIN DE PÉRIODE</h3>
        <table>
            <tbody>
                <tr>
                    <td style="width: 80%;"><strong>E90 - Total actif net</strong></td>
                    <td class="text-right"><strong><?= number_format($totalActif, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="data-table">
        <h3>📊 Synthèse du calcul du ratio R08</h3>
        <table>
            <tbody>
                <tr>
                    <td style="width: 60%;"><strong>A - Fonds propres (nets des déductions)</strong></td>
                    <td class="text-right"><strong><?= number_format($fondsPropres, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr>
                    <td><strong>B - Total actif de fin de période</strong></td>
                    <td class="text-right"><strong><?= number_format($totalActif, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr style="background:#f0f7ff;">
                    <td><strong>RATIO R08 = A / B</strong></td>
                    <td class="text-right"><strong><?= number_format($pourcentageCapitalisation, 2) ?>%</strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="data-table">
        <h3>📖 Interprétation du ratio R08 - Norme de capitalisation</h3>
        <div style="padding: 15px; line-height: 1.6;">
            <p><strong>Ratio calculé :</strong> <?= number_format($pourcentageCapitalisation, 2) ?>%</p>
            <p><strong>Formule :</strong> R08 = (Fonds propres nets) / (Total actif)</p>
            <p><strong>Norme BCEAO :</strong> Le ratio de capitalisation doit être <strong>au moins égal à 15%</strong>.</p>
            <p><strong>Interprétation :</strong></p>
            <ul style="margin-left: 25px; margin-top: 10px;">
                <?php if($ratioR08 >= $normeMin): ?>
                    <li style="color:#2e7d32;">✓ Le ratio est <strong>CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>L'institution dispose d'une capitalisation suffisante : <?= number_format($pourcentageCapitalisation, 2) ?>% des actifs sont financés par des fonds propres.</li>
                    <li>Cette situation offre une bonne capacité d'absorption des pertes.</li>
                <?php else: ?>
                    <li style="color:#c62828;">✗ Le ratio est <strong>NON CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>L'institution dispose d'une capitalisation insuffisante : seulement <?= number_format($pourcentageCapitalisation, 2) ?>% des actifs sont financés par des fonds propres.</li>
                    <li>Il est recommandé de :</li>
                    <ul style="margin-left: 25px;">
                        <li>Augmenter le capital social</li>
                        <li>Constituer davantage de réserves</li>
                        <li>Réduire le niveau des actifs (notamment les créances en souffrance)</li>
                        <li>Limiter la distribution de dividendes</li>
                    </ul>
                <?php endif; ?>
            </ul>
            <?php if($totalActif > 0 && $fondsPropres > 0): ?>
            <p style="margin-top: 15px; font-size: 0.9rem; color: #666; border-top: 1px solid #eee; padding-top: 10px;">
                <strong>Analyse complémentaire :</strong><br>
                - Fonds propres : <?= number_format($fondsPropres, 0, ',', ' ') ?> FCFA<br>
                - Actif total : <?= number_format($totalActif, 0, ',', ' ') ?> FCFA<br>
                - Insuffisance de capitalisation : <?= number_format(($normeMin * $totalActif) - $fondsPropres, 0, ',', ' ') ?> FCFA
            </p>
            <?php endif; ?>
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
        window.location.href = 'R08.php?exercice=' + exercice + '&mois=' + mois + '&provisions_non_const=' + provisions;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>