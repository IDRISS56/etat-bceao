<?php
// IF11.php - Indicateurs financiers (Qualité du portefeuille et activités)
// Partie 1 du canevas DSFD

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
$date_debut_exercice = $exercice . '-01-01';
$date_fin_exercice = $exercice . '-12-31';

// Période précédente pour les comparaisons
$exercice_prec = $exercice - 1;
$date_fin_prec = $exercice_prec . '-12-31';

// ============================================================
// I-1- INDICATEUR DE QUALITÉ DU PORTEFEUILLE
// ============================================================

// 1. Portefeuille à risque (PAR) - diffèrents seuils
// Calcul des encours des prêts avec échéances impayées

// Récupérer tous les dossiers avec leurs échéances
$dossiers = [];
try {
    $stmtDossiers = $pdo->prepare("
        SELECT 
            d.dossier_id,
            d.montant as montant_initial,
            d.date_octroi,
            d.statut as dossier_statut,
            COALESCE(d.montant - SUM(CASE WHEN e.statut = 'payee' THEN e.montant ELSE 0 END), d.montant) as encours_actuel,
            MAX(CASE WHEN e.statut = 'attente' AND e.date_echeance < :date_fin THEN e.date_echeance ELSE NULL END) as date_dernier_impaye,
            SUM(CASE WHEN e.statut = 'attente' AND e.date_echeance < :date_fin THEN e.montant ELSE 0 END) as montant_impaye
        FROM dossiers d
        LEFT JOIN echeances e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve', 'impaye')
          AND d.date_octroi <= :date_fin
        GROUP BY d.dossier_id
        HAVING encours_actuel > 0
    ");
    $stmtDossiers->execute([':date_fin' => $date_fin_periode]);
    $dossiers = $stmtDossiers->fetchAll();
} catch (PDOException $e) {
    $dossiers = [];
}

// Calcul des indicateurs PAR
$encours_par_30 = 0;  // Prêts avec impayés >= 30 jours
$encours_par_90 = 0;  // Prêts avec impayés >= 90 jours
$encours_par_180 = 0; // Prêts avec impayés >= 180 jours
$encours_total_brut = 0;
$nb_credits_30 = 0;
$nb_credits_90 = 0;
$nb_credits_180 = 0;

$date_reference = new DateTime($date_fin_periode);

foreach ($dossiers as $dossier) {
    $encours_total_brut += $dossier['encours_actuel'];
    
    if ($dossier['date_dernier_impaye']) {
        $date_impaye = new DateTime($dossier['date_dernier_impaye']);
        $interval = $date_reference->diff($date_impaye);
        $jours_retard = $interval->days;
        
        if ($jours_retard >= 30) {
            $encours_par_30 += $dossier['encours_actuel'];
            $nb_credits_30++;
        }
        if ($jours_retard >= 90) {
            $encours_par_90 += $dossier['encours_actuel'];
            $nb_credits_90++;
        }
        if ($jours_retard >= 180) {
            $encours_par_180 += $dossier['encours_actuel'];
            $nb_credits_180++;
        }
    }
}

// Taux PAR
$par_30 = ($encours_total_brut > 0) ? $encours_par_30 / $encours_total_brut : 0;
$par_90 = ($encours_total_brut > 0) ? $encours_par_90 / $encours_total_brut : 0;
$par_180 = ($encours_total_brut > 0) ? $encours_par_180 / $encours_total_brut : 0;

// 2. Taux de provisions pour créances en souffrance
$provisions_constituées = 0;
$encours_souffrance = $encours_par_90; // Créances en souffrance = PAR90

try {
    $stmtProvisions = $pdo->prepare("
        SELECT COALESCE(SUM(p.montant), 0) as total_provisions
        FROM provisions p
        WHERE p.statut = 'actif'
          AND p.date_provision <= :date_fin
    ");
    $stmtProvisions->execute([':date_fin' => $date_fin_periode]);
    $resultProvisions = $stmtProvisions->fetch();
    $provisions_constituées = $resultProvisions['total_provisions'];
} catch (PDOException $e) {
    $provisions_constituées = 0;
}

$taux_provision = ($encours_souffrance > 0) ? $provisions_constituées / $encours_souffrance : 0;

// 3. Taux de perte sur créances (dans l'année)
$pertes_creances = 0;
$encours_moyen_portefeuille = $encours_total_brut;

try {
    // Pertes sur créances passées en perte
    $stmtPertes = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total_pertes
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '657%'  -- Comptes de pertes sur créances
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmtPertes->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_periode
    ]);
    $resultPertes = $stmtPertes->fetch();
    $pertes_creances = $resultPertes['total_pertes'];
} catch (PDOException $e) {
    $pertes_creances = 0;
}

