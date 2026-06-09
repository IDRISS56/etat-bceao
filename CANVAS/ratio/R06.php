<?php
// R06.php - Limitation des opérations autres que les activités d'épargne et de crédit
// Norme BCEAO: 0% à 5% (0 - 0.05)

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
$date_debut_periode = $exercice . '-01-01';
$date_fin_annee = $exercice . '-12-31';

// ============================================================
// A - MONTANT CONSACRÉ AUX ACTIVITÉS AUTRES QUE L'ÉPARGNE ET LE CRÉDIT (Z55)
// ============================================================
// Ce montant représente les investissements dans des activités non financières
// Exemples : commerce, immobilier, services non financiers, etc.

$montantA = 0;
$detailsActivitesNonFinancieres = [];

// 1. Immobilisations hors exploitation (biens immobiliers non utilisés pour l'activité)
$immobilisationsHorsExploit = 0;
try {
    $stmtImmob = $pdo->prepare("
        SELECT COALESCE(SUM(i.montant_achat - i.amortissement_total), 0) as valeur_nette
        FROM immobilisations i
        WHERE i.type_immobilisation = 'Immobilisations corporelles'
          AND i.statut = 'actif'
          AND i.date_achat <= :date_fin
          AND (i.libelle LIKE '%immobilier%' 
               OR i.libelle LIKE '%terrain%' 
               OR i.libelle LIKE '%bâtiment%'
               OR i.libelle LIKE '%immeuble%')
    ");
    $stmtImmob->execute([':date_fin' => $date_fin_periode]);
    $resultImmob = $stmtImmob->fetch();
    $immobilisationsHorsExploit = $resultImmob['valeur_nette'];
    $montantA += $immobilisationsHorsExploit;
} catch (PDOException $e) {
    $immobilisationsHorsExploit = 0;
}

// 2. Titres de participation dans d'autres sociétés (hors SFD)
$titresParticipation = 0;
try {
    $stmtTitres = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as montant
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '26%'  -- Comptes de titres de participation
          AND e.date_ecriture <= :date_fin
    ");
    $stmtTitres->execute([':date_fin' => $date_fin_periode]);
    $resultTitres = $stmtTitres->fetch();
    $titresParticipation = $resultTitres['montant'];
    $montantA += $titresParticipation;
} catch (PDOException $e) {
    $titresParticipation = 0;
}

// 3. Autres opérations commerciales (achats/reventes)
$operationsCommerciales = 0;
try {
    $stmtCommercial = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit), 0) as montant
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '60%'  -- Achats
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmtCommercial->execute([
        ':date_debut' => $date_debut_periode,
        ':date_fin' => $date_fin_periode
    ]);
    $resultCommercial = $stmtCommercial->fetch();
    $operationsCommerciales = $resultCommercial['montant'];
    $montantA += $operationsCommerciales;
} catch (PDOException $e) {
    $operationsCommerciales = 0;
}

// 4. Autres activités non financières (à saisir manuellement si nécessaire)
// Variable pour permettre la saisie manuelle via formulaire
$autresActivites = isset($_GET['autres_activites']) ? (float)$_GET['autres_activites'] : 0;
$montantA += $autresActivites;

// Récupération du Z55 saisi manuellement (peut être stocké dans une table param)
$z55Manuel = 0;
try {
    $stmtZ55 = $pdo->prepare("
        SELECT valeur FROM parametres WHERE code = 'Z55' AND exercice = :exercice
    ");
    $stmtZ55->execute([':exercice' => $exercice]);
    $resultZ55 = $stmtZ55->fetch();
    if ($resultZ55) {
        $z55Manuel = $resultZ55['valeur'];
        $montantA = $z55Manuel;
    }
} catch (PDOException $e) {
    $z55Manuel = 0;
}

// ============================================================
// B - RISQUES PORTÉS PAR L'INSTITUTION (activités principales)
// ============================================================

// B1 - Comptes ordinaires débiteurs (A12)
$comptesDebiteurs = 0;
try {
    $stmtDebiteurs = $pdo->prepare("
        SELECT COALESCE(SUM(solde), 0) as total
        FROM comptes
        WHERE solde > 0 AND statut = 'actif'
    ");
    $stmtDebiteurs->execute();
    $resultDebiteurs = $stmtDebiteurs->fetch();
    $comptesDebiteurs = $resultDebiteurs['total'];
} catch (PDOException $e) {
    $comptesDebiteurs = 0;
}

// B2 - Comptes de prêts (A3A)
$comptesPrets = 0;
try {
    $stmtPrets = $pdo->prepare("
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
    $stmtPrets->execute();
    $resultPrets = $stmtPrets->fetch();
    $comptesPrets = $resultPrets['total'];
} catch (PDOException $e) {
    $comptesPrets = 0;
}

// B3 - Prêts en souffrance (A70)
$pretsSouffrance = 0;
try {
    $stmtSouffrance = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut = 'impaye'
    ");
    $stmtSouffrance->execute();
    $resultSouffrance = $stmtSouffrance->fetch();
    $pretsSouffrance = $resultSouffrance['total'];
} catch (PDOException $e) {
    $pretsSouffrance = 0;
}

// B4 - Crédits à court terme (B2D)
$creditsCourtTerme = 0;
try {
    $stmtCreditsCT = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.duree <= 12
    ");
    $stmtCreditsCT->execute();
    $resultCreditsCT = $stmtCreditsCT->fetch();
    $creditsCourtTerme = $resultCreditsCT['total'];
} catch (PDOException $e) {
    $creditsCourtTerme = 0;
}

// B5 - Crédits à moyen terme (B30)
$creditsMoyenTerme = 0;
try {
    $stmtCreditsMT = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.duree > 12 AND d.duree <= 60
    ");
    $stmtCreditsMT->execute();
    $resultCreditsMT = $stmtCreditsMT->fetch();
    $creditsMoyenTerme = $resultCreditsMT['total'];
} catch (PDOException $e) {
    $creditsMoyenTerme = 0;
}

// B6 - Crédits à long terme (B40)
$creditsLongTerme = 0;
try {
    $stmtCreditsLT = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.duree > 60
    ");
    $stmtCreditsLT->execute();
    $resultCreditsLT = $stmtCreditsLT->fetch();
    $creditsLongTerme = $resultCreditsLT['total'];
} catch (PDOException $e) {
    $creditsLongTerme = 0;
}

// B7 - Crédits en souffrance (B70)
$creditsSouffrance = $pretsSouffrance;

// B8 - Titres de placement (C10)
$titresPlacement = 0;
try {
    $stmtTitresPlacement = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '50%'  -- Titres de placement
          AND e.date_ecriture <= :date_fin
    ");
    $stmtTitresPlacement->execute([':date_fin' => $date_fin_periode]);
    $resultTitresPlacement = $stmtTitresPlacement->fetch();
    $titresPlacement = $resultTitresPlacement['total'];
} catch (PDOException $e) {
    $titresPlacement = 0;
}

// B9 - Titres de participation (D1E)
$titresParticipationActif = 0;
try {
    $stmtTitresPart = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '26%'  -- Titres de participation
          AND e.date_ecriture <= :date_fin
    ");
    $stmtTitresPart->execute([':date_fin' => $date_fin_periode]);
    $resultTitresPart = $stmtTitresPart->fetch();
    $titresParticipationActif = $resultTitresPart['total'];
} catch (PDOException $e) {
    $titresParticipationActif = 0;
}

// B10 - Titres d'investissement (D1L)
$titresInvestissement = 0;
try {
    $stmtTitresInv = $pdo->prepare("
        SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '27%'  -- Titres d'investissement
          AND e.date_ecriture <= :date_fin
    ");
    $stmtTitresInv->execute([':date_fin' => $date_fin_periode]);
    $resultTitresInv = $stmtTitresInv->fetch();
    $titresInvestissement = $resultTitresInv['total'];
} catch (PDOException $e) {
    $titresInvestissement = 0;
}

// B11 - Engagements par signature donnés (N1A, N1J, N2A, N2J)
$engagementsSignature = 0;
try {
    $stmtEngagements = $pdo->prepare("
        SELECT COALESCE(SUM(g.valeur_nette), 0) as total
        FROM garanties g
        WHERE g.statut = 'actif'
    ");
    $stmtEngagements->execute();
    $resultEngagements = $stmtEngagements->fetch();
    $engagementsSignature = $resultEngagements['total'];
} catch (PDOException $e) {
    $engagementsSignature = 0;
}

// Éléments à déduire - Dépôts de garantie (F2C, G30)
$depotsGarantie = 0;
try {
    $stmtGarantie = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant_initial), 0) as total
        FROM garanties g
        INNER JOIN dossiers d ON g.credit_id = d.dossier_id
        WHERE g.code_type_garantie = '02'  -- Nantissements
    ");
    $stmtGarantie->execute();
    $resultGarantie = $stmtGarantie->fetch();
    $depotsGarantie = $resultGarantie['total'];
} catch (PDOException $e) {
    $depotsGarantie = 0;
}

// TOTAL B - Risques portés par l'institution (nets des déductions)
$totalB_brut = $comptesDebiteurs + $comptesPrets + $pretsSouffrance + $creditsCourtTerme 
             + $creditsMoyenTerme + $creditsLongTerme + $creditsSouffrance + $titresPlacement 
             + $titresParticipationActif + $titresInvestissement + $engagementsSignature;

$totalB = $totalB_brut - $depotsGarantie;

// ============================================================
// CALCUL DU RATIO R06
// ============================================================

if ($totalB <= 0) {
    $totalB = 1;
    $ratioR06 = 0;
} else {
    $ratioR06 = $montantA / $totalB;
}

// Normes : 0% à 5%
$normeMin = 0;
$normeMax = 0.05;
$conformite = ($ratioR06 >= $normeMin && $ratioR06 <= $normeMax) ? 'CONFORME' : 'NON_CONFORME';

// Récupération du total de l'actif
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
} catch (PDOException $e) {
    $totalActif = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>R06 - Limitation des opérations autres que l'épargne et le crédit</title>
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
        
        .manual-input {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
        }
        
        .manual-input label {
            font-weight: 600;
            margin-right: 10px;
        }
        
        .manual-input input {
            padding: 5px 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            width: 200px;
        }
        
        .two-columns {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .two-columns > div {
            flex: 1;
            min-width: 250px;
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
        <h1>R06 - Limitation des opérations autres que l'épargne et le crédit</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Norme BCEAO : 0% à 5%</div>
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
    
    <!-- Zone de saisie manuelle pour Z55 -->
    <div class="manual-input">
        <form method="get" action="">
            <label>📝 Z55 - Montant consacré aux activités autres que l'épargne et le crédit :</label>
            <input type="number" name="autres_activites" id="autres_activites" 
                   value="<?= $autresActivites ?>" placeholder="Montant en FCFA">
            <input type="hidden" name="exercice" value="<?= $exercice ?>">
            <input type="hidden" name="mois" value="<?= $mois ?>">
            <button type="submit" class="btn btn-primary" style="margin-left: 10px;">Mettre à jour</button>
        </form>
        <div style="font-size: 0.8rem; color: #666; margin-top: 10px;">
            ⓘ Saisir le montant total des investissements dans les activités non financières (commerce, immobilier, etc.)
        </div>
    </div>
    
    <div class="ratio-card">
        <div class="ratio-title">📊 Ratio R06 - Opérations hors épargne et crédit</div>
        <div class="ratio-value-container">
            <div class="ratio-value">
                <div class="value <?= ($ratioR06 >= $normeMin && $ratioR06 <= $normeMax) ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($ratioR06 * 100, 2) ?>%
                </div>
                <div class="label">Valeur du ratio</div>
            </div>
            <div class="norme">
                <div class="title">Norme réglementaire</div>
                <div class="range">0% ≤ Ratio ≤ 5%</div>
                <div class="label">Conformité requise</div>
            </div>
            <div>
                <span class="status-badge <?= $conformite == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                    <?= $conformite ?>
                </span>
            </div>
        </div>
    </div>
    
    <div class="two-columns">
        <div class="data-table">
            <h3>📌 A - ACTIVITÉS AUTRES QUE L'ÉPARGNE ET LE CRÉDIT (Z55)</h3>
            <table>
                <thead>
                    <tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>
                </thead>
                <tbody>
                    <tr><td>-</td><td>Immobilisations hors exploitation</td><td class="text-right"><?= number_format($immobilisationsHorsExploit, 0, ',', ' ') ?></td></tr>
                    <tr><td>-</td><td>Titres de participation</td><td class="text-right"><?= number_format($titresParticipation, 0, ',', ' ') ?></td></tr>
                    <tr><td>-</td><td>Opérations commerciales</td><td class="text-right"><?= number_format($operationsCommerciales, 0, ',', ' ') ?></td></tr>
                    <tr><td>-</td><td>Autres activités (saisie manuelle)</td><td class="text-right"><?= number_format($autresActivites, 0, ',', ' ') ?></td></tr>
                    <tr style="background:#f0f7ff; font-weight:bold;">
                        <td colspan="2">TOTAL A</td>
                        <td class="text-right"><?= number_format($montantA, 0, ',', ' ') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="data-table">
            <h3>🏦 B - RISQUES PORTÉS PAR L'INSTITUTION</h3>
            <table>
                <thead>
                    <tr><th>Code</th><th>Libellé</th><th class="text-right">Montant (FCFA)</th></tr>
                </thead>
                <tbody>
                    <tr><td>A12</td><td>Comptes ordinaires débiteurs</td><td class="text-right"><?= number_format($comptesDebiteurs, 0, ',', ' ') ?></td></tr>
                    <tr><td>A3A</td><td>Comptes de prêts</td><td class="text-right"><?= number_format($comptesPrets, 0, ',', ' ') ?></td></tr>
                    <tr><td>A70</td><td>Prêts en souffrance</td><td class="text-right"><?= number_format($pretsSouffrance, 0, ',', ' ') ?></td></tr>
                    <tr><td>B2D</td><td>Crédits à court terme</td><td class="text-right"><?= number_format($creditsCourtTerme, 0, ',', ' ') ?></td></tr>
                    <tr><td>B30</td><td>Crédits à moyen terme</td><td class="text-right"><?= number_format($creditsMoyenTerme, 0, ',', ' ') ?></td></tr>
                    <tr><td>B40</td><td>Crédits à long terme</td><td class="text-right"><?= number_format($creditsLongTerme, 0, ',', ' ') ?></td></tr>
                    <tr><td>B70</td><td>Crédits en souffrance</td><td class="text-right"><?= number_format($creditsSouffrance, 0, ',', ' ') ?></td></tr>
                    <tr><td>C10</td><td>Titres de placement</td><td class="text-right"><?= number_format($titresPlacement, 0, ',', ' ') ?></td></tr>
                    <tr><td>D1E</td><td>Titres de participation</td><td class="text-right"><?= number_format($titresParticipationActif, 0, ',', ' ') ?></td></tr>
                    <tr><td>D1L</td><td>Titres d'investissement</td><td class="text-right"><?= number_format($titresInvestissement, 0, ',', ' ') ?></td></tr>
                    <tr><td>N1A/N1J</td><td>Engagements par signature donnés</td><td class="text-right"><?= number_format($engagementsSignature, 0, ',', ' ') ?></td></tr>
                    <tr style="background:#f5f5f5;">
                        <td colspan="2">Éléments à déduire</td>
                        <td class="text-right">- <?= number_format($depotsGarantie, 0, ',', ' ') ?></td>
                    </tr>
                    <tr style="background:#f0f7ff; font-weight:bold;">
                        <td colspan="2">TOTAL B (net)</td>
                        <td class="text-right"><?= number_format($totalB, 0, ',', ' ') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="data-table">
        <h3>📊 Synthèse du calcul du ratio R06</h3>
        <table>
            <tbody>
                <tr>
                    <td style="width: 60%;"><strong>A - Montant consacré aux activités autres que l'épargne et le crédit</strong></td>
                    <td class="text-right"><strong><?= number_format($montantA, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr>
                    <td><strong>B - Risques portés par l'institution (nets)</strong></td>
                    <td class="text-right"><strong><?= number_format($totalB, 0, ',', ' ') ?> FCFA</strong></td>
                </tr>
                <tr style="background:#f0f7ff;">
                    <td><strong>RATIO R06 = A / B</strong></td>
                    <td class="text-right"><strong><?= number_format($ratioR06 * 100, 2) ?>%</strong></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="data-table">
        <h3>📖 Interprétation du ratio R06</h3>
        <div style="padding: 15px; line-height: 1.6;">
            <p><strong>Ratio calculé :</strong> <?= number_format($ratioR06 * 100, 2) ?>%</p>
            <p><strong>Formule :</strong> R06 = (Montant des activités hors épargne/crédit) / (Risques portés par l'institution)</p>
            <p><strong>Norme BCEAO :</strong> Le ratio doit être compris entre <strong>0% et 5%</strong>.</p>
            <p><strong>Interprétation :</strong></p>
            <ul style="margin-left: 25px; margin-top: 10px;">
                <?php if($ratioR06 >= $normeMin && $ratioR06 <= $normeMax): ?>
                    <li style="color:#2e7d32;">✓ Le ratio est <strong>CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>L'institution consacre <?= number_format($ratioR06 * 100, 2) ?>% de ses actifs aux activités autres que l'épargne et le crédit, soit dans la limite autorisée de 5%.</li>
                    <li>L'institution se concentre bien sur son cœur de métier (épargne et crédit).</li>
                <?php else: ?>
                    <li style="color:#c62828;">✗ Le ratio est <strong>NON CONFORME</strong> à la réglementation BCEAO.</li>
                    <li>L'institution consacre <?= number_format($ratioR06 * 100, 2) ?>% de ses actifs aux activités hors épargne/crédit, ce qui dépasse la limite de 5%.</li>
                    <li>Il est recommandé de :</li>
                    <ul style="margin-left: 25px;">
                        <li>Réduire les investissements dans les activités non financières</li>
                        <li>Céder les actifs non essentiels à l'activité principale</li>
                        <li>Se recentrer sur les activités d'épargne et de crédit</li>
                    </ul>
                <?php endif; ?>
            </ul>
            <?php if($totalActif > 0): ?>
            <p style="margin-top: 15px; font-size: 0.9rem; color: #666;">
                <strong>Note :</strong> Total de l'actif au <?= date('d/m/Y', strtotime($date_fin_periode)) ?> : <?= number_format($totalActif, 0, ',', ' ') ?> FCFA
            </p>
            <?php endif; ?>
            <p style="margin-top: 10px; font-size: 0.85rem; color: #666; border-top: 1px solid #eee; padding-top: 10px;">
                ⓘ <strong>Définition :</strong> Les opérations autres que les activités d'épargne et de crédit comprennent 
                principalement les activités commerciales, immobilières, de participation, et toutes autres activités non financières.
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
        window.location.href = 'R06.php?exercice=' + exercice + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>