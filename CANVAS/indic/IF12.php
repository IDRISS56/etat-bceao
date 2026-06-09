<?php
// IF12.php - Indicateurs financiers (Efficacité, Rentabilité, Gestion du bilan)
// Partie 2 du canevas DSFD

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

// Période précédente
$exercice_prec = $exercice - 1;
$date_fin_prec = $exercice_prec . '-12-31';

// ============================================================
// I-3 - INDICATEURS D'EFFICACITÉ/PRODUCTIVITÉ
// ============================================================

// 1. Productivité des agents de crédits
$nb_emprunteurs_actifs = 0;
$nb_agents_credit = 0;
$productivite_agents = 0;

try {
    // Nombre d'emprunteurs actifs
    $stmtEmprunteurs = $pdo->prepare("
        SELECT COUNT(DISTINCT c.client_id) as nb_emprunteurs
        FROM dossiers d
        INNER JOIN comptes cpt ON d.compte_id = cpt.compte_id
        INNER JOIN clients c ON cpt.client_id = c.client_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.date_octroi <= :date_fin
    ");
    $stmtEmprunteurs->execute([':date_fin' => $date_fin_periode]);
    $resultEmprunteurs = $stmtEmprunteurs->fetch();
    $nb_emprunteurs_actifs = $resultEmprunteurs['nb_emprunteurs'];
    
    // Nombre d'agents de crédit (utilisateurs avec rôle 'Gestionnaire' ou 'Caisse')
    $stmtAgents = $pdo->prepare("
        SELECT COUNT(*) as nb_agents
        FROM utilisateurs
        WHERE role IN ('Gestionnaire', 'Caisse')
          AND etat = 'actif'
    ");
    $stmtAgents->execute();
    $resultAgents = $stmtAgents->fetch();
    $nb_agents_credit = $resultAgents['nb_agents'];
    
    $productivite_agents = ($nb_agents_credit > 0) ? $nb_emprunteurs_actifs / $nb_agents_credit : 0;
} catch (PDOException $e) {
    $nb_emprunteurs_actifs = 0;
    $nb_agents_credit = 0;
    $productivite_agents = 0;
}

// 2. Productivité du personnel
$nb_clients_actifs = 0;
$nb_employes = 0;
$productivite_personnel = 0;

try {
    // Nombre de clients actifs (avec un compte ou un dossier)
    $stmtClients = $pdo->prepare("
        SELECT COUNT(DISTINCT c.client_id) as nb_clients
        FROM clients c
        WHERE c.statut = 'actif'
    ");
    $stmtClients->execute();
    $resultClients = $stmtClients->fetch();
    $nb_clients_actifs = $resultClients['nb_clients'];
    
    // Nombre d'employés (tous utilisateurs sauf 'Client')
    $stmtEmployes = $pdo->prepare("
        SELECT COUNT(*) as nb_employes
        FROM utilisateurs
        WHERE role != 'Client'
          AND etat = 'actif'
    ");
    $stmtEmployes->execute();
    $resultEmployes = $stmtEmployes->fetch();
    $nb_employes = $resultEmployes['nb_employes'];
    
    $productivite_personnel = ($nb_employes > 0) ? $nb_clients_actifs / $nb_employes : 0;
} catch (PDOException $e) {
    $nb_clients_actifs = 0;
    $nb_employes = 0;
    $productivite_personnel = 0;
}

// 3. Charges d'exploitation rapportées au portefeuille de crédit
$charges_exploitation = 0;
$portefeuille_moyen = 0;
$ratio_charges_portefeuille = 0;

try {
    // Charges d'exploitation (comptes de classe 6)
    $stmtCharges = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total_charges
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '6'
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmtCharges->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_periode
    ]);
    $resultCharges = $stmtCharges->fetch();
    $charges_exploitation = $resultCharges['total_charges'];
    
    // Portefeuille de crédit moyen (N + N-1)/2
    $portefeuille_n = 0;
    $portefeuille_n1 = 0;
    
    // Portefeuille N
    $stmtPortefeuille = $pdo->prepare("
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
    $stmtPortefeuille->execute([':date_fin' => $date_fin_periode]);
    $resultPortefeuille = $stmtPortefeuille->fetch();
    $portefeuille_n = $resultPortefeuille['encours'];
    
    // Portefeuille N-1
    $stmtPortefeuillePrec = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as encours
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee' AND date_echeance <= :date_fin_prec
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve')
          AND d.date_octroi <= :date_fin_prec
    ");
    $stmtPortefeuillePrec->execute([':date_fin_prec' => $date_fin_prec]);
    $resultPortefeuillePrec = $stmtPortefeuillePrec->fetch();
    $portefeuille_n1 = $resultPortefeuillePrec['encours'];
    
    $portefeuille_moyen = ($portefeuille_n + $portefeuille_n1) / 2;
    $ratio_charges_portefeuille = ($portefeuille_moyen > 0) ? $charges_exploitation / $portefeuille_moyen : 0;
} catch (PDOException $e) {
    $charges_exploitation = 0;
    $portefeuille_moyen = 0;
    $ratio_charges_portefeuille = 0;
}

// 4. Ratios des frais généraux rapportés au portefeuille de crédits
$frais_generaux = 0;
$ratio_frais_generaux = 0;

try {
    // Frais généraux (frais de personnel, impôts, charges externes)
    $stmtFrais = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total_frais
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '62%'  -- Frais de personnel
           OR pc.numero_compte LIKE '63%'  -- Impôts et taxes
           OR pc.numero_compte LIKE '64%'  -- Autres charges externes
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmtFrais->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_periode
    ]);
    $resultFrais = $stmtFrais->fetch();
    $frais_generaux = $resultFrais['total_frais'];
    
    $ratio_frais_generaux = ($portefeuille_moyen > 0) ? $frais_generaux / $portefeuille_moyen : 0;
} catch (PDOException $e) {
    $frais_generaux = 0;
    $ratio_frais_generaux = 0;
}

// 5. Ratio des charges de personnel
$charges_personnel = 0;
$ratio_charges_personnel = 0;

try {
    $stmtPersonnel = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit), 0) as total_personnel
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '62%'  -- Frais de personnel
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmtPersonnel->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_periode
    ]);
    $resultPersonnel = $stmtPersonnel->fetch();
    $charges_personnel = $resultPersonnel['total_personnel'];
    
    $ratio_charges_personnel = ($portefeuille_moyen > 0) ? $charges_personnel / $portefeuille_moyen : 0;
} catch (PDOException $e) {
    $charges_personnel = 0;
    $ratio_charges_personnel = 0;
}

