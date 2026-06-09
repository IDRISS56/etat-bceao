<?php
// DIMF_2000.php - Bilan Actif, Passif et Hors Bilan
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
// CALCUL DU BILAN ACTIF (Brut, Amortissements/Provisions, Net)
// ============================================================

// A01 - OPERATIONS DE TRESORERIE ET AVEC LES INSTITUTIONS FINANCIERES
$A01_brut = 0;
$A01_amort = 0;
$A01_net = 0;

// A10 - Valeur en caisse
$A10_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(solde_actuel), 0) as total
        FROM caisses
        WHERE statut = 'ouverte'
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $A10_brut = $result['total'];
} catch (PDOException $e) {
    $A10_brut = 0;
}

// A11 - Billets et monnaies (même que A10)
$A11_brut = $A10_brut;

// A12 - Comptes ordinaires débiteurs
$A12_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(solde), 0) as total
        FROM comptes
        WHERE solde > 0 AND statut = 'actif'
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $A12_brut = $result['total'];
} catch (PDOException $e) {
    $A12_brut = 0;
}

// A2A - Autres comptes de dépôts débiteurs
$A2A_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(solde), 0) as total
        FROM comptes
        WHERE solde > 0 AND statut = 'actif' AND type_compte_id = 'DEPOT'
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $A2A_brut = $result['total'];
} catch (PDOException $e) {
    $A2A_brut = 0;
}

// A2H - Dépôts à terme constitués
$A2H_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(capital_initial), 0) as total
        FROM comptes_dat
        WHERE statut = 'en cours'
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $A2H_brut = $result['total'];
} catch (PDOException $e) {
    $A2H_brut = 0;
}

// A2I - Dépôts de garantie constitués
$A2I_brut = 0;

// A2J - Autres dépôts constitués
$A2J_brut = 0;

// A3A - Comptes de prêts
$A3A_brut = 0;
try {
    $stmt = $pdo->prepare("
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
    $stmt->execute();
    $result = $stmt->fetch();
    $A3A_brut = $result['total'];
} catch (PDOException $e) {
    $A3A_brut = 0;
}

// A3B - Prêts à moins d'un an
$A3B_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve') AND d.duree <= 12
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $A3B_brut = $result['total'];
} catch (PDOException $e) {
    $A3B_brut = 0;
}

// A3C - Prêts à terme (>12 mois)
$A3C_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve') AND d.duree > 12
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $A3C_brut = $result['total'];
} catch (PDOException $e) {
    $A3C_brut = 0;
}

// A60 - Créances rattachées
$A60_brut = 0;

// A70 - Prêts en souffrance
$A70_brut = 0;
try {
    $stmt = $pdo->prepare("
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
    $stmt->execute();
    $result = $stmt->fetch();
    $A70_brut = $result['total'];
} catch (PDOException $e) {
    $A70_brut = 0;
}

// B01 - OPERATIONS AVEC LES MEMBRES, BENEFICIAIRES OU CLIENTS
$B01_brut = 0;
$B01_amort = 0;
$B01_net = 0;

// B2D - Crédits à court terme
$B2D_brut = $A3B_brut;

// B2N - Comptes ordinaires
$B2N_brut = 0;

// B30 - Crédits à moyen terme (13-60 mois)
$B30_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve') AND d.duree BETWEEN 13 AND 60
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $B30_brut = $result['total'];
} catch (PDOException $e) {
    $B30_brut = 0;
}

// B40 - Crédits à long terme (>60 mois)
$B40_brut = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
        FROM dossiers d
        LEFT JOIN (
            SELECT dossier_id, SUM(montant) as rembourse
            FROM echeances
            WHERE statut = 'payee'
            GROUP BY dossier_id
        ) e ON d.dossier_id = e.dossier_id
        WHERE d.statut IN ('actif', 'approuve') AND d.duree > 60
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $B40_brut = $result['total'];
} catch (PDOException $e) {
    $B40_brut = 0;
}

