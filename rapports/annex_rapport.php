<?php
// ANNEX_RAPP_AN.php - Annexe au rapport annuel
// Instruction n°018-12-2010 du 29 décembre 2010
// Version corrigée – export Excel avec json_encode()

session_start();
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ============================================================
// PARAMÈTRES
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
$date_debut_periode = $exercice . '-01-01';
$date_fin_periode_sql = $date_fin_periode;

$lib_periode = '';
switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Annee ' . $exercice;
}

// ============================================================
// DÉFINITION DES TABLEAUX (indicateurs) – identique à avant
// ============================================================
$sections = [];

// Tableau 1.1
$sections['1.1'] = [
    'title' => 'Tableau n°1.1 : Nombre de membres, bénéficiaires ou clients (en unités)',
    'rows' => [
        'Y01101' => ['label' => 'Nombre total de membres bénéficiaires ou clients (les groupements sont comptés sur une base unitaires) (1)+(2)', 'type' => 'effectif', 'auto' => true],
        'Y01102' => ['label' => 'Nombre de personnes physiques non – membres d\'un groupement (1) = (a)+(b)', 'type' => 'effectif', 'auto' => true],
        'Y01103' => ['label' => 'Hommes (a)', 'type' => 'effectif', 'auto' => true],
        'Y01104' => ['label' => 'Femmes (b)', 'type' => 'effectif', 'auto' => true],
        'Y01105' => ['label' => 'Nombre de personnes morales (groupements de personnes physiques, entreprises, associations, etc) (2)', 'type' => 'effectif', 'auto' => true],
        'Y01106' => ['label' => 'Nombre de groupements de personnes physiques bénéficiaires', 'type' => 'effectif', 'auto' => true],
        'Y01107' => ['label' => 'Nombre total des membres des groupements de personnes physiques bénéficiaires (3) = (c)+ (d)', 'type' => 'effectif', 'auto' => false],
        'Y01108' => ['label' => 'Hommes ( c )', 'type' => 'effectif', 'auto' => false],
        'Y01109' => ['label' => 'Femmes (d)', 'type' => 'effectif', 'auto' => false],
    ]
];
// Tableau 1.2
$sections['1.2'] = [
    'title' => 'Tableau n°1.2 : Effectif des dirigeants et du personnel employé (en unités)',
    'rows' => [
        'Y01201' => ['label' => 'Nombre de membres du Conseil d\'Administration ou de l\'organe équivalent', 'type' => 'effectif', 'auto' => false],
        'Y01202' => ['label' => 'Nombre de membres du Conseil de Surveillance (*)', 'type' => 'effectif', 'auto' => false],
        'Y01203' => ['label' => 'Nombre de membres du Comité de Crédit (*)', 'type' => 'effectif', 'auto' => false],
        'Y01204' => ['label' => 'nombre de membres des autres comités créés par les SFD (**)', 'type' => 'effectif', 'auto' => false],
        'Y01205' => ['label' => 'Effectifs total des employés (3) = (1)+(2)', 'type' => 'effectif', 'auto' => true],
        'Y01206' => ['label' => 'Dirigeants (employés exerçant des fonctions de direction ou de gérance) dont : (1)', 'type' => 'effectif', 'auto' => true],
        'Y01207' => ['label' => '- nationaux', 'type' => 'effectif', 'auto' => false],
        'Y01208' => ['label' => '- personnel expatrié', 'type' => 'effectif', 'auto' => false],
        'Y01209' => ['label' => 'Autres Employés (2) = (a) + (b) + ( c )', 'type' => 'effectif', 'auto' => false],
        'Y01210' => ['label' => 'Agents permanents (a)', 'type' => 'effectif', 'auto' => false],
        'Y01211' => ['label' => 'Agents contractuels (b)', 'type' => 'effectif', 'auto' => false],
        'Y01212' => ['label' => 'Personnel expatrié ( c )', 'type' => 'effectif', 'auto' => false],
    ]
];
// Tableau 1.3.1
$sections['1.3.1'] = [
    'title' => 'Tableau sur l\'état des rémunérations des dirigeants et du personnel de l\'institution',
    'rows' => [
        'Y01301' => ['label' => 'Masse salariale globale en FCFA1', 'type' => 'montant', 'auto' => false],
        'Y01302' => ['label' => '- Personnel dirigeant (Directeur Général et son adjoint, Directeur de service)', 'type' => 'montant', 'auto' => false],
        'Y01303' => ['label' => '- Autre personnel', 'type' => 'montant', 'auto' => false],
        'Y01304' => ['label' => 'Montant des frais généraux en FCFA', 'type' => 'montant', 'auto' => false],
        'Y01305' => ['label' => 'Ratio Masse salariale rapportée aux frais généraux', 'type' => 'montant', 'auto' => false],
        'Y01306' => ['label' => 'Proportion salaire du Directeur Général rapporté aux frais généraux', 'type' => 'montant', 'auto' => false],
    ]
];
// Tableau 1.3.2
$sections['1.3.2'] = [
    'title' => 'Tableau sur les remboursements de frais de dirigeants élus',
    'rows' => [
        'Y01401' => ['label' => 'Indemnités de fonctions versées aux administrateurs non salariés2 en FCFA', 'type' => 'montant', 'auto' => false],
        'Y01402' => ['label' => 'Frais de tenue des réunions des organes et des assemblées en FCFA', 'type' => 'montant', 'auto' => false],
        'Y01403' => ['label' => '- Perdiem', 'type' => 'montant', 'auto' => false],
        'Y01404' => ['label' => '- Transport', 'type' => 'montant', 'auto' => false],
        'Y01405' => ['label' => '- Hébergement', 'type' => 'montant', 'auto' => false],
        'Y01406' => ['label' => '- Téléphone', 'type' => 'montant', 'auto' => false],
        'Y01407' => ['label' => '- Carburant', 'type' => 'montant', 'auto' => false],
        'Y01408' => ['label' => '- Autres', 'type' => 'montant', 'auto' => false],
    ]
];
// Tableau 2.0
$sections['2.0'] = [
    'title' => 'Tableau n°2 : évolution du nombre de points de service',
    'rows' => [
        'Y02001' => ['label' => 'Nombre d\'institutions de base', 'type' => 'effectif', 'auto' => true],
        'Y02002' => ['label' => 'Nombre de guichets ou d\'antennes', 'type' => 'effectif', 'auto' => true],
    ]
];
// Tableau 3.1
$sections['3.1'] = [
    'title' => 'Tableau n°3.1 : Evolution du montant des dépôts (en FCFA)',
    'rows' => [
        'Y03101' => ['label' => 'Montant total des dépôts des membres, bénéficiaires ou clients (1) + (2)', 'type' => 'montant', 'auto' => true],
        'Y03102' => ['label' => 'Montant des dépôts des personnes physiques non-membres d\'un groupement (1) = (a) + (b)', 'type' => 'montant', 'auto' => true],
        'Y03103' => ['label' => '- Montant des dépôts des hommes (a)', 'type' => 'montant', 'auto' => true],
        'Y03104' => ['label' => '- Montant des dépôts des femmes (b)', 'type' => 'montant', 'auto' => true],
        'Y03105' => ['label' => 'Montant des dépôts des personnes morales (groupements de personnes physique, entreprises, associations etc.) (2)', 'type' => 'montant', 'auto' => true],
    ]
];
// Tableau 3.2
$sections['3.2'] = [
    'title' => 'Tableau 3.2 Décomposition des dépôts par terme',
    'rows' => [
        'Y03201' => ['label' => 'Dépôts à vue Montant en FCFA', 'type' => 'montant', 'auto' => true],
        'Y03201_PART' => ['label' => 'Dépôts à vue Part (en %)', 'type' => 'pourcentage', 'auto' => true],
        'Y03202' => ['label' => 'Dépôts à terme Montant en FCFA', 'type' => 'montant', 'auto' => true],
        'Y03202_PART' => ['label' => 'Dépôts à terme Part (en %)', 'type' => 'pourcentage', 'auto' => true],
        'Y03203' => ['label' => 'Autres dépôts Année (n) Montant en FCFA', 'type' => 'montant', 'auto' => true],
        'Y03203_PART' => ['label' => 'Autres dépôts Année (n) Part (en %)', 'type' => 'pourcentage', 'auto' => true],
    ]
];
// Tableau 3.3
$sections['3.3'] = [
    'title' => 'Tableau 3.3 Evolution du nombre déposants (membres, bénéficiaires ou clients ayant un dépôt dans les livres du SFD) et des comptes inactifs',
    'rows' => [
        'Y03301' => ['label' => 'Nombre total des déposants  (1) + (2)', 'type' => 'effectif', 'auto' => true],
        'Y03302' => ['label' => 'Nombre de déposants personnes physiques non-membres d\'un groupement (1) = (a)+(b)', 'type' => 'effectif', 'auto' => true],
        'Y03303' => ['label' => '- Nombre de déposants hommes (a)', 'type' => 'effectif', 'auto' => true],
        'Y03304' => ['label' => '- Nombre de déposants Femmes (b)', 'type' => 'effectif', 'auto' => true],
        'Y03305' => ['label' => 'Nombres de déposants personnes morales (groupements de personnes physiques,entreprises, associations, etc (2)', 'type' => 'effectif', 'auto' => true],
        'Y03306' => ['label' => 'Nombres de comptes inactifs', 'type' => 'effectif', 'auto' => true],
        'Y03307' => ['label' => 'Montant des soldes des comptes inactifs', 'type' => 'montant', 'auto' => true],
        'Y03308' => ['label' => 'nombre total de comptes', 'type' => 'effectif', 'auto' => true],
    ]
];
// Tableau 3.4
$sections['3.4'] = [
    'title' => 'Tableau 3.4 Evolution du Capital social *',
    'rows' => [
        'Y03401' => ['label' => 'Montant du capital social (en FCFA)', 'type' => 'montant', 'auto' => true],
    ]
];
// Tableau 3.5
$sections['3.5'] = [
    'title' => 'Tableau 3.5 Répartition du Capital social entre les principaux actionnaires',
    'rows' => [],
];
for ($i=1; $i<=20; $i++) {
    $sections['3.5']['rows']["ACT_$i"] = ['label' => "Actionnaire $i (Nom, Montant, Part)", 'type' => 'montant', 'auto' => false];
}
// Tableau 4.1
$sections['4.1'] = [
    'title' => 'Tableau 4.1 Evolution du montant annuel des prêts accordés * (en milliers de CFA)',
    'rows' => [
        'Y04101' => ['label' => 'Montant des prêts accordés (1) + (2)', 'type' => 'montant', 'auto' => true],
        'Y04102' => ['label' => 'Montant des  prêts accordés aux personnes physiques non-membres d\'un groupement (1) = (a) + (b)', 'type' => 'montant', 'auto' => true],
        'Y04103' => ['label' => '- Montant des  prêts accordés aux hommes (a)', 'type' => 'montant', 'auto' => true],
        'Y04104' => ['label' => '- Montant des  prêts accordés aux femmes (b)', 'type' => 'montant', 'auto' => true],
        'Y04105' => ['label' => 'Montant des  prêts accordés aux personnes morales (groupements de personnes physiques, entreprises, associations etc.) (2)', 'type' => 'montant', 'auto' => true],
    ]
];
// Tableau 4.2
$sections['4.2'] = [
    'title' => 'Tableau 4.2 Evolution du nombre de prêts accordés dans l\'année (en unité)',
    'rows' => [
        'Y04201' => ['label' => 'Nombre total des prêts accordés (1) + (2)', 'type' => 'effectif', 'auto' => true],
        'Y04202' => ['label' => 'Nombre de prêts accordés aux personnes physiques non-membres d\'un groupement (1) = (a) + (b)', 'type' => 'effectif', 'auto' => true],
        'Y04203' => ['label' => '- Nombre de  prêts accordés aux hommes (a)', 'type' => 'effectif', 'auto' => true],
        'Y04204' => ['label' => '- Nombre de  prêts accordés aux femmes (b)', 'type' => 'effectif', 'auto' => true],
        'Y04205' => ['label' => 'Nombre de  prêts accordés aux personnes morales (groupements de personnes physiques, entreprises, associations etc.) (2)', 'type' => 'effectif', 'auto' => true],
        'Y04206' => ['label' => 'Montant moyen des prêts accordés (somme des prêts rapportée au nombre de prêts accordés', 'type' => 'montant', 'auto' => true],
    ]
];
// Tableau 4.3
$sections['4.3'] = [
    'title' => 'Tableau 4.3 Engagements par signature (en milliers de CFA)',
    'rows' => [
        'Y04301' => ['label' => 'Engagements de financement donnés en faveur des institutions financières', 'type' => 'montant', 'auto' => false],
        'Y04302' => ['label' => 'Engagements de financement donnés en faveur des membres, bénéficiaires ou clients', 'type' => 'montant', 'auto' => false],
        'Y04303' => ['label' => 'Engagements de garantie d\'ordre des institutions financières', 'type' => 'montant', 'auto' => false],
        'Y04304' => ['label' => 'Engagements de garantie d\'ordre des membres, bénéficiaires ou clients', 'type' => 'montant', 'auto' => false],
    ]
];
// Tableau 4.4
$sections['4.4'] = [
    'title' => 'Tableau 4.4 Encours de crédits au 31 décembre (en milliers de CFA)',
    'rows' => [
        'Y04401' => ['label' => 'Encours total de crédits (1)+(2)', 'type' => 'montant', 'auto' => true],
        'Y04402' => ['label' => 'Encours de crédits sur les personnes physiques non-membres d\'un groupement (1) =(a)+(b', 'type' => 'montant', 'auto' => true],
        'Y04403' => ['label' => 'Encours de crédits sur les hommes (a)', 'type' => 'montant', 'auto' => true],
        'Y04404' => ['label' => 'Encours de crédits sur les femmes   (b)', 'type' => 'montant', 'auto' => true],
        'Y04405' => ['label' => 'Encours de crédits sur les  personnes morales (groupements de personnes physiques, entreprises associations, etc.) (2)', 'type' => 'montant', 'auto' => true],
    ]
];
// Tableau 4.5
$sections['4.5'] = [
    'title' => 'Tableau 4.5 Nombre de crédits en cours au 31 décembre (en unité)',
    'rows' => [
        'Y04501' => ['label' => 'Nombre de crédits en cours (1)+(2)', 'type' => 'effectif', 'auto' => true],
        'Y04502' => ['label' => 'Nombre de crédits en cours sur les personnes physiques non-membres d\'un groupement (1) =(a)+(b)', 'type' => 'effectif', 'auto' => true],
        'Y04503' => ['label' => 'Nombre de crédits en cours sur les hommes (a)', 'type' => 'effectif', 'auto' => true],
        'Y04504' => ['label' => 'Nombre de crédits en cours sur les femmes  (b)', 'type' => 'effectif', 'auto' => true],
        'Y04505' => ['label' => 'Nombre de crédits en cours sur les  personnes morales (groupements de personnes physiques, entreprises associations, etc.) (2)', 'type' => 'effectif', 'auto' => true],
    ]
];
// Tableau 4.6
$sections['4.6'] = [
    'title' => 'Tableau 4.6 Évolution de l\'encours de crédits par terme',
    'rows' => [
        'Y04601' => ['label' => 'Encours total de crédits Court terme', 'type' => 'montant', 'auto' => true],
        'Y04602' => ['label' => 'Encours total de crédits Moyen et long terme', 'type' => 'montant', 'auto' => true],
    ]
];
// Tableau 4.7
$sections['4.7'] = [
    'title' => 'Tableau 4.7 Encours des crédits des agents relevant des Autorités de contrôle (Ministère chargé des Finances, BCEAO et Commission Bancaire de l\'UMOA)',
    'rows' => [],
];
for ($i=1; $i<=20; $i++) {
    $sections['4.7']['rows']["AUT_$i"] = ['label' => "Agent $i (Prénoms et nom, Encours, Structure)", 'type' => 'montant', 'auto' => false];
}
// Tableau 4.8
$sections['4.8'] = [
    'title' => 'Tableau 4.8 Opérations de crédit sur ressources affectées',
    'rows' => [
        'Y04801' => ['label' => 'Nombre de crédits accordés sur ressources affectées', 'type' => 'effectif', 'auto' => false],
        'Y04802' => ['label' => 'Montant de crédits accordés sur ressources affectées (en milliers de FCFA)', 'type' => 'montant', 'auto' => false],
        'Y04803' => ['label' => 'Nombre de crédits en cours sur ressources affectées', 'type' => 'effectif', 'auto' => false],
        'Y04804' => ['label' => 'Montant des crédits en cours sur ressources affectées (en milliers de FCFA)', 'type' => 'montant', 'auto' => false],
    ]
];
// Tableau 4.9
$sections['4.9'] = [
    'title' => 'Tableau 4.9 Gestion du portefeuille de crédit',
    'rows' => [
        'Y04901' => ['label' => 'Encours des créances en souffrance (en milliers de FCFA)', 'type' => 'montant', 'auto' => true],
        'Y04902' => ['label' => 'Taux brut des créances en souffrance3', 'type' => 'montant', 'auto' => true],
        'Y04903' => ['label' => 'Taux de remboursement des crédits accordés4', 'type' => 'montant', 'auto' => true],
        'Y04904' => ['label' => 'Taux de recouvrement des créances en souffrance', 'type' => 'montant', 'auto' => false],
        'Y04905' => ['label' => 'Encours brut des créances en souffrance sur ressources affectées (en milliers de FCFA)', 'type' => 'montant', 'auto' => false],
        'Y04906' => ['label' => 'Taux brut de créances en souffrance sur ressources affectées6', 'type' => 'montant', 'auto' => false],
        'Y04907' => ['label' => 'Taux de remboursement  crédits accordés sur ressources affectées7', 'type' => 'montant', 'auto' => false],
        'Y04908' => ['label' => 'Taux de recouvrement des créances en souffrance sur ressources affectées8', 'type' => 'montant', 'auto' => false],
        'Y04909' => ['label' => 'Montant des crédits passés en pertes (en milliers de FCFA)', 'type' => 'montant', 'auto' => false],
        'Y04910' => ['label' => 'taux de perte sur créances9', 'type' => 'montant', 'auto' => false],
    ]
];
// Tableau 5.1.1
$sections['5.1.1'] = [
    'title' => '5.1 Activités de transfert rapide d\'argent - Informations générales',
    'rows' => [
        'Y05001' => ['label' => 'nom et adresse du représentant (Banque, poste)', 'type' => 'text', 'auto' => false],
        'Y05002' => ['label' => 'nom et adresse de la société représentée (Western union, Money gram, etc)', 'type' => 'text', 'auto' => false],
        'Y05003' => ['label' => 'nombre d\'opérations exécutées au cours de l\'année : à l\'émission', 'type' => 'effectif', 'auto' => false],
        'Y05004' => ['label' => 'nombre d\'opérations exécutées au cours de l\'année : à la réception', 'type' => 'effectif', 'auto' => false],
    ]
];
// Tableau 5.1.2
$sections['5.1.2'] = [
    'title' => 'Tableau 5.1 Opérations de transferts (en milliers de FCFA)',
    'rows' => [
        'Y05101' => ['label' => 'Transferts reçus (1)', 'type' => 'montant', 'auto' => false],
        'Y05102' => ['label' => 'UEMOA', 'type' => 'montant', 'auto' => false],
        'Y05103' => ['label' => 'Autres pays Africains', 'type' => 'montant', 'auto' => false],
        'Y05104' => ['label' => 'Union Européenne', 'type' => 'montant', 'auto' => false],
        'Y05105' => ['label' => 'Etats-Unis', 'type' => 'montant', 'auto' => false],
        'Y05106' => ['label' => 'Autres pays', 'type' => 'montant', 'auto' => false],
        'Y05107' => ['label' => 'Transferts émis (2)', 'type' => 'montant', 'auto' => false],
        'Y05108' => ['label' => 'UEMOA', 'type' => 'montant', 'auto' => false],
        'Y05109' => ['label' => 'Autres pays Africains', 'type' => 'montant', 'auto' => false],
        'Y05110' => ['label' => 'Union Européenne', 'type' => 'montant', 'auto' => false],
        'Y05111' => ['label' => 'Etats-Unis', 'type' => 'montant', 'auto' => false],
        'Y05112' => ['label' => 'Autres pays', 'type' => 'montant', 'auto' => false],
        'Y05113' => ['label' => 'Solde des transferts (3) = (1)-(2)', 'type' => 'montant', 'auto' => false],
    ]
];
// Tableau 5.2.1
$sections['5.2.1'] = [
    'title' => '5.2 Activités de micro assurance - Informations générales',
    'rows' => [
        'Y05005' => ['label' => 'nombre de bénéficiaires', 'type' => 'effectif', 'auto' => false],
        'Y05006' => ['label' => 'catégories de prestations offertes (à détailler)', 'type' => 'text', 'auto' => false],
    ]
];
// Tableau 5.2.2
$sections['5.2.2'] = [
    'title' => 'Tableau 5.2 Opérations de micro assurance (en milliers FCFA)',
    'rows' => [
        'Y05201' => ['label' => 'Montant de primes émises', 'type' => 'montant', 'auto' => false],
        'Y05202' => ['label' => 'Assurance-vie', 'type' => 'montant', 'auto' => false],
        'Y05203' => ['label' => 'Assurance non vie', 'type' => 'montant', 'auto' => false],
        'Y05204' => ['label' => 'Montant des arriérés de primes', 'type' => 'montant', 'auto' => false],
        'Y05205' => ['label' => 'Montant des sinistrés à payer', 'type' => 'montant', 'auto' => false],
    ]
];
// Tableau 5.3
$sections['5.3'] = [
    'title' => 'Tableau 5.3 Opérations de change',
    'rows' => [
        'EUR_ACHAT' => ['label' => 'EURO (EUR) - Montant des devises achetées', 'type' => 'montant', 'auto' => false],
        'EUR_CONTREVAL' => ['label' => 'EURO (EUR) - Contre valeur en FCFA des devises achetées', 'type' => 'montant', 'auto' => false],
        'EUR_VENTE' => ['label' => 'EURO (EUR) - Montant des devises vendues', 'type' => 'montant', 'auto' => false],
        'EUR_CONTREVAL_VENTE' => ['label' => 'EURO (EUR) - Contre valeur en FCFA des devises vendues', 'type' => 'montant', 'auto' => false],
        'USD_ACHAT' => ['label' => 'Dollar des EU (USD) - Montant des devises achetées', 'type' => 'montant', 'auto' => false],
        'USD_CONTREVAL' => ['label' => 'Dollar des EU (USD) - Contre valeur en FCFA des devises achetées', 'type' => 'montant', 'auto' => false],
        'USD_VENTE' => ['label' => 'Dollar des EU (USD) - Montant des devises vendues', 'type' => 'montant', 'auto' => false],
        'USD_CONTREVAL_VENTE' => ['label' => 'Dollar des EU (USD) - Contre valeur en FCFA des devises vendues', 'type' => 'montant', 'auto' => false],
        'CHF_ACHAT' => ['label' => 'Franc Suisse (CHF) - Montant des devises achetées', 'type' => 'montant', 'auto' => false],
        'CHF_CONTREVAL' => ['label' => 'Franc Suisse (CHF) - Contre valeur en FCFA des devises achetées', 'type' => 'montant', 'auto' => false],
        'CHF_VENTE' => ['label' => 'Franc Suisse (CHF) - Montant des devises vendues', 'type' => 'montant', 'auto' => false],
        'CHF_CONTREVAL_VENTE' => ['label' => 'Franc Suisse (CHF) - Contre valeur en FCFA des devises vendues', 'type' => 'montant', 'auto' => false],
        'GBP_ACHAT' => ['label' => 'Livre sterling (GBP) - Montant des devises achetées', 'type' => 'montant', 'auto' => false],
        'GBP_CONTREVAL' => ['label' => 'Livre sterling (GBP) - Contre valeur en FCFA des devises achetées', 'type' => 'montant', 'auto' => false],
        'GBP_VENTE' => ['label' => 'Livre sterling (GBP) - Montant des devises vendues', 'type' => 'montant', 'auto' => false],
        'GBP_CONTREVAL_VENTE' => ['label' => 'Livre sterling (GBP) - Contre valeur en FCFA des devises vendues', 'type' => 'montant', 'auto' => false],
        'AUT_ACHAT' => ['label' => 'Autres - Montant des devises achetées', 'type' => 'montant', 'auto' => false],
        'AUT_CONTREVAL' => ['label' => 'Autres - Contre valeur en FCFA des devises achetées', 'type' => 'montant', 'auto' => false],
        'AUT_VENTE' => ['label' => 'Autres - Montant des devises vendues', 'type' => 'montant', 'auto' => false],
        'AUT_CONTREVAL_VENTE' => ['label' => 'Autres - Contre valeur en FCFA des devises vendues', 'type' => 'montant', 'auto' => false],
    ]
];
// Tableau 6.1
$sections['6.1'] = [
    'title' => 'Tableau 6.1 Tarification des opérations avec la clientèle (*)',
    'rows' => [
        'Y06101' => ['label' => 'Taux d\'intérêt créditeur minimum servi sur les dépôts des membres, bénéficiaires ou clients', 'type' => 'montant', 'auto' => true],
        'Y06102' => ['label' => 'Taux d\'intérêt créditeur maximum servi sur les dépôts des membres, bénéficiaires ou clients', 'type' => 'montant', 'auto' => true],
        'Y06103' => ['label' => 'Taux d\'intérêt nominal débiteur minimum sur les crédits accordés aux membres, bénéficiaires ou clients', 'type' => 'montant', 'auto' => true],
        'Y06104' => ['label' => 'Taux d\'intérêt nominal débiteur maximum sur les crédits accordés aux membres, bénéficiaires ou clients', 'type' => 'montant', 'auto' => true],
        'Y06105' => ['label' => 'Taux d\'intérêt effectif global (**)', 'type' => 'montant', 'auto' => false],
    ]
];
// Tableau 6.2
$sections['6.2'] = [
    'title' => 'Tableau 6.2 Répartition des crédits selon leur objet (en milliers de FCFA)',
    'rows' => [
        'Y06201' => ['label' => 'Crédits immobiliers', 'type' => 'montant', 'auto' => false],
        'Y06202' => ['label' => 'Crédits d\'équipement', 'type' => 'montant', 'auto' => false],
        'Y06203' => ['label' => 'Crédits à la consommation', 'type' => 'montant', 'auto' => false],
        'Y06204' => ['label' => 'Crédits trésorerie', 'type' => 'montant', 'auto' => false],
        'Y06205' => ['label' => 'Autres crédits', 'type' => 'montant', 'auto' => false],
    ]
];
// Tableau 6.3
$sections['6.3'] = [
    'title' => 'Tableau 6.3 Dons et œuvres sociales',
    'rows' => [],
];
for ($i=1; $i<=20; $i++) {
    $sections['6.3']['rows']["DON_$i"] = ['label' => "Don $i (Références du bénéficiaire, Nature, Évaluation)", 'type' => 'montant', 'auto' => false];
}
// Tableau 6.4
$sections['6.4'] = [
    'title' => 'Tableau 6.4 Répartition sectorielle des crédits accordés (*) (en milliers de FCFA)',
    'rows' => [
        'Y06401' => ['label' => 'Agriculture, sylviculture et pêche', 'type' => 'montant', 'auto' => true],
        'Y06402' => ['label' => 'Industries extractives', 'type' => 'montant', 'auto' => true],
        'Y06403' => ['label' => 'Industries manufacturières', 'type' => 'montant', 'auto' => true],
        'Y06404' => ['label' => 'Bâtiment et travaux publics', 'type' => 'montant', 'auto' => true],
        'Y06405' => ['label' => 'Commerce, restaurants et hôtels', 'type' => 'montant', 'auto' => true],
        'Y06406' => ['label' => 'Électricité, gaz, eau', 'type' => 'montant', 'auto' => true],
        'Y06407' => ['label' => 'Transports, entrepôts et communications', 'type' => 'montant', 'auto' => true],
        'Y06408' => ['label' => 'Assurances, services aux entreprises', 'type' => 'montant', 'auto' => true],
        'Y06409' => ['label' => 'Immobilier', 'type' => 'montant', 'auto' => true],
        'Y06410' => ['label' => 'Services divers', 'type' => 'montant', 'auto' => true],
    ]
];
// Tableau 7.0
$sections['7.0'] = [
    'title' => 'Tableau n°7 : Opérations avec les autres institutions financières (établissements de crédit, SFD, autres institutions financières) et les partenaires au développement',
    'rows' => [
        'Y07001' => ['label' => 'Encours des placements auprès des autres institutions financières', 'type' => 'montant', 'auto' => false],
        'Y07002' => ['label' => 'Encours des emprunts auprès des autres institutions financières', 'type' => 'montant', 'auto' => false],
        'Y07003' => ['label' => 'Montant total des emprunts obtenus dans l\'année auprès des autres institutions financières (en milliers de FCFA)', 'type' => 'montant', 'auto' => false],
        'Y07004' => ['label' => 'Taux d\'intérêt moyen des emprunts obtenus dans l\'année auprès des autres institutions financières', 'type' => 'montant', 'auto' => false],
        'Y07005' => ['label' => 'Ressources affectées (en milliers de FCFA)', 'type' => 'montant', 'auto' => false],
        'Y07006' => ['label' => 'Subventions d\'exploitation reçues (en milliers de FCFA)', 'type' => 'montant', 'auto' => false],
        'Y07007' => ['label' => 'Subventions d\'équipement reçues (en milliers de FCFA)', 'type' => 'montant', 'auto' => false],
    ]
];
// Tableau 8.0
$sections['8.0'] = [
    'title' => 'Tableau n°8 Indicateur de performance des institutions affiliées au réseau (*)',
    'rows' => [
        'Y08001' => ['label' => 'Nombre d\'institutions affiliées déficitaires', 'type' => 'effectif', 'auto' => false],
        'Y08002' => ['label' => 'Montant total du déficit d\'exploitation des institutions affiliées (en milliers de FCFA)', 'type' => 'montant', 'auto' => false],
        'Y08003' => ['label' => 'Nombre d\'institutions affiliées excédentaires', 'type' => 'effectif', 'auto' => false],
        'Y08004' => ['label' => 'Montant total de l\'excédent d\'exploitation des institutions affiliées (en milliers de FCFA)', 'type' => 'montant', 'auto' => false],
    ]
];
// Tableau 9.0
$sections['9.0'] = [
    'title' => 'Tableau n°9 Nombre de réunion tenues au cours de l\'année',
    'rows' => [
        'Y09001' => ['label' => 'Par l\'Assemblée Générale', 'type' => 'effectif', 'auto' => false],
        'Y09002' => ['label' => 'Par le Conseil d\'Administration ou l\'organe équivalent', 'type' => 'effectif', 'auto' => false],
        'Y09003' => ['label' => 'Par le Conseil de Surveillance (*)', 'type' => 'effectif', 'auto' => false],
        'Y09004' => ['label' => 'Par le Comité de Crédit (*)', 'type' => 'effectif', 'auto' => false],
        'Y09005' => ['label' => 'Par les autres comités (**)', 'type' => 'effectif', 'auto' => false],
    ]
];
// Tableau 10.0
$sections['10.0'] = [
    'title' => 'Tableau n°10 Indicateurs de performances financières',
    'rows' => [
        'Y10001' => ['label' => 'Marge d\'intérêt en milliers de FCFA', 'type' => 'montant', 'auto' => true],
        'Y10002' => ['label' => 'Produit financier net en milliers de FCFA', 'type' => 'montant', 'auto' => true],
        'Y10003' => ['label' => 'Résultat net en milliers de FCFA', 'type' => 'montant', 'auto' => true],
        'Y10004' => ['label' => 'Taux de marge nette10', 'type' => 'montant', 'auto' => true],
    ]
];