$taux_perte = ($encours_moyen_portefeuille > 0) ? $pertes_creances / $encours_moyen_portefeuille : 0;

// ============================================================
// I-2- INDICATEURS D'ACTIVITÉS
// ============================================================

// 1. Montant moyen des crédits décaissés
$total_decaissements = 0;
$nb_decaissements = 0;
$montant_moyen_credit = 0;

try {
    $stmtDecaissements = $pdo->prepare("
        SELECT 
            COUNT(*) as nb_decaissements,
            COALESCE(SUM(montant), 0) as total_decaissements
        FROM dossiers
        WHERE date_octroi BETWEEN :date_debut AND :date_fin
          AND statut IN ('actif', 'approuve')
    ");
    $stmtDecaissements->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_periode
    ]);
    $resultDecaissements = $stmtDecaissements->fetch();
    $total_decaissements = $resultDecaissements['total_decaissements'];
    $nb_decaissements = $resultDecaissements['nb_decaissements'];
    $montant_moyen_credit = ($nb_decaissements > 0) ? $total_decaissements / $nb_decaissements : 0;
} catch (PDOException $e) {
    $total_decaissements = 0;
    $nb_decaissements = 0;
    $montant_moyen_credit = 0;
}

// 2. Montant moyen de l'épargne par épargnant
$total_epargne = 0;
$nb_epargnants = 0;
$montant_moyen_epargne = 0;

try {
    // Total des dépôts (épargne)
    $stmtEpargne = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde), 0) as total_epargne
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne'
          AND c.statut = 'actif'
          AND c.solde > 0
    ");
    $stmtEpargne->execute();
    $resultEpargne = $stmtEpargne->fetch();
    $total_epargne = $resultEpargne['total_epargne'];
    
    // Nombre d'épargnants (clients distincts avec un compte épargne)
    $stmtNbEpargnants = $pdo->prepare("
        SELECT COUNT(DISTINCT c.client_id) as nb_epargnants
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne'
          AND c.statut = 'actif'
          AND c.solde > 0
    ");
    $stmtNbEpargnants->execute();
    $resultNbEpargnants = $stmtNbEpargnants->fetch();
    $nb_epargnants = $resultNbEpargnants['nb_epargnants'];
    
    $montant_moyen_epargne = ($nb_epargnants > 0) ? $total_epargne / $nb_epargnants : 0;
} catch (PDOException $e) {
    $total_epargne = 0;
    $nb_epargnants = 0;
    $montant_moyen_epargne = 0;
}

// 3. Encours moyen des crédits par emprunteur
$nb_emprunteurs_actifs = 0;
$encours_moyen_emprunteur = 0;