// ============================================================
// I-4 - INDICATEURS DE RENTABILITÉ
// ============================================================

// 1. Rentabilité des fonds propres (ROE)
$resultat_net = 0;
$fonds_propres_moyens = 0;
$roe = 0;

try {
    // Résultat net (produits - charges)
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
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_periode
    ]);
    $resultResultat = $stmtResultat->fetch();
    $resultat_net = $resultResultat['produits'] - $resultResultat['charges'];
    
    // Fonds propres (capital + réserves + report à nouveau)
    $stmtFP = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as fonds_propres
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '101%'  -- Capital
           OR pc.numero_compte LIKE '106%'  -- Réserves
           OR pc.numero_compte LIKE '11%'   -- Report à nouveau
          AND e.date_ecriture <= :date_fin
    ");
    $stmtFP->execute([':date_fin' => $date_fin_periode]);
    $resultFP = $stmtFP->fetch();
    $fonds_propres_moyens = $resultFP['fonds_propres'];
    
    $roe = ($fonds_propres_moyens > 0) ? $resultat_net / $fonds_propres_moyens : 0;
} catch (PDOException $e) {
    $resultat_net = 0;
    $fonds_propres_moyens = 0;
    $roe = 0;
}

// 2. Rendement sur actif (ROA)
$actif_total_moyen = 0;
$roa = 0;