// ============================================================
// CRÉATION DE LA TABLE ANNEXE_DATA
// ============================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS annexe_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exercice INT NOT NULL,
            indicateur VARCHAR(30) NOT NULL,
            valeur_effectif INT DEFAULT 0,
            valeur_montant DECIMAL(20,2) DEFAULT 0,
            valeur_text TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_exercice_indicateur (exercice, indicateur)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) { }

// ============================================================
// SAUVEGARDE DES DONNÉES
// ============================================================
$message = '';
$message_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO annexe_data (exercice, indicateur, valeur_effectif, valeur_montant, valeur_text)
            VALUES (:exercice, :indicateur, :effectif, :montant, :text)
            ON DUPLICATE KEY UPDATE 
                valeur_effectif = VALUES(valeur_effectif),
                valeur_montant = VALUES(valeur_montant),
                valeur_text = VALUES(valeur_text)
        ");
        foreach ($sections as $section_id => $section) {
            foreach ($section['rows'] as $code => $info) {
                $val = isset($_POST[$code]) ? $_POST[$code] : '';
                $effectif = 0; $montant = 0; $text = '';
                if ($info['type'] == 'effectif') $effectif = (int)$val;
                elseif ($info['type'] == 'montant') $montant = (float)str_replace(',', '', $val);
                elseif ($info['type'] == 'pourcentage') continue;
                else $text = $val;
                $stmt->execute([':exercice' => $exercice, ':indicateur' => $code, ':effectif' => $effectif, ':montant' => $montant, ':text' => $text]);
            }
        }
        $message = "Annexe enregistrée avec succès !";
        $message_type = "success";
    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $message_type = "error";
    }
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES EXISTANTES
// ============================================================
$data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM annexe_data WHERE exercice = :exercice");
    $stmt->execute([':exercice' => $exercice]);
    foreach ($stmt->fetchAll() as $row) {
        $data[$row['indicateur']] = $row;
    }
} catch (PDOException $e) { }