try {
    // Nombre d'emprunteurs actifs
    $stmtNbEmprunteurs = $pdo->prepare("
        SELECT COUNT(DISTINCT c.client_id) as nb_emprunteurs
        FROM dossiers d
        INNER JOIN comptes cpt ON d.compte_id = cpt.compte_id
        INNER JOIN clients c ON cpt.client_id = c.client_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.date_octroi <= :date_fin
    ");
    $stmtNbEmprunteurs->execute([':date_fin' => $date_fin_periode]);
    $resultNbEmprunteurs = $stmtNbEmprunteurs->fetch();
    $nb_emprunteurs_actifs = $resultNbEmprunteurs['nb_emprunteurs'];
    
    $encours_moyen_emprunteur = ($nb_emprunteurs_actifs > 0) ? $encours_total_brut / $nb_emprunteurs_actifs : 0;
} catch (PDOException $e) {
    $nb_emprunteurs_actifs = 0;
    $encours_moyen_emprunteur = 0;
}

// Données pour l'évolution (année précédente)
$encours_prec = 0;
$epargne_prec = 0;
$nb_emprunteurs_prec = 0;
$nb_epargnants_prec = 0;

try {
    // Encours année précédente
    $stmtEncoursPrec = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as encours
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee' AND date_echeance <= :date_fin
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.date_octroi <= :date_fin
    ");
    $stmtEncoursPrec->execute([':date_fin' => $date_fin_prec]);
    $resultEncoursPrec = $stmtEncoursPrec->fetch();
    $encours_prec = $resultEncoursPrec['encours'];
} catch (PDOException $e) {
    $encours_prec = 0;
}
$montant_moyen_credit_prec = 0;
$montant_moyen_epargne_prec = 0;
$encours_moyen_emprunteur_prec = 0;

// Calcul des tendances
$evolution_credit = ($montant_moyen_credit_prec > 0) ? (($montant_moyen_credit - $montant_moyen_credit_prec) / $montant_moyen_credit_prec) * 100 : 0;
$evolution_epargne = ($montant_moyen_epargne_prec > 0) ? (($montant_moyen_epargne - $montant_moyen_epargne_prec) / $montant_moyen_epargne_prec) * 100 : 0;
$evolution_encours = ($encours_moyen_emprunteur_prec > 0) ? (($encours_moyen_emprunteur - $encours_moyen_emprunteur_prec) / $encours_moyen_emprunteur_prec) * 100 : 0;

// Normes
$norme_par_30 = 0.05;   // 5%
$norme_par_90 = 0.03;   // 3%
$norme_par_180 = 0.02;  // 2%
$norme_taux_provision = 0.40; // 40%
$norme_taux_perte = 0.02; // 2%

$conformite_par_30 = ($par_30 <= $norme_par_30) ? 'CONFORME' : 'NON_CONFORME';
$conformite_par_90 = ($par_90 <= $norme_par_90) ? 'CONFORME' : 'NON_CONFORME';
$conformite_par_180 = ($par_180 <= $norme_par_180) ? 'CONFORME' : 'NON_CONFORME';
$conformite_provision = ($taux_provision >= $norme_taux_provision) ? 'CONFORME' : 'NON_CONFORME';
$conformite_perte = ($taux_perte <= $norme_taux_perte) ? 'CONFORME' : 'NON_CONFORME';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IF 11 - Indicateurs financiers (Qualité du portefeuille et activités)</title>
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
        
        .indicator-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid #1a3a5c;
        }
        
        .indicator-title {
            font-size: 1rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 15px;
        }
        
        .indicator-value {
            font-size: 2rem;
            font-weight: bold;
        }
        
        .indicator-norme {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
        }
        
        .conforme {
            color: #2e7d32;
        }
        
        .non-conforme {
            color: #c62828;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .status-conforme {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-non-conforme {
            background: #ffebee;
            color: #c62828;
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
        
        .trend-up {
            color: #2e7d32;
        }
        
        .trend-down {
            color: #c62828;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            padding: 20px;
        }
        
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            padding: 20px;
        }
        
        @media (max-width: 768px) {
            .grid-2, .grid-3 {
                grid-template-columns: 1fr;
            }
            
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>IF 11 - Indicateurs financiers</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Partie 1 : Qualité du portefeuille et indicateurs d'activités</div>
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
    
    <!-- I-1 - Qualité du portefeuille -->
    <div class="section-card">
        <div class="section-title">📊 I-1 - Indicateur de qualité du portefeuille</div>
        <div class="grid-3">
            <div class="indicator-card">
                <div class="indicator-title">Portefeuille à risque (PAR30)</div>
                <div class="indicator-value <?= $conformite_par_30 == 'CONFORME' ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($par_30 * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≤ 5%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $conformite_par_30 == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $conformite_par_30 ?>
                    </span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Encours concerné : <?= number_format($encours_par_30, 0, ',', ' ') ?> FCFA<br>
                    Nombre de crédits : <?= $nb_credits_30 ?>
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Portefeuille à risque (PAR90)</div>
                <div class="indicator-value <?= $conformite_par_90 == 'CONFORME' ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($par_90 * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≤ 3%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $conformite_par_90 == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $conformite_par_90 ?>
                    </span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Encours concerné : <?= number_format($encours_par_90, 0, ',', ' ') ?> FCFA<br>
                    Nombre de crédits : <?= $nb_credits_90 ?>
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Portefeuille à risque (PAR180)</div>
                <div class="indicator-value <?= $conformite_par_180 == 'CONFORME' ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($par_180 * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≤ 2%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $conformite_par_180 == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $conformite_par_180 ?>
                    </span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Encours concerné : <?= number_format($encours_par_180, 0, ',', ' ') ?> FCFA<br>
                    Nombre de crédits : <?= $nb_credits_180 ?>
                </div>
            </div>
        </div>
        
        <div class="grid-2">
            <div class="indicator-card">
                <div class="indicator-title">Taux de provisions pour créances en souffrance</div>
                <div class="indicator-value <?= $conformite_provision == 'CONFORME' ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($taux_provision * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≥ 40%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $conformite_provision == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $conformite_provision ?>
                    </span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Provisions constituées : <?= number_format($provisions_constituées, 0, ',', ' ') ?> FCFA<br>
                    Encours en souffrance : <?= number_format($encours_souffrance, 0, ',', ' ') ?> FCFA
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Taux de perte sur créances</div>
                <div class="indicator-value <?= $conformite_perte == 'CONFORME' ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($taux_perte * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≤ 2%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $conformite_perte == 'CONFORME' ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $conformite_perte ?>
                    </span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Pertes sur créances : <?= number_format($pertes_creances, 0, ',', ' ') ?> FCFA<br>
                    Portefeuille brut moyen : <?= number_format($encours_moyen_portefeuille, 0, ',', ' ') ?> FCFA
                </div>
            </div>
        </div>
    </div>
    
    <!-- I-2 - Indicateurs d'activités -->
    <div class="section-card">
        <div class="section-title">📈 I-2 - Indicateurs d'activités</div>
        <div class="grid-3">
            <div class="indicator-card">
                <div class="indicator-title">Montant moyen des crédits décaissés</div>
                <div class="indicator-value">
                    <?= number_format($montant_moyen_credit, 0, ',', ' ') ?> FCFA
                </div>
                <div class="indicator-norme">Norme : Tendance haussière</div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Total décaissé : <?= number_format($total_decaissements, 0, ',', ' ') ?> FCFA<br>
                    Nombre de crédits : <?= $nb_decaissements ?>
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Montant moyen de l'épargne par épargnant</div>
                <div class="indicator-value">
                    <?= number_format($montant_moyen_epargne, 0, ',', ' ') ?> FCFA
                </div>
                <div class="indicator-norme">Norme : Tendance haussière</div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Total épargne : <?= number_format($total_epargne, 0, ',', ' ') ?> FCFA<br>
                    Nombre d'épargnants : <?= $nb_epargnants ?>
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Encours moyen des crédits par emprunteur</div>
                <div class="indicator-value">
                    <?= number_format($encours_moyen_emprunteur, 0, ',', ' ') ?> FCFA
                </div>
                <div class="indicator-norme">Norme : Tendance haussière</div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Encours total : <?= number_format($encours_total_brut, 0, ',', ' ') ?> FCFA<br>
                    Emprunteurs actifs : <?= $nb_emprunteurs_actifs ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Récapitulatif -->
    <div class="section-card">
        <div class="section-title">📋 Récapitulatif des données de base</div>
        <div style="padding: 20px;">
            <table>
                <thead>
                    <tr>
                        <th>Indicateur</th>
                        <th class="text-right">Valeur</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Encours brut total du portefeuille de prêts</td>
                        <td class="text-right"><?= number_format($encours_total_brut, 0, ',', ' ') ?> FCFA</td>
                    </tr>
                    <tr>
                        <td>Encours des prêts à risque (PAR90)</td>
                        <td class="text-right"><?= number_format($encours_par_90, 0, ',', ' ') ?> FCFA</td>
                    </tr>
                    <tr>
                        <td>Provisions constituées</td>
                        <td class="text-right"><?= number_format($provisions_constituées, 0, ',', ' ') ?> FCFA</td>
                    </tr>
                    <tr>
                        <td>Pertes sur créances (exercice)</td>
                        <td class="text-right"><?= number_format($pertes_creances, 0, ',', ' ') ?> FCFA</td>
                    </tr>
                    <tr>
                        <td>Total des décaissements (exercice)</td>
                        <td class="text-right"><?= number_format($total_decaissements, 0, ',', ' ') ?> FCFA</td>
                    </tr>
                    <tr>
                        <td>Total de l'épargne collectée</td>
                        <td class="text-right"><?= number_format($total_epargne, 0, ',', ' ') ?> FCFA</td>
                    </tr>
                </tbody>
            </table>
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
        window.location.href = 'IF11.php?exercice=' + exercice + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>