try {
    // Actif total
    $stmtActif = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_debit - e.montant_credit), 0) as total_actif
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '2'
          AND e.date_ecriture <= :date_fin
    ");
    $stmtActif->execute([':date_fin' => $date_fin_periode]);
    $resultActif = $stmtActif->fetch();
    $actif_total_moyen = $resultActif['total_actif'];
    
    $roa = ($actif_total_moyen > 0) ? $resultat_net / $actif_total_moyen : 0;
} catch (PDOException $e) {
    $actif_total_moyen = 0;
    $roa = 0;
}

// 3. Autosuffisance opérationnelle
$produits_exploitation = 0;
$charges_exploitation_hors_subventions = $charges_exploitation;
$autosuffisance = 0;

try {
    // Produits d'exploitation (comptes de classe 7)
    $stmtProduits = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total_produits
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '7'
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmtProduits->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_periode
    ]);
    $resultProduits = $stmtProduits->fetch();
    $produits_exploitation = $resultProduits['total_produits'];
    
    $autosuffisance = ($charges_exploitation_hors_subventions > 0) ? $produits_exploitation / $charges_exploitation_hors_subventions : 0;
} catch (PDOException $e) {
    $produits_exploitation = 0;
    $autosuffisance = 0;
}

// 4. Marge bénéficiaire
$marge_beneficiaire = ($produits_exploitation > 0) ? $resultat_net / $produits_exploitation : 0;

// 5. Coefficient d'exploitation
$coefficient_exploitation = ($produits_exploitation > 0) ? $charges_exploitation / $produits_exploitation : 0;

// ============================================================
// I-5 - INDICATEURS DE GESTION DU BILAN
// ============================================================

// 1. Taux de rendement des actifs (intérêts et commissions perçus / actifs productifs)
$interets_commissions = 0;
$actifs_productifs = 0;
$taux_rendement_actifs = 0;

try {
    // Intérêts et commissions perçus
    $stmtInterets = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total_interets
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.numero_compte LIKE '70%'  -- Produits financiers
          AND e.date_ecriture BETWEEN :date_debut AND :date_fin
    ");
    $stmtInterets->execute([
        ':date_debut' => $date_debut_exercice,
        ':date_fin' => $date_fin_periode
    ]);
    $resultInterets = $stmtInterets->fetch();
    $interets_commissions = $resultInterets['total_interets'];
    
    // Actifs productifs (portefeuille de crédits + titres de placement)
    $actifs_productifs = $portefeuille_moyen;
    
    $taux_rendement_actifs = ($actifs_productifs > 0) ? $interets_commissions / $actifs_productifs : 0;
} catch (PDOException $e) {
    $interets_commissions = 0;
    $taux_rendement_actifs = 0;
}

// 2. Ratio de liquidité de l'actif
$disponibilites = 0;
$ratio_liquidite = 0;

try {
    // Disponibilités (caisse + banque)
    $stmtDispo = $pdo->prepare("
        SELECT COALESCE(SUM(solde_actuel), 0) as total_dispo
        FROM caisses
        WHERE statut = 'ouverte'
    ");
    $stmtDispo->execute();
    $resultDispo = $stmtDispo->fetch();
    $disponibilites = $resultDispo['total_dispo'];
    
    $ratio_liquidite = ($actif_total_moyen > 0) ? $disponibilites / $actif_total_moyen : 0;
} catch (PDOException $e) {
    $disponibilites = 0;
    $ratio_liquidite = 0;
}

// 3. Ratio de capitalisation
$ratio_capitalisation = ($actif_total_moyen > 0) ? $fonds_propres_moyens / $actif_total_moyen : 0;

// Normes
$norme_productivite_agents = 130;
$norme_productivite_personnel = 115;
$norme_charges_portefeuille = 0.35;
$norme_frais_generaux = 0.20;
$norme_charges_personnel = 0.10;
$norme_roe = 0.15;
$norme_roa = 0.03;
$norme_autosuffisance = 1.30;
$norme_marge = 0.20;
$norme_coefficient = 0.60;
$norme_rendement = 0.15;
$norme_liquidite_actif = 0.05;
$norme_capitalisation = 0.15;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IF 12 - Indicateurs financiers (Efficacité, Rentabilité, Gestion du bilan)</title>
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
        
        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            padding: 20px;
        }
        
        @media (max-width: 768px) {
            .grid-2, .grid-3, .grid-4 {
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
        <h1>IF 12 - Indicateurs financiers</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">Partie 2 : Efficacité, Rentabilité et Gestion du bilan</div>
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
    
    <!-- I-3 - Indicateurs d'efficacité/productivité -->
    <div class="section-card">
        <div class="section-title">⚡ I-3 - Indicateurs d'efficacité/productivité</div>
        <div class="grid-3">
            <div class="indicator-card">
                <div class="indicator-title">Productivité des agents de crédits</div>
                <div class="indicator-value <?= $productivite_agents >= $norme_productivite_agents ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($productivite_agents, 0) ?>
                </div>
                <div class="indicator-norme">Norme : ≥ 130 emprunteurs/agent</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $productivite_agents >= $norme_productivite_agents ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $productivite_agents >= $norme_productivite_agents ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Emprunteurs actifs : <?= number_format($nb_emprunteurs_actifs) ?><br>
                    Agents de crédit : <?= $nb_agents_credit ?>
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Productivité du personnel</div>
                <div class="indicator-value <?= $productivite_personnel >= $norme_productivite_personnel ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($productivite_personnel, 0) ?>
                </div>
                <div class="indicator-norme">Norme : ≥ 115 clients/employé</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $productivite_personnel >= $norme_productivite_personnel ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $productivite_personnel >= $norme_productivite_personnel ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Clients actifs : <?= number_format($nb_clients_actifs) ?><br>
                    Effectif personnel : <?= $nb_employes ?>
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Charges d'exploitation / Portefeuille crédit</div>
                <div class="indicator-value <?= $ratio_charges_portefeuille <= $norme_charges_portefeuille ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($ratio_charges_portefeuille * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≤ 35%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $ratio_charges_portefeuille <= $norme_charges_portefeuille ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $ratio_charges_portefeuille <= $norme_charges_portefeuille ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
            </div>
        </div>
        
        <div class="grid-3">
            <div class="indicator-card">
                <div class="indicator-title">Ratios des frais généraux / Portefeuille crédits</div>
                <div class="indicator-value <?= $ratio_frais_generaux <= $norme_frais_generaux ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($ratio_frais_generaux * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≤ 20%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $ratio_frais_generaux <= $norme_frais_generaux ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $ratio_frais_generaux <= $norme_frais_generaux ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Ratio des charges de personnel</div>
                <div class="indicator-value <?= $ratio_charges_personnel <= $norme_charges_personnel ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($ratio_charges_personnel * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≤ 10%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $ratio_charges_personnel <= $norme_charges_personnel ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $ratio_charges_personnel <= $norme_charges_personnel ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- I-4 - Indicateurs de rentabilité -->
    <div class="section-card">
        <div class="section-title">💰 I-4 - Indicateurs de rentabilité</div>
        <div class="grid-4">
            <div class="indicator-card">
                <div class="indicator-title">Rentabilité des fonds propres (ROE)</div>
                <div class="indicator-value <?= $roe >= $norme_roe ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($roe * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≥ 15%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $roe >= $norme_roe ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $roe >= $norme_roe ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Rendement sur actif (ROA)</div>
                <div class="indicator-value <?= $roa >= $norme_roa ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($roa * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≥ 3%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $roa >= $norme_roa ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $roa >= $norme_roa ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Autosuffisance opérationnelle</div>
                <div class="indicator-value <?= $autosuffisance >= $norme_autosuffisance ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($autosuffisance, 2) ?>
                </div>
                <div class="indicator-norme">Norme : ≥ 1.3</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $autosuffisance >= $norme_autosuffisance ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $autosuffisance >= $norme_autosuffisance ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Marge bénéficiaire</div>
                <div class="indicator-value <?= $marge_beneficiaire >= $norme_marge ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($marge_beneficiaire * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≥ 20%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $marge_beneficiaire >= $norme_marge ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $marge_beneficiaire >= $norme_marge ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
            </div>
        </div>
        
        <div class="grid-2">
            <div class="indicator-card">
                <div class="indicator-title">Coefficient d'exploitation</div>
                <div class="indicator-value <?= $coefficient_exploitation <= $norme_coefficient ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($coefficient_exploitation * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≤ 60%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $coefficient_exploitation <= $norme_coefficient ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $coefficient_exploitation <= $norme_coefficient ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- I-5 - Indicateurs de gestion du bilan -->
    <div class="section-card">
        <div class="section-title">📋 I-5 - Indicateurs de gestion du bilan</div>
        <div class="grid-3">
            <div class="indicator-card">
                <div class="indicator-title">Taux de rendement des actifs</div>
                <div class="indicator-value <?= $taux_rendement_actifs >= $norme_rendement ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($taux_rendement_actifs * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≥ 15%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $taux_rendement_actifs >= $norme_rendement ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $taux_rendement_actifs >= $norme_rendement ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Intérêts perçus : <?= number_format($interets_commissions, 0, ',', ' ') ?> FCFA
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Ratio de liquidité de l'actif</div>
                <div class="indicator-value <?= $ratio_liquidite >= $norme_liquidite_actif ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($ratio_liquidite * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≥ 5%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $ratio_liquidite >= $norme_liquidite_actif ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $ratio_liquidite >= $norme_liquidite_actif ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Disponibilités : <?= number_format($disponibilites, 0, ',', ' ') ?> FCFA
                </div>
            </div>
            
            <div class="indicator-card">
                <div class="indicator-title">Ratio de capitalisation</div>
                <div class="indicator-value <?= $ratio_capitalisation >= $norme_capitalisation ? 'conforme' : 'non-conforme' ?>">
                    <?= number_format($ratio_capitalisation * 100, 2) ?>%
                </div>
                <div class="indicator-norme">Norme : ≥ 15%</div>
                <div style="margin-top: 10px;">
                    <span class="status-badge <?= $ratio_capitalisation >= $norme_capitalisation ? 'status-conforme' : 'status-non-conforme' ?>">
                        <?= $ratio_capitalisation >= $norme_capitalisation ? 'CONFORME' : 'NON CONFORME' ?>
                    </span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    Fonds propres : <?= number_format($fonds_propres_moyens, 0, ',', ' ') ?> FCFA
                </div>
            </div>
        </div>
    </div>
    
    <!-- Récapitulatif des données de base -->
    <div class="section-card">
        <div class="section-title">📊 Récapitulatif des données de base</div>
        <div style="padding: 20px;">
            <div class="grid-2">
                <div>
                    <strong>Efficacité :</strong><br>
                    Emprunteurs actifs : <?= number_format($nb_emprunteurs_actifs) ?><br>
                    Clients actifs : <?= number_format($nb_clients_actifs) ?><br>
                    Agents de crédit : <?= $nb_agents_credit ?><br>
                    Effectif personnel : <?= $nb_employes ?>
                </div>
                <div>
                    <strong>Rentabilité :</strong><br>
                    Résultat net : <?= number_format($resultat_net, 0, ',', ' ') ?> FCFA<br>
                    Produits d'exploitation : <?= number_format($produits_exploitation, 0, ',', ' ') ?> FCFA<br>
                    Charges d'exploitation : <?= number_format($charges_exploitation, 0, ',', ' ') ?> FCFA<br>
                    Fonds propres moyens : <?= number_format($fonds_propres_moyens, 0, ',', ' ') ?> FCFA
                </div>
                <div>
                    <strong>Gestion du bilan :</strong><br>
                    Actif total moyen : <?= number_format($actif_total_moyen, 0, ',', ' ') ?> FCFA<br>
                    Portefeuille crédit moyen : <?= number_format($portefeuille_moyen, 0, ',', ' ') ?> FCFA<br>
                    Disponibilités : <?= number_format($disponibilites, 0, ',', ' ') ?> FCFA<br>
                    Intérêts et commissions perçus : <?= number_format($interets_commissions, 0, ',', ' ') ?> FCFA
                </div>
            </div>
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
        window.location.href = 'IF12.php?exercice=' + exercice + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>