// ============================================================
// CALCUL AUTOMATIQUE (AUTO_VALUES) – identique à avant
// ============================================================
$auto_values = [];
try {
    // Clients
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients WHERE statut='actif'");
    $auto_values['Y01101'] = (int)$stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients WHERE statut='actif' AND (categorie='Personne physique' OR genre!='Morale')");
    $auto_values['Y01102'] = (int)$stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients WHERE statut='actif' AND genre='Masculin'");
    $auto_values['Y01103'] = (int)$stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients WHERE statut='actif' AND genre='Feminin'");
    $auto_values['Y01104'] = (int)$stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients WHERE statut='actif' AND (categorie IN ('Entreprise','Association') OR genre='Morale')");
    $auto_values['Y01105'] = (int)$stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients WHERE statut='actif' AND categorie='Association'");
    $auto_values['Y01106'] = (int)$stmt->fetch()['total'];
    // Personnel
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM utilisateurs WHERE role!='Client' AND etat='actif'");
    $auto_values['Y01205'] = (int)$stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM utilisateurs WHERE role IN ('Superviseur','Administrateur','Responsable') AND etat='actif'");
    $auto_values['Y01206'] = (int)$stmt->fetch()['total'];
    // Points de service
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM agences WHERE statut='active'");
    $auto_values['Y02001'] = (int)$stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM caisses WHERE statut='ouverte'");
    $auto_values['Y02002'] = (int)$stmt->fetch()['total'];
    // Dépôts
    $stmt = $pdo->query("SELECT COALESCE(SUM(c.solde),0) as total FROM comptes c JOIN produits p ON c.produit_id = p.produit_id JOIN produits_familles pf ON p.famille_id = pf.famille_id WHERE pf.categorie IN ('Epargne','DAT') AND c.statut='actif'");
    $auto_values['Y03101'] = (float)$stmt->fetch()['total'];
    $sql_phys = "SELECT COALESCE(SUM(c.solde),0) as total FROM comptes c JOIN produits p ON c.produit_id = p.produit_id JOIN produits_familles pf ON p.famille_id = pf.famille_id JOIN clients cl ON c.client_id = cl.client_id WHERE pf.categorie IN ('Epargne','DAT') AND c.statut='actif' AND cl.categorie='Personne physique'";
    $stmt = $pdo->query($sql_phys); $auto_values['Y03102'] = (float)$stmt->fetch()['total'];
    $sql_h = str_replace("cl.categorie='Personne physique'", "cl.genre='Masculin'", $sql_phys);
    $stmt = $pdo->query($sql_h); $auto_values['Y03103'] = (float)$stmt->fetch()['total'];
    $sql_f = str_replace("cl.categorie='Personne physique'", "cl.genre='Feminin'", $sql_phys);
    $stmt = $pdo->query($sql_f); $auto_values['Y03104'] = (float)$stmt->fetch()['total'];
    $sql_m = str_replace("cl.categorie='Personne physique'", "cl.categorie IN ('Entreprise','Association')", $sql_phys);
    $stmt = $pdo->query($sql_m); $auto_values['Y03105'] = (float)$stmt->fetch()['total'];
    // 3.2
    $stmt = $pdo->query("SELECT COALESCE(SUM(c.solde),0) as total FROM comptes c JOIN produits p ON c.produit_id = p.produit_id JOIN comptes_types ct ON p.type_compte_id = ct.type_id WHERE ct.nom_type = 'Compte courant' AND c.statut='actif'");
    $auto_values['Y03201'] = (float)$stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant_place),0) as total FROM comptes_dat WHERE statut='en cours'");
    $auto_values['Y03202'] = (float)$stmt->fetch()['total'];
    $auto_values['Y03203'] = $auto_values['Y03101'] - $auto_values['Y03201'] - $auto_values['Y03202'];
    // 3.3
    $stmt = $pdo->query("SELECT COUNT(DISTINCT client_id) as total FROM comptes WHERE statut='actif' AND solde>0");
    $auto_values['Y03301'] = (int)$stmt->fetch()['total'];
    $sql_dep_phys = "SELECT COUNT(DISTINCT c.client_id) as total FROM comptes c JOIN clients cl ON c.client_id = cl.client_id WHERE c.statut='actif' AND c.solde>0 AND cl.categorie='Personne physique'";
    $stmt = $pdo->query($sql_dep_phys); $auto_values['Y03302'] = (int)$stmt->fetch()['total'];
    $sql_d_h = str_replace("cl.categorie='Personne physique'", "cl.genre='Masculin'", $sql_dep_phys);
    $stmt = $pdo->query($sql_d_h); $auto_values['Y03303'] = (int)$stmt->fetch()['total'];
    $sql_d_f = str_replace("cl.categorie='Personne physique'", "cl.genre='Feminin'", $sql_dep_phys);
    $stmt = $pdo->query($sql_d_f); $auto_values['Y03304'] = (int)$stmt->fetch()['total'];
    $sql_d_m = str_replace("cl.categorie='Personne physique'", "cl.categorie IN ('Entreprise','Association')", $sql_dep_phys);
    $stmt = $pdo->query($sql_d_m); $auto_values['Y03305'] = (int)$stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COUNT(*) as total, COALESCE(SUM(solde),0) as solde FROM comptes WHERE statut='actif' AND solde=0");
    $res = $stmt->fetch(); $auto_values['Y03306'] = (int)$res['total']; $auto_values['Y03307'] = (float)$res['solde'];
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM comptes WHERE statut='actif'");
    $auto_values['Y03308'] = (int)$stmt->fetch()['total'];
    // 3.4
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant),0) as total FROM capital WHERE statut='valide'");
    $auto_values['Y03401'] = (float)$stmt->fetch()['total'];
    // Crédits
    $sql_credits = "SELECT COALESCE(SUM(montant),0) as total, COUNT(*) as nb FROM dossiers WHERE date_octroi BETWEEN :debut AND :fin AND statut IN ('actif','approuve','rembourse')";
    $stmt = $pdo->prepare($sql_credits); $stmt->execute([':debut' => $date_debut_periode, ':fin' => $date_fin_periode_sql]); $res = $stmt->fetch();
    $auto_values['Y04101'] = (float)$res['total']; $auto_values['Y04201'] = (int)$res['nb']; $auto_values['Y04206'] = ($auto_values['Y04201'] > 0) ? $auto_values['Y04101'] / $auto_values['Y04201'] : 0;
    $sql_credits_phys = "SELECT COALESCE(SUM(d.montant),0) as total, COUNT(*) as nb FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') AND cl.categorie='Personne physique'";
    $stmt = $pdo->prepare($sql_credits_phys); $stmt->execute([':debut' => $date_debut_periode, ':fin' => $date_fin_periode_sql]); $res = $stmt->fetch();
    $auto_values['Y04102'] = (float)$res['total']; $auto_values['Y04202'] = (int)$res['nb'];
    $sql_c_h = str_replace("cl.categorie='Personne physique'", "cl.genre='Masculin'", $sql_credits_phys);
    $stmt = $pdo->prepare($sql_c_h); $stmt->execute([':debut' => $date_debut_periode, ':fin' => $date_fin_periode_sql]); $res = $stmt->fetch();
    $auto_values['Y04103'] = (float)$res['total']; $auto_values['Y04203'] = (int)$res['nb'];
    $sql_c_f = str_replace("cl.categorie='Personne physique'", "cl.genre='Feminin'", $sql_credits_phys);
    $stmt = $pdo->prepare($sql_c_f); $stmt->execute([':debut' => $date_debut_periode, ':fin' => $date_fin_periode_sql]); $res = $stmt->fetch();
    $auto_values['Y04104'] = (float)$res['total']; $auto_values['Y04204'] = (int)$res['nb'];
    $sql_c_m = str_replace("cl.categorie='Personne physique'", "cl.categorie IN ('Entreprise','Association')", $sql_credits_phys);
    $stmt = $pdo->prepare($sql_c_m); $stmt->execute([':debut' => $date_debut_periode, ':fin' => $date_fin_periode_sql]); $res = $stmt->fetch();
    $auto_values['Y04105'] = (float)$res['total']; $auto_values['Y04205'] = (int)$res['nb'];
    // Encours
    $sql_encours = "SELECT COALESCE(SUM(d.montant - COALESCE(r.rembourse,0)),0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) r ON d.dossier_id = r.dossier_id WHERE d.statut IN ('actif','approuve')";
    $stmt = $pdo->query($sql_encours); $auto_values['Y04401'] = (float)$stmt->fetch()['total'];
    $sql_enc_phys = "SELECT COALESCE(SUM(d.montant - COALESCE(r.rembourse,0)),0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) r ON d.dossier_id = r.dossier_id JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id WHERE d.statut IN ('actif','approuve') AND cl.categorie='Personne physique'";
    $stmt = $pdo->query($sql_enc_phys); $auto_values['Y04402'] = (float)$stmt->fetch()['total'];
    $sql_e_h = str_replace("cl.categorie='Personne physique'", "cl.genre='Masculin'", $sql_enc_phys);
    $stmt = $pdo->query($sql_e_h); $auto_values['Y04403'] = (float)$stmt->fetch()['total'];
    $sql_e_f = str_replace("cl.categorie='Personne physique'", "cl.genre='Feminin'", $sql_enc_phys);
    $stmt = $pdo->query($sql_e_f); $auto_values['Y04404'] = (float)$stmt->fetch()['total'];
    $sql_e_m = str_replace("cl.categorie='Personne physique'", "cl.categorie IN ('Entreprise','Association')", $sql_enc_phys);
    $stmt = $pdo->query($sql_e_m); $auto_values['Y04405'] = (float)$stmt->fetch()['total'];
    // 4.5
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM dossiers WHERE statut IN ('actif','approuve')");
    $auto_values['Y04501'] = (int)$stmt->fetch()['total'];
    $sql_nb_phys = str_replace("COALESCE(SUM(d.montant - COALESCE(r.rembourse,0)),0)", "COUNT(*)", $sql_enc_phys);
    $stmt = $pdo->query($sql_nb_phys); $auto_values['Y04502'] = (int)$stmt->fetch()['total'];
    $sql_nb_h = str_replace("cl.categorie='Personne physique'", "cl.genre='Masculin'", $sql_nb_phys);
    $stmt = $pdo->query($sql_nb_h); $auto_values['Y04503'] = (int)$stmt->fetch()['total'];
    $sql_nb_f = str_replace("cl.categorie='Personne physique'", "cl.genre='Feminin'", $sql_nb_phys);
    $stmt = $pdo->query($sql_nb_f); $auto_values['Y04504'] = (int)$stmt->fetch()['total'];
    $sql_nb_m = str_replace("cl.categorie='Personne physique'", "cl.categorie IN ('Entreprise','Association')", $sql_nb_phys);
    $stmt = $pdo->query($sql_nb_m); $auto_values['Y04505'] = (int)$stmt->fetch()['total'];
    // 4.6
    $sql_ct = "SELECT COALESCE(SUM(d.montant - COALESCE(r.rembourse,0)),0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) r ON d.dossier_id = r.dossier_id WHERE d.statut IN ('actif','approuve') AND d.duree <= 12";
    $stmt = $pdo->query($sql_ct); $auto_values['Y04601'] = (float)$stmt->fetch()['total'];
    $sql_mtlt = "SELECT COALESCE(SUM(d.montant - COALESCE(r.rembourse,0)),0) as total FROM dossiers d LEFT JOIN (SELECT dossier_id, SUM(montant) as rembourse FROM echeances WHERE statut = 'payee' GROUP BY dossier_id) r ON d.dossier_id = r.dossier_id WHERE d.statut IN ('actif','approuve') AND d.duree > 12";
    $stmt = $pdo->query($sql_mtlt); $auto_values['Y04602'] = (float)$stmt->fetch()['total'];
    // 4.9
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant),0) as total FROM echeances WHERE statut='impayee' AND date_echeance < CURDATE()");
    $auto_values['Y04901'] = (float)$stmt->fetch()['total'];
    $auto_values['Y04902'] = ($auto_values['Y04401'] > 0) ? ($auto_values['Y04901'] / $auto_values['Y04401']) * 100 : 0;
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant),0) as total FROM echeances WHERE statut='payee'");
    $rembourses = (float)$stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COALESCE(SUM(montant),0) as total FROM echeances WHERE statut IN ('payee','impayee','attente')");
    $total_dus = (float)$stmt->fetch()['total'];
    $auto_values['Y04903'] = ($total_dus > 0) ? ($rembourses / $total_dus) * 100 : 0;
    // 6.1
    $stmt = $pdo->query("SELECT MIN(valeur) as min_val, MAX(valeur) as max_val FROM regles_taux WHERE type='pourcentage' AND (nom LIKE '%crédit%' OR nom LIKE '%taux crédit%')");
    $res = $stmt->fetch(); $auto_values['Y06103'] = (float)$res['min_val']; $auto_values['Y06104'] = (float)$res['max_val'];
    $stmt = $pdo->query("SELECT MIN(valeur) as min_val, MAX(valeur) as max_val FROM regles_taux WHERE type='pourcentage' AND (nom LIKE '%épargne%' OR nom LIKE '%dépôt%')");
    $res = $stmt->fetch(); $auto_values['Y06101'] = (float)$res['min_val']; $auto_values['Y06102'] = (float)$res['max_val'];
    // 6.4
    $sql_sect = "SELECT s.nom, COALESCE(SUM(d.montant),0) as total FROM dossiers d JOIN comptes c ON d.compte_id = c.compte_id JOIN clients cl ON c.client_id = cl.client_id JOIN secteurs s ON cl.secteur_id = s.secteur_id WHERE d.date_octroi BETWEEN :debut AND :fin AND d.statut IN ('actif','approuve','rembourse') GROUP BY s.secteur_id";
    $stmt = $pdo->prepare($sql_sect); $stmt->execute([':debut' => $date_debut_periode, ':fin' => $date_fin_periode_sql]);
    $secteurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $secteur_map = ['Y06401' => 'Agriculture', 'Y06402' => 'Industries extractives', 'Y06403' => 'Industries manufacturières', 'Y06404' => 'Bâtiment', 'Y06405' => 'Commerce', 'Y06406' => 'Électricité', 'Y06407' => 'Transports', 'Y06408' => 'Assurances', 'Y06409' => 'Immobilier', 'Y06410' => 'Services divers'];
    foreach ($secteur_map as $code => $lib) {
        $found = false;
        foreach ($secteurs as $s) { if (stripos($s['nom'], $lib) !== false) { $auto_values[$code] = (float)$s['total']; $found = true; break; } }
        if (!$found) $auto_values[$code] = 0;
    }
    // 10.0
    $sql_prod = "SELECT COALESCE(SUM(montant_credit - montant_debit),0) as total FROM ecritures_comptables WHERE compte_general LIKE '7%' AND date_ecriture BETWEEN :debut AND :fin";
    $stmt = $pdo->prepare($sql_prod); $stmt->execute([':debut' => $date_debut_periode, ':fin' => $date_fin_periode_sql]); $produits = (float)$stmt->fetch()['total'];
    $sql_charg = "SELECT COALESCE(SUM(montant_debit - montant_credit),0) as total FROM ecritures_comptables WHERE compte_general LIKE '6%' AND date_ecriture BETWEEN :debut AND :fin";
    $stmt = $pdo->prepare($sql_charg); $stmt->execute([':debut' => $date_debut_periode, ':fin' => $date_fin_periode_sql]); $charges = (float)$stmt->fetch()['total'];
    $auto_values['Y10001'] = $produits - $charges; $auto_values['Y10002'] = $produits - $charges; $auto_values['Y10003'] = $produits - $charges;
    $auto_values['Y10004'] = ($produits > 0) ? ($auto_values['Y10003'] / $produits) * 100 : 0;
} catch (PDOException $e) { }

// ============================================================
// FONCTIONS DE RÉCUPÉRATION
// ============================================================
function getValue($code, $type, $data) {
    global $auto_values;
    if (isset($auto_values[$code])) {
        $val = $auto_values[$code];
        if ($type == 'effectif') return (int)$val;
        if ($type == 'montant') return (float)$val;
        return (string)$val;
    }
    if (isset($data[$code])) {
        if ($type == 'effectif') return (int)$data[$code]['valeur_effectif'];
        if ($type == 'montant') return (float)$data[$code]['valeur_montant'];
        return (string)$data[$code]['valeur_text'];
    }
    if ($type == 'effectif' || $type == 'montant') return 0;
    return '';
}
function calcPourcentage($code, $data) {
    global $auto_values;
    $v1 = (isset($auto_values['Y03201'])) ? $auto_values['Y03201'] : getValue('Y03201', 'montant', $data);
    $v2 = (isset($auto_values['Y03202'])) ? $auto_values['Y03202'] : getValue('Y03202', 'montant', $data);
    $v3 = (isset($auto_values['Y03203'])) ? $auto_values['Y03203'] : getValue('Y03203', 'montant', $data);
    $total = $v1 + $v2 + $v3;
    if ($total == 0) return 0;
    if ($code == 'Y03201_PART') return ($v1 / $total) * 100;
    if ($code == 'Y03202_PART') return ($v2 / $total) * 100;
    if ($code == 'Y03203_PART') return ($v3 / $total) * 100;
    return 0;
}

// ============================================================
// CONSTRUCTION DU FLUX DOCUMENTAIRE (reproduit ann.xlsx)
// ============================================================
$document_flow = [];

// 1. En-tête général
$document_flow[] = ['type' => 'main_title', 'text' => 'INSTRUCTION NO 018-12-2010 DU 29 décembre 2010'];

// 2. Tableau 1.1
$document_flow[] = ['type' => 'table', 'ref' => '1.1'];

// 3. Tableau 1.2
$document_flow[] = ['type' => 'table', 'ref' => '1.2'];
$document_flow[] = ['type' => 'footnote', 'text' => '(*) A renseigner par les institutions coopératives ou mutualistes d\'épargne et de crédit'];
$document_flow[] = ['type' => 'footnote', 'text' => '(**) A préciser'];

// 4. Sous-section 1.3
$document_flow[] = ['type' => 'sub_section', 'text' => '1.3 Données sur la gouvernance'];
$document_flow[] = ['type' => 'table', 'ref' => '1.3.1'];
$document_flow[] = ['type' => 'footnote', 'text' => '1 Salaires, appointements, indemnités, gratifications et primes occasionnelles ou périodiques versées au personnel, les rémunérations des administrateurs salariés, les cotisations aux régimes de retraite, etc'];
$document_flow[] = ['type' => 'table', 'ref' => '1.3.2'];
$document_flow[] = ['type' => 'footnote', 'text' => '2 s\'applique aux sociétés (SA, SARL)'];

// 5. Section II
$document_flow[] = ['type' => 'section_title', 'text' => 'II. DONNEES SUR LES POINTS DE SERVICE'];
$document_flow[] = ['type' => 'table', 'ref' => '2.0'];

// 6. Section III
$document_flow[] = ['type' => 'section_title', 'text' => 'III. DONNEES SUR LES OPERATIONS DE COLLECTE DE DEPÔTS'];
$document_flow[] = ['type' => 'table', 'ref' => '3.1'];
$document_flow[] = ['type' => 'table', 'ref' => '3.2'];
$document_flow[] = ['type' => 'table', 'ref' => '3.3'];
$document_flow[] = ['type' => 'table', 'ref' => '3.4'];
$document_flow[] = ['type' => 'footnote', 'text' => '* Pour les sociétés de capitaux'];
$document_flow[] = ['type' => 'sub_section', 'text' => '3.5 Tableau 3.5 Répartition du Capital social entre les principaux actionnaires'];
$document_flow[] = ['type' => 'table', 'ref' => '3.5'];

// 7. Section IV
$document_flow[] = ['type' => 'section_title', 'text' => 'IV. DONNEES SUR LES CREDITS (PRETS ET ENGAGEMENTS PAR SIGNATURE)'];
$document_flow[] = ['type' => 'table', 'ref' => '4.1'];
$document_flow[] = ['type' => 'footnote', 'text' => '* Il s\'agit du montant des prêts accordé dans l\'année'];
$document_flow[] = ['type' => 'table', 'ref' => '4.2'];
$document_flow[] = ['type' => 'table', 'ref' => '4.3'];
$document_flow[] = ['type' => 'table', 'ref' => '4.4'];
$document_flow[] = ['type' => 'table', 'ref' => '4.5'];
$document_flow[] = ['type' => 'table', 'ref' => '4.6'];
$document_flow[] = ['type' => 'sub_section', 'text' => '4.7 Tableau 4.7 Encours des crédits des agents relevant des Autorités de contrôle (Ministère chargé des Finances, BCEAO et Commission Bancaire de l\'UMOA)'];
$document_flow[] = ['type' => 'table', 'ref' => '4.7'];
$document_flow[] = ['type' => 'table', 'ref' => '4.8'];
$document_flow[] = ['type' => 'table', 'ref' => '4.9'];
$document_flow[] = ['type' => 'footnote', 'text' => '3 – rapport entre l\'encours brut des créances en souffrance et le total de l\'encours brut des crédits'];
$document_flow[] = ['type' => 'footnote', 'text' => '4 – rapport entre les échéances remboursées et le montant attendu au cours de l\'année'];
$document_flow[] = ['type' => 'footnote', 'text' => '5 – rapport entre le montant des créances en souffrance recouvrées et le montant total des créances en souffrance'];
$document_flow[] = ['type' => 'footnote', 'text' => '6 – rapport entre l\'encours brut des créances en souffrance sur ressources affectées et le montant total de l\'encours brut des crédits sur ressources affectées'];
$document_flow[] = ['type' => 'footnote', 'text' => '7 - rapport entre le montant des échéances des crédits sur ressources affectées effectivement remboursées et le total des échéances attendues sur les crédits sur ressources affectées'];
$document_flow[] = ['type' => 'footnote', 'text' => '8 – rapport entre le montant recouvré sur créances en souffrance sur ressources affectées et le total des créances en souffrance sur ressources affectées'];
$document_flow[] = ['type' => 'footnote', 'text' => '9 – rapport entre le montant des crédits passés en perte et le total de l\'encours des crédits de la période'];

// 8. Section V
$document_flow[] = ['type' => 'section_title', 'text' => 'V. DONNEES SUR LES AUTRES ACTIVITES AUTORISEES'];
$document_flow[] = ['type' => 'sub_section', 'text' => '5.1 Activités de transfert rapide d\'argent'];
$document_flow[] = ['type' => 'table', 'ref' => '5.1.1'];
$document_flow[] = ['type' => 'table', 'ref' => '5.1.2'];
$document_flow[] = ['type' => 'sub_section', 'text' => '5.2 Activités de micro assurance'];
$document_flow[] = ['type' => 'table', 'ref' => '5.2.1'];
$document_flow[] = ['type' => 'table', 'ref' => '5.2.2'];
$document_flow[] = ['type' => 'sub_section', 'text' => '5.3 Tableau 5.3 Opérations de change'];
$document_flow[] = ['type' => 'table', 'ref' => '5.3'];

// 9. Section VI
$document_flow[] = ['type' => 'section_title', 'text' => 'VI. AUTRES INFORMATIONS SUR LES OPÉRATIONS AVEC LA CLIENTÈLE'];
$document_flow[] = ['type' => 'table', 'ref' => '6.1'];
$document_flow[] = ['type' => 'footnote', 'text' => '(*) : Communiquer le taux d\'intérêt annuel'];
$document_flow[] = ['type' => 'footnote', 'text' => '(**) : Indiquer le mode de détermination'];
$document_flow[] = ['type' => 'table', 'ref' => '6.2'];
$document_flow[] = ['type' => 'sub_section', 'text' => '6.3 Tableau 6.3 Dons et œuvres sociales'];
$document_flow[] = ['type' => 'table', 'ref' => '6.3'];
$document_flow[] = ['type' => 'table', 'ref' => '6.4'];
$document_flow[] = ['type' => 'footnote', 'text' => '(*) La sectorisation retenue dans ce tableau est celle prévue par le référentiel comptable spécifique des SFD'];

// 10. Section VII
$document_flow[] = ['type' => 'section_title', 'text' => 'VII. OPERATIONS AVEC LES AUTRES INSTITUTIONS FINANCIERES'];
$document_flow[] = ['type' => 'table', 'ref' => '7.0'];

// 11. Section VIII
$document_flow[] = ['type' => 'section_title', 'text' => 'VIII. DONNEES SUR LA PERFORMANCE DES MEMBRES DES RESEAUX (UNIONS, FEDERATIONS ET CONFEDERATIONS)'];
$document_flow[] = ['type' => 'table', 'ref' => '8.0'];
$document_flow[] = ['type' => 'footnote', 'text' => '(*) Tableau à renseigner par les structures faîtières'];

// 12. Section IX
$document_flow[] = ['type' => 'section_title', 'text' => 'IX. FONCTIONNEMENT ET VIE DES ORGANES'];
$document_flow[] = ['type' => 'table', 'ref' => '9.0'];
$document_flow[] = ['type' => 'footnote', 'text' => '(**) A renseigner par les institutions mutualistes ou coopératives d\'épargne et de crédit'];

// 13. Section X
$document_flow[] = ['type' => 'section_title', 'text' => 'X. PERFORMANCES FINANCIERES'];
$document_flow[] = ['type' => 'table', 'ref' => '10.0'];

// ============================================================
// EXPORT PDF (utilise le même flux documentaire)
// ============================================================
if ($format === 'pdf') {
    if (ob_get_length()) ob_end_clean();
    class PDF_DIMF extends FPDF {
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
            $this->Cell(0, 7, $this->convert('ANNEXE AU RAPPORT ANNUEL'), 0, 1, 'L');
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(255, 255, 255);
            $this->SetX(8);
            $this->Cell(0, 5, $this->convert('Instruction n°018-12-2010 du 29 decembre 2010'), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->Ln(10);
        }
        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, $this->convert('Page ' . $this->PageNo() . '/{nb} - Genere le ' . date('d/m/Y H:i:s')), 0, 0, 'C');
        }
        function SectionTitle($label) {
            $this->SetFont('Arial', 'B', 11);
            $this->SetFillColor(0, 0, 0);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 8, $this->convert($label), 0, 1, 'L', true);
            $this->Ln(2);
        }
        function SubSection($label) {
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(0, 0, 0);
            $this->Cell(0, 6, $this->convert($label), 0, 1, 'L');
            $this->Ln(1);
        }
        function Footnote($text) {
            $this->SetFont('Arial', 'I', 7);
            $this->SetTextColor(50, 50, 50);
            $this->MultiCell(0, 4, $this->convert($text));
            $this->Ln(1);
        }
        function TableHeader($cols) {
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(240, 240, 240);
            $this->SetTextColor(0, 0, 0);
            foreach ($cols as $col) {
                $this->Cell($col['w'], 7, $this->convert($col['label']), 1, 0, $col['align'] ?? 'L', true);
            }
            $this->Ln();
        }
        function TableRow($cols, $data) {
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(0, 0, 0);
            foreach ($cols as $i => $col) {
                $val = $data[$i] ?? '';
                $this->Cell($col['w'], 6, $this->convert($val), 1, 0, $col['align'] ?? 'L');
            }
            $this->Ln();
        }
        function montant($val) {
            return number_format((float)$val, 0, ',', ' ') . ' F';
        }
        function RenderTable($ref) {
            global $sections, $data;
            if (!isset($sections[$ref])) return;
            $section = $sections[$ref];
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(0, 6, $this->convert($section['title']), 0, 1, 'L');
            $cols = [['w' => 100, 'label' => 'INDICATEUR', 'align' => 'L'], ['w' => 70, 'label' => 'VALEUR', 'align' => 'R']];
            $this->TableHeader($cols);
            foreach ($section['rows'] as $code => $info) {
                $val = '';
                if ($info['type'] == 'effectif') {
                    $v = (int)getValue($code, 'effectif', $data);
                    $val = number_format($v, 0, ',', ' ');
                } elseif ($info['type'] == 'montant') {
                    $v = (float)getValue($code, 'montant', $data);
                    $val = $this->montant($v);
                } elseif ($info['type'] == 'pourcentage') {
                    $v = (float)calcPourcentage($code, $data);
                    $val = number_format($v, 2, ',', ' ') . ' %';
                } else {
                    $val = (string)getValue($code, 'text', $data);
                }
                $this->TableRow($cols, [$code . ' - ' . $info['label'], $val]);
            }
            $this->Ln(3);
        }
    }
    $pdf = new PDF_DIMF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(10, 35, 10);
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, $pdf->convert('Periode : ' . $lib_periode), 0, 1, 'C');
    $pdf->Ln(5);

    foreach ($document_flow as $block) {
        if ($block['type'] == 'main_title') {
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 8, $pdf->convert($block['text']), 0, 1, 'C');
            $pdf->Ln(4);
        } elseif ($block['type'] == 'section_title') {
            $pdf->SectionTitle($block['text']);
        } elseif ($block['type'] == 'sub_section') {
            $pdf->SubSection($block['text']);
        } elseif ($block['type'] == 'table') {
            $pdf->RenderTable($block['ref']);
        } elseif ($block['type'] == 'footnote') {
            $pdf->Footnote($block['text']);
        }
    }
    $pdf->Output('I', 'ANNEXE_RAPPORT_' . $exercice . '.pdf');
    exit;
}

// ============================================================
// AFFICHAGE HTML AVEC LE FLUX DOCUMENTAIRE
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>ANNEX_RAPP_AN - Annexe au rapport annuel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background: #f1f5f9; padding: 24px; }
        .dashboard { max-width: 1400px; margin: 0 auto; }
        .page-header { background: linear-gradient(135deg, #3b82f6, #60a5fa); border-radius: 24px; padding: 20px 28px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .header-left h1 { font-size: 1.6rem; font-weight: 600; color: white; display: flex; align-items: center; gap: 10px; }
        .subtitle { font-size: 0.8rem; color: #e0f2fe; }
        .badge-custom { display: inline-block; background: #2563eb; color: white; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem; font-weight: 500; margin-top: 8px; }
        .btn-group { display: flex; gap: 12px; flex-wrap: wrap; }
        .btn-excel, .btn-pdf { display: inline-flex; align-items: center; gap: 8px; padding: 8px 20px; border-radius: 40px; font-weight: 500; font-size: 0.85rem; border: none; cursor: pointer; transition: 0.2s; }
        .btn-excel { background: #10b981; color: white; }
        .btn-excel:hover { background: #059669; transform: translateY(-1px); }
        .btn-pdf { background: #ef4444; color: white; }
        .btn-pdf:hover { background: #dc2626; transform: translateY(-1px); }
        .btn-save { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 12px 28px; font-weight: 500; font-size: 0.9rem; cursor: pointer; transition: 0.2s; }
        .btn-save:hover { background: #2563eb; transform: translateY(-1px); }
        .card { background: white; border-radius: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px; overflow: hidden; }
        .card-header { display: flex; align-items: center; gap: 10px; padding: 16px 24px; background: #f8fafc; border-bottom: 1px solid #eef2f6; font-weight: 600; color: #1e40af; }
        .card-body { padding: 20px 24px; }
        .filters-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 20px; }
        .filter-item { display: flex; flex-direction: column; gap: 6px; }
        .filter-item label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: #4b5563; }
        .filter-item select { background: white; border: 1px solid #d1d5db; border-radius: 12px; padding: 8px 14px; font-size: 0.85rem; }
        .btn-apply { background: #3b82f6; color: white; border: none; border-radius: 40px; padding: 8px 24px; font-weight: 500; cursor: pointer; transition: 0.2s; }
        .btn-apply:hover { background: #2563eb; transform: translateY(-1px); }
        .info-box { background: #eef2ff; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .alert { padding: 14px 20px; border-radius: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #ecfdf5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border-left: 4px solid #ef4444; }
        .auto-badge { font-size: 0.7rem; background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 20px; margin-left: 6px; white-space: nowrap; }
        .annex-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 8px; }
        .annex-table th { background: #f1f5f9; border: 1px solid #d1d5db; padding: 8px 10px; text-align: left; font-weight: 700; color: #1e293b; }
        .annex-table td { border: 1px solid #d1d5db; padding: 6px 10px; vertical-align: middle; }
        .annex-table .code-col { width: 12%; font-weight: 600; color: #0f172a; font-family: monospace; }
        .annex-table .label-col { width: 63%; }
        .annex-table .value-col { width: 25%; text-align: right; }
        .annex-table .value-col input { width: 100%; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem; text-align: right; background: white; }
        .annex-table .value-col input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }
        .annex-table .value-col input.calculated-value { background: #f0fdf4; border-color: #86efac; }
        .annex-table .value-col input.readonly-value { background: #f3f4f6; color: #6b7280; cursor: not-allowed; }
        .section-title-block { background: #1e293b; color: white; padding: 8px 14px; border-radius: 6px; font-weight: 700; font-size: 1.1rem; margin: 20px 0 12px 0; }
        .sub-section-block { font-weight: 700; font-size: 1rem; color: #0f172a; margin: 16px 0 8px 0; padding-left: 4px; border-left: 4px solid #3b82f6; padding-left: 10px; }
        .footnote-block { font-style: italic; font-size: 0.75rem; color: #4b5563; padding: 4px 0 4px 8px; border-bottom: 1px dashed #e2e8f0; }
        .main-title-block { font-weight: 800; font-size: 1.2rem; text-align: center; color: #0f172a; margin: 16px 0 20px 0; }
        .table-title-block { font-weight: 700; background: #e2e8f0; padding: 6px 12px; border: 1px solid #d1d5db; border-bottom: 2px solid #94a3b8; margin-top: 12px; }
        .footer { text-align: center; font-size: 0.75rem; color: #6b7280; margin-top: 16px; padding: 16px; }
        @media (max-width: 768px) {
            .annex-table .code-col { width: 18%; }
            .annex-table .label-col { width: 52%; }
            .annex-table .value-col { width: 30%; }
            body { padding: 12px; }
            .filters-row { flex-direction: column; }
        }
        @media print { .btn-group, .filters-row, .btn-save, .alert, .info-box { display: none !important; } }
    </style>
</head>
<body>
<div class="dashboard">
    <!-- EN-TÊTE -->
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-file-alt"></i> ANNEXE AU RAPPORT ANNUEL</h1>
            <div class="subtitle">Republique de Cote d'Ivoire / Ministere de l'Economie et des Finances – DGTCP / DSFD</div>
            <div class="badge-custom">Instruction n°018-12-2010 du 29 decembre 2010</div>
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

    <!-- FILTRES -->
    <div class="card" id="filtersCard">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
            <form method="post" id="filterForm">
                <div class="filters-row">
                    <div class="filter-item"><label>Annee</label><select name="exercice" id="exerciceSelect"><?php for ($y = 2020; $y <= date('Y')+1; $y++): ?><option value="<?= $y ?>" <?= $y==$exercice?'selected':'' ?>><?= $y ?></option><?php endfor; ?></select></div>
                    <div class="filter-item"><label>Type de periode</label><select name="type_periode" id="typePeriodeSelect"><option value="mensuel" <?= $type_periode=='mensuel'?'selected':'' ?>>Mensuel</option><option value="trimestre" <?= $type_periode=='trimestre'?'selected':'' ?>>Trimestre</option><option value="semestre" <?= $type_periode=='semestre'?'selected':'' ?>>Semestre</option><option value="annuel" <?= $type_periode=='annuel'?'selected':'' ?>>Annuel</option></select></div>
                    <div class="filter-item" id="dynamicSelectContainer"></div>
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
                <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;"><i class="fas fa-info-circle"></i> Periode : <?= $lib_periode ?> (arrete au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)</div>
            </form>
        </div>
    </div>

    <?php if($message): ?><div class="alert alert-<?= $message_type ?>"><i class="fas fa-<?= $message_type == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <div class="info-box"><i class="fas fa-info-circle"></i><div><strong>Note :</strong> Les champs marqués <span class="badge bg-primary">⚡ auto</span> sont pré-remplis automatiquement. Les pourcentages du tableau 3.2 sont calculés automatiquement.</div></div>

    <!-- FORMULAIRE PRINCIPAL -->
    <form method="post" action="">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="exercice" value="<?= $exercice ?>">
        <input type="hidden" name="type_periode" value="<?= $type_periode ?>">
        <input type="hidden" name="mois" value="<?= $mois ?>">
        <input type="hidden" name="trimestre" value="<?= $trimestre ?>">
        <input type="hidden" name="semestre" value="<?= $semestre ?>">

        <?php
        // Fonction locale pour afficher un tableau
        function renderTableHTML($ref, $sections, $data) {
            if (!isset($sections[$ref])) return;
            $section = $sections[$ref];
            echo '<div class="table-title-block">' . htmlspecialchars($section['title']) . '</div>';
            echo '<table class="annex-table"><thead><tr><th style="width:12%;">Code</th><th style="width:63%;">Indicateurs</th><th style="width:25%;">Année (n)</th></tr></thead><tbody>';
            foreach ($section['rows'] as $code => $info) {
                echo '<tr>';
                echo '<td class="code-col">' . $code . '</td>';
                echo '<td class="label-col">' . htmlspecialchars($info['label']);
                if (isset($info['auto']) && $info['auto']) echo ' <span class="auto-badge"><i class="fas fa-bolt"></i> auto</span>';
                echo '</td>';
                echo '<td class="value-col">';
                $class = '';
                if ($info['type'] == 'effectif') {
                    $v = (int)getValue($code, 'effectif', $data);
                    if (isset($info['auto']) && $info['auto']) $class = 'calculated-value';
                    echo '<input type="number" name="' . $code . '" id="' . $code . '" value="' . number_format($v, 0, '', '') . '" class="' . $class . '">';
                } elseif ($info['type'] == 'montant') {
                    $v = (float)getValue($code, 'montant', $data);
                    if (isset($info['auto']) && $info['auto']) $class = 'calculated-value';
                    echo '<input type="number" step="0.01" name="' . $code . '" id="' . $code . '" value="' . number_format($v, 0, '', '') . '" class="' . $class . '">';
                } elseif ($info['type'] == 'pourcentage') {
                    $v = (float)calcPourcentage($code, $data);
                    $class = 'readonly-value';
                    echo '<input type="text" id="' . $code . '" value="' . number_format($v, 2, ',', ' ') . ' %" class="' . $class . '" readonly>';
                } else {
                    $v = (string)getValue($code, 'text', $data);
                    echo '<input type="text" name="' . $code . '" id="' . $code . '" value="' . htmlspecialchars($v) . '">';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }

        // Parcours du flux documentaire
        foreach ($document_flow as $block) {
            if ($block['type'] == 'main_title') {
                echo '<div class="main-title-block">' . htmlspecialchars($block['text']) . '</div>';
            } elseif ($block['type'] == 'section_title') {
                echo '<div class="section-title-block">' . htmlspecialchars($block['text']) . '</div>';
            } elseif ($block['type'] == 'sub_section') {
                echo '<div class="sub-section-block">' . htmlspecialchars($block['text']) . '</div>';
            } elseif ($block['type'] == 'table') {
                renderTableHTML($block['ref'], $sections, $data);
            } elseif ($block['type'] == 'footnote') {
                echo '<div class="footnote-block">' . htmlspecialchars($block['text']) . '</div>';
            }
        }
        ?>

        <div style="text-align: center; margin: 30px 0;">
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer l'annexe</button>
        </div>
    </form>

    <div class="footer"><i class="fas fa-calendar-alt"></i> Document genere le <?= date('d/m/Y a H:i:s') ?> - Donnees extraites de la base<br>Periode : <?= $exercice ?> - <?= $lib_periode ?></div>
</div>

<script>
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

    function exporterExcel() {
        const wb = XLSX.utils.book_new();
        const data = [];

        // En-tête
        data.push(['ANNEXE AU RAPPORT ANNUEL']);
        data.push(['Periode : ' + <?= json_encode($lib_periode) ?>]);
        data.push([]);

        <?php
        // Génération des données depuis le flux documentaire avec json_encode
        foreach ($document_flow as $block) {
            if ($block['type'] == 'main_title' || $block['type'] == 'section_title' || $block['type'] == 'sub_section') {
                echo "data.push([" . json_encode($block['text']) . "]);\n";
            } elseif ($block['type'] == 'table') {
                $ref = $block['ref'];
                if (isset($sections[$ref])) {
                    echo "data.push([" . json_encode($sections[$ref]['title']) . "]);\n";
                    echo "data.push(['Code', 'INDICATEUR', 'VALEUR']);\n";
                    foreach ($sections[$ref]['rows'] as $code => $info) {
                        $val = '';
                        if ($info['type'] == 'effectif') {
                            $v = (int)getValue($code, 'effectif', $data);
                            $val = $v;
                        } elseif ($info['type'] == 'montant') {
                            $v = (float)getValue($code, 'montant', $data);
                            $val = $v;
                        } elseif ($info['type'] == 'pourcentage') {
                            $v = (float)calcPourcentage($code, $data);
                            $val = number_format($v, 2, ',', ' ') . ' %';
                        } else {
                            $val = (string)getValue($code, 'text', $data);
                        }
                        echo "data.push([" . json_encode($code) . ", " . json_encode($info['label']) . ", " . json_encode($val) . "]);\n";
                    }
                }
            } elseif ($block['type'] == 'footnote') {
                echo "data.push([" . json_encode($block['text']) . "]);\n";
            }
            echo "data.push([]);\n";
        }
        ?>

        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), "ANNEXE_RAPPORT");
        XLSX.writeFile(wb, 'ANNEXE_RAPPORT_<?= json_encode($exercice) ?>.xlsx');
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        // Recalcul auto des pourcentages 3.2
        const inputs = document.querySelectorAll('input[name^="Y0320"]');
        inputs.forEach(input => {
            input.addEventListener('input', function() {
                const val1 = parseFloat(document.querySelector('input[name="Y03201"]')?.value) || 0;
                const val2 = parseFloat(document.querySelector('input[name="Y03202"]')?.value) || 0;
                const val3 = parseFloat(document.querySelector('input[name="Y03203"]')?.value) || 0;
                const total = val1 + val2 + val3;
                document.getElementById('Y03201_PART').value = (total > 0 ? (val1/total*100) : 0).toFixed(2) + ' %';
                document.getElementById('Y03202_PART').value = (total > 0 ? (val2/total*100) : 0).toFixed(2) + ' %';
                document.getElementById('Y03203_PART').value = (total > 0 ? (val3/total*100) : 0).toFixed(2) + ' %';
            });
        });
    });
</script>
</body>
</html>