// B65 - Créances rattachées
$B65_brut = 0;

// B70 - Crédits en souffrance
$B70_brut = $A70_brut;
$B70_amort = 0;

// Provisions sur créances en souffrance
$provisions_creances = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.montant), 0) as total
        FROM provisions p
        WHERE p.statut = 'actif' AND p.type_provision = 'CREANCES'
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $provisions_creances = $result['total'];
    $B70_amort = $provisions_creances;
} catch (PDOException $e) {
    $provisions_creances = 0;
}

// B71 - Crédits en souffrance <= 6 mois
$B71_brut = 0;
$B71_amort = 0;

// B72 - Crédits en souffrance 6-12 mois
$B72_brut = 0;
$B72_amort = 0;

// B73 - Crédits en souffrance 12-24 mois
$B73_brut = 0;
$B73_amort = 0;

// C01 - OPERATIONS SUR TITRES ET OPERATIONS DIVERSES
$C01_brut = 0;
$C01_amort = 0;
$C01_net = 0;

// C10 - Titres de placement
$C10_brut = 0;

// C30 - Comptes de stocks
$C30_brut = 0;

// C40 - Débiteurs divers
$C40_brut = 0;

// C56 - Valeur à l'encaissement avec crédit immédiat
$C56_brut = 0;

// C6A - Comptes d'ordre et divers
$C6A_brut = 0;

// D01 - VALEURS IMMOBILISEES
$D01_brut = 0;
$D01_amort = 0;
$D01_net = 0;

// Immobilisations
$immobilisations_brut = 0;
$amortissements_immob = 0;

try {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(montant_achat), 0) as brut,
            COALESCE(SUM(amortissement_total), 0) as amort
        FROM immobilisations
        WHERE statut = 'actif' AND date_achat <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $immobilisations_brut = $result['brut'];
    $amortissements_immob = $result['amort'];
} catch (PDOException $e) {
    $immobilisations_brut = 0;
    $amortissements_immob = 0;
}

$D01_brut = $immobilisations_brut;
$D01_amort = $amortissements_immob;
$D01_net = $D01_brut - $D01_amort;

// E90 - TOTAL ACTIF
$total_actif_brut = $A01_brut + $B01_brut + $C01_brut + $D01_brut;
$total_actif_amort = $A01_amort + $B01_amort + $C01_amort + $D01_amort;
$total_actif_net = $total_actif_brut - $total_actif_amort;

// ============================================================
// CALCUL DU BILAN PASSIF
// ============================================================

// F01 - OPERATIONS DE TRESORERIE ET AVEC LES INSTITUTIONS FINANCIERES
$F01_net = 0;

// F1A - Comptes ordinaires créditeurs
$F1A_net = 0;

// F2A - Autres comptes de dépôts créditeurs
$F2A_net = 0;

// F3A - Comptes d'emprunts
$F3A_net = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant), 0) as total
        FROM capital
        WHERE statut = 'valide' AND mode_paiement = 'BANQUE'
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $F3A_net = $result['total'];
} catch (PDOException $e) {
    $F3A_net = 0;
}

// F3E - Emprunts à moins d'un an
$F3E_net = 0;

// F3F - Emprunts à terme
$F3F_net = $F3A_net;

// F50 - Autres sommes dues aux institutions financières
$F50_net = 0;

// F55 - Ressources affectées
$F55_net = 0;

// F60 - Dettes rattachées
$F60_net = 0;

// G01 - OPERATIONS AVEC LES MEMBRES, BENEFICIAIRES OU CLIENTS
$G01_net = 0;

// G10 - Comptes ordinaires créditeurs
$G10_net = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(ABS(solde)), 0) as total
        FROM comptes
        WHERE solde < 0 AND statut = 'actif'
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $G10_net = $result['total'];
} catch (PDOException $e) {
    $G10_net = 0;
}

// G15 - Dépôts à terme reçus
$G15_net = $A2H_brut;

// G2A - Comptes d'épargne à régime spécial
$G2A_net = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.solde), 0) as total
        FROM comptes c
        INNER JOIN produits p ON c.produit_id = p.produit_id
        INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
        WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $G2A_net = $result['total'];
} catch (PDOException $e) {
    $G2A_net = 0;
}

// G30 - Autres dépôts de garantie reçus
$G30_net = 0;

// G35 - Autres dépôts reçus
$G35_net = 0;

// G60 - Emprunts
$G60_net = 0;

// G70 - Autres sommes dues
$G70_net = 0;

// G90 - Dettes rattachées
$G90_net = 0;

// H01 - OPERATIONS SUR TITRES ET OPERATIONS DIVERSES
$H01_net = 0;

// H40 - Créditeurs divers
$H40_net = 0;

// H6A - Comptes d'ordre et divers
$H6A_net = 0;

// L01 - PROVISIONS, FONDS PROPRES ET ASSIMILES
$L01_net = 0;

// Calcul des fonds propres
$fonds_propres = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.montant_credit - e.montant_debit), 0) as total
        FROM ecritures_comptables e
        INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
        WHERE pc.classe_compte = '1' AND e.date_ecriture <= :date_fin
    ");
    $stmt->execute([':date_fin' => $date_fin_periode]);
    $result = $stmt->fetch();
    $fonds_propres = $result['total'];
} catch (PDOException $e) {
    $fonds_propres = 0;
}

// L10 - Subventions d'investissement
$L10_net = 0;

// L20 - Fonds affectés
$L20_net = 0;

// L27 - Fonds de crédit
$L27_net = 0;

// L30 - Provisions pour risques et charges
$L30_net = 0;

// L55 - Réserves
$L55_net = 0;

// L65 - Fonds de dotation
$L65_net = 0;

// L70 - Report à nouveau
$L70_net = 0;

// L75 - Excédent des produits sur les charges
$L75_net = 0;

$L01_net = $fonds_propres;

// TOTAL PASSIF
$total_passif = $F01_net + $G01_net + $H01_net + $L01_net;

// ============================================================
// CALCUL DU HORS BILAN
// ============================================================

// N1H - Engagements reçus des institutions financières
$N1H_net = 0;

// N1K - Engagements reçus des membres
$N1K_net = 0;

// N2A - Engagements de garantie donnés
$N2A_net = 0;

// N2H - Engagements de garantie reçus
$N2H_net = 0;

// N2J - Engagements de garantie donnés aux membres
$N2J_net = 0;

// N2M - Engagements de garantie reçus des membres
$N2M_net = 0;

// Q1M - Crédits distribués pour le compte de tiers
$Q1M_net = 0;

// N90 - Engagements douteux
$N90_net = 0;

$total_hors_bilan = $N1H_net + $N1K_net + $N2A_net + $N2H_net + $N2J_net + $N2M_net + $Q1M_net + $N90_net;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2000 - Bilan (Actif, Passif, Hors Bilan)</title>
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
        
        .three-columns {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .three-columns > div {
            flex: 1;
            min-width: 300px;
        }
        
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin: 15px;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            .three-columns {
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
        <h1>DIMF_2000 - BILAN</h1>
        <div class="subtitle">
            République de Côte d'Ivoire / Ministère de l'Economie et des Finances<br>
            Direction Générale du Trésor et de la Comptabilité Publique (DGTCP)<br>
            Direction des Systèmes Financiers Décentralisés (DSFD)
        </div>
        <div class="badge">SICS-BCEAO - État financier annuel</div>
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
    
    <div class="three-columns">
        <!-- BILAN ACTIF -->
        <div class="section-card">
            <div class="section-title">📊 BILAN ACTIF</div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>CODE POSTE</th>
                            <th>ACTIF</th>
                            <th class="text-right">Brut</th>
                            <th class="text-right">Amort. Prov.</th>
                            <th class="text-right">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="subtotal-row">
                            <td>A01</td>
                            <td colspan="4">OPERATIONS DE TRESORERIE ET AVEC LES INSTITUTIONS FINANCIERES</td>
                        </tr>
                        <tr><td>A10</td><td>Valeur en caisse</td>
                        <td class="text-right"><?= number_format($A10_brut, 0, ',', ' ') ?></td>
                        <td class="text-right">0</td>
                        <td class="text-right"><?= number_format($A10_brut, 0, ',', ' ') ?></td></tr>
                        <tr><td>A11</td><td>Billets et monnaies</td>
                        <td class="text-right"><?= number_format($A11_brut, 0, ',', ' ') ?></td>
                        <td class="text-right">0</td>
                        <td class="text-right"><?= number_format($A11_brut, 0, ',', ' ') ?></td></tr>
                        <tr><td>A12</td><td>Comptes ordinaires débiteurs</td>
                        <td class="text-right"><?= number_format($A12_brut, 0, ',', ' ') ?></td>
                        <td class="text-right">0</td>
                        <td class="text-right"><?= number_format($A12_brut, 0, ',', ' ') ?></td></tr>
                        <tr><td>A2A</td><td>Autres comptes de dépôts débiteurs</td>
                        <td class="text-right"><?= number_format($A2A_brut, 0, ',', ' ') ?></td>
                        <td class="text-right">0</td>
                        <td class="text-right"><?= number_format($A2A_brut, 0, ',', ' ') ?></td></tr>
                        <tr><td>A2H</td><td>Dépôts à terme constitués</td>
                        <td class="text-right"><?= number_format($A2H_brut, 0, ',', ' ') ?></td>
                        <td class="text-right">0</td>
                        <td class="text-right"><?= number_format($A2H_brut, 0, ',', ' ') ?></td></tr>
                        <tr><td>A3A</td><td>Comptes de prêts</td>
                        <td class="text-right"><?= number_format($A3A_brut, 0, ',', ' ') ?></td>
                        <td class="text-right">0</td>
                        <td class="text-right"><?= number_format($A3A_brut, 0, ',', ' ') ?></td></tr>
                        <tr><td>A70</td><td>Prêts en souffrance</td>
                        <td class="text-right"><?= number_format($A70_brut, 0, ',', ' ') ?></td>
                        <td class="text-right">0</td>
                        <td class="text-right"><?= number_format($A70_brut, 0, ',', ' ') ?></td></tr>
                        <tr class="subtotal-row">
                            <td>B01</td>
                            <td colspan="4">OPERATIONS AVEC LES MEMBRES, BENEFICIAIRES OU CLIENTS</td>
                        </tr>
                        <tr><td>B2D</td><td>Crédits à court terme</td>
                        <td class="text-right"><?= number_format($B2D_brut, 0, ',', ' ') ?></td>
                        <td class="text-right">0</td>
                        <td class="text-right"><?= number_format($B2D_brut, 0, ',', ' ') ?></td></tr>
                        <tr><td>B30</td><td>Crédits à moyen terme</td>
                        <td class="text-right"><?= number_format($B30_brut, 0, ',', ' ') ?></td>
                        <td class="text-right">0</td>
                        <td class="text-right"><?= number_format($B30_brut, 0, ',', ' ') ?></td></tr>
                        <tr><td>B40</td><td>Crédits à long terme</td>
                        <td class="text-right"><?= number_format($B40_brut, 0, ',', ' ') ?></td>
                        <td class="text-right">0</td>
                        <td class="text-right"><?= number_format($B40_brut, 0, ',', ' ') ?></td></tr>
                        <tr><td>B70</td><td>Crédits en souffrance</td>
                        <td class="text-right"><?= number_format($B70_brut, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($B70_amort, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($B70_brut - $B70_amort, 0, ',', ' ') ?></td></tr>
                        <tr class="subtotal-row">
                            <td>D01</td>
                            <td colspan="4">VALEURS IMMOBILISEES</td>
                        </tr>
                        <tr><td>D30</td><td>Immobilisations d'exploitation</td>
                        <td class="text-right"><?= number_format($D01_brut, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($D01_amort, 0, ',', ' ') ?></td>
                        <td class="text-right"><?= number_format($D01_net, 0, ',', ' ') ?></td></tr>
                        <tr class="total-row">
                            <td colspan="2"><strong>E90 - TOTAL ACTIF</strong></td>
                            <td class="text-right"><strong><?= number_format($total_actif_brut, 0, ',', ' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_actif_amort, 0, ',', ' ') ?></strong></td>
                            <td class="text-right"><strong><?= number_format($total_actif_net, 0, ',', ' ') ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- BILAN PASSIF -->
        <div class="section-card">
            <div class="section-title">💰 BILAN PASSIF</div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>CODE POSTE</th>
                            <th>PASSIF</th>
                            <th class="text-right">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="subtotal-row">
                            <td>F01</td>
                            <td colspan="2">OPERATIONS DE TRESORERIE ET AVEC LES INSTITUTIONS FINANCIERES</td>
                        </tr>
                        <tr><td>F3A</td><td>Comptes d'emprunts</td><td class="text-right"><?= number_format($F3A_net, 0, ',', ' ') ?></td></tr>
                        <tr class="subtotal-row"><td>G01</td><td colspan="2">OPERATIONS AVEC LES MEMBRES, BENEFICIAIRES OU CLIENTS</td></tr>
                        <tr><td>G10</td><td>Comptes ordinaires créditeurs</td><td class="text-right"><?= number_format($G10_net, 0, ',', ' ') ?></td></tr>
                        <tr><td>G15</td><td>Dépôts à terme reçus</td><td class="text-right"><?= number_format($G15_net, 0, ',', ' ') ?></td></tr>
                        <tr><td>G2A</td><td>Comptes d'épargne à régime spécial</td><td class="text-right"><?= number_format($G2A_net, 0, ',', ' ') ?></td></tr>
                        <tr class="subtotal-row"><td>L01</td><td colspan="2">PROVISIONS, FONDS PROPRES ET ASSIMILES</td></tr>
                        <tr><td>L01</td><td>Fonds propres</td><td class="text-right"><?= number_format($L01_net, 0, ',', ' ') ?></td></tr>
                        <tr class="total-row"><td>L90</td><td><strong>TOTAL PASSIF</strong></td><td class="text-right"><strong><?= number_format($total_passif, 0, ',', ' ') ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- HORS BILAN -->
        <div class="section-card">
            <div class="section-title">📋 HORS BILAN</div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>CODE POSTE</th><th>HORS BILAN</th><th class="text-right">Net</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>N1H</td><td>Engagements reçus des institutions financières</td><td class="text-right"><?= number_format($N1H_net, 0, ',', ' ') ?></td></tr>
                        <tr><td>Q1M</td><td>Crédits distribués pour le compte de tiers</td><td class="text-right"><?= number_format($Q1M_net, 0, ',', ' ') ?></td></tr>
                        <tr class="total-row"><td></td><td><strong>TOTAL HORS BILAN</strong></td><td class="text-right"><strong><?= number_format($total_hors_bilan, 0, ',', ' ') ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="info-box">
        <strong>ⓘ Note :</strong> Le total actif (<?= number_format($total_actif_net, 0, ',', ' ') ?> FCFA) doit être égal au total passif (<?= number_format($total_passif, 0, ',', ' ') ?> FCFA).
        <?php if(abs($total_actif_net - $total_passif) > 1000): ?>
            <span style="color: #c62828;">⚠️ Écart constaté ! Veuillez vérifier les calculs.</span>
        <?php else: ?>
            <span style="color: #2e7d32;">✓ Équilibre bilan respecté.</span>
        <?php endif; ?>
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
        window.location.href = 'DIMF_2000.php?exercice=' + exercice + '&trimestre=' + trimestre + '&mois=' + mois;
    }
    
    function exporterPDF() {
        window.print();
    }
</script>
</body>
</html>