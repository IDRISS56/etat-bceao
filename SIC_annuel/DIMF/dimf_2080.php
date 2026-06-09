<?php
// DIMF_2080.php - Compte de résultat (Charges et Produits)
// Déclaration SICS-BCEAO

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

// Récupération des paramètres
$exercice = isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y');
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4;
$mois = isset($_GET['mois']) ? (int)$_GET['mois'] : 12;
$date_fin_periode = $exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01';
$date_fin_periode = date('Y-m-t', strtotime($date_fin_periode));
$date_debut_exercice = $exercice . '-01-01';

// ============================================================
// CALCUL DES CHARGES
// ============================================================

// R08 - CHARGES SUR OPERATIONS AVEC LES INSTITUTIONS FINANCIERES
$R08_total = 0;

// R1A - Intérêts sur comptes ordinaires créditeurs
$R1A_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '661%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $R1A_total = $result['total'];
    $R08_total += $R1A_total;
} catch (PDOException $e) { $R1A_total = 0; }

// R2A - Intérêts sur compte d'emprunts
$R2A_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '662%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $R2A_total = $result['total'];
    $R08_total += $R2A_total;
} catch (PDOException $e) { $R2A_total = 0; }

// R2Z - Commissions
$R2Z_total = 0;

// R3A - CHARGES SUR OPERATIONS AVEC LES MEMBRES, BENEFICIAIRES OU CLIENTS
$R3A_total = 0;

// R3C - Intérêts sur comptes des membres
$R3C_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '663%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $R3C_total = $result['total'];
    $R3A_total += $R3C_total;
} catch (PDOException $e) { $R3C_total = 0; }

// R4B - CHARGES SUR OPERATIONS SUR TITRES
$R4B_total = 0;

// R5B - CHARGES SUR IMMOBILISATIONS FINANCIERES
$R5B_total = 0;

// R5E - CHARGES SUR CREDIT-BAIL
$R5E_total = 0;

// R5Y - CHARGES SUR EMPRUNT ET TITRES SUBORDONNES
$R5Y_total = 0;

// R6A - CHARGES SUR OPERATIONS DE CHANGE
$R6A_total = 0;

// R6F - CHARGES SUR OPERATIONS HORS BILAN
$R6F_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '668%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $R6F_total = $result['total'];
} catch (PDOException $e) { $R6F_total = 0; }

// R6V - CHARGES SUR PRESTATIONS DE SERVICES FINANCIERS
$R6V_total = 0;

// R7A - AUTRES CHARGES D'EXPLOITATION FINANCIERES
$R7A_total = 0;

// Z27 - ACHATS ET VARIATIONS DE STOCKS
$Z27_total = 0;

// S02 - FRAIS DE PERSONNEL
$S02_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '62%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $S02_total = $result['total'];
} catch (PDOException $e) { $S02_total = 0; }

// S03 - Salaires et traitements
$S03_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '621%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $S03_total = $result['total'];
} catch (PDOException $e) { $S03_total = 0; }

// S04 - Charges sociales
$S04_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '622%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $S04_total = $result['total'];
} catch (PDOException $e) { $S04_total = 0; }

// S1A - IMPOTS ET TAXES
$S1A_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '63%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $S1A_total = $result['total'];
} catch (PDOException $e) { $S1A_total = 0; }

// S2A - AUTRES CHARGES EXTERNES ET CHARGES DIVERSES D'EXPLOITATION
$S2A_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '64%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $S2A_total = $result['total'];
} catch (PDOException $e) { $S2A_total = 0; }

// T50 - DOTATIONS DU FONDS POUR RISQUES FINANCIERS GENERAUX
$T50_total = 0;

// T51 - DOTATIONS AUX AMORTISSEMENTS ET AUX PROVISIONS SUR IMMOBILISATIONS
$T51_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '681%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $T51_total = $result['total'];
} catch (PDOException $e) { $T51_total = 0; }

// T6B - DOTATIONS AUX PROVISIONS ET PERTES SUR CREANCES IRRECOUVRABLES
$T6B_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '687%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $T6B_total = $result['total'];
} catch (PDOException $e) { $T6B_total = 0; }

// T6K - Pertes sur créances irrécouvrables couvertes par des provisions
$T6K_total = 0;

// T6L - Pertes sur créances irrécouvrables non couvertes
$T6L_total = 0;

// T80 - CHARGES EXCEPTIONNELLES
$T80_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '67%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $T80_total = $result['total'];
} catch (PDOException $e) { $T80_total = 0; }

// T81 - PERTES SUR EXERCICES ANTERIEURS
$T81_total = 0;

// T82 - IMPOTS SUR LES EXCEDENTS
$T82_total = 0;

// TOTAL CHARGES
$total_charges = $R08_total + $R3A_total + $R4B_total + $R5B_total + $R5E_total + $R5Y_total
               + $R6A_total + $R6F_total + $R6V_total + $R7A_total + $Z27_total + $S02_total
               + $S1A_total + $S2A_total + $T50_total + $T51_total + $T6B_total + $T80_total
               + $T81_total + $T82_total;

// ============================================================
// CALCUL DES PRODUITS
// ============================================================

// V08 - PRODUITS SUR OPERATIONS AVEC LES INSTITUTIONS FINANCIERES
$V08_total = 0;

// V1A - Intérêts sur comptes ordinaires débiteurs
$V1A_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '761%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $V1A_total = $result['total'];
    $V08_total += $V1A_total;
} catch (PDOException $e) { $V1A_total = 0; }

// V1L - Intérêts sur autres comptes de dépôts débiteurs
$V1L_total = 0;

// V3A - PRODUITS SUR OPERATIONS AVEC LES MEMBRES, BENEFICIAIRES OU CLIENTS
$V3A_total = 0;

// V3B - Intérêts sur crédits aux membres
$V3B_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '763%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $V3B_total = $result['total'];
    $V3A_total += $V3B_total;
} catch (PDOException $e) { $V3B_total = 0; }

// V3G - Intérêts sur crédits à court terme
$V3G_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '7631%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $V3G_total = $result['total'];
} catch (PDOException $e) { $V3G_total = 0; }

// V3M - Intérêts sur crédits à moyen terme
$V3M_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '7632%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $V3M_total = $result['total'];
} catch (PDOException $e) { $V3M_total = 0; }

// V3N - Intérêts sur crédits à long terme
$V3N_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '7633%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $V3N_total = $result['total'];
} catch (PDOException $e) { $V3N_total = 0; }

// V3R - Autres intérêts
$V3R_total = 0;

// V3X - Commissions
$V3X_total = 0;

// V4B - PRODUITS SUR OPERATIONS SUR TITRES
$V4B_total = 0;

// V4F - Commissions
$V4F_total = 0;

// V5B - PRODUITS SUR IMMOBILISATIONS FINANCIERES
$V5B_total = 0;

// V6A - PRODUITS SUR OPERATIONS DE CHANGE
$V6A_total = 0;

// V6F - PRODUITS SUR OPERATIONS HORS BILAN
$V6F_total = 0;

// V6S - Produits sur opérations pour compte de tiers
$V6S_total = 0;

// V6U - PRODUITS SUR PRESTATIONS DE SERVICES FINANCIERS
$V6U_total = 0;

// V7A - AUTRES PRODUITS D'EXPLOITATION FINANCIERE
$V7A_total = 0;

// V8A - VENTES ET VARIATION DE STOCK
$V8A_total = 0;

// W4A - PRODUITS DIVERS D'EXPLOITATION
$W4A_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '78%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $W4A_total = $result['total'];
} catch (PDOException $e) { $W4A_total = 0; }

// W50 - PRODUCTION IMMOBILISEE
$W50_total = 0;

// X50 - REPRISES DU FONDS POUR RISQUES BANCAIRES GENERAUX
$X50_total = 0;

// X51 - REPRISES D'AMORTISSEMENT ET PROVISIONS SUR IMMOBILISATIONS
$X51_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '781%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $X51_total = $result['total'];
} catch (PDOException $e) { $X51_total = 0; }

// X6B - REPRISES DE PROVISIONS ET RECUPERATION SUR CREANCES AMORTIES
$X6B_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '787%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $X6B_total = $result['total'];
} catch (PDOException $e) { $X6B_total = 0; }

// X80 - PRODUITS EXCEPTIONNELS
$X80_total = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '77%' AND e.date_ecriture BETWEEN :debut AND :fin
    ");
    $stmt->execute([':debut' => $date_debut_exercice, ':fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $X80_total = $result['total'];
} catch (PDOException $e) { $X80_total = 0; }

// X81 - PROFITS SUR EXERCICES ANTERIEURS
$X81_total = 0;

// TOTAL PRODUITS
$total_produits = $V08_total + $V3A_total + $V4B_total + $V5B_total + $V6A_total + $V6F_total
                + $V6U_total + $V7A_total + $V8A_total + $W4A_total + $W50_total + $X50_total
                + $X51_total + $X6B_total + $X80_total + $X81_total;

// Résultat net
$resultat_net = $total_produits - $total_charges;
$resultat_type = ($resultat_net >= 0) ? "EXCEDENT" : "DEFICIT";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2080 - Compte de résultat (Charges et Produits)</title>
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
            max-width: 1400px;
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
        
        .section-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .section-title {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #1a3a5c;
            font-size: 1.1rem;
            font-weight: bold;
            color: #1a3a5c;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
            font-size: 0.85rem;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-bold {
            font-weight: bold;
        }
        
        .total-row {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        .subtotal-row {
            background: #f0f7ff;
            font-weight: bold;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #777;
            font-size: 0.8rem;
        }
        
        .two-columns {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .two-columns > div {
            flex: 1;
            min-width: 400px;
        }
        
        .result-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin: 15px;
            border-radius: 8px;
            font-size: 1rem;
            text-align: center;
        }
        
        .excedent {
            color: #2e7d32;
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .deficit {
            color: #c62828;
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            .two-columns {
                flex-direction: column;
            }
            
            table {
                font-size: 0.75rem;
            }
            
            th, td {
                padding: 6px 8px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>DIMF_2080 - COMPTE DE RÉSULTAT</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - Compte de résultat annuel</div>
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
            <label>Trimestre</label>
            <select name="trimestre" id="trimestre">
                <option value="1" <?= $trimestre == 1 ? 'selected' : '' ?>>1er Trimestre</option>
                <option value="2" <?= $trimestre == 2 ? 'selected' : '' ?>>2ème Trimestre</option>
                <option value="3" <?= $trimestre == 3 ? 'selected' : '' ?>>3ème Trimestre</option>
                <option value="4" <?= $trimestre == 4 ? 'selected' : '' ?>>4ème Trimestre</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Mois</label>
            <select name="mois" id="mois">
                <?php for($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == $mois ? 'selected' : '' ?>>
                        <?= str_pad($m, 2, '0', STR_PAD_LEFT) ?> - <?= date('F', mktime(0,0,0,$m,1)) ?>
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
    
    <div class="two-columns">
        <!-- CHARGES -->
        <div class="section-card">
            <div class="section-title">📉 CHARGES</div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>CODE POSTE</th><th>LIBELLÉ</th><th class="text-right">Montant (FCFA)</th></tr>
                    </thead>
                    <tbody>
                        <tr class="subtotal-row"><td colspan="2">CHARGES SUR OPÉRATIONS FINANCIÈRES</td><td class="text-right"><?= number_format($R08_total + $R3A_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>R08</td><td>Charges sur opérations avec institutions financières</td><td class="text-right"><?= number_format($R08_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>R3A</td><td>Charges sur opérations avec membres</td><td class="text-right"><?= number_format($R3A_total, 0, ',', ' ') ?></td></tr>
                        <tr class="subtotal-row"><td colspan="2">CHARGES D'EXPLOITATION</td><td class="text-right"><?= number_format($S02_total + $S1A_total + $S2A_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>S02</td><td>Frais de personnel</td><td class="text-right"><?= number_format($S02_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>S1A</td><td>Impôts et taxes</td><td class="text-right"><?= number_format($S1A_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>S2A</td><td>Autres charges externes</td><td class="text-right"><?= number_format($S2A_total, 0, ',', ' ') ?></td></tr>
                        <tr class="subtotal-row"><td colspan="2">DOTATIONS ET PROVISIONS</td><td class="text-right"><?= number_format($T51_total + $T6B_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>T51</td><td>Dotations aux amortissements</td><td class="text-right"><?= number_format($T51_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>T6B</td><td>Dotations aux provisions sur créances</td><td class="text-right"><?= number_format($T6B_total, 0, ',', ' ') ?></td></tr>
                        <tr class="subtotal-row"><td colspan="2">CHARGES EXCEPTIONNELLES<\/td><td class="text-right"><?= number_format($T80_total, 0, ',', ' ') ?></td></tr>
                        <tr class="total-row"><td colspan="2"><strong>TOTAL CHARGES</strong></td><td class="text-right"><strong><?= number_format($total_charges, 0, ',', ' ') ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- PRODUITS -->
        <div class="section-card">
            <div class="section-title">📈 PRODUITS</div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>CODE POSTE</th><th>LIBELLÉ</th><th class="text-right">Montant (FCFA)</th></tr>
                    </thead>
                    <tbody>
                        <tr class="subtotal-row"><td colspan="2">PRODUITS SUR OPÉRATIONS FINANCIÈRES</td><td class="text-right"><?= number_format($V08_total + $V3A_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>V08</td><td>Produits sur opérations avec institutions financières</td><td class="text-right"><?= number_format($V08_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>V3A</td><td>Produits sur opérations avec membres (intérêts crédits)</td><td class="text-right"><?= number_format($V3A_total, 0, ',', ' ') ?></td></tr>
                        <tr class="subtotal-row"><td colspan="2">AUTRES PRODUITS</td><td class="text-right"><?= number_format($W4A_total + $X51_total + $X6B_total + $X80_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>W4A</td><td>Produits divers d'exploitation</td><td class="text-right"><?= number_format($W4A_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>X51</td><td>Reprises d'amortissements et provisions</td><td class="text-right"><?= number_format($X51_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>X6B</td><td>Reprises de provisions sur créances</td><td class="text-right"><?= number_format($X6B_total, 0, ',', ' ') ?></td></tr>
                        <tr><td>X80</td><td>Produits exceptionnels</td><td class="text-right"><?= number_format($X80_total, 0, ',', ' ') ?></td></tr>
                        <tr class="total-row"><td colspan="2"><strong>TOTAL PRODUITS</strong></td><td class="text-right"><strong><?= number_format($total_produits, 0, ',', ' ') ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Résultat de l'exercice -->
    <div class="section-card">
        <div class="section-title">📊 RÉSULTAT DE L'EXERCICE</div>
        <div class="result-box">
            <strong>Résultat = Total Produits - Total Charges</strong><br><br>
            <span class="<?= $resultat_type == 'EXCEDENT' ? 'excedent' : 'deficit' ?>">
                <?= number_format(abs($resultat_net), 0, ',', ' ') ?> FCFA
            </span><br>
            <span style="font-size: 0.9rem;">
                L'exercice <?= $exercice ?> se solde par un <strong><?= $resultat_type ?></strong> de 
                <?= number_format(abs($resultat_net), 0, ',', ' ') ?> FCFA
            </span>
        </div>
    </div>
    
    <div class="footer">
        Document généré le <?= date('d/m/Y à H:i:s') ?> - Données extraites de la base Mandigo<br>
        Période : <?= $exercice ?> - <?= $trimestre ?>ème trimestre (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
    </div>
</div>

<script>
    function appliquerFiltres() {
        let exercice = document.getElementById('exercice').value;
        let trimestre = document.getElementById('trimestre').value;
        let mois = document.getElementById('mois').value;
        window.location.href = 'DIMF_2080.php?exercice=' + exercice + '&trimestre=' + trimestre + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>