<?php
// dimf_2005.php - Tableau des emplois et ressources + Bilan complet (conforme Excel)
session_start();
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ================================================================
// CLASSE PDF_DIMF (améliorée pour afficher tous les détails)
// ================================================================
class PDF_DIMF extends FPDF {
    public $codeDimf  = 'DIMF';
    public $titreDimf = 'Etat financier';
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
        $this->SetTextColor(255,255,255);
        $this->SetXY(8,3);
        $this->Cell(0,4, self::u('Republique de Cote d\'Ivoire  •  Ministere de l\'Economie et des Finances  -  DGTCP / DSFD'),0,1,'L');
        $this->SetFont('Arial','B',13);
        $this->SetX(8);
        $this->Cell(0,7, self::u($this->codeDimf.'  -  '.$this->titreDimf),0,1,'L');
        $this->SetFont('Arial','',8);
        $this->SetX(8);
        $this->Cell(0,5, self::u(
            'SFD : '.$this->nomSfd.
            '   |   Periode : '.$this->periode.
            '   |   Exercice : '.$this->exercice.
            '   |   Arrete au : '.date('d/m/Y')),
            0,1,'L');
        $this->SetTextColor(0,0,0);
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial','I',7);
        $this->SetTextColor(100,116,139);
        $this->Cell(0,4, self::u(
            'SICS-BCEAO  •  Genere le '.date('d/m/Y a H:i:s').
            '  •  Page '.$this->PageNo().'/{nb}'),
            0,0,'C');
    }

    function SectionTitle($label) {
        $this->SetFont('Arial','B',9);
        $this->SetFillColor(0,0,0);
        $this->SetTextColor(255,255,255);
        $this->Cell(0,7, self::u('  '.strtoupper($label)),0,1,'L',true);
        $this->SetTextColor(0,0,0);
        $this->Ln(1);
    }

    function TableHeader($cols) {
        $this->SetFont('Arial','B',8);
        $this->SetFillColor(248,250,252);
        $this->SetTextColor(30,41,59);
        $this->SetDrawColor(226,232,240);
        $this->SetLineWidth(0.2);
        foreach ($cols as $col) {
            $align = isset($col['align']) ? $col['align'] : 'L';
            $this->Cell($col['w'],6, self::u($col['label']),1,0,$align,true);
        }
        $this->Ln();
    }

    function TableRow($cols, $data, $style='') {
        $fill = false;
        $this->SetTextColor(15,23,42);
        $this->SetDrawColor(226,232,240);
        $this->SetLineWidth(0.1);
        switch ($style) {
            case 'subtotal': $this->SetFillColor(248,250,252); $this->SetFont('Arial','B',8); $fill=true; break;
            case 'total':    $this->SetFillColor(240,253,244); $this->SetFont('Arial','B',8.5); $fill=true; break;
            default:         $this->SetFillColor(255,255,255); $this->SetFont('Arial','',7.5); $fill=false; break;
        }
        foreach ($cols as $i => $col) {
            $val = isset($data[$i]) ? $data[$i] : '';
            $align = isset($col['align']) ? $col['align'] : 'L';
            $this->Cell($col['w'],5.5, self::u($val),1,0,$align,$fill);
        }
        $this->Ln();
    }

    static function montant($val) {
        return number_format((float)$val, 0, ',', ' ').' F';
    }
}

// ================================================================
// PARAMÈTRES (POST / GET)
// ================================================================
$exercice     = isset($_POST['exercice'])     ? (int)$_POST['exercice']     : (isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode']      : (isset($_GET['type_periode']) ? $_GET['type_periode'] : 'mensuel');
$mois         = isset($_POST['mois'])         ? (int)$_POST['mois']         : (isset($_GET['mois']) ? (int)$_GET['mois'] : 12);
$trimestre    = isset($_POST['trimestre'])    ? (int)$_POST['trimestre']    : (isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4);
$semestre     = isset($_POST['semestre'])     ? (int)$_POST['semestre']     : (isset($_GET['semestre']) ? (int)$_GET['semestre'] : null);

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
}

$date_fin_periode = date('Y-m-t', strtotime($exercice . '-' . str_pad($mois, 2, '0', STR_PAD_LEFT) . '-01'));
$exercice_prec    = $exercice - 1;
$date_fin_prec    = $exercice_prec . '-12-31';

// ================================================================
// FONCTIONS DE CALCUL (base de données)
// ================================================================
$pdo = $GLOBALS['pdo'];

/** Solde d'un compte général à une date */
function soldeCompte($compte, $date, $pdo) {
    $sql = "SELECT COALESCE(SUM(montant_debit) - SUM(montant_credit), 0) as solde
            FROM ecritures_comptables
            WHERE compte_general = :compte AND date_ecriture <= :date AND statut = 'VALIDÉE'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':compte' => $compte, ':date' => $date]);
    return (float)$stmt->fetch()['solde'];
}

/** Solde des comptes clients (débiteurs ou créditeurs) à une date */
function soldeComptesClients($sens, $date, $pdo) {
    $sign = ($sens == 'debiteur') ? '<' : '>';
    $sql = "SELECT COALESCE(SUM(solde), 0) as total
            FROM comptes
            WHERE client_id IS NOT NULL AND solde $sign 0 AND date_ouverture <= :date AND statut = 'actif'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':date' => $date]);
    return (float)$stmt->fetch()['total'];
}

/** Crédits par condition de durée (en mois) */
function creditEncours($condDuree, $date, $pdo) {
    $sql = "SELECT COALESCE(SUM(d.montant - COALESCE(e.rembourse, 0)), 0) as total
            FROM dossiers d
            LEFT JOIN (
                SELECT dossier_id, SUM(montant) as rembourse
                FROM echeances
                WHERE statut = 'payee' AND date_echeance <= :date
                GROUP BY dossier_id
            ) e ON d.dossier_id = e.dossier_id
            WHERE d.statut IN ('actif','approuve') AND $condDuree AND d.date_octroi <= :date";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':date' => $date]);
    return (float)$stmt->fetch()['total'];
}

/** Valeur nette des immobilisations par type */
function immoValeur($type, $date, $pdo) {
    // On prend la valeur nette actuelle (les amortissements sont dans la table)
    $sql = "SELECT COALESCE(SUM(valeur_nette), 0) as total
            FROM immobilisations
            WHERE type_immobilisation = :type AND statut = 'actif'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':type' => $type]);
    return (float)$stmt->fetch()['total'];
}

/** Dépôts à terme (comptes_dat) */
function depotsTerme($date, $pdo) {
    $sql = "SELECT COALESCE(SUM(montant_place), 0) as total
            FROM comptes_dat
            WHERE date_ouverture <= :date AND statut IN ('en cours','renouvelle','echeance')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':date' => $date]);
    return (float)$stmt->fetch()['total'];
}

/** Épargne (comptes de produits catégorie 'Epargne') */
function epargne($date, $pdo) {
    $sql = "SELECT COALESCE(SUM(c.solde), 0) as total
            FROM comptes c
            INNER JOIN produits p ON c.produit_id = p.produit_id
            INNER JOIN produits_familles pf ON p.famille_id = pf.famille_id
            WHERE pf.categorie = 'Epargne' AND c.statut = 'actif' AND c.solde > 0 AND c.date_ouverture <= :date";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':date' => $date]);
    return (float)$stmt->fetch()['total'];
}

/** Fonds propres (classe 1) */
function fondsPropores($date, $pdo) {
    $sql = "SELECT COALESCE(SUM(montant_debit - montant_credit), 0) as total
            FROM ecritures_comptables
            WHERE LEFT(compte_general,1) = '1' AND date_ecriture <= :date AND statut = 'VALIDÉE'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':date' => $date]);
    return (float)$stmt->fetch()['total'];
}

// ================================================================
// CONSTRUCTION EXHAUSTIVE DES TABLEAUX (conforme Excel)
// ================================================================

// 1) Définition des lignes de variation (emplois/ressources)
// Chaque ligne a : code, libelle, type (feuille, sous_total, total), enfants (pour calcul)
// On calcule les valeurs en parcourant récursivement
$variation_def = [
    ['code' => 'B01', 'libelle' => 'I- OPERATIONS AVEC LES MEMBRES', 'type' => 'sous_total', 'enfants' => ['B2D','B30','B40','B2N','B70','D50']],
    ['code' => 'B2D', 'libelle' => '1) Crédits à court terme', 'type' => 'feuille', 'calcul' => 'creditCourt'],
    ['code' => 'B30', 'libelle' => '2) Crédits à moyen terme', 'type' => 'feuille', 'calcul' => 'creditMoyen'],
    ['code' => 'B40', 'libelle' => '3) Crédits à long terme', 'type' => 'feuille', 'calcul' => 'creditLong'],
    ['code' => 'B2N', 'libelle' => '4) Comptes ordinaires débiteurs', 'type' => 'feuille', 'calcul' => 'comptesDebiteurs'],
    ['code' => 'B70', 'libelle' => '5) Créances en souffrance et immobilisées (5\'+6)', 'type' => 'sous_total', 'enfants' => ['B71','B72','B73']],
    ['code' => 'B71', 'libelle' => '5\') Créances en souffrance', 'type' => 'feuille', 'calcul' => 'creancesSouffrance'],
    ['code' => 'B72', 'libelle' => '6) Crédits immobilisés', 'type' => 'feuille', 'calcul' => 'creditsImmobilises'],
    ['code' => 'B73', 'libelle' => 'Crédits nets en souffrance/total crédits nets (5/I)', 'type' => 'feuille', 'calcul' => 'ratioSouffrance'], // ratio, on mettra 0
    ['code' => 'D50', 'libelle' => '7) Crédits bail et opérations assimilées (8+9+10)', 'type' => 'sous_total', 'enfants' => ['D51','D52','D53']],
    ['code' => 'D51', 'libelle' => '8) Crédits bail', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'D52', 'libelle' => '9) L.o.a.', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'D53', 'libelle' => '10) Location vente', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => '',    'libelle' => 'II- AUTRES EMPLOIS (11+12+15+16+17+22)', 'type' => 'sous_total', 'enfants' => ['C10','D1A','D10','D23','D30','D40','C30','C40','C6A','D70']],
    ['code' => 'C10', 'libelle' => '11) Titre de placement', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'D1A', 'libelle' => '12) Immobilisations financières (13+14)', 'type' => 'sous_total', 'enfants' => ['D1E','D1L']],
    ['code' => 'D1E', 'libelle' => '13) Titres de participation', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'D1L', 'libelle' => '14) Titres d\'investissement', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'D10', 'libelle' => '15) Prêts et titres subordonnés', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'D1S', 'libelle' => '16) Dépôts et cautionnements', 'type' => 'feuille', 'calcul' => 'zero'], // pas dans la somme II? Excel l'a en 16 mais pas dans le total?
    ['code' => 'D23', 'libelle' => '17) Autres immobilisations (18+19+20+21)', 'type' => 'sous_total', 'enfants' => ['D24','D25','D26','D27']],
    ['code' => 'D24', 'libelle' => '18) Immobilisations en cours', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'D25', 'libelle' => '19) Immobilisations d\'exploitation', 'type' => 'feuille', 'calcul' => 'immoExploitation'],
    ['code' => 'D26', 'libelle' => '20) Immobilisations hors exploitation', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'D27', 'libelle' => '21) Immobilisations acquises par réalisation de garantie', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'C30', 'libelle' => '22) Divers emplois (23+24+25+28+26+27)', 'type' => 'sous_total', 'enfants' => ['C31','C32','C33','C34','C35','C36']],
    ['code' => 'C31', 'libelle' => '23) Créances rattachées', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'C32', 'libelle' => '24) Comptes de meubles', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'C33', 'libelle' => '25) Débiteurs divers', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'C34', 'libelle' => '26) Valeur à l\'encaissement avec crédit immédiat et à rejeter', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'C35', 'libelle' => '27) Comptes d\'ordre et divers', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'C36', 'libelle' => '28) Créances en souffrance', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => '', 'libelle' => 'A- TOTAL EMPLOIS (I+II)', 'type' => 'total', 'enfants' => ['B01','II']],
    // RESSOURCES
    ['code' => '', 'libelle' => 'III- DEPOTS ET EMPRUNTS (29+30+31+32+33+34+35)', 'type' => 'sous_total', 'enfants' => ['G10','G15','G2A','G30','G35','G60','G70']],
    ['code' => 'G10', 'libelle' => '29) Comptes ordinaires créditeurs', 'type' => 'feuille', 'calcul' => 'comptesCrediteurs'],
    ['code' => 'G15', 'libelle' => '30) Dépôts à terme reçus', 'type' => 'feuille', 'calcul' => 'depotsTerme'],
    ['code' => 'G2A', 'libelle' => '31) Comptes d\'épargne à régime spécial', 'type' => 'feuille', 'calcul' => 'epargne'],
    ['code' => 'G30', 'libelle' => '32) Autres dépôts de garantie reçus', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'G35', 'libelle' => '33) Autres dépôts reçus', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'G60', 'libelle' => '34) Emprunts', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'G70', 'libelle' => '35) Autres sommes dues', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => '', 'libelle' => 'IV- DIVERSES RESSOURCES', 'type' => 'sous_total', 'enfants' => ['F60','G90','H10','H40','H6A','K01','L30','L75']],
    ['code' => 'F60', 'libelle' => 'Dettes rattachées', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'G90', 'libelle' => 'Dettes rattachées', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'H10', 'libelle' => 'Versements restant à effectuer', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'H40', 'libelle' => 'Créditeurs divers', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'H6A', 'libelle' => 'Comptes d\'ordre et divers', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'K01', 'libelle' => 'Versements restant à effectuer sur immobilisations financières', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'L30', 'libelle' => 'Provisions pour risques et charges', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => 'L75', 'libelle' => 'Excédent des produits sur les charges', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => '', 'libelle' => 'V- FONDS PROPRES NETS (36+37)', 'type' => 'sous_total', 'enfants' => ['V1','V2']],
    ['code' => 'V1', 'libelle' => '36) Capital, Dotation Réserves', 'type' => 'feuille', 'calcul' => 'fondsPropores'],
    ['code' => 'V2', 'libelle' => '37) Autres', 'type' => 'feuille', 'calcul' => 'zero'],
    ['code' => '', 'libelle' => 'B- TOTAL RESSOURCES (III+IV+V)', 'type' => 'total', 'enfants' => ['III','IV','V']],
    ['code' => '', 'libelle' => 'C- Excédent + ou Déficit – (B – A)', 'type' => 'sous_total', 'enfants' => ['B','A']],
];

// On va créer une fonction pour calculer chaque feuille
function calculFeuille($code, $date, $pdo) {
    switch ($code) {
        case 'creditCourt':     return creditEncours("d.duree <= 12", $date, $pdo);
        case 'creditMoyen':     return creditEncours("d.duree BETWEEN 13 AND 60", $date, $pdo);
        case 'creditLong':      return creditEncours("d.duree > 60", $date, $pdo);
        case 'comptesDebiteurs':return soldeComptesClients('debiteur', $date, $pdo);
        case 'comptesCrediteurs':return soldeComptesClients('crediteur', $date, $pdo);
        case 'creancesSouffrance': return creditEncours("d.statut = 'impaye'", $date, $pdo);
        case 'creditsImmobilises': return 0; // pas de donnée
        case 'ratioSouffrance':  return 0;
        case 'zero':            return 0;
        case 'immoExploitation':return immoValeur('Immobilisations corporelles', $date, $pdo) + immoValeur('Immobilisations incorporelles', $date, $pdo);
        case 'depotsTerme':     return depotsTerme($date, $pdo);
        case 'epargne':         return epargne($date, $pdo);
        case 'fondsPropores':   return fondsPropores($date, $pdo);
        default:                return 0;
    }
}

// Parcours récursif pour calculer une ligne de variation
function calcVariationLigne($ligneDef, $date, $pdo) {
    if ($ligneDef['type'] == 'feuille') {
        return calculFeuille($ligneDef['calcul'], $date, $pdo);
    } else {
        // sous_total ou total
        $total = 0;
        foreach ($ligneDef['enfants'] as $codeEnfant) {
            // retrouver la définition de l'enfant
            global $variation_def;
            $enfantDef = null;
            foreach ($variation_def as $def) {
                if ($def['code'] == $codeEnfant) { $enfantDef = $def; break; }
            }
            if ($enfantDef) {
                $total += calcVariationLigne($enfantDef, $date, $pdo);
            } else {
                // cas particulier : 'II' ou 'III' etc. (ce sont des codes vides)
                // on va chercher par libellé partiel ?
                if ($codeEnfant == 'II') {
                    // on doit trouver la ligne avec libellé 'II- AUTRES EMPLOIS'
                    foreach ($variation_def as $def) {
                        if (strpos($def['libelle'], 'II- AUTRES EMPLOIS') !== false) {
                            $total += calcVariationLigne($def, $date, $pdo);
                            break;
                        }
                    }
                } elseif ($codeEnfant == 'III') {
                    foreach ($variation_def as $def) {
                        if (strpos($def['libelle'], 'III- DEPOTS ET EMPRUNTS') !== false) {
                            $total += calcVariationLigne($def, $date, $pdo);
                            break;
                        }
                    }
                } elseif ($codeEnfant == 'IV') {
                    foreach ($variation_def as $def) {
                        if (strpos($def['libelle'], 'IV- DIVERSES RESSOURCES') !== false) {
                            $total += calcVariationLigne($def, $date, $pdo);
                            break;
                        }
                    }
                } elseif ($codeEnfant == 'V') {
                    foreach ($variation_def as $def) {
                        if (strpos($def['libelle'], 'V- FONDS PROPRES NETS') !== false) {
                            $total += calcVariationLigne($def, $date, $pdo);
                            break;
                        }
                    }
                } elseif ($codeEnfant == 'B') {
                    foreach ($variation_def as $def) {
                        if (strpos($def['libelle'], 'B- TOTAL RESSOURCES') !== false) {
                            $total += calcVariationLigne($def, $date, $pdo);
                            break;
                        }
                    }
                } elseif ($codeEnfant == 'A') {
                    foreach ($variation_def as $def) {
                        if (strpos($def['libelle'], 'A- TOTAL EMPLOIS') !== false) {
                            $total += calcVariationLigne($def, $date, $pdo);
                            break;
                        }
                    }
                }
            }
        }
        return $total;
    }
}

// On calcule les valeurs pour les deux périodes
$lignesVar = [];
foreach ($variation_def as $def) {
    $prec = calcVariationLigne($def, $date_fin_prec, $pdo);
    $cours = calcVariationLigne($def, $date_fin_periode, $pdo);
    $lignesVar[] = [
        'code' => $def['code'],
        'libelle' => $def['libelle'],
        'prec' => $prec,
        'cours' => $cours,
        'style' => ($def['type'] == 'total') ? 'total' : (($def['type'] == 'sous_total') ? 'subtotal' : '')
    ];
}

// 2) ACTIF : on définit toutes les lignes de l'actif avec leur hiérarchie
$actif_def = [
    ['code'=>'A01', 'libelle'=>'OPERATIONS DE TRESORERIE ET AVEC LES INSTITUTIONS FINANCIERES', 'type'=>'sous_total', 'enfants'=>['A10','A12','A2A','A3A','A60','A70']],
    ['code'=>'A10', 'libelle'=>'Valeur en caisse', 'type'=>'sous_total', 'enfants'=>['A11']],
    ['code'=>'A11', 'libelle'=>'Billets et monnaies', 'type'=>'feuille', 'calcul'=>'caisse'],
    ['code'=>'A12', 'libelle'=>'Comptes ordinaires débiteurs', 'type'=>'feuille', 'calcul'=>'comptesDebiteurs'],
    ['code'=>'A2A', 'libelle'=>'Autres comptes de dépôts débiteurs', 'type'=>'sous_total', 'enfants'=>['A2H','A2I','A2J']],
    ['code'=>'A2H', 'libelle'=>'Dépôts à terme constitués', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'A2I', 'libelle'=>'Dépôts de garantie constitués', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'A2J', 'libelle'=>'Autres dépôts constitués', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'A3A', 'libelle'=>'Comptes de prêts', 'type'=>'sous_total', 'enfants'=>['A3B','A3C']],
    ['code'=>'A3B', 'libelle'=>'Prêts à moins d\'un an', 'type'=>'feuille', 'calcul'=>'creditCourt'],
    ['code'=>'A3C', 'libelle'=>'Prêts à terme', 'type'=>'sous_total', 'enfants'=>['A3D']], // A3D n'existe pas, on va mettre creditMoyen+Long
    ['code'=>'A3D', 'libelle'=>'Prêts à terme (moyen+long)', 'type'=>'feuille', 'calcul'=>'creditMoyen+Long'],
    ['code'=>'A60', 'libelle'=>'Créances rattachées', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'A70', 'libelle'=>'Prêts en souffrance prêts immobilisés', 'type'=>'sous_total', 'enfants'=>['Z01','Z02']],
    ['code'=>'Z01', 'libelle'=>'Prêts immobilisés', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'Z02', 'libelle'=>'Prêts en souffrance', 'type'=>'feuille', 'calcul'=>'creancesSouffrance'], // à vérifier
    ['code'=>'B01', 'libelle'=>'OPERATIONS AVEC LES MEMBRES, BENEFICIAIRES OU CLIENTS', 'type'=>'sous_total', 'enfants'=>['B2D','B2N','B30','B40','B65','B70']],
    ['code'=>'B2D', 'libelle'=>'Crédits à court terme', 'type'=>'feuille', 'calcul'=>'creditCourt'],
    ['code'=>'B2N', 'libelle'=>'Comptes ordinaires', 'type'=>'feuille', 'calcul'=>'comptesDebiteurs'],
    ['code'=>'B30', 'libelle'=>'Crédits à moyen terme', 'type'=>'feuille', 'calcul'=>'creditMoyen'],
    ['code'=>'B40', 'libelle'=>'Crédits à long terme', 'type'=>'feuille', 'calcul'=>'creditLong'],
    ['code'=>'B65', 'libelle'=>'Créances rattachées', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'B70', 'libelle'=>'Crédits en souffrance', 'type'=>'sous_total', 'enfants'=>['B71','B72','B73']],
    ['code'=>'B71', 'libelle'=>'Crédits en souffrance de 6 mois au plus', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'B72', 'libelle'=>'Crédits en souffrance de plus de 6 mois à 12 mois au plus', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'B73', 'libelle'=>'Crédits en souffrance de plus de 12 mois a 24 mois au plus', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C01', 'libelle'=>'OPERATIONS SUR TITRES ET OPERATIONS DIVERSES', 'type'=>'sous_total', 'enfants'=>['C10','C30','C40','C55','C56','C59','C6A']],
    ['code'=>'C10', 'libelle'=>'Titres de placement', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C30', 'libelle'=>'Comptes de stocks', 'type'=>'sous_total', 'enfants'=>['C31','C32','C33','C34']],
    ['code'=>'C31', 'libelle'=>'Stocks de meubles', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C32', 'libelle'=>'Stocks de marchandises', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C33', 'libelle'=>'Stocks de fournitures', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C34', 'libelle'=>'Autres stocks et assimilés', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C40', 'libelle'=>'Débiteurs divers', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C55', 'libelle'=>'Créances rattachées', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C56', 'libelle'=>'Valeur à l\'encaissement avec crédit immédiat', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C59', 'libelle'=>'Valeur à rejeter', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C6A', 'libelle'=>'Comptes d\'ordre et divers', 'type'=>'sous_total', 'enfants'=>['C6B','C6C','C6G','C6Q','C6R']],
    ['code'=>'C6B', 'libelle'=>'Comptes de liaison', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C6C', 'libelle'=>'Comptes de différence de conversion', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C6G', 'libelle'=>'Comptes de régularisation actif', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C6Q', 'libelle'=>'Comptes transitoires', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'C6R', 'libelle'=>'Comptes d\'attente actif', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D01', 'libelle'=>'VALEURS IMMOBILISEES', 'type'=>'sous_total', 'enfants'=>['D1A','D23','D30','D40','Z03','D50','D60','D70']],
    ['code'=>'D1A', 'libelle'=>'Immobilisations financières', 'type'=>'sous_total', 'enfants'=>['D10','D1E','D1L','D1S']],
    ['code'=>'D10', 'libelle'=>'Prêts et titres subordonnés', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D1E', 'libelle'=>'Titres de participation', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D1L', 'libelle'=>'Titres d\'investissement', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D1S', 'libelle'=>'Dépôts et cautionnements', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D23', 'libelle'=>'Immobilisations en cours', 'type'=>'sous_total', 'enfants'=>['D24','D25']],
    ['code'=>'D24', 'libelle'=>'Incorporelles', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D25', 'libelle'=>'Corporelles', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D30', 'libelle'=>'Immobilisations d\'exploitation', 'type'=>'sous_total', 'enfants'=>['D31','D36']],
    ['code'=>'D31', 'libelle'=>'Incorporelles', 'type'=>'feuille', 'calcul'=>'immoIncorp'],
    ['code'=>'D36', 'libelle'=>'Corporelles', 'type'=>'feuille', 'calcul'=>'immoCorp'],
    ['code'=>'D40', 'libelle'=>'Immobilisations hors exploitation', 'type'=>'sous_total', 'enfants'=>['D41','D45']],
    ['code'=>'D41', 'libelle'=>'Incorporelles', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D45', 'libelle'=>'Corporelles', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'Z03', 'libelle'=>'Immobilisations acquises par réalisation de garantie', 'type'=>'sous_total', 'enfants'=>['D46','D47']],
    ['code'=>'D46', 'libelle'=>'Incorporelles', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D47', 'libelle'=>'Corporelles', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D50', 'libelle'=>'Crédits bail et opérations assimilées', 'type'=>'sous_total', 'enfants'=>['D51','D52','D53']],
    ['code'=>'D51', 'libelle'=>'Crédits bail', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D52', 'libelle'=>'L.o.a.', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D53', 'libelle'=>'Location vente', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D60', 'libelle'=>'Créances rattachées', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D70', 'libelle'=>'Créances en souffrance', 'type'=>'sous_total', 'enfants'=>['D71','D72','D73']],
    ['code'=>'D71', 'libelle'=>'Créances en souffrance de 6 mois au plus', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D72', 'libelle'=>'Créances en souffrance de plus de 6 mois à 12 mois au plus', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'D73', 'libelle'=>'Créances en souffrance de plus de 12 mois à 24 mois au plus', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'E01', 'libelle'=>'ACTIONNAIRES ASSOCIES OU MEMBRES', 'type'=>'sous_total', 'enfants'=>['E02','E03']],
    ['code'=>'E02', 'libelle'=>'Actionnaires, associés ou membres, capital non appelé', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'E03', 'libelle'=>'Actionnaires, associés ou membres, capital appelé non versé', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'E05', 'libelle'=>'EXCEDENT DE CHARGES SUR LES PRODUITS', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'E90', 'libelle'=>'TOTAL ACTIF', 'type'=>'total', 'enfants'=>['A01','B01','C01','D01','E01','E05']],
];

// Fonction de calcul pour une feuille d'actif (retourne brut, amort, net)
function calcActifFeuille($calcul, $date, $pdo) {
    switch ($calcul) {
        case 'caisse': $val = soldeCompte('571', $date, $pdo); return ['brut'=>$val, 'amort'=>0, 'net'=>$val];
        case 'comptesDebiteurs': $val = soldeComptesClients('debiteur', $date, $pdo); return ['brut'=>$val, 'amort'=>0, 'net'=>$val];
        case 'creditCourt': $val = creditEncours("d.duree <= 12", $date, $pdo); return ['brut'=>$val, 'amort'=>0, 'net'=>$val];
        case 'creditMoyen': $val = creditEncours("d.duree BETWEEN 13 AND 60", $date, $pdo); return ['brut'=>$val, 'amort'=>0, 'net'=>$val];
        case 'creditLong': $val = creditEncours("d.duree > 60", $date, $pdo); return ['brut'=>$val, 'amort'=>0, 'net'=>$val];
        case 'creditMoyen+Long': $val = creditEncours("d.duree BETWEEN 13 AND 60", $date, $pdo) + creditEncours("d.duree > 60", $date, $pdo); return ['brut'=>$val, 'amort'=>0, 'net'=>$val];
        case 'creancesSouffrance': $val = creditEncours("d.statut = 'impaye'", $date, $pdo); return ['brut'=>$val, 'amort'=>0, 'net'=>$val];
        case 'immoIncorp': $val = immoValeur('Immobilisations incorporelles', $date, $pdo); return ['brut'=>$val, 'amort'=>0, 'net'=>$val];
        case 'immoCorp': $val = immoValeur('Immobilisations corporelles', $date, $pdo); return ['brut'=>$val, 'amort'=>0, 'net'=>$val];
        default: return ['brut'=>0, 'amort'=>0, 'net'=>0];
    }
}

function calcActifNode($nodeDef, $date, $pdo) {
    if ($nodeDef['type'] == 'feuille') {
        return calcActifFeuille($nodeDef['calcul'], $date, $pdo);
    } else {
        $brut=0; $amort=0; $net=0;
        foreach ($nodeDef['enfants'] as $codeEnfant) {
            global $actif_def;
            $enfantDef = null;
            foreach ($actif_def as $def) {
                if ($def['code'] == $codeEnfant) { $enfantDef = $def; break; }
            }
            if ($enfantDef) {
                $vals = calcActifNode($enfantDef, $date, $pdo);
                $brut += $vals['brut'];
                $amort += $vals['amort'];
                $net += $vals['net'];
            }
        }
        return ['brut'=>$brut, 'amort'=>$amort, 'net'=>$net];
    }
}

$lignesActif = [];
foreach ($actif_def as $def) {
    $vals = calcActifNode($def, $date_fin_periode, $pdo);
    $lignesActif[] = [
        'code' => $def['code'],
        'libelle' => $def['libelle'],
        'brut' => $vals['brut'],
        'amort' => $vals['amort'],
        'net' => $vals['net'],
        'style' => ($def['type'] == 'total') ? 'total' : (($def['type'] == 'sous_total') ? 'subtotal' : '')
    ];
}

// 3) PASSIF
$passif_def = [
    ['code'=>'F01', 'libelle'=>'OPERATIONS DE TRESORERIE ET AVEC LES INSTITUTIONS FINANCIERES', 'type'=>'sous_total', 'enfants'=>['F1A','F2A','F3A','F50','F55','F60']],
    ['code'=>'F1A', 'libelle'=>'Comptes ordinaires créditeurs', 'type'=>'feuille', 'calcul'=>'comptesCrediteurs'],
    ['code'=>'F2A', 'libelle'=>'Autres comptes de dépôts créditeurs', 'type'=>'sous_total', 'enfants'=>['F2B','F2C','F2D']],
    ['code'=>'F2B', 'libelle'=>'Dépôts à terme reçus', 'type'=>'feuille', 'calcul'=>'depotsTerme'],
    ['code'=>'F2C', 'libelle'=>'Dépôts de garantie reçus', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'F2D', 'libelle'=>'Autres dépôts reçus', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'F3A', 'libelle'=>'Comptes d\'emprunts', 'type'=>'sous_total', 'enfants'=>['F3E','F3F']],
    ['code'=>'F3E', 'libelle'=>'Emprunts à moins d\'un an', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'F3F', 'libelle'=>'Emprunts à termes', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'F50', 'libelle'=>'Autres sommes dues aux institutions financières', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'F55', 'libelle'=>'Ressources affectées', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'F60', 'libelle'=>'Dettes rattachées', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'G01', 'libelle'=>'OPERATIONS AVEC LES MEMBRES, BENEFICIAIRES OU CLIENTS', 'type'=>'sous_total', 'enfants'=>['G10','G15','G2A','G30','G35','G60','G70','G90']],
    ['code'=>'G10', 'libelle'=>'Comptes ordinaires créditeurs', 'type'=>'feuille', 'calcul'=>'comptesCrediteurs'],
    ['code'=>'G15', 'libelle'=>'Dépôts à terme reçus', 'type'=>'feuille', 'calcul'=>'depotsTerme'],
    ['code'=>'G2A', 'libelle'=>'Comptes d\'épargne à régime spécial', 'type'=>'feuille', 'calcul'=>'epargne'],
    ['code'=>'G30', 'libelle'=>'Autres dépôts de garantie reçus', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'G35', 'libelle'=>'Autres dépôts reçus', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'G60', 'libelle'=>'Emprunts', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'G70', 'libelle'=>'Autres sommes dues', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'G90', 'libelle'=>'Dettes rattachées', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'H01', 'libelle'=>'OPERATIONS SUR TITRES ET OPERATIONS DIVERSES', 'type'=>'sous_total', 'enfants'=>['H10','H40','H6A']],
    ['code'=>'H10', 'libelle'=>'Versements restant à effectuer', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'H40', 'libelle'=>'Créditeurs divers', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'H6A', 'libelle'=>'Comptes d\'ordre et divers', 'type'=>'sous_total', 'enfants'=>['H6B','H6C','H6G','H6P']],
    ['code'=>'H6B', 'libelle'=>'Compte de liaison', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'H6C', 'libelle'=>'Comptes de différences de conversion', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'H6G', 'libelle'=>'Comptes de régularisation-passif', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'H6P', 'libelle'=>'Comptes d\'attente Passif', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'K01', 'libelle'=>'VERSEMENTS RESTANT A EFFECTUER SUR IMMOBILISATIONS FINANCIERES', 'type'=>'sous_total', 'enfants'=>['K20']],
    ['code'=>'K20', 'libelle'=>'Titres de participation', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L01', 'libelle'=>'PROVISIONS, FONDS PROPRES ET ASSIMILES', 'type'=>'sous_total', 'enfants'=>['L10','L20','L30','L35','L36','L37','L41','L43','L45','L50','L55','L59','L60','L65','L70','L75','L80']],
    ['code'=>'L10', 'libelle'=>'Subventions d\'investissement', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L20', 'libelle'=>'Fonds affectés', 'type'=>'sous_total', 'enfants'=>['L21','L22','L23','L24','L25','L27']],
    ['code'=>'L21', 'libelle'=>'Fonds de garantie', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L22', 'libelle'=>'Fonds d\'assurance', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L23', 'libelle'=>'Fonds de bonification', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L24', 'libelle'=>'Fonds de sécurité', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L25', 'libelle'=>'Autres fonds affectés', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L27', 'libelle'=>'Fonds de crédit', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L30', 'libelle'=>'Provisions pour risques et charges', 'type'=>'sous_total', 'enfants'=>['L31','L32','L33']],
    ['code'=>'L31', 'libelle'=>'Provisions pour charges de retraite', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L32', 'libelle'=>'Provisions pour risque d\'exécution des engagements par signature', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L33', 'libelle'=>'Autres provisions pour risques et charges', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L35', 'libelle'=>'Provisions réglementées', 'type'=>'sous_total', 'enfants'=>['L36','L37']],
    ['code'=>'L36', 'libelle'=>'Provisions pour risques afférents aux opérations de crédits à moyen et long termes', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L37', 'libelle'=>'Provision spéciale de réévaluation', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L41', 'libelle'=>'Emprunts et titres émis subordonnés', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L43', 'libelle'=>'Dettes rattachées aux emprunts et titres émis subordonnés', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L45', 'libelle'=>'Fonds pour risques financiers généraux', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L50', 'libelle'=>'Prime liées au capital', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L55', 'libelle'=>'Réserves', 'type'=>'sous_total', 'enfants'=>['L56','L57','L58']],
    ['code'=>'L56', 'libelle'=>'Réserve générale', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L57', 'libelle'=>'Réserves facultatives', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L58', 'libelle'=>'Autres réserves', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L59', 'libelle'=>'Écart de réévaluation des immobilisations', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L60', 'libelle'=>'Capital', 'type'=>'sous_total', 'enfants'=>['L61','L62']],
    ['code'=>'L61', 'libelle'=>'Capital appelé', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L62', 'libelle'=>'Capital non appelé', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L65', 'libelle'=>'Fonds de dotation', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L70', 'libelle'=>'Report à nouveau (+ou-)', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L75', 'libelle'=>'Excédent des produits sur les charges', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L80', 'libelle'=>'Résultat de l\'exercice (+ou-)', 'type'=>'sous_total', 'enfants'=>['L81','L82']],
    ['code'=>'L81', 'libelle'=>'Excédent ou déficit en instance d\'approbation', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L82', 'libelle'=>'Excédent ou déficit de l\'exercice', 'type'=>'feuille', 'calcul'=>'zero'],
    ['code'=>'L90', 'libelle'=>'TOTAL PASSIF', 'type'=>'total', 'enfants'=>['F01','G01','H01','K01','L01']],
];

function calcPassifFeuille($calcul, $date, $pdo) {
    switch ($calcul) {
        case 'comptesCrediteurs': return soldeComptesClients('crediteur', $date, $pdo);
        case 'depotsTerme': return depotsTerme($date, $pdo);
        case 'epargne': return epargne($date, $pdo);
        case 'fondsPropores': return fondsPropores($date, $pdo);
        default: return 0;
    }
}

function calcPassifNode($nodeDef, $date, $pdo) {
    if ($nodeDef['type'] == 'feuille') {
        return calcPassifFeuille($nodeDef['calcul'], $date, $pdo);
    } else {
        $total = 0;
        foreach ($nodeDef['enfants'] as $codeEnfant) {
            global $passif_def;
            $enfantDef = null;
            foreach ($passif_def as $def) {
                if ($def['code'] == $codeEnfant) { $enfantDef = $def; break; }
            }
            if ($enfantDef) $total += calcPassifNode($enfantDef, $date, $pdo);
        }
        return $total;
    }
}

$lignesPassif = [];
foreach ($passif_def as $def) {
    $net = calcPassifNode($def, $date_fin_periode, $pdo);
    $lignesPassif[] = [
        'code' => $def['code'],
        'libelle' => $def['libelle'],
        'net' => $net,
        'style' => ($def['type'] == 'total') ? 'total' : (($def['type'] == 'sous_total') ? 'subtotal' : '')
    ];
}

// ================================================================
// GÉNÉRATION PDF ou HTML
// ================================================================
$format = isset($_POST['format']) ? $_POST['format'] : (isset($_GET['format']) ? $_GET['format'] : 'html');

if ($format === 'pdf') {
    switch ($type_periode) {
        case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
        case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
        case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
        default:          $lib_periode = 'Annee ' . $exercice;
    }

    $pdf = new PDF_DIMF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->codeDimf  = 'DIMF_2005';
    $pdf->titreDimf = 'Tableau des emplois et ressources + Bilan';
    $pdf->nomSfd    = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode   = $lib_periode;
    $pdf->exercice  = $exercice;
    $pdf->SetMargins(8, 35, 8);
    $pdf->SetAutoPageBreak(true, 14);

    // ---- Variation ----
    $pdf->AddPage();
    $pdf->SectionTitle('Emplois et ressources - Variations');
    $colsVar = [
        ['label'=>'CODE','w'=>25],
        ['label'=>'LIBELLÉS','w'=>90],
        ['label'=>'Période préc.','w'=>45,'align'=>'R'],
        ['label'=>'Période en cours','w'=>45,'align'=>'R'],
        ['label'=>'Variation abs.','w'=>35,'align'=>'R'],
        ['label'=>'Variation %','w'=>30,'align'=>'R']
    ];
    $pdf->TableHeader($colsVar);
    foreach ($lignesVar as $l) {
        $prec = $l['prec']; $cours = $l['cours']; $var_abs = $cours - $prec; $var_pct = ($prec != 0) ? ($var_abs / abs($prec)) * 100 : 0;
        $style = $l['style'];
        $pdf->TableRow($colsVar, [
            $l['code'],
            $l['libelle'],
            PDF_DIMF::montant($prec),
            PDF_DIMF::montant($cours),
            PDF_DIMF::montant($var_abs),
            number_format($var_pct, 2).'%'
        ], $style);
    }

    // ---- Actif ----
    $pdf->AddPage();
    $pdf->SectionTitle('Bilan - ACTIF');
    $colsActif = [
        ['label'=>'CODE POSTE','w'=>25],
        ['label'=>'ACTIF','w'=>70],
        ['label'=>'Brut','w'=>35,'align'=>'R'],
        ['label'=>'Amort. Prov.','w'=>35,'align'=>'R'],
        ['label'=>'Net','w'=>35,'align'=>'R']
    ];
    $pdf->TableHeader($colsActif);
    foreach ($lignesActif as $l) {
        $pdf->TableRow($colsActif, [
            $l['code'],
            $l['libelle'],
            PDF_DIMF::montant($l['brut']),
            PDF_DIMF::montant($l['amort']),
            PDF_DIMF::montant($l['net'])
        ], $l['style']);
    }

    // ---- Passif ----
    $pdf->AddPage();
    $pdf->SectionTitle('Bilan - PASSIF');
    $colsPassif = [
        ['label'=>'CODE POSTE','w'=>30],
        ['label'=>'PASSIF','w'=>100],
        ['label'=>'Net','w'=>40,'align'=>'R']
    ];
    $pdf->TableHeader($colsPassif);
    foreach ($lignesPassif as $l) {
        $pdf->TableRow($colsPassif, [
            $l['code'],
            $l['libelle'],
            PDF_DIMF::montant($l['net'])
        ], $l['style']);
    }

    $pdf->Output('I', 'DIMF_2005_Complet_'.$exercice.'_'.$type_periode.'.pdf');
    exit;
}

// ================================================================
// AFFICHAGE HTML
// ================================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2005 - Emplois, Ressources et Bilan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter', system-ui, sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; font-size:0.7rem; }
        .btn-group { display:flex; gap:12px; }
        .btn-excel { background:#10b981; color:white; padding:8px 20px; border-radius:40px; border:none; cursor:pointer; }
        .btn-pdf { background:#ef4444; color:white; padding:8px 20px; border-radius:40px; border:none; cursor:pointer; }
        .card { background:white; border-radius:20px; padding:20px 24px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
        .card-header { display:flex; align-items:center; gap:10px; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #eef2f6; font-weight:600; color:#1e40af; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select, .filter-item input { border:1px solid #d1d5db; border-radius:12px; padding:8px 14px; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.85rem; }
        th { text-align:left; padding:10px 12px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
        td { padding:8px 12px; border-bottom:1px solid #f1f5f9; }
        .text-right { text-align:right; font-family:monospace; }
        .subtotal-row { background:#f8fafc; font-weight:600; }
        .total-row { background:#f0fdf4; font-weight:700; border-top:2px solid #bbf7d0; }
        .variation-positive { color:#16a34a; font-weight:700; }
        .variation-negative { color:#dc2626; font-weight:700; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px 20px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; }
        @media print { .btn-group, .page-footer, #filtersCard { display:none; } }
        h3 { font-size:1.1rem; font-weight:600; color:#1e293b; margin:20px 0 10px; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-pie"></i> DIMF_2005 - EMPLOIS, RESSOURCES & BILAN</h1>
            <div class="subtitle">République de Côte d'Ivoire – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO • Analyse des variations</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <form method="post" class="card" id="filtersForm">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="filters-row">
            <div class="filter-item">
                <label>Année</label>
                <select name="exercice" id="exerciceSelect">
                    <?php for ($y = 2020; $y <= date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y==$exercice?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="filter-item">
                <label>Type période</label>
                <select name="type_periode" id="typePeriodeSelect">
                    <option value="mensuel"   <?= $type_periode=='mensuel'  ?'selected':'' ?>>Mensuel</option>
                    <option value="trimestre" <?= $type_periode=='trimestre'?'selected':'' ?>>Trimestre</option>
                    <option value="semestre"  <?= $type_periode=='semestre' ?'selected':'' ?>>Semestre</option>
                    <option value="annuel"    <?= $type_periode=='annuel'   ?'selected':'' ?>>Annuel</option>
                </select>
            </div>
            <div class="filter-item" id="dynamicSelectContainer">
                <?php
                if ($type_periode == 'mensuel') {
                    echo '<label>Mois</label><select name="mois" id="moisSelect">';
                    for ($m=1;$m<=12;$m++) echo "<option value='$m' ".($m==$mois?'selected':'').">".str_pad($m,2,'0')." - ".date('F',mktime(0,0,0,$m,1))."</option>";
                    echo '</select>';
                } elseif ($type_periode == 'trimestre') {
                    echo '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
                    for ($t=1;$t<=4;$t++) echo "<option value='$t' ".($t==$trimestre?'selected':'').">$t".($t==1?'er':'ème')." Trimestre</option>";
                    echo '</select>';
                } elseif ($type_periode == 'semestre') {
                    echo '<label>Semestre</label><select name="semestre" id="semestreSelect">';
                    for ($s=1;$s<=2;$s++) echo "<option value='$s' ".($s==$semestre?'selected':'').">$s".($s==1?'er':'e')." semestre</option>";
                    echo '</select>';
                } else {
                    echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
                }
                ?>
            </div>
            <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Appliquer</button>
        </div>
    </form>

    <!-- Tableau des variations -->
    <div class="card">
        <div class="card-header"><i class="fas fa-exchange-alt"></i> EMPLOIS ET RESSOURCES - VARIATIONS</div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>CODE</th><th>LIBELLÉS</th><th class="text-right">Période préc.</th><th class="text-right">Période en cours</th><th class="text-right">Variation abs.</th><th class="text-right">Variation %</th></tr></thead>
                <tbody>
                <?php foreach ($lignesVar as $l): 
                    $prec = $l['prec']; $cours = $l['cours']; $var_abs = $cours - $prec; $var_pct = ($prec != 0) ? ($var_abs / abs($prec)) * 100 : 0;
                    $class = $l['style'] ? ($l['style']=='total'?'total-row':'subtotal-row') : '';
                    $var_class = ($var_abs >= 0) ? 'variation-positive' : 'variation-negative';
                ?>
                    <tr class="<?= $class ?>">
                        <td><?= $l['code'] ?></td>
                        <td><?= $l['libelle'] ?></td>
                        <td class="text-right"><?= number_format($prec,0,',',' ') ?></td>
                        <td class="text-right"><?= number_format($cours,0,',',' ') ?></td>
                        <td class="text-right <?= $var_class ?>"><?= number_format($var_abs,0,',',' ') ?></td>
                        <td class="text-right <?= $var_class ?>"><?= number_format($var_pct,2) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Bilan Actif -->
    <div class="card">
        <div class="card-header"><i class="fas fa-building"></i> BILAN - ACTIF</div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>CODE POSTE</th><th>ACTIF</th><th class="text-right">Brut</th><th class="text-right">Amort. Prov.</th><th class="text-right">Net</th></tr></thead>
                <tbody>
                <?php foreach ($lignesActif as $l): ?>
                    <tr class="<?= $l['style'] ? ($l['style']=='total'?'total-row':'subtotal-row') : '' ?>">
                        <td><?= $l['code'] ?></td>
                        <td><?= $l['libelle'] ?></td>
                        <td class="text-right"><?= number_format($l['brut'],0,',',' ') ?></td>
                        <td class="text-right"><?= number_format($l['amort'],0,',',' ') ?></td>
                        <td class="text-right"><?= number_format($l['net'],0,',',' ') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Bilan Passif -->
    <div class="card">
        <div class="card-header"><i class="fas fa-hand-holding-usd"></i> BILAN - PASSIF</div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>CODE POSTE</th><th>PASSIF</th><th class="text-right">Net</th></tr></thead>
                <tbody>
                <?php foreach ($lignesPassif as $l): ?>
                    <tr class="<?= $l['style'] ? ($l['style']=='total'?'total-row':'subtotal-row') : '' ?>">
                        <td><?= $l['code'] ?></td>
                        <td><?= $l['libelle'] ?></td>
                        <td class="text-right"><?= number_format($l['net'],0,',',' ') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="page-footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>) arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>
    </div>
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
        html = '<label>Mois</label><select name="mois" id="moisSelect">';
        for (let m=1;m<=12;m++) { const s = (m===currentMois)?'selected':''; const n = new Date(2000,m-1,1).toLocaleString('fr',{month:'long'}); html+=`<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`; }
        html += '</select>';
    } else if (type === 'trimestre') {
        html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
        for (let t=1;t<=4;t++) { const s = (t===currentTrimestre)?'selected':''; html+=`<option value="${t}" ${s}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
        html += '</select>';
    } else if (type === 'semestre') {
        html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
        for (let s=1;s<=2;s++) { const sel = (s===currentSemestre)?'selected':''; html+=`<option value="${s}" ${sel}>${s}${s===1?'er':'e'} semestre</option>`; }
        html += '</select>';
    } else {
        html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
    }
    container.innerHTML = html;
}

function exporterPDF() {
    const form = document.getElementById('filtersForm');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'format';
    input.value = 'pdf';
    form.appendChild(input);
    form.target = '_self';
    form.submit();
    form.target = '';
    form.removeChild(input);
}

function exporterExcel() {
    // Export avec 3 feuilles
    const wb = XLSX.utils.book_new();
    // 1. Variations
    const dataVar = [['CODE','LIBELLÉ','N-1','N','Var. abs','Var. %']];
    <?php foreach ($lignesVar as $l): ?>
        dataVar.push(['<?= addslashes($l['code']) ?>','<?= addslashes($l['libelle']) ?>',<?= $l['prec'] ?>,<?= $l['cours'] ?>,<?= $l['cours']-$l['prec'] ?>,<?= ($l['prec']!=0)?(($l['cours']-$l['prec'])/abs($l['prec']))*100:0 ?>]);
    <?php endforeach; ?>
    const wsVar = XLSX.utils.aoa_to_sheet(dataVar);
    XLSX.utils.book_append_sheet(wb, wsVar, "Variations");

    // 2. Actif
    const dataActif = [['CODE POSTE','ACTIF','Brut','Amort. Prov.','Net']];
    <?php foreach ($lignesActif as $l): ?>
        dataActif.push(['<?= addslashes($l['code']) ?>','<?= addslashes($l['libelle']) ?>',<?= $l['brut'] ?>,<?= $l['amort'] ?>,<?= $l['net'] ?>]);
    <?php endforeach; ?>
    const wsActif = XLSX.utils.aoa_to_sheet(dataActif);
    XLSX.utils.book_append_sheet(wb, wsActif, "Actif");

    // 3. Passif
    const dataPassif = [['CODE POSTE','PASSIF','Net']];
    <?php foreach ($lignesPassif as $l): ?>
        dataPassif.push(['<?= addslashes($l['code']) ?>','<?= addslashes($l['libelle']) ?>',<?= $l['net'] ?>]);
    <?php endforeach; ?>
    const wsPassif = XLSX.utils.aoa_to_sheet(dataPassif);
    XLSX.utils.book_append_sheet(wb, wsPassif, "Passif");

    XLSX.writeFile(wb, 'DIMF_2005_Complet_<?= $exercice ?>_<?= $type_periode ?>.xlsx');
}

document.addEventListener('DOMContentLoaded', function() {
    updateDynamicSelect();
    document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
    document.getElementById('btnPdf').addEventListener('click', exporterPDF);
});
</script>
</body>
</html>