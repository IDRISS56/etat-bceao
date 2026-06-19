<?php
// DIMF_2080.php - Compte de résultat (Charges et Produits)
// FPDF intégré, gestion des filtres par POST, PDF en AJAX (téléchargement direct sans nouvelle page)

session_start();

// ============================================================
// CONFIGURATION BDD
// ============================================================
require_once '../databases/database.php';
require_once '../fpdf/fpdf.php';

// ============================================================
// CLASSE PDF_DIMF
// ============================================================
class PDF_DIMF extends FPDF {
    public $codeDimf = 'DIMF';
    public $titreDimf = 'Etat financier';
    public $nomSfd = 'SFD';
    public $periode = '';
    public $exercice = '';

    static function u($str) {
        return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
    }

    function Header() {
        $this->SetFillColor(156,163,175);
        $this->Rect(0,0,$this->GetPageWidth(),28,'F');
        $this->SetFont('Arial','',7);
        $this->SetTextColor(255,255,255);
        $this->SetXY(8,3);
        $this->Cell(0,4,self::u('Republique de Cote d\'Ivoire  •  Ministere de l\'Economie et des Finances  -  DGTCP / DSFD'),0,1,'L');
        $this->SetFont('Arial','B',13);
        $this->SetX(8);
        $this->Cell(0,7,self::u($this->codeDimf.'  -  '.$this->titreDimf),0,1,'L');
        $this->SetFont('Arial','',8);
        $this->SetX(8);
        $this->Cell(0,5,self::u('SFD : '.$this->nomSfd.'   |   Periode : '.$this->periode.'   |   Exercice : '.$this->exercice.'   |   Arrete au : '.date('d/m/Y')),0,1,'L');
        $this->SetTextColor(0,0,0);
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial','I',7);
        $this->SetTextColor(100,116,139);
        $this->Cell(0,4,self::u('SICS-BCEAO  •  Genere le '.date('d/m/Y a H:i:s').'  •  Page '.$this->PageNo().'/{nb}'),0,0,'C');
    }

    function SectionTitle($label) {
        $this->SetFont('Arial','B',9);
        $this->SetFillColor(0,0,0);
        $this->SetTextColor(255,255,255);
        $this->Cell(0,7,self::u('  '.strtoupper($label)),0,1,'L',true);
        $this->SetTextColor(0,0,0);
        $this->Ln(1);
    }

    function TableHeader($cols) {
        $this->SetFont('Arial','B',8);
        $this->SetFillColor(248,250,252);
        $this->SetTextColor(30,41,59);
        $this->SetDrawColor(226,232,240);
        foreach($cols as $col){
            $align = isset($col['align']) ? $col['align'] : 'L';
            $this->Cell($col['w'],6,self::u($col['label']),1,0,$align,true);
        }
        $this->Ln();
    }

    function TableRow($cols,$data,$style='') {
        $fill = false;
        if($style=='subtotal'){
            $this->SetFillColor(248,250,252);
            $this->SetFont('Arial','B',8);
            $fill = true;
        }elseif($style=='total'){
            $this->SetFillColor(240,253,244);
            $this->SetFont('Arial','B',8.5);
            $fill = true;
        }else{
            $this->SetFillColor(255,255,255);
            $this->SetFont('Arial','',7.5);
        }
        $this->SetTextColor(15,23,42);
        $this->SetDrawColor(226,232,240);
        foreach($cols as $i=>$col){
            $val = isset($data[$i]) ? $data[$i] : '';
            $align = isset($col['align']) ? $col['align'] : 'L';
            $this->Cell($col['w'],5.5,self::u($val),1,0,$align,$fill);
        }
        $this->Ln();
    }

    static function montant($val) {
        return number_format((float)$val,0,',',' ').' F';
    }
}

// ============================================================
// PARAMÈTRES
// ============================================================
$exercice     = isset($_POST['exercice'])     ? (int)$_POST['exercice']     : (isset($_GET['exercice']) ? (int)$_GET['exercice'] : date('Y'));
$type_periode = isset($_POST['type_periode']) ? $_POST['type_periode']      : (isset($_GET['type_periode']) ? $_GET['type_periode'] : 'mensuel');
$mois         = isset($_POST['mois'])         ? (int)$_POST['mois']         : (isset($_GET['mois']) ? (int)$_GET['mois'] : 12);
$trimestre    = isset($_POST['trimestre'])    ? (int)$_POST['trimestre']    : (isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 4);
$semestre     = isset($_POST['semestre'])     ? (int)$_POST['semestre']     : (isset($_GET['semestre']) ? (int)$_GET['semestre'] : null);
$format       = isset($_POST['format'])       ? $_POST['format']            : (isset($_GET['format']) ? $_GET['format'] : 'html');
$ajax         = isset($_POST['ajax'])         ? (bool)$_POST['ajax']        : false;

switch ($type_periode) {
    case 'trimestre': $mois = $trimestre * 3; break;
    case 'semestre':  $mois = ($semestre == 1) ? 6 : 12; break;
    case 'annuel':    $mois = 12; break;
}
$date_fin_periode = date('Y-m-t', strtotime($exercice.'-'.str_pad($mois,2,'0',STR_PAD_LEFT).'-01'));
$date_debut_exercice = $exercice.'-01-01';

// Libellé période
switch ($type_periode) {
    case 'mensuel':   $lib_periode = 'Mois ' . str_pad($mois,2,'0',STR_PAD_LEFT) . '/' . $exercice; break;
    case 'trimestre': $lib_periode = $trimestre . 'e Trim. ' . $exercice; break;
    case 'semestre':  $lib_periode = $semestre . 'er Sem. ' . $exercice; break;
    default:          $lib_periode = 'Année ' . $exercice;
}

// ============================================================
// FONCTIONS DE RÉCUPÉRATION
// ============================================================
function getCharges($like, $debut, $fin) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(e.montant_debit),0) as total
            FROM ecritures_comptables e
            INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
            WHERE pc.numero_compte LIKE :like
              AND e.date_ecriture BETWEEN :debut AND :fin
              AND e.statut = 'VALIDEE'
        ");
        $stmt->execute([':like'=>$like, ':debut'=>$debut, ':fin'=>$fin]);
        return (float)$stmt->fetch()['total'];
    } catch(PDOException $e){ return 0; }
}

function getProduits($like, $debut, $fin) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(e.montant_credit),0) as total
            FROM ecritures_comptables e
            INNER JOIN plan_comptables pc ON e.compte_general = pc.numero_compte
            WHERE pc.numero_compte LIKE :like
              AND e.date_ecriture BETWEEN :debut AND :fin
              AND e.statut = 'VALIDEE'
        ");
        $stmt->execute([':like'=>$like, ':debut'=>$debut, ':fin'=>$fin]);
        return (float)$stmt->fetch()['total'];
    } catch(PDOException $e){ return 0; }
}

// ============================================================
// CALCUL DE TOUS LES POSTES (CHARGES ET PRODUITS)
// ============================================================

// -------------------- CHARGES --------------------
$R1B = getCharges('6611%', $date_debut_exercice, $date_fin_periode);
$R1C = getCharges('6612%', $date_debut_exercice, $date_fin_periode);
$R1D = getCharges('6613%', $date_debut_exercice, $date_fin_periode);
$R1E = getCharges('6614%', $date_debut_exercice, $date_fin_periode);
$R1F = getCharges('6615%', $date_debut_exercice, $date_fin_periode);
$R1H = getCharges('6616%', $date_debut_exercice, $date_fin_periode);
$R1I = getCharges('6617%', $date_debut_exercice, $date_fin_periode);
$R1K = getCharges('6618%', $date_debut_exercice, $date_fin_periode);
$R1A = $R1B + $R1C + $R1D + $R1E + $R1F + $R1H + $R1I + $R1K;

$R1N = getCharges('6621%', $date_debut_exercice, $date_fin_periode);
$R1P = getCharges('6622%', $date_debut_exercice, $date_fin_periode);
$R1Q = getCharges('6623%', $date_debut_exercice, $date_fin_periode);
$R1L = $R1N + $R1P + $R1Q;

$R2F = getCharges('6631%', $date_debut_exercice, $date_fin_periode);
$R2G = getCharges('6632%', $date_debut_exercice, $date_fin_periode);
$R2A = $R2F + $R2G;

$R2T = getCharges('6633%', $date_debut_exercice, $date_fin_periode);
$R2R = $R2T;
$R2Z = getCharges('664%', $date_debut_exercice, $date_fin_periode);

$R08 = $R1A + $R1L + $R2A + $R2R + $R2Z;

// R3A
$R3D = getCharges('6651%', $date_debut_exercice, $date_fin_periode);
$R3F = getCharges('6652%', $date_debut_exercice, $date_fin_periode);
$R3G = getCharges('6653%', $date_debut_exercice, $date_fin_periode);
$R3H = getCharges('6654%', $date_debut_exercice, $date_fin_periode);
$R3J = getCharges('6655%', $date_debut_exercice, $date_fin_periode);
$R3C = $R3D + $R3F + $R3G + $R3H + $R3J;
$R3N = getCharges('666%', $date_debut_exercice, $date_fin_periode);
$R3Q = getCharges('667%', $date_debut_exercice, $date_fin_periode);
$R3T = getCharges('668%', $date_debut_exercice, $date_fin_periode);
$R3A = $R3C + $R3N + $R3Q + $R3T;

// --- Agrégats Z21 et Z22 (placés après R3A) ---
// Calculés après avoir les produits, on les définit plus tard (après calcul des produits)
// On les initialise à 0 pour l'instant
$Z21 = 0;
$Z22 = 0;

// R4B
$R4C = getCharges('671%', $date_debut_exercice, $date_fin_periode);
$R4K = getCharges('672%', $date_debut_exercice, $date_fin_periode);
$R4N = getCharges('673%', $date_debut_exercice, $date_fin_periode);
$R4B = $R4C + $R4K + $R4N;

// R5B
$R5C = getCharges('6751%', $date_debut_exercice, $date_fin_periode);
$R5D = getCharges('6752%', $date_debut_exercice, $date_fin_periode);
$R5B = $R5C + $R5D;

// R5E
$R5H = getCharges('6761%', $date_debut_exercice, $date_fin_periode);
$R5J = getCharges('6762%', $date_debut_exercice, $date_fin_periode);
$R5K = getCharges('6763%', $date_debut_exercice, $date_fin_periode);
$R5L = getCharges('6764%', $date_debut_exercice, $date_fin_periode);
$R5G = $R5H + $R5J + $R5K + $R5L;

$R5N = getCharges('6771%', $date_debut_exercice, $date_fin_periode);
$R5P = getCharges('6772%', $date_debut_exercice, $date_fin_periode);
$R5Q = getCharges('6773%', $date_debut_exercice, $date_fin_periode);
$R5R = getCharges('6774%', $date_debut_exercice, $date_fin_periode);
$R5M = $R5N + $R5P + $R5Q + $R5R;

$R5T = getCharges('6781%', $date_debut_exercice, $date_fin_periode);
$R5U = getCharges('6782%', $date_debut_exercice, $date_fin_periode);
$R5V = getCharges('6783%', $date_debut_exercice, $date_fin_periode);
$R5X = getCharges('6784%', $date_debut_exercice, $date_fin_periode);
$R5S = $R5T + $R5U + $R5V + $R5X;

$R5Y = getCharges('679%', $date_debut_exercice, $date_fin_periode);
$R5E = $R5G + $R5M + $R5S + $R5Y;

// R6A
$R6B = getCharges('6811%', $date_debut_exercice, $date_fin_periode);
$R6C = getCharges('6812%', $date_debut_exercice, $date_fin_periode);
$R6A = $R6B + $R6C;

// R6F
$R6K = getCharges('6821%', $date_debut_exercice, $date_fin_periode);
$R6M = getCharges('6822%', $date_debut_exercice, $date_fin_periode);
$R6L = getCharges('6823%', $date_debut_exercice, $date_fin_periode);
$R6P = getCharges('6824%', $date_debut_exercice, $date_fin_periode);
$R6S = getCharges('6825%', $date_debut_exercice, $date_fin_periode);
$R6T = getCharges('6826%', $date_debut_exercice, $date_fin_periode);
$R6F = $R6K + $R6M + $R6L + $R6P + $R6S + $R6T;

// R6V
$R6W = getCharges('6831%', $date_debut_exercice, $date_fin_periode);
$R6X = getCharges('6832%', $date_debut_exercice, $date_fin_periode);
$R6V = $R6W + $R6X;

// R7A
$R7B = getCharges('6841%', $date_debut_exercice, $date_fin_periode);
$R7C = getCharges('6842%', $date_debut_exercice, $date_fin_periode);
$R7D = getCharges('6843%', $date_debut_exercice, $date_fin_periode);
$R7A = $R7B + $R7C + $R7D;

// --- Agrégats Z23 à Z28 (placés après R7A) ---
$Z23 = 0; $Z24 = 0; $Z25 = 0; $Z26 = 0; $Z27 = 0; $Z28 = 0;

// S02
$S03 = getCharges('621%', $date_debut_exercice, $date_fin_periode);
$S04 = getCharges('622%', $date_debut_exercice, $date_fin_periode);
$S05 = getCharges('623%', $date_debut_exercice, $date_fin_periode);
$S02 = $S03 + $S04 + $S05;

// S1A
$S1B = getCharges('631%', $date_debut_exercice, $date_fin_periode);
$S1D = getCharges('6321%', $date_debut_exercice, $date_fin_periode);
$S1G = getCharges('6322%', $date_debut_exercice, $date_fin_periode);
$S1H = getCharges('6323%', $date_debut_exercice, $date_fin_periode);
$S1J = getCharges('6324%', $date_debut_exercice, $date_fin_periode);
$S1C = $S1D + $S1G + $S1H + $S1J;
$S1K = getCharges('633%', $date_debut_exercice, $date_fin_periode);
$S1A = $S1B + $S1C + $S1K;

// S2A
$S2C = getCharges('6411%', $date_debut_exercice, $date_fin_periode);
$S2D = getCharges('6412%', $date_debut_exercice, $date_fin_periode);
$S2F = getCharges('6413%', $date_debut_exercice, $date_fin_periode);
$S2H = getCharges('6414%', $date_debut_exercice, $date_fin_periode);
$S2J = getCharges('6415%', $date_debut_exercice, $date_fin_periode);
$S2M = getCharges('6416%', $date_debut_exercice, $date_fin_periode);
$S2K = getCharges('6417%', $date_debut_exercice, $date_fin_periode);
$S2L = getCharges('6418%', $date_debut_exercice, $date_fin_periode);
$S2B = $S2C + $S2D + $S2F + $S2H + $S2J + $S2M + $S2K + $S2L;

$S3B = getCharges('6421%', $date_debut_exercice, $date_fin_periode);
$S3C = getCharges('6422%', $date_debut_exercice, $date_fin_periode);
$S3E = getCharges('6423%', $date_debut_exercice, $date_fin_periode);
$S3G = getCharges('6424%', $date_debut_exercice, $date_fin_periode);
$S3J = getCharges('6425%', $date_debut_exercice, $date_fin_periode);
$S3L = getCharges('6426%', $date_debut_exercice, $date_fin_periode);
$S3M = getCharges('6427%', $date_debut_exercice, $date_fin_periode);
$S3N = getCharges('6428%', $date_debut_exercice, $date_fin_periode);
$S3P = getCharges('6429%', $date_debut_exercice, $date_fin_periode);
$S3A = $S3B + $S3C + $S3E + $S3G + $S3J + $S3L + $S3M + $S3N + $S3P;

$S4B = getCharges('6511%', $date_debut_exercice, $date_fin_periode);
$S4D = getCharges('6512%', $date_debut_exercice, $date_fin_periode);
$S4I = getCharges('6513%', $date_debut_exercice, $date_fin_periode);
$S4L = getCharges('6521%', $date_debut_exercice, $date_fin_periode);
$S4M = getCharges('6522%', $date_debut_exercice, $date_fin_periode);
$S4K = $S4L + $S4M;
$S4Q = getCharges('6531%', $date_debut_exercice, $date_fin_periode);
$S4R = getCharges('6532%', $date_debut_exercice, $date_fin_periode);
$S4P = $S4Q + $S4R;
$S4S = getCharges('654%', $date_debut_exercice, $date_fin_periode);
$S4A = $S4B + $S4D + $S4I + $S4K + $S4P + $S4S;

$S2A = $S2B + $S3A + $S4A;

// T50
$T50 = getCharges('661%', $date_debut_exercice, $date_fin_periode);

// T51
$T53 = getCharges('6811%', $date_debut_exercice, $date_fin_periode);
$T54 = getCharges('6812%', $date_debut_exercice, $date_fin_periode);
$T55 = getCharges('6813%', $date_debut_exercice, $date_fin_periode);
$T56 = getCharges('6814%', $date_debut_exercice, $date_fin_periode);
$T57 = getCharges('6815%', $date_debut_exercice, $date_fin_periode);
$T58 = getCharges('6816%', $date_debut_exercice, $date_fin_periode);
$T51 = $T53 + $T54 + $T55 + $T56 + $T57 + $T58;

// T6B
$T6D = getCharges('6821%', $date_debut_exercice, $date_fin_periode);
$T6E = getCharges('6822%', $date_debut_exercice, $date_fin_periode);
$T6F = getCharges('6823%', $date_debut_exercice, $date_fin_periode);
$T6C = $T6D + $T6E + $T6F;
$T6G = getCharges('6824%', $date_debut_exercice, $date_fin_periode);
$T6H = getCharges('6825%', $date_debut_exercice, $date_fin_periode);
$T6J = getCharges('6826%', $date_debut_exercice, $date_fin_periode);
$T6K = getCharges('6827%', $date_debut_exercice, $date_fin_periode);
$T6L = getCharges('6828%', $date_debut_exercice, $date_fin_periode);
$T6B = $T6C + $T6G + $T6H + $T6J + $T6K + $T6L;

// T80, T81, T82
$T80 = getCharges('691%', $date_debut_exercice, $date_fin_periode);
$T81 = getCharges('692%', $date_debut_exercice, $date_fin_periode);
$T82 = getCharges('693%', $date_debut_exercice, $date_fin_periode);

// R8G, R8J, R8L (Achats et variations de stocks)
$R8G = getCharges('607%', $date_debut_exercice, $date_fin_periode);
$R8J = getCharges('608%', $date_debut_exercice, $date_fin_periode);
$R8L = getCharges('609%', $date_debut_exercice, $date_fin_periode);
$Z27 = $R8G + $R8J + $R8L;

// ---------- PRODUITS ----------
$V1B = getProduits('7611%', $date_debut_exercice, $date_fin_periode);
$V1C = getProduits('7612%', $date_debut_exercice, $date_fin_periode);
$V1D = getProduits('7613%', $date_debut_exercice, $date_fin_periode);
$V1E = getProduits('7614%', $date_debut_exercice, $date_fin_periode);
$V1F = getProduits('7615%', $date_debut_exercice, $date_fin_periode);
$V1H = getProduits('7616%', $date_debut_exercice, $date_fin_periode);
$V1I = getProduits('7617%', $date_debut_exercice, $date_fin_periode);
$V1K = getProduits('7618%', $date_debut_exercice, $date_fin_periode);
$V1A = $V1B + $V1C + $V1D + $V1E + $V1F + $V1H + $V1I + $V1K;

$V1Q = getProduits('7621%', $date_debut_exercice, $date_fin_periode);
$V1R = getProduits('7622%', $date_debut_exercice, $date_fin_periode);
$V1S = getProduits('7623%', $date_debut_exercice, $date_fin_periode);
$V1L = $V1Q + $V1R + $V1S;

$V2C = getProduits('7631%', $date_debut_exercice, $date_fin_periode);
$V2G = getProduits('7632%', $date_debut_exercice, $date_fin_periode);
$V2A = $V2C + $V2G;

$V2S = getProduits('7633%', $date_debut_exercice, $date_fin_periode);
$V2Q = $V2S;
$V2T = getProduits('764%', $date_debut_exercice, $date_fin_periode);
$V08 = $V1A + $V1L + $V2A + $V2Q + $V2T;

// V3A
$V3G = getProduits('7651%', $date_debut_exercice, $date_fin_periode);
$V3M = getProduits('7652%', $date_debut_exercice, $date_fin_periode);
$V3N = getProduits('7653%', $date_debut_exercice, $date_fin_periode);
$V3B = $V3G + $V3M + $V3N;
$V3T = getProduits('7654%', $date_debut_exercice, $date_fin_periode);
$V3R = $V3T;
$V3X = getProduits('766%', $date_debut_exercice, $date_fin_periode);
$V3A = $V3B + $V3R + $V3X;

// On peut maintenant calculer Z21 et Z22 (après avoir V08+V3A et R08+R3A)
$Z21 = max(0, ($V08 + $V3A) - ($R08 + $R3A));
$Z22 = $R08 + $R3A;

// V4B
$V4C = getProduits('771%', $date_debut_exercice, $date_fin_periode);
$V4D = getProduits('772%', $date_debut_exercice, $date_fin_periode);
$V4E = getProduits('773%', $date_debut_exercice, $date_fin_periode);
$V4F = getProduits('774%', $date_debut_exercice, $date_fin_periode);
$V4B = $V4C + $V4D + $V4E + $V4F;

// V5B
$V5C = getProduits('7751%', $date_debut_exercice, $date_fin_periode);
$V5D = getProduits('7752%', $date_debut_exercice, $date_fin_periode);
$V5F = getProduits('7753%', $date_debut_exercice, $date_fin_periode);
$V5B = $V5C + $V5D + $V5F;

// V5G
$V5J = getProduits('7761%', $date_debut_exercice, $date_fin_periode);
$V5K = getProduits('7762%', $date_debut_exercice, $date_fin_periode);
$V5L = getProduits('7763%', $date_debut_exercice, $date_fin_periode);
$V5M = getProduits('7764%', $date_debut_exercice, $date_fin_periode);
$V5H = $V5J + $V5K + $V5L + $V5M;

$V5P = getProduits('7771%', $date_debut_exercice, $date_fin_periode);
$V5Q = getProduits('7772%', $date_debut_exercice, $date_fin_periode);
$V5R = getProduits('7773%', $date_debut_exercice, $date_fin_periode);
$V5S = getProduits('7774%', $date_debut_exercice, $date_fin_periode);
$V5N = $V5P + $V5Q + $V5R + $V5S;

$V5V = getProduits('7781%', $date_debut_exercice, $date_fin_periode);
$V5W = getProduits('7782%', $date_debut_exercice, $date_fin_periode);
$V5X = getProduits('7783%', $date_debut_exercice, $date_fin_periode);
$V5Y = getProduits('7784%', $date_debut_exercice, $date_fin_periode);
$V5T = $V5V + $V5W + $V5X + $V5Y;
$V5G = $V5H + $V5N + $V5T;

// V6A
$V6B = getProduits('7811%', $date_debut_exercice, $date_fin_periode);
$V6C = getProduits('7812%', $date_debut_exercice, $date_fin_periode);
$V6A = $V6B + $V6C;

// V6F
$V6K = getProduits('7821%', $date_debut_exercice, $date_fin_periode);
$V6L = getProduits('7822%', $date_debut_exercice, $date_fin_periode);
$V6N = getProduits('7823%', $date_debut_exercice, $date_fin_periode);
$V6P = getProduits('7824%', $date_debut_exercice, $date_fin_periode);
$V6Q = getProduits('7825%', $date_debut_exercice, $date_fin_periode);
$V6R = getProduits('7826%', $date_debut_exercice, $date_fin_periode);
$V6S = getProduits('7827%', $date_debut_exercice, $date_fin_periode);
$V6F = $V6K + $V6L + $V6N + $V6P + $V6Q + $V6R + $V6S;

// V6U
$V6V = getProduits('7831%', $date_debut_exercice, $date_fin_periode);
$V6W = getProduits('7832%', $date_debut_exercice, $date_fin_periode);
$V6U = $V6V + $V6W;

// V7A
$V7B = getProduits('7841%', $date_debut_exercice, $date_fin_periode);
$V7C = getProduits('7842%', $date_debut_exercice, $date_fin_periode);
$V7D = getProduits('7843%', $date_debut_exercice, $date_fin_periode);
$V7A = $V7B + $V7C + $V7D;

// V8A
$V8B = getProduits('701%', $date_debut_exercice, $date_fin_periode);
$V8C = getProduits('702%', $date_debut_exercice, $date_fin_periode);
$V8D = getProduits('703%', $date_debut_exercice, $date_fin_periode);
$V8A = $V8B + $V8C + $V8D;

// W4A
$W4B = getProduits('7511%', $date_debut_exercice, $date_fin_periode);
$W4D = getProduits('7512%', $date_debut_exercice, $date_fin_periode);
$W4H = getProduits('7521%', $date_debut_exercice, $date_fin_periode);
$W4J = getProduits('7522%', $date_debut_exercice, $date_fin_periode);
$W4G = $W4H + $W4J;
$W4K = getProduits('753%', $date_debut_exercice, $date_fin_periode);
$W4M = getProduits('7541%', $date_debut_exercice, $date_fin_periode);
$W4N = getProduits('7542%', $date_debut_exercice, $date_fin_periode);
$W4P = getProduits('7543%', $date_debut_exercice, $date_fin_periode);
$W4L = $W4M + $W4N + $W4P;
$W4Q = getProduits('755%', $date_debut_exercice, $date_fin_periode);
$W4A = $W4B + $W4D + $W4G + $W4K + $W4L + $W4Q;

// W50
$W51 = getProduits('731%', $date_debut_exercice, $date_fin_periode);
$W52 = getProduits('732%', $date_debut_exercice, $date_fin_periode);
$W50 = $W51 + $W52;

// W53
$W53 = getProduits('741%', $date_debut_exercice, $date_fin_periode);

// X51
$X54 = getProduits('7811%', $date_debut_exercice, $date_fin_periode);
$X56 = getProduits('7812%', $date_debut_exercice, $date_fin_periode);
$X51 = $X54 + $X56;

// X6B
$X6D = getProduits('7821%', $date_debut_exercice, $date_fin_periode);
$X6E = getProduits('7822%', $date_debut_exercice, $date_fin_periode);
$X6F = getProduits('7823%', $date_debut_exercice, $date_fin_periode);
$X6C = $X6D + $X6E + $X6F;
$X6G = getProduits('7824%', $date_debut_exercice, $date_fin_periode);
$X6H = getProduits('7825%', $date_debut_exercice, $date_fin_periode);
$X6J = getProduits('7826%', $date_debut_exercice, $date_fin_periode);
$X6I = getProduits('7827%', $date_debut_exercice, $date_fin_periode);
$X6B = $X6C + $X6G + $X6H + $X6J + $X6I;

// X80, X81
$X80 = getProduits('791%', $date_debut_exercice, $date_fin_periode);
$X81 = getProduits('792%', $date_debut_exercice, $date_fin_periode);

// Totaux produits
$total_produits = $V08 + $V3A + $V4B + $V5B + $V5G + $V6A + $V6F + $V6U + $V7A
                + $V8A + $W4A + $W50 + $W53 + $X51 + $X6B + $X80 + $X81;

// Maintenant on peut calculer les agrégats Z23 à Z28
$Z23 = max(0, ($V4B + $V5B + $V5G + $V6A + $V6F + $V6U + $V7A) - ($R4B + $R5B + $R5E + $R6A + $R6F + $R6V + $R7A));
$Z24 = max(0, ($R4B + $R5B + $R5E + $R6A + $R6F + $R6V + $R7A) - ($V4B + $V5B + $V5G + $V6A + $V6F + $V6U + $V7A));
$Z25 = $Z21;
$Z26 = max(0, ($V4B + $V5B + $V5G + $V6A + $V6F + $V6U + $V7A) - ($R4B + $R5B + $R5E + $R6A + $R6F + $R6V + $R7A) + $Z21 - $Z22);
$Z27 = $R8G + $R8J + $R8L;
$Z28 = $S02 + $S1A + $S2A;

// Agrégats produits
$Z31 = max(0, -($V08 + $V3A - $R08 - $R3A));
$Z32 = $V08 + $V3A;
$Z33 = $Z24;
$Z34 = $Z23;
$Z35 = $Z31;
$Z36 = max(0, ($R4B + $R5B + $R5E + $R6A + $R6F + $R6V + $R7A) - ($V4B + $V5B + $V5G + $V6A + $V6F + $V6U + $V7A) + $Z22 - $Z21);
$Z37 = $W4A + $W50 + $W53 + $X51 + $X6B + $X80 + $X81 + $V8A;

// Total charges
$total_charges = $R08 + $R3A + $R4B + $R5B + $R5E + $R6A + $R6F + $R6V + $R7A
               + $S02 + $S1A + $S2A + $T50 + $T51 + $T6B + $T80 + $T81 + $T82;

$resultat_net = $total_produits - $total_charges;
$resultat_type = ($resultat_net >= 0) ? "EXCEDENT" : "DEFICIT";

// ============================================================
// GÉNÉRATION PDF (si format=pdf)
// ============================================================
if ($format === 'pdf') {
    if (ob_get_length()) ob_end_clean();

    $pdf = new PDF_DIMF('L','mm','A4');
    $pdf->AliasNbPages();
    $pdf->codeDimf = 'DIMF_2080';
    $pdf->titreDimf = 'Compte de résultat';
    $pdf->nomSfd = $_SESSION['nom_sfd'] ?? 'SFD';
    $pdf->periode = $lib_periode;
    $pdf->exercice = $exercice;
    $pdf->SetMargins(8,35,8);
    $pdf->SetAutoPageBreak(true,14);
    $pdf->AddPage();

    $cols = [['label'=>'CODE','w'=>25],['label'=>'LIBELLÉ','w'=>100],['label'=>'Montant (FCFA)','w'=>45,'align'=>'R']];
    $pdf->SectionTitle('CHARGES');
    $pdf->TableHeader($cols);

    // R08
    $pdf->TableRow($cols, ['R08','CHARGES SUR OPERATIONS AVEC LES IF',PDF_DIMF::montant($R08)],'subtotal');
    $pdf->TableRow($cols, ['R1A','Intérêts sur comptes ordinaires créditeurs',PDF_DIMF::montant($R1A)]);
    $pdf->TableRow($cols, ['R1B','Organe financier',PDF_DIMF::montant($R1B)]);
    $pdf->TableRow($cols, ['R1C','Caisse centrale',PDF_DIMF::montant($R1C)]);
    $pdf->TableRow($cols, ['R1D','Trésor Public',PDF_DIMF::montant($R1D)]);
    $pdf->TableRow($cols, ['R1E','CCP',PDF_DIMF::montant($R1E)]);
    $pdf->TableRow($cols, ['R1F','Banques et correspondants',PDF_DIMF::montant($R1F)]);
    $pdf->TableRow($cols, ['R1H','Établissements Financiers',PDF_DIMF::montant($R1H)]);
    $pdf->TableRow($cols, ['R1I','SFD',PDF_DIMF::montant($R1I)]);
    $pdf->TableRow($cols, ['R1K','Autres institutions financières',PDF_DIMF::montant($R1K)]);
    $pdf->TableRow($cols, ['R1L','Intérêts sur autres comptes de dépôts créditeurs',PDF_DIMF::montant($R1L)]);
    $pdf->TableRow($cols, ['R1N','Dépôts à terme reçus',PDF_DIMF::montant($R1N)]);
    $pdf->TableRow($cols, ['R1P','Dépôts de garantie reçus',PDF_DIMF::montant($R1P)]);
    $pdf->TableRow($cols, ['R1Q','Autres dépôts reçus',PDF_DIMF::montant($R1Q)]);
    $pdf->TableRow($cols, ['R2A','Intérêts sur compte d\'emprunts',PDF_DIMF::montant($R2A)]);
    $pdf->TableRow($cols, ['R2F','Intérêts sur emprunts à moins d\'un an',PDF_DIMF::montant($R2F)]);
    $pdf->TableRow($cols, ['R2G','Intérêts sur emprunts à terme',PDF_DIMF::montant($R2G)]);
    $pdf->TableRow($cols, ['R2R','Autres intérêts',PDF_DIMF::montant($R2R)]);
    $pdf->TableRow($cols, ['R2T','Divers intérêts',PDF_DIMF::montant($R2T)]);
    $pdf->TableRow($cols, ['R2Z','Commissions',PDF_DIMF::montant($R2Z)]);

    // R3A
    $pdf->TableRow($cols, ['R3A','CHARGES SUR OPERATIONS AVEC LES MEMBRES',PDF_DIMF::montant($R3A)],'subtotal');
    $pdf->TableRow($cols, ['R3C','Intérêts sur comptes des membres',PDF_DIMF::montant($R3C)]);
    $pdf->TableRow($cols, ['R3D','Intérêts sur comptes ordinaires créditeurs',PDF_DIMF::montant($R3D)]);
    $pdf->TableRow($cols, ['R3F','Intérêts sur dépôts à terme reçus',PDF_DIMF::montant($R3F)]);
    $pdf->TableRow($cols, ['R3G','Intérêts sur comptes d\'épargne à régime spécial',PDF_DIMF::montant($R3G)]);
    $pdf->TableRow($cols, ['R3H','Intérêts sur dépôts de garantie reçus',PDF_DIMF::montant($R3H)]);
    $pdf->TableRow($cols, ['R3J','Intérêts sur autres dépôts reçus',PDF_DIMF::montant($R3J)]);
    $pdf->TableRow($cols, ['R3N','Intérêts sur emprunts et autres sommes dues',PDF_DIMF::montant($R3N)]);
    $pdf->TableRow($cols, ['R3Q','Autres intérêts',PDF_DIMF::montant($R3Q)]);
    $pdf->TableRow($cols, ['R3T','Commissions',PDF_DIMF::montant($R3T)]);

    // Agrégats Z21 et Z22 (placés immédiatement après R3A)
    $pdf->TableRow($cols, ['Z21','MARGE D\'INTÉRÊT BÉNÉFICIAIRE',PDF_DIMF::montant($Z21)],'subtotal');
    $pdf->TableRow($cols, ['Z22','TOTAL CHARGES D\'INTÉRÊTS',PDF_DIMF::montant($Z22)]);

    // R4B
    $pdf->TableRow($cols, ['R4B','CHARGES SUR OPERATIONS SUR TITRES ET DIVERSES',PDF_DIMF::montant($R4B)],'subtotal');
    $pdf->TableRow($cols, ['R4C','Charges et pertes sur titres de placement',PDF_DIMF::montant($R4C)]);
    $pdf->TableRow($cols, ['R4K','Charges sur opérations diverses',PDF_DIMF::montant($R4K)]);
    $pdf->TableRow($cols, ['R4N','Commissions',PDF_DIMF::montant($R4N)]);

    // R5B
    $pdf->TableRow($cols, ['R5B','CHARGES SUR IMMOBILISATIONS FINANCIERES',PDF_DIMF::montant($R5B)],'subtotal');
    $pdf->TableRow($cols, ['R5C','Frais d\'acquisition',PDF_DIMF::montant($R5C)]);
    $pdf->TableRow($cols, ['R5D','Étalement de la Prime',PDF_DIMF::montant($R5D)]);

    // R5E
    $pdf->TableRow($cols, ['R5E','CHARGES SUR CREDIT-BAIL ET ASSIMILEES',PDF_DIMF::montant($R5E)],'subtotal');
    $pdf->TableRow($cols, ['R5G','Charges sur opérations de crédit-bail',PDF_DIMF::montant($R5G)]);
    $pdf->TableRow($cols, ['R5H','Dotations aux amortissements',PDF_DIMF::montant($R5H)]);
    $pdf->TableRow($cols, ['R5J','Dotations aux provisions',PDF_DIMF::montant($R5J)]);
    $pdf->TableRow($cols, ['R5K','Moins-values de cession',PDF_DIMF::montant($R5K)]);
    $pdf->TableRow($cols, ['R5L','Autres charges',PDF_DIMF::montant($R5L)]);
    $pdf->TableRow($cols, ['R5M','Charges sur opérations de location avec option d\'achat',PDF_DIMF::montant($R5M)]);
    $pdf->TableRow($cols, ['R5N','Dotations aux amortissements',PDF_DIMF::montant($R5N)]);
    $pdf->TableRow($cols, ['R5P','Dotations aux provisions',PDF_DIMF::montant($R5P)]);
    $pdf->TableRow($cols, ['R5Q','Moins-value de cession',PDF_DIMF::montant($R5Q)]);
    $pdf->TableRow($cols, ['R5R','Autres charges',PDF_DIMF::montant($R5R)]);
    $pdf->TableRow($cols, ['R5S','Charges sur opérations de location-vente',PDF_DIMF::montant($R5S)]);
    $pdf->TableRow($cols, ['R5T','Dotations aux amortissements',PDF_DIMF::montant($R5T)]);
    $pdf->TableRow($cols, ['R5U','Dotations aux provisions',PDF_DIMF::montant($R5U)]);
    $pdf->TableRow($cols, ['R5V','Moins-values de cession',PDF_DIMF::montant($R5V)]);
    $pdf->TableRow($cols, ['R5X','Autres charges',PDF_DIMF::montant($R5X)]);
    $pdf->TableRow($cols, ['R5Y','Charges sur emprunt et titres subordonnés',PDF_DIMF::montant($R5Y)]);

    // R6A
    $pdf->TableRow($cols, ['R6A','CHARGES SUR OPERATIONS DE CHANGE',PDF_DIMF::montant($R6A)],'subtotal');
    $pdf->TableRow($cols, ['R6B','Pertes sur opérations de change',PDF_DIMF::montant($R6B)]);
    $pdf->TableRow($cols, ['R6C','Commissions',PDF_DIMF::montant($R6C)]);

    // R6F
    $pdf->TableRow($cols, ['R6F','CHARGES SUR OPERATIONS HORS BILAN',PDF_DIMF::montant($R6F)],'subtotal');
    $pdf->TableRow($cols, ['R6K','Engagements de financement reçus des IF',PDF_DIMF::montant($R6K)]);
    $pdf->TableRow($cols, ['R6M','Engagements de garanties reçus des IF',PDF_DIMF::montant($R6M)]);
    $pdf->TableRow($cols, ['R6L','Engagements de financements reçus des membres',PDF_DIMF::montant($R6L)]);
    $pdf->TableRow($cols, ['R6P','Engagements de garanties reçus des membres',PDF_DIMF::montant($R6P)]);
    $pdf->TableRow($cols, ['R6S','Engagements sur titres',PDF_DIMF::montant($R6S)]);
    $pdf->TableRow($cols, ['R6T','Autres engagements reçus',PDF_DIMF::montant($R6T)]);

    // R6V
    $pdf->TableRow($cols, ['R6V','CHARGES SUR PRESTATIONS DE SERVICES FINANCIERS',PDF_DIMF::montant($R6V)],'subtotal');
    $pdf->TableRow($cols, ['R6W','Charges sur les moyens de paiement',PDF_DIMF::montant($R6W)]);
    $pdf->TableRow($cols, ['R6X','Autres charges sur prestations',PDF_DIMF::montant($R6X)]);

    // R7A
    $pdf->TableRow($cols, ['R7A','AUTRES CHARGES D\'EXPLOITATION FINANCIERES',PDF_DIMF::montant($R7A)],'subtotal');
    $pdf->TableRow($cols, ['R7B','Moins-values sur cessions d\'éléments d\'actifs',PDF_DIMF::montant($R7B)]);
    $pdf->TableRow($cols, ['R7C','Transferts de produits d\'exploitation financière',PDF_DIMF::montant($R7C)]);
    $pdf->TableRow($cols, ['R7D','Diverses charges d\'exploitation financière',PDF_DIMF::montant($R7D)]);

    // Agrégats Z23 à Z28 (placés après R7A)
    $pdf->TableRow($cols, ['Z23','AUTRES PRODUITS FINANCIERS NETS',PDF_DIMF::montant($Z23)],'subtotal');
    $pdf->TableRow($cols, ['Z24','AUTRES CHARGES FINANCIÈRES NETTES',PDF_DIMF::montant($Z24)]);
    $pdf->TableRow($cols, ['Z25','MARGE D\'INTÉRÊT BÉNÉFICIAIRE (idem)',PDF_DIMF::montant($Z25)]);
    $pdf->TableRow($cols, ['Z26','PRODUITS FINANCIERS NET',PDF_DIMF::montant($Z26)]);
    $pdf->TableRow($cols, ['Z27','ACHATS ET VARIATIONS DE STOCKS',PDF_DIMF::montant($Z27)],'subtotal');
    $pdf->TableRow($cols, ['R8G','Achats de marchandises',PDF_DIMF::montant($R8G)]);
    $pdf->TableRow($cols, ['R8J','Stocks vendus',PDF_DIMF::montant($R8J)]);
    $pdf->TableRow($cols, ['R8L','Variations de stocks de marchandises',PDF_DIMF::montant($R8L)]);
    $pdf->TableRow($cols, ['Z28','CHARGES GÉNÉRALES D\'EXPLOITATION',PDF_DIMF::montant($Z28)],'subtotal');

    // S02
    $pdf->TableRow($cols, ['S02','FRAIS DE PERSONNEL',PDF_DIMF::montant($S02)],'subtotal');
    $pdf->TableRow($cols, ['S03','Salaires et traitements',PDF_DIMF::montant($S03)]);
    $pdf->TableRow($cols, ['S04','Charges sociales',PDF_DIMF::montant($S04)]);
    $pdf->TableRow($cols, ['S05','Rémunérations versées aux stagiaires',PDF_DIMF::montant($S05)]);

    // S1A
    $pdf->TableRow($cols, ['S1A','IMPÔTS ET TAXES',PDF_DIMF::montant($S1A)],'subtotal');
    $pdf->TableRow($cols, ['S1B','Autres impôts sur rémunérations',PDF_DIMF::montant($S1B)]);
    $pdf->TableRow($cols, ['S1C','Autres impôts versés à l\'administration',PDF_DIMF::montant($S1C)]);
    $pdf->TableRow($cols, ['S1D','Impôts directs',PDF_DIMF::montant($S1D)]);
    $pdf->TableRow($cols, ['S1G','Impôts indirects',PDF_DIMF::montant($S1G)]);
    $pdf->TableRow($cols, ['S1H','Droits d\'enregistrement et de timbre',PDF_DIMF::montant($S1H)]);
    $pdf->TableRow($cols, ['S1J','Impôts et taxes divers',PDF_DIMF::montant($S1J)]);
    $pdf->TableRow($cols, ['S1K','Autres impôts versés aux autres organismes',PDF_DIMF::montant($S1K)]);

    // S2A
    $pdf->TableRow($cols, ['S2A','AUTRES CHARGES EXTERNES ET DIVERSES',PDF_DIMF::montant($S2A)],'subtotal');
    $pdf->TableRow($cols, ['S2B','Services extérieurs',PDF_DIMF::montant($S2B)]);
    $pdf->TableRow($cols, ['S2C','Redevance de crédit-bail',PDF_DIMF::montant($S2C)]);
    $pdf->TableRow($cols, ['S2D','Loyers',PDF_DIMF::montant($S2D)]);
    $pdf->TableRow($cols, ['S2F','Charges locatives et de copropriété',PDF_DIMF::montant($S2F)]);
    $pdf->TableRow($cols, ['S2H','Entretien et réparation',PDF_DIMF::montant($S2H)]);
    $pdf->TableRow($cols, ['S2J','Primes d\'assurance',PDF_DIMF::montant($S2J)]);
    $pdf->TableRow($cols, ['S2M','Frais de formation de personnel',PDF_DIMF::montant($S2M)]);
    $pdf->TableRow($cols, ['S2K','Études et recherches',PDF_DIMF::montant($S2K)]);
    $pdf->TableRow($cols, ['S2L','Divers',PDF_DIMF::montant($S2L)]);
    $pdf->TableRow($cols, ['S3A','Autres services extérieurs',PDF_DIMF::montant($S3A)]);
    $pdf->TableRow($cols, ['S3B','Personnel extérieur',PDF_DIMF::montant($S3B)]);
    $pdf->TableRow($cols, ['S3C','Rémunérations d\'intermédiaires et honoraires',PDF_DIMF::montant($S3C)]);
    $pdf->TableRow($cols, ['S3E','Publicité, publications et relations publiques',PDF_DIMF::montant($S3E)]);
    $pdf->TableRow($cols, ['S3G','Transports de biens',PDF_DIMF::montant($S3G)]);
    $pdf->TableRow($cols, ['S3J','Transports collectifs de personnel',PDF_DIMF::montant($S3J)]);
    $pdf->TableRow($cols, ['S3L','Déplacements, missions et réceptions',PDF_DIMF::montant($S3L)]);
    $pdf->TableRow($cols, ['S3M','Achats non stocks de matières et fournitures',PDF_DIMF::montant($S3M)]);
    $pdf->TableRow($cols, ['S3N','Frais postaux et frais de télécommunication',PDF_DIMF::montant($S3N)]);
    $pdf->TableRow($cols, ['S3P','Divers',PDF_DIMF::montant($S3P)]);
    $pdf->TableRow($cols, ['S4A','Charges diverses d\'exploitation',PDF_DIMF::montant($S4A)]);
    $pdf->TableRow($cols, ['S4B','Redevances pour concessions',PDF_DIMF::montant($S4B)]);
    $pdf->TableRow($cols, ['S4D','Indemnités de fonction versées',PDF_DIMF::montant($S4D)]);
    $pdf->TableRow($cols, ['S4I','Frais de tenue d\'assemblée',PDF_DIMF::montant($S4I)]);
    $pdf->TableRow($cols, ['S4K','Moins-value de cession sur immobilisations',PDF_DIMF::montant($S4K)]);
    $pdf->TableRow($cols, ['S4L','Sur immobilisations incorporelles et corporelles',PDF_DIMF::montant($S4L)]);
    $pdf->TableRow($cols, ['S4M','Sur immobilisations financières',PDF_DIMF::montant($S4M)]);
    $pdf->TableRow($cols, ['S4P','Transferts de produits d\'exploitation non financière',PDF_DIMF::montant($S4P)]);
    $pdf->TableRow($cols, ['S4Q','Produits rétrocédés',PDF_DIMF::montant($S4Q)]);
    $pdf->TableRow($cols, ['S4R','Autres transferts de produits',PDF_DIMF::montant($S4R)]);
    $pdf->TableRow($cols, ['S4S','Autres charges diverses d\'exploitation',PDF_DIMF::montant($S4S)]);

    // T50, T51, T6B, T80, T81, T82
    $pdf->TableRow($cols, ['T50','DOTATIONS DU FONDS POUR RISQUES GENERAUX',PDF_DIMF::montant($T50)],'subtotal');
    $pdf->TableRow($cols, ['T51','DOTATIONS AUX AMORTISSEMENTS ET PROVISIONS SUR IMMOBILISATIONS',PDF_DIMF::montant($T51)],'subtotal');
    $pdf->TableRow($cols, ['T53','Dotations aux amortissements de charges à répartir',PDF_DIMF::montant($T53)]);
    $pdf->TableRow($cols, ['T54','Dotations aux amortissements des immobilisations d\'exploitation',PDF_DIMF::montant($T54)]);
    $pdf->TableRow($cols, ['T55','Dotations aux amortissements des immobilisations hors exploitation',PDF_DIMF::montant($T55)]);
    $pdf->TableRow($cols, ['T56','Dotations aux provisions pour dépréciation des immobilisations en cours',PDF_DIMF::montant($T56)]);
    $pdf->TableRow($cols, ['T57','Dotations aux provisions pour dépréciation des immobilisations d\'exploitation',PDF_DIMF::montant($T57)]);
    $pdf->TableRow($cols, ['T58','Dotations aux provisions pour dépréciation des immobilisations hors exploitation',PDF_DIMF::montant($T58)]);
    $pdf->TableRow($cols, ['T6B','DOTATIONS AUX PROVISIONS ET PERTES SUR CREANCES IRRECOUVRABLES',PDF_DIMF::montant($T6B)],'subtotal');
    $pdf->TableRow($cols, ['T6C','Dotations aux provisions sur créances en souffrance',PDF_DIMF::montant($T6C)]);
    $pdf->TableRow($cols, ['T6D','Provisions sur créances en souffrance de 6 mois au plus',PDF_DIMF::montant($T6D)]);
    $pdf->TableRow($cols, ['T6E','Provisions sur créances en souffrance de 6 à 12 mois',PDF_DIMF::montant($T6E)]);
    $pdf->TableRow($cols, ['T6F','Provisions sur créances en souffrance de 12 à 24 mois',PDF_DIMF::montant($T6F)]);
    $pdf->TableRow($cols, ['T6G','Dotations aux provisions pour dépréciation des autres éléments d\'actif',PDF_DIMF::montant($T6G)]);
    $pdf->TableRow($cols, ['T6H','Dotations aux provisions pour risques et charges',PDF_DIMF::montant($T6H)]);
    $pdf->TableRow($cols, ['T6J','Dotations aux provisions réglementées',PDF_DIMF::montant($T6J)]);
    $pdf->TableRow($cols, ['T6K','Pertes sur créances irrécouvrables couvertes',PDF_DIMF::montant($T6K)]);
    $pdf->TableRow($cols, ['T6L','Pertes sur créances irrécouvrables non couvertes',PDF_DIMF::montant($T6L)]);
    $pdf->TableRow($cols, ['T80','CHARGES EXCEPTIONNELLES',PDF_DIMF::montant($T80)],'subtotal');
    $pdf->TableRow($cols, ['T81','PERTES SUR EXERCICES ANTERIEURS',PDF_DIMF::montant($T81)]);
    $pdf->TableRow($cols, ['T82','IMPOTS SUR LES EXCEDENTS',PDF_DIMF::montant($T82)]);

    // Total charges
    $pdf->TableRow($cols, ['T84','TOTAL CHARGES',PDF_DIMF::montant($total_charges)],'total');

    // Produits (nouvelle page)
    $pdf->AddPage();
    $pdf->SectionTitle('PRODUITS');
    $pdf->TableHeader($cols);

    // V08
    $pdf->TableRow($cols, ['V08','PRODUITS SUR OPERATIONS AVEC LES IF',PDF_DIMF::montant($V08)],'subtotal');
    $pdf->TableRow($cols, ['V1A','Intérêts sur comptes ordinaires débiteurs',PDF_DIMF::montant($V1A)]);
    $pdf->TableRow($cols, ['V1B','Organe financier',PDF_DIMF::montant($V1B)]);
    $pdf->TableRow($cols, ['V1C','Caisse centrale',PDF_DIMF::montant($V1C)]);
    $pdf->TableRow($cols, ['V1D','Trésor public',PDF_DIMF::montant($V1D)]);
    $pdf->TableRow($cols, ['V1E','CCP',PDF_DIMF::montant($V1E)]);
    $pdf->TableRow($cols, ['V1F','Banques et correspondants',PDF_DIMF::montant($V1F)]);
    $pdf->TableRow($cols, ['V1H','Établissements financiers',PDF_DIMF::montant($V1H)]);
    $pdf->TableRow($cols, ['V1I','SFD',PDF_DIMF::montant($V1I)]);
    $pdf->TableRow($cols, ['V1K','Autres Institutions financières',PDF_DIMF::montant($V1K)]);
    $pdf->TableRow($cols, ['V1L','Intérêts sur autres comptes de dépôts débiteurs',PDF_DIMF::montant($V1L)]);
    $pdf->TableRow($cols, ['V1Q','Dépôts à terme constitués',PDF_DIMF::montant($V1Q)]);
    $pdf->TableRow($cols, ['V1R','Dépôts de garantie constitués',PDF_DIMF::montant($V1R)]);
    $pdf->TableRow($cols, ['V1S','Autres dépôts constitués',PDF_DIMF::montant($V1S)]);
    $pdf->TableRow($cols, ['V2A','Intérêts sur comptes de prêts',PDF_DIMF::montant($V2A)]);
    $pdf->TableRow($cols, ['V2C','Prêts à moins d\'un an',PDF_DIMF::montant($V2C)]);
    $pdf->TableRow($cols, ['V2G','Prêts à terme',PDF_DIMF::montant($V2G)]);
    $pdf->TableRow($cols, ['V2Q','Autres intérêts',PDF_DIMF::montant($V2Q)]);
    $pdf->TableRow($cols, ['V2S','Divers intérêts',PDF_DIMF::montant($V2S)]);
    $pdf->TableRow($cols, ['V2T','Commissions',PDF_DIMF::montant($V2T)]);

    // V3A
    $pdf->TableRow($cols, ['V3A','PRODUITS SUR OPERATIONS AVEC LES MEMBRES',PDF_DIMF::montant($V3A)],'subtotal');
    $pdf->TableRow($cols, ['V3B','Intérêts sur crédits aux membres',PDF_DIMF::montant($V3B)]);
    $pdf->TableRow($cols, ['V3G','Crédits à court terme',PDF_DIMF::montant($V3G)]);
    $pdf->TableRow($cols, ['V3M','Crédits à moyen terme',PDF_DIMF::montant($V3M)]);
    $pdf->TableRow($cols, ['V3N','Crédits à long terme',PDF_DIMF::montant($V3N)]);
    $pdf->TableRow($cols, ['V3R','Autres intérêts',PDF_DIMF::montant($V3R)]);
    $pdf->TableRow($cols, ['V3T','Divers intérêts',PDF_DIMF::montant($V3T)]);
    $pdf->TableRow($cols, ['V3X','Commissions',PDF_DIMF::montant($V3X)]);

    // Agrégats produits
    $pdf->TableRow($cols, ['Z31','MARGE D\'INTÉRÊT DÉFICITAIRE',PDF_DIMF::montant($Z31)],'subtotal');
    $pdf->TableRow($cols, ['Z32','TOTAL PRODUITS D\'INTÉRÊTS',PDF_DIMF::montant($Z32)]);
    $pdf->TableRow($cols, ['Z33','AUTRES CHARGES FINANCIÈRES NETTES',PDF_DIMF::montant($Z33)],'subtotal');
    $pdf->TableRow($cols, ['Z34','AUTRES PRODUITS FINANCIERS NETS',PDF_DIMF::montant($Z34)]);
    $pdf->TableRow($cols, ['Z35','MARGE D\'INTÉRÊT DÉFICITAIRE (idem)',PDF_DIMF::montant($Z35)]);
    $pdf->TableRow($cols, ['Z36','CHARGE FINANCIÈRE NETTE',PDF_DIMF::montant($Z36)]);
    $pdf->TableRow($cols, ['Z37','PRODUITS GÉNÉRAUX D\'EXPLOITATION',PDF_DIMF::montant($Z37)],'subtotal');

    // V4B
    $pdf->TableRow($cols, ['V4B','PRODUITS SUR OPERATIONS SUR TITRES ET DIVERSES',PDF_DIMF::montant($V4B)],'subtotal');
    $pdf->TableRow($cols, ['V4C','Produits et profits sur titres de placement',PDF_DIMF::montant($V4C)]);
    $pdf->TableRow($cols, ['V4D','Intérêts sur crédits au personnel non membre',PDF_DIMF::montant($V4D)]);
    $pdf->TableRow($cols, ['V4E','Produits sur opérations diverses',PDF_DIMF::montant($V4E)]);
    $pdf->TableRow($cols, ['V4F','Commissions',PDF_DIMF::montant($V4F)]);

    // V5B
    $pdf->TableRow($cols, ['V5B','PRODUITS SUR IMMOBILISATIONS FINANCIERES',PDF_DIMF::montant($V5B)],'subtotal');
    $pdf->TableRow($cols, ['V5C','Prêts et titres subordonnés',PDF_DIMF::montant($V5C)]);
    $pdf->TableRow($cols, ['V5D','Dividendes sur titres de participation',PDF_DIMF::montant($V5D)]);
    $pdf->TableRow($cols, ['V5F','Produits sur titres d\'investissement',PDF_DIMF::montant($V5F)]);

    // V5G
    $pdf->TableRow($cols, ['V5G','PRODUITS SUR CREDIT-BAIL ET ASSIMILEES',PDF_DIMF::montant($V5G)],'subtotal');
    $pdf->TableRow($cols, ['V5H','Produits sur opérations de crédit-bail',PDF_DIMF::montant($V5H)]);
    $pdf->TableRow($cols, ['V5J','Loyers',PDF_DIMF::montant($V5J)]);
    $pdf->TableRow($cols, ['V5K','Reprises de provisions',PDF_DIMF::montant($V5K)]);
    $pdf->TableRow($cols, ['V5L','Plus-values sur cession',PDF_DIMF::montant($V5L)]);
    $pdf->TableRow($cols, ['V5M','Autres produits',PDF_DIMF::montant($V5M)]);
    $pdf->TableRow($cols, ['V5N','Produits sur opérations LOA',PDF_DIMF::montant($V5N)]);
    $pdf->TableRow($cols, ['V5P','Loyers',PDF_DIMF::montant($V5P)]);
    $pdf->TableRow($cols, ['V5Q','Reprises de provisions',PDF_DIMF::montant($V5Q)]);
    $pdf->TableRow($cols, ['V5R','Plus-values sur cession',PDF_DIMF::montant($V5R)]);
    $pdf->TableRow($cols, ['V5S','Autres produits',PDF_DIMF::montant($V5S)]);
    $pdf->TableRow($cols, ['V5T','Produits sur opérations de location-vente',PDF_DIMF::montant($V5T)]);
    $pdf->TableRow($cols, ['V5V','Loyers',PDF_DIMF::montant($V5V)]);
    $pdf->TableRow($cols, ['V5W','Reprises de provisions',PDF_DIMF::montant($V5W)]);
    $pdf->TableRow($cols, ['V5X','Plus-values sur cession',PDF_DIMF::montant($V5X)]);
    $pdf->TableRow($cols, ['V5Y','Autres produits',PDF_DIMF::montant($V5Y)]);

    // V6A
    $pdf->TableRow($cols, ['V6A','PRODUITS SUR OPERATIONS DE CHANGE',PDF_DIMF::montant($V6A)],'subtotal');
    $pdf->TableRow($cols, ['V6B','Gains sur opération de change',PDF_DIMF::montant($V6B)]);
    $pdf->TableRow($cols, ['V6C','Commissions',PDF_DIMF::montant($V6C)]);

    // V6F
    $pdf->TableRow($cols, ['V6F','PRODUITS SUR OPERATIONS HORS BILAN',PDF_DIMF::montant($V6F)],'subtotal');
    $pdf->TableRow($cols, ['V6K','Engagements de financement donnés aux IF',PDF_DIMF::montant($V6K)]);
    $pdf->TableRow($cols, ['V6L','Engagements de financement donnés aux membres',PDF_DIMF::montant($V6L)]);
    $pdf->TableRow($cols, ['V6N','Engagements de garantie donnés aux IF',PDF_DIMF::montant($V6N)]);
    $pdf->TableRow($cols, ['V6P','Engagements de garantie donnés aux membres',PDF_DIMF::montant($V6P)]);
    $pdf->TableRow($cols, ['V6Q','Engagements sur titres',PDF_DIMF::montant($V6Q)]);
    $pdf->TableRow($cols, ['V6R','Autres engagements donnés',PDF_DIMF::montant($V6R)]);
    $pdf->TableRow($cols, ['V6S','Opérations pour compte de tiers',PDF_DIMF::montant($V6S)]);

    // V6U
    $pdf->TableRow($cols, ['V6U','PRODUITS SUR PRESTATIONS DE SERVICES FINANCIERS',PDF_DIMF::montant($V6U)],'subtotal');
    $pdf->TableRow($cols, ['V6V','Moyens de paiement',PDF_DIMF::montant($V6V)]);
    $pdf->TableRow($cols, ['V6W','Autres prestations',PDF_DIMF::montant($V6W)]);

    // V7A
    $pdf->TableRow($cols, ['V7A','AUTRES PRODUITS D\'EXPLOITATION FINANCIERE',PDF_DIMF::montant($V7A)],'subtotal');
    $pdf->TableRow($cols, ['V7B','Plus-values sur cession d\'éléments d\'actif',PDF_DIMF::montant($V7B)]);
    $pdf->TableRow($cols, ['V7C','Transfert de charges d\'exploitation financières',PDF_DIMF::montant($V7C)]);
    $pdf->TableRow($cols, ['V7D','Divers produits d\'exploitation financière',PDF_DIMF::montant($V7D)]);

    // V8A
    $pdf->TableRow($cols, ['V8A','VENTES ET VARIATION DE STOCKS',PDF_DIMF::montant($V8A)],'subtotal');
    $pdf->TableRow($cols, ['V8B','Marge commerciale',PDF_DIMF::montant($V8B)]);
    $pdf->TableRow($cols, ['V8C','Vente de marchandises',PDF_DIMF::montant($V8C)]);
    $pdf->TableRow($cols, ['V8D','Variations négatives de stocks de marchandises',PDF_DIMF::montant($V8D)]);

    // W4A
    $pdf->TableRow($cols, ['W4A','PRODUITS DIVERS D\'EXPLOITATION',PDF_DIMF::montant($W4A)],'subtotal');
    $pdf->TableRow($cols, ['W4B','Redevances pour concessions',PDF_DIMF::montant($W4B)]);
    $pdf->TableRow($cols, ['W4D','Indemnités de fonction reçues',PDF_DIMF::montant($W4D)]);
    $pdf->TableRow($cols, ['W4G','Plus-value de cession',PDF_DIMF::montant($W4G)]);
    $pdf->TableRow($cols, ['W4H','Sur immobilisations incorporelles et corporelles',PDF_DIMF::montant($W4H)]);
    $pdf->TableRow($cols, ['W4J','Sur immobilisations financières',PDF_DIMF::montant($W4J)]);
    $pdf->TableRow($cols, ['W4K','Revenus des immeubles hors exploitation',PDF_DIMF::montant($W4K)]);
    $pdf->TableRow($cols, ['W4L','Transferts de charges d\'exploitation non financière',PDF_DIMF::montant($W4L)]);
    $pdf->TableRow($cols, ['W4M','Charges refacturées',PDF_DIMF::montant($W4M)]);
    $pdf->TableRow($cols, ['W4N','Charges à répartir sur plusieurs exercices',PDF_DIMF::montant($W4N)]);
    $pdf->TableRow($cols, ['W4P','Autres transferts de charges',PDF_DIMF::montant($W4P)]);
    $pdf->TableRow($cols, ['W4Q','Autres produits divers d\'exploitation',PDF_DIMF::montant($W4Q)]);

    // W50, W53
    $pdf->TableRow($cols, ['W50','PRODUCTION IMMOBILISEE',PDF_DIMF::montant($W50)],'subtotal');
    $pdf->TableRow($cols, ['W51','Immobilisations corporelles',PDF_DIMF::montant($W51)]);
    $pdf->TableRow($cols, ['W52','Immobilisations incorporelles',PDF_DIMF::montant($W52)]);
    $pdf->TableRow($cols, ['W53','SUBVENTIONS D\'EXPLOITATION',PDF_DIMF::montant($W53)]);

    // X51, X6B, X80, X81
    $pdf->TableRow($cols, ['X51','REPRISES D\'AMORTISSEMENTS ET PROVISIONS SUR IMMOBILISATIONS',PDF_DIMF::montant($X51)],'subtotal');
    $pdf->TableRow($cols, ['X54','Reprises d\'amortissements des immobilisations',PDF_DIMF::montant($X54)]);
    $pdf->TableRow($cols, ['X56','Reprises de provisions sur immobilisations',PDF_DIMF::montant($X56)]);
    $pdf->TableRow($cols, ['X6B','REPRISES DE PROVISIONS ET RECUPERATIONS',PDF_DIMF::montant($X6B)],'subtotal');
    $pdf->TableRow($cols, ['X6C','Reprises de provisions sur créances en souffrance',PDF_DIMF::montant($X6C)]);
    $pdf->TableRow($cols, ['X6D','Créances en souffrance de 6 mois au plus',PDF_DIMF::montant($X6D)]);
    $pdf->TableRow($cols, ['X6E','Créances en souffrance de 6 à 12 mois',PDF_DIMF::montant($X6E)]);
    $pdf->TableRow($cols, ['X6F','Créances en souffrance de 12 à 24 mois',PDF_DIMF::montant($X6F)]);
    $pdf->TableRow($cols, ['X6G','Reprises de provisions pour dépréciations des autres éléments d\'actifs',PDF_DIMF::montant($X6G)]);
    $pdf->TableRow($cols, ['X6H','Reprises de provisions pour risques et charges',PDF_DIMF::montant($X6H)]);
    $pdf->TableRow($cols, ['X6J','Récupération sur créances amorties',PDF_DIMF::montant($X6J)]);
    $pdf->TableRow($cols, ['X6I','Reprise de provisions réglementées',PDF_DIMF::montant($X6I)]);
    $pdf->TableRow($cols, ['X80','PRODUITS EXCEPTIONNELS',PDF_DIMF::montant($X80)],'subtotal');
    $pdf->TableRow($cols, ['X81','PROFITS SUR EXERCICES ANTERIEURS',PDF_DIMF::montant($X81)]);

    // Total produits
    $pdf->TableRow($cols, ['X84','TOTAL PRODUITS',PDF_DIMF::montant($total_produits)],'total');

    // Résultat
    $pdf->Ln(10);
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(0,8,PDF_DIMF::u('RÉSULTAT DE L\'EXERCICE'),0,1);
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(0,6,PDF_DIMF::u("Résultat = Total Produits - Total Charges"),0,1);
    $pdf->SetFont('Arial','B',12);
    $pdf->SetTextColor($resultat_type=='EXCEDENT'?22:199, $resultat_type=='EXCEDENT'?163:40, $resultat_type=='EXCEDENT'?74:40);
    $pdf->Cell(0,8,PDF_DIMF::u(number_format(abs($resultat_net),0,',',' ').' FCFA ('.$resultat_type.')'),0,1,'C');
    $pdf->SetTextColor(0,0,0);

    // Sortie du PDF : téléchargement direct (D) ou affichage (I) selon que la requête est AJAX ou non
    // Ici, on utilise D pour forcer le téléchargement (comportement attendu avec AJAX)
    if ($ajax) {
        $pdf->Output('D', 'DIMF_2080_CompteResultat_'.$exercice.'_'.$type_periode.'.pdf');
    } else {
        $pdf->Output('I', 'DIMF_2080_CompteResultat_'.$exercice.'_'.$type_periode.'.pdf');
    }
    exit;
}

// ============================================================
// EXPORT EXCEL (format=excel) – simplifié
// ============================================================
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="DIMF_2080_'.$exercice.'.xls"');
    echo '<html><head><meta charset="UTF-8"><style> body { font-family: Arial; } table { border-collapse: collapse; } th, td { border: 1px solid #999; padding: 6px; } .text-right { text-align: right; } </style></head><body>';
    echo '<h2>DIMF_2080 - Compte de résultat</h2>';
    echo '<p>Période : ' . htmlspecialchars($lib_periode) . '</p>';
    // Affichage simplifié des totaux
    echo '<h3>CHARGES</h3>';
    echo '<table><tr><th>CODE</th><th>LIBELLÉ</th><th class="text-right">Montant</th></tr>';
    echo '<tr><td>R08</td><td>Charges sur opérations avec IF</td><td class="text-right">'.number_format($R08,0,',',' ').'</td></tr>';
    echo '<tr><td>R3A</td><td>Charges sur opérations avec membres</td><td class="text-right">'.number_format($R3A,0,',',' ').'</td></tr>';
    // ... (on peut ajouter d'autres lignes si besoin, mais on garde l'essentiel)
    echo '<tr style="background:#e8f5e9;"><td colspan="2"><strong>TOTAL CHARGES</strong></td><td class="text-right"><strong>'.number_format($total_charges,0,',',' ').'</strong></td></tr>';
    echo '</table><br/>';
    echo '<h3>PRODUITS</h3>';
    echo '<table><tr><th>CODE</th><th>LIBELLÉ</th><th class="text-right">Montant</th></tr>';
    echo '<tr><td>V08</td><td>Produits sur opérations avec IF</td><td class="text-right">'.number_format($V08,0,',',' ').'</td></tr>';
    echo '<tr><td>V3A</td><td>Produits sur opérations avec membres</td><td class="text-right">'.number_format($V3A,0,',',' ').'</td></tr>';
    // ...
    echo '<tr style="background:#e8f5e9;"><td colspan="2"><strong>TOTAL PRODUITS</strong></td><td class="text-right"><strong>'.number_format($total_produits,0,',',' ').'</strong></td></tr>';
    echo '</table><br/>';
    echo '<h3>Résultat</h3>';
    echo '<p><strong>Total Produits - Total Charges</strong> = ' . number_format(abs($resultat_net),0,',',' ') . ' FCFA (' . $resultat_type . ')</p>';
    echo '</body></html>';
    exit;
}

// ============================================================
// AFFICHAGE HTML
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DIMF_2080 - Compte de résultat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter', sans-serif; background:#f1f5f9; padding:24px; }
        .dashboard { max-width:1400px; margin:0 auto; }
        .page-header { background:linear-gradient(135deg,#3b82f6,#60a5fa); border-radius:24px; padding:20px 28px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .header-left h1 { font-size:1.6rem; font-weight:600; color:white; display:flex; align-items:center; gap:10px; }
        .subtitle { font-size:0.8rem; color:#e0f2fe; }
        .badge { background:#2563eb; color:white; padding:4px 12px; border-radius:30px; display:inline-block; margin-top:8px; }
        .btn-group { display:flex; gap:12px; }
        .btn-excel, .btn-pdf { display:inline-flex; align-items:center; gap:8px; padding:8px 20px; border-radius:40px; font-weight:500; border:none; cursor:pointer; text-decoration:none; }
        .btn-excel { background:#10b981; color:white; }
        .btn-excel:hover { background:#059669; }
        .btn-pdf { background:#ef4444; color:white; }
        .btn-pdf:hover { background:#dc2626; }
        .card { background:white; border-radius:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px; overflow:hidden; }
        .card-header { display:flex; align-items:center; gap:10px; padding:16px 24px; background:#f8fafc; border-bottom:1px solid #eef2f6; font-weight:600; color:#1e40af; }
        .card-body { padding:20px 24px; }
        .filters-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:20px; }
        .filter-item { display:flex; flex-direction:column; gap:6px; }
        .filter-item label { font-size:0.7rem; font-weight:600; text-transform:uppercase; color:#4b5563; }
        .filter-item select { background:white; border:1px solid #d1d5db; border-radius:12px; padding:8px 14px; font-size:0.85rem; }
        .btn-apply { background:#3b82f6; color:white; border:none; border-radius:40px; padding:8px 24px; cursor:pointer; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:0.8rem; }
        th { text-align:left; padding:8px 12px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
        td { padding:6px 12px; border-bottom:1px solid #f1f5f9; }
        .text-right { text-align:right; font-family:monospace; }
        .total-row { background:#f0fdf4; font-weight:700; }
        .subtotal-row { background:#f8fafc; font-weight:600; }
        .info-box { background:#eef2ff; border-left:4px solid #3b82f6; padding:16px; border-radius:16px; display:flex; align-items:center; gap:14px; }
        .excedent { color:#16a34a; font-size:2rem; font-weight:700; }
        .deficit { color:#dc2626; font-size:2rem; font-weight:700; }
        .page-footer { text-align:center; font-size:0.75rem; color:#6b7280; margin-top:16px; padding:16px; }
        @media (max-width:768px) { body { padding:12px; } .filters-row { flex-direction:column; } .btn-group { flex-wrap:wrap; } }
        @media print { .btn-group, .page-footer, .filters-row, #filtersCard { display:none !important; } }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="page-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-line"></i> DIMF_2080 - COMPTE DE RÉSULTAT</h1>
            <div class="subtitle">République de Côte d'Ivoire – DGTCP / DSFD</div>
            <div class="badge">SICS-BCEAO</div>
        </div>
        <div class="btn-group">
            <button class="btn-excel" onclick="exporterExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <form method="post" class="card" id="filtersForm">
        <div class="card-header"><i class="fas fa-sliders-h"></i> Filtres</div>
        <div class="card-body">
            <div class="filters-row">
                <div class="filter-item">
                    <label>Année</label>
                    <select name="exercice" id="exerciceSelect">
                        <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                            <option value="<?=$y?>" <?=$y==$exercice?'selected':''?>><?=$y?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Type de période</label>
                    <select name="type_periode" id="typePeriodeSelect">
                        <option value="mensuel" <?=$type_periode=='mensuel'?'selected':''?>>Mensuel</option>
                        <option value="trimestre" <?=$type_periode=='trimestre'?'selected':''?>>Trimestre</option>
                        <option value="semestre" <?=$type_periode=='semestre'?'selected':''?>>Semestre</option>
                        <option value="annuel" <?=$type_periode=='annuel'?'selected':''?>>Annuel</option>
                    </select>
                </div>
                <div class="filter-item" id="dynamicSelectContainer">
                    <?php
                    if ($type_periode == 'mensuel') {
                        echo '<label>Mois</label><select name="mois" id="moisSelect">';
                        for ($m=1;$m<=12;$m++) echo '<option value="'.$m.'" '.($m==$mois?'selected':'').'>'.str_pad($m,2,'0',STR_PAD_LEFT).' - '.date('F',mktime(0,0,0,$m,1)).'</option>';
                        echo '</select>';
                    } elseif ($type_periode == 'trimestre') {
                        echo '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
                        for ($t=1;$t<=4;$t++) echo '<option value="'.$t.'" '.($t==$trimestre?'selected':'').'>'.$t.($t==1?'er':'ème').' Trimestre</option>';
                        echo '</select>';
                    } elseif ($type_periode == 'semestre') {
                        echo '<label>Semestre</label><select name="semestre" id="semestreSelect">';
                        for ($s=1;$s<=2;$s++) echo '<option value="'.$s.'" '.($s==$semestre?'selected':'').'>'.$s.($s==1?'er':'e').' semestre</option>';
                        echo '</select>';
                    } else {
                        echo '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
                    }
                    ?>
                </div>
                <div class="filter-item">
                    <button type="submit" class="btn-apply" name="format" value="html"><i class="fas fa-filter"></i> Appliquer</button>
                </div>
            </div>
            <div style="font-size:0.7rem;color:#6b7280;margin-top:12px;">
                <i class="fas fa-info-circle"></i> Période : <?= $lib_periode ?> (arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>)
            </div>
        </div>
    </form>

    <!-- Deux colonnes : Charges / Produits -->
    <div class="row g-3">
        <!-- Charges -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><i class="fas fa-arrow-down"></i> CHARGES</div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>CODE</th><th>LIBELLÉ</th><th class="text-right">Montant (FCFA)</th></tr></thead>
                        <tbody>
                            <!-- R08 -->
                            <tr class="subtotal-row"><td colspan="2">R08 - CHARGES SUR OPÉRATIONS AVEC LES IF</td><td class="text-right"><?= number_format($R08,0,',',' ') ?></td></tr>
                            <tr><td>R1A</td><td>Intérêts sur comptes ordinaires créditeurs</td><td class="text-right"><?= number_format($R1A,0,',',' ') ?></td></tr>
                            <tr><td>R1B</td><td>Organe financier</td><td class="text-right"><?= number_format($R1B,0,',',' ') ?></td></tr>
                            <tr><td>R1C</td><td>Caisse centrale</td><td class="text-right"><?= number_format($R1C,0,',',' ') ?></td></tr>
                            <tr><td>R1D</td><td>Trésor Public</td><td class="text-right"><?= number_format($R1D,0,',',' ') ?></td></tr>
                            <tr><td>R1E</td><td>CCP</td><td class="text-right"><?= number_format($R1E,0,',',' ') ?></td></tr>
                            <tr><td>R1F</td><td>Banques et correspondants</td><td class="text-right"><?= number_format($R1F,0,',',' ') ?></td></tr>
                            <tr><td>R1H</td><td>Établissements Financiers</td><td class="text-right"><?= number_format($R1H,0,',',' ') ?></td></tr>
                            <tr><td>R1I</td><td>SFD</td><td class="text-right"><?= number_format($R1I,0,',',' ') ?></td></tr>
                            <tr><td>R1K</td><td>Autres institutions financières</td><td class="text-right"><?= number_format($R1K,0,',',' ') ?></td></tr>
                            <tr><td>R1L</td><td>Intérêts sur autres comptes de dépôts créditeurs</td><td class="text-right"><?= number_format($R1L,0,',',' ') ?></td></tr>
                            <tr><td>R1N</td><td>Dépôts à terme reçus</td><td class="text-right"><?= number_format($R1N,0,',',' ') ?></td></tr>
                            <tr><td>R1P</td><td>Dépôts de garantie reçus</td><td class="text-right"><?= number_format($R1P,0,',',' ') ?></td></tr>
                            <tr><td>R1Q</td><td>Autres dépôts reçus</td><td class="text-right"><?= number_format($R1Q,0,',',' ') ?></td></tr>
                            <tr><td>R2A</td><td>Intérêts sur compte d'emprunts</td><td class="text-right"><?= number_format($R2A,0,',',' ') ?></td></tr>
                            <tr><td>R2F</td><td>Intérêts sur emprunts à moins d'un an</td><td class="text-right"><?= number_format($R2F,0,',',' ') ?></td></tr>
                            <tr><td>R2G</td><td>Intérêts sur emprunts à terme</td><td class="text-right"><?= number_format($R2G,0,',',' ') ?></td></tr>
                            <tr><td>R2R</td><td>Autres intérêts</td><td class="text-right"><?= number_format($R2R,0,',',' ') ?></td></tr>
                            <tr><td>R2T</td><td>Divers intérêts</td><td class="text-right"><?= number_format($R2T,0,',',' ') ?></td></tr>
                            <tr><td>R2Z</td><td>Commissions</td><td class="text-right"><?= number_format($R2Z,0,',',' ') ?></td></tr>

                            <!-- R3A -->
                            <tr class="subtotal-row"><td colspan="2">R3A - CHARGES SUR OPÉRATIONS AVEC LES MEMBRES</td><td class="text-right"><?= number_format($R3A,0,',',' ') ?></td></tr>
                            <tr><td>R3C</td><td>Intérêts sur comptes des membres</td><td class="text-right"><?= number_format($R3C,0,',',' ') ?></td></tr>
                            <tr><td>R3D</td><td>Intérêts sur comptes ordinaires créditeurs</td><td class="text-right"><?= number_format($R3D,0,',',' ') ?></td></tr>
                            <tr><td>R3F</td><td>Intérêts sur dépôts à terme reçus</td><td class="text-right"><?= number_format($R3F,0,',',' ') ?></td></tr>
                            <tr><td>R3G</td><td>Intérêts sur comptes d'épargne à régime spécial</td><td class="text-right"><?= number_format($R3G,0,',',' ') ?></td></tr>
                            <tr><td>R3H</td><td>Intérêts sur dépôts de garantie reçus</td><td class="text-right"><?= number_format($R3H,0,',',' ') ?></td></tr>
                            <tr><td>R3J</td><td>Intérêts sur autres dépôts reçus</td><td class="text-right"><?= number_format($R3J,0,',',' ') ?></td></tr>
                            <tr><td>R3N</td><td>Intérêts sur emprunts et autres sommes dues</td><td class="text-right"><?= number_format($R3N,0,',',' ') ?></td></tr>
                            <tr><td>R3Q</td><td>Autres intérêts</td><td class="text-right"><?= number_format($R3Q,0,',',' ') ?></td></tr>
                            <tr><td>R3T</td><td>Commissions</td><td class="text-right"><?= number_format($R3T,0,',',' ') ?></td></tr>

                            <!-- Agrégats Z21 et Z22 (placés juste après R3A) -->
                            <tr class="subtotal-row"><td colspan="2">Z21 - MARGE D'INTÉRÊT BÉNÉFICIAIRE</td><td class="text-right"><?= number_format($Z21,0,',',' ') ?></td></tr>
                            <tr><td colspan="2">Z22 - TOTAL CHARGES D'INTÉRÊTS</td><td class="text-right"><?= number_format($Z22,0,',',' ') ?></td></tr>

                            <!-- R4B -->
                            <tr class="subtotal-row"><td colspan="2">R4B - CHARGES SUR OPÉRATIONS SUR TITRES ET DIVERSES</td><td class="text-right"><?= number_format($R4B,0,',',' ') ?></td></tr>
                            <tr><td>R4C</td><td>Charges et pertes sur titres de placement</td><td class="text-right"><?= number_format($R4C,0,',',' ') ?></td></tr>
                            <tr><td>R4K</td><td>Charges sur opérations diverses</td><td class="text-right"><?= number_format($R4K,0,',',' ') ?></td></tr>
                            <tr><td>R4N</td><td>Commissions</td><td class="text-right"><?= number_format($R4N,0,',',' ') ?></td></tr>

                            <!-- R5B -->
                            <tr class="subtotal-row"><td colspan="2">R5B - CHARGES SUR IMMOBILISATIONS FINANCIERES</td><td class="text-right"><?= number_format($R5B,0,',',' ') ?></td></tr>
                            <tr><td>R5C</td><td>Frais d'acquisition</td><td class="text-right"><?= number_format($R5C,0,',',' ') ?></td></tr>
                            <tr><td>R5D</td><td>Étalement de la Prime</td><td class="text-right"><?= number_format($R5D,0,',',' ') ?></td></tr>

                            <!-- R5E -->
                            <tr class="subtotal-row"><td colspan="2">R5E - CHARGES SUR CREDIT-BAIL ET ASSIMILEES</td><td class="text-right"><?= number_format($R5E,0,',',' ') ?></td></tr>
                            <tr><td>R5G</td><td>Charges sur opérations de crédit-bail</td><td class="text-right"><?= number_format($R5G,0,',',' ') ?></td></tr>
                            <tr><td>R5H</td><td>Dotations aux amortissements</td><td class="text-right"><?= number_format($R5H,0,',',' ') ?></td></tr>
                            <tr><td>R5J</td><td>Dotations aux provisions</td><td class="text-right"><?= number_format($R5J,0,',',' ') ?></td></tr>
                            <tr><td>R5K</td><td>Moins-values de cession</td><td class="text-right"><?= number_format($R5K,0,',',' ') ?></td></tr>
                            <tr><td>R5L</td><td>Autres charges</td><td class="text-right"><?= number_format($R5L,0,',',' ') ?></td></tr>
                            <tr><td>R5M</td><td>Charges sur opérations LOA</td><td class="text-right"><?= number_format($R5M,0,',',' ') ?></td></tr>
                            <tr><td>R5N</td><td>Dotations aux amortissements</td><td class="text-right"><?= number_format($R5N,0,',',' ') ?></td></tr>
                            <tr><td>R5P</td><td>Dotations aux provisions</td><td class="text-right"><?= number_format($R5P,0,',',' ') ?></td></tr>
                            <tr><td>R5Q</td><td>Moins-value de cession</td><td class="text-right"><?= number_format($R5Q,0,',',' ') ?></td></tr>
                            <tr><td>R5R</td><td>Autres charges</td><td class="text-right"><?= number_format($R5R,0,',',' ') ?></td></tr>
                            <tr><td>R5S</td><td>Charges sur opérations de location-vente</td><td class="text-right"><?= number_format($R5S,0,',',' ') ?></td></tr>
                            <tr><td>R5T</td><td>Dotations aux amortissements</td><td class="text-right"><?= number_format($R5T,0,',',' ') ?></td></tr>
                            <tr><td>R5U</td><td>Dotations aux provisions</td><td class="text-right"><?= number_format($R5U,0,',',' ') ?></td></tr>
                            <tr><td>R5V</td><td>Moins-values de cession</td><td class="text-right"><?= number_format($R5V,0,',',' ') ?></td></tr>
                            <tr><td>R5X</td><td>Autres charges</td><td class="text-right"><?= number_format($R5X,0,',',' ') ?></td></tr>
                            <tr><td>R5Y</td><td>Charges sur emprunt et titres subordonnés</td><td class="text-right"><?= number_format($R5Y,0,',',' ') ?></td></tr>

                            <!-- R6A -->
                            <tr class="subtotal-row"><td colspan="2">R6A - CHARGES SUR OPERATIONS DE CHANGE</td><td class="text-right"><?= number_format($R6A,0,',',' ') ?></td></tr>
                            <tr><td>R6B</td><td>Pertes sur opérations de change</td><td class="text-right"><?= number_format($R6B,0,',',' ') ?></td></tr>
                            <tr><td>R6C</td><td>Commissions</td><td class="text-right"><?= number_format($R6C,0,',',' ') ?></td></tr>

                            <!-- R6F -->
                            <tr class="subtotal-row"><td colspan="2">R6F - CHARGES SUR OPERATIONS HORS BILAN</td><td class="text-right"><?= number_format($R6F,0,',',' ') ?></td></tr>
                            <tr><td>R6K</td><td>Engagements de financement reçus des IF</td><td class="text-right"><?= number_format($R6K,0,',',' ') ?></td></tr>
                            <tr><td>R6M</td><td>Engagements de garanties reçus des IF</td><td class="text-right"><?= number_format($R6M,0,',',' ') ?></td></tr>
                            <tr><td>R6L</td><td>Engagements de financements reçus des membres</td><td class="text-right"><?= number_format($R6L,0,',',' ') ?></td></tr>
                            <tr><td>R6P</td><td>Engagements de garanties reçus des membres</td><td class="text-right"><?= number_format($R6P,0,',',' ') ?></td></tr>
                            <tr><td>R6S</td><td>Engagements sur titres</td><td class="text-right"><?= number_format($R6S,0,',',' ') ?></td></tr>
                            <tr><td>R6T</td><td>Autres engagements reçus</td><td class="text-right"><?= number_format($R6T,0,',',' ') ?></td></tr>

                            <!-- R6V -->
                            <tr class="subtotal-row"><td colspan="2">R6V - CHARGES SUR PRESTATIONS DE SERVICES FINANCIERS</td><td class="text-right"><?= number_format($R6V,0,',',' ') ?></td></tr>
                            <tr><td>R6W</td><td>Charges sur les moyens de paiement</td><td class="text-right"><?= number_format($R6W,0,',',' ') ?></td></tr>
                            <tr><td>R6X</td><td>Autres charges sur prestations</td><td class="text-right"><?= number_format($R6X,0,',',' ') ?></td></tr>

                            <!-- R7A -->
                            <tr class="subtotal-row"><td colspan="2">R7A - AUTRES CHARGES D'EXPLOITATION FINANCIERES</td><td class="text-right"><?= number_format($R7A,0,',',' ') ?></td></tr>
                            <tr><td>R7B</td><td>Moins-values sur cessions d'éléments d'actifs</td><td class="text-right"><?= number_format($R7B,0,',',' ') ?></td></tr>
                            <tr><td>R7C</td><td>Transferts de produits d'exploitation financière</td><td class="text-right"><?= number_format($R7C,0,',',' ') ?></td></tr>
                            <tr><td>R7D</td><td>Diverses charges d'exploitation financière</td><td class="text-right"><?= number_format($R7D,0,',',' ') ?></td></tr>

                            <!-- Agrégats Z23 à Z28 (placés après R7A) -->
                            <tr class="subtotal-row"><td colspan="2">Z23 - AUTRES PRODUITS FINANCIERS NETS</td><td class="text-right"><?= number_format($Z23,0,',',' ') ?></td></tr>
                            <tr><td colspan="2">Z24 - AUTRES CHARGES FINANCIÈRES NETTES</td><td class="text-right"><?= number_format($Z24,0,',',' ') ?></td></tr>
                            <tr><td colspan="2">Z25 - MARGE D'INTÉRÊT BÉNÉFICIAIRE (idem)</td><td class="text-right"><?= number_format($Z25,0,',',' ') ?></td></tr>
                            <tr><td colspan="2">Z26 - PRODUITS FINANCIERS NET</td><td class="text-right"><?= number_format($Z26,0,',',' ') ?></td></tr>
                            <tr class="subtotal-row"><td colspan="2">Z27 - ACHATS ET VARIATIONS DE STOCKS</td><td class="text-right"><?= number_format($Z27,0,',',' ') ?></td></tr>
                            <tr><td>R8G</td><td>Achats de marchandises</td><td class="text-right"><?= number_format($R8G,0,',',' ') ?></td></tr>
                            <tr><td>R8J</td><td>Stocks vendus</td><td class="text-right"><?= number_format($R8J,0,',',' ') ?></td></tr>
                            <tr><td>R8L</td><td>Variations de stocks de marchandises</td><td class="text-right"><?= number_format($R8L,0,',',' ') ?></td></tr>
                            <tr class="subtotal-row"><td colspan="2">Z28 - CHARGES GÉNÉRALES D'EXPLOITATION</td><td class="text-right"><?= number_format($Z28,0,',',' ') ?></td></tr>

                            <!-- S02 -->
                            <tr class="subtotal-row"><td colspan="2">S02 - FRAIS DE PERSONNEL</td><td class="text-right"><?= number_format($S02,0,',',' ') ?></td></tr>
                            <tr><td>S03</td><td>Salaires et traitements</td><td class="text-right"><?= number_format($S03,0,',',' ') ?></td></tr>
                            <tr><td>S04</td><td>Charges sociales</td><td class="text-right"><?= number_format($S04,0,',',' ') ?></td></tr>
                            <tr><td>S05</td><td>Rémunérations versées aux stagiaires</td><td class="text-right"><?= number_format($S05,0,',',' ') ?></td></tr>

                            <!-- S1A -->
                            <tr class="subtotal-row"><td colspan="2">S1A - IMPÔTS ET TAXES</td><td class="text-right"><?= number_format($S1A,0,',',' ') ?></td></tr>
                            <tr><td>S1B</td><td>Autres impôts sur rémunérations</td><td class="text-right"><?= number_format($S1B,0,',',' ') ?></td></tr>
                            <tr><td>S1C</td><td>Autres impôts versés à l'administration</td><td class="text-right"><?= number_format($S1C,0,',',' ') ?></td></tr>
                            <tr><td>S1D</td><td>Impôts directs</td><td class="text-right"><?= number_format($S1D,0,',',' ') ?></td></tr>
                            <tr><td>S1G</td><td>Impôts indirects</td><td class="text-right"><?= number_format($S1G,0,',',' ') ?></td></tr>
                            <tr><td>S1H</td><td>Droits d'enregistrement et de timbre</td><td class="text-right"><?= number_format($S1H,0,',',' ') ?></td></tr>
                            <tr><td>S1J</td><td>Impôts et taxes divers</td><td class="text-right"><?= number_format($S1J,0,',',' ') ?></td></tr>
                            <tr><td>S1K</td><td>Autres impôts versés aux autres organismes</td><td class="text-right"><?= number_format($S1K,0,',',' ') ?></td></tr>

                            <!-- S2A -->
                            <tr class="subtotal-row"><td colspan="2">S2A - AUTRES CHARGES EXTERNES ET DIVERSES</td><td class="text-right"><?= number_format($S2A,0,',',' ') ?></td></tr>
                            <tr><td>S2B</td><td>Services extérieurs</td><td class="text-right"><?= number_format($S2B,0,',',' ') ?></td></tr>
                            <tr><td>S2C</td><td>Redevance de crédit-bail</td><td class="text-right"><?= number_format($S2C,0,',',' ') ?></td></tr>
                            <tr><td>S2D</td><td>Loyers</td><td class="text-right"><?= number_format($S2D,0,',',' ') ?></td></tr>
                            <tr><td>S2F</td><td>Charges locatives et de copropriété</td><td class="text-right"><?= number_format($S2F,0,',',' ') ?></td></tr>
                            <tr><td>S2H</td><td>Entretien et réparation</td><td class="text-right"><?= number_format($S2H,0,',',' ') ?></td></tr>
                            <tr><td>S2J</td><td>Primes d'assurance</td><td class="text-right"><?= number_format($S2J,0,',',' ') ?></td></tr>
                            <tr><td>S2M</td><td>Frais de formation de personnel</td><td class="text-right"><?= number_format($S2M,0,',',' ') ?></td></tr>
                            <tr><td>S2K</td><td>Études et recherches</td><td class="text-right"><?= number_format($S2K,0,',',' ') ?></td></tr>
                            <tr><td>S2L</td><td>Divers</td><td class="text-right"><?= number_format($S2L,0,',',' ') ?></td></tr>
                            <tr><td>S3A</td><td>Autres services extérieurs</td><td class="text-right"><?= number_format($S3A,0,',',' ') ?></td></tr>
                            <tr><td>S3B</td><td>Personnel extérieur</td><td class="text-right"><?= number_format($S3B,0,',',' ') ?></td></tr>
                            <tr><td>S3C</td><td>Rémunérations d'intermédiaires et honoraires</td><td class="text-right"><?= number_format($S3C,0,',',' ') ?></td></tr>
                            <tr><td>S3E</td><td>Publicité, publications et relations publiques</td><td class="text-right"><?= number_format($S3E,0,',',' ') ?></td></tr>
                            <tr><td>S3G</td><td>Transports de biens</td><td class="text-right"><?= number_format($S3G,0,',',' ') ?></td></tr>
                            <tr><td>S3J</td><td>Transports collectifs de personnel</td><td class="text-right"><?= number_format($S3J,0,',',' ') ?></td></tr>
                            <tr><td>S3L</td><td>Déplacements, missions et réceptions</td><td class="text-right"><?= number_format($S3L,0,',',' ') ?></td></tr>
                            <tr><td>S3M</td><td>Achats non stocks de matières et fournitures</td><td class="text-right"><?= number_format($S3M,0,',',' ') ?></td></tr>
                            <tr><td>S3N</td><td>Frais postaux et frais de télécommunication</td><td class="text-right"><?= number_format($S3N,0,',',' ') ?></td></tr>
                            <tr><td>S3P</td><td>Divers</td><td class="text-right"><?= number_format($S3P,0,',',' ') ?></td></tr>
                            <tr><td>S4A</td><td>Charges diverses d'exploitation</td><td class="text-right"><?= number_format($S4A,0,',',' ') ?></td></tr>
                            <tr><td>S4B</td><td>Redevances pour concessions</td><td class="text-right"><?= number_format($S4B,0,',',' ') ?></td></tr>
                            <tr><td>S4D</td><td>Indemnités de fonction versées</td><td class="text-right"><?= number_format($S4D,0,',',' ') ?></td></tr>
                            <tr><td>S4I</td><td>Frais de tenue d'assemblée</td><td class="text-right"><?= number_format($S4I,0,',',' ') ?></td></tr>
                            <tr><td>S4K</td><td>Moins-value de cession sur immobilisations</td><td class="text-right"><?= number_format($S4K,0,',',' ') ?></td></tr>
                            <tr><td>S4L</td><td>Sur immobilisations incorporelles et corporelles</td><td class="text-right"><?= number_format($S4L,0,',',' ') ?></td></tr>
                            <tr><td>S4M</td><td>Sur immobilisations financières</td><td class="text-right"><?= number_format($S4M,0,',',' ') ?></td></tr>
                            <tr><td>S4P</td><td>Transferts de produits d'exploitation non financière</td><td class="text-right"><?= number_format($S4P,0,',',' ') ?></td></tr>
                            <tr><td>S4Q</td><td>Produits rétrocédés</td><td class="text-right"><?= number_format($S4Q,0,',',' ') ?></td></tr>
                            <tr><td>S4R</td><td>Autres transferts de produits</td><td class="text-right"><?= number_format($S4R,0,',',' ') ?></td></tr>
                            <tr><td>S4S</td><td>Autres charges diverses d'exploitation</td><td class="text-right"><?= number_format($S4S,0,',',' ') ?></td></tr>

                            <!-- T50, T51, T6B, T80 -->
                            <tr class="subtotal-row"><td colspan="2">T50 - DOTATIONS FONDS RISQUES GENERAUX</td><td class="text-right"><?= number_format($T50,0,',',' ') ?></td></tr>
                            <tr class="subtotal-row"><td colspan="2">T51 - DOTATIONS AUX AMORTISSEMENTS ET PROVISIONS</td><td class="text-right"><?= number_format($T51,0,',',' ') ?></td></tr>
                            <tr><td>T53</td><td>Dotations aux amortissements de charges à répartir</td><td class="text-right"><?= number_format($T53,0,',',' ') ?></td></tr>
                            <tr><td>T54</td><td>Dotations aux amortissements des immobilisations d'exploitation</td><td class="text-right"><?= number_format($T54,0,',',' ') ?></td></tr>
                            <tr><td>T55</td><td>Dotations aux amortissements des immobilisations hors exploitation</td><td class="text-right"><?= number_format($T55,0,',',' ') ?></td></tr>
                            <tr><td>T56</td><td>Dotations aux provisions pour dépréciation des immobilisations en cours</td><td class="text-right"><?= number_format($T56,0,',',' ') ?></td></tr>
                            <tr><td>T57</td><td>Dotations aux provisions pour dépréciation des immobilisations d'exploitation</td><td class="text-right"><?= number_format($T57,0,',',' ') ?></td></tr>
                            <tr><td>T58</td><td>Dotations aux provisions pour dépréciation des immobilisations hors exploitation</td><td class="text-right"><?= number_format($T58,0,',',' ') ?></td></tr>
                            <tr class="subtotal-row"><td colspan="2">T6B - DOTATIONS PROVISIONS ET PERTES SUR CREANCES</td><td class="text-right"><?= number_format($T6B,0,',',' ') ?></td></tr>
                            <tr><td>T6C</td><td>Dotations aux provisions sur créances en souffrance</td><td class="text-right"><?= number_format($T6C,0,',',' ') ?></td></tr>
                            <tr><td>T6D</td><td>Provisions sur créances en souffrance de 6 mois au plus</td><td class="text-right"><?= number_format($T6D,0,',',' ') ?></td></tr>
                            <tr><td>T6E</td><td>Provisions sur créances en souffrance de 6 à 12 mois</td><td class="text-right"><?= number_format($T6E,0,',',' ') ?></td></tr>
                            <tr><td>T6F</td><td>Provisions sur créances en souffrance de 12 à 24 mois</td><td class="text-right"><?= number_format($T6F,0,',',' ') ?></td></tr>
                            <tr><td>T6G</td><td>Dotations aux provisions pour dépréciation des autres éléments d'actif</td><td class="text-right"><?= number_format($T6G,0,',',' ') ?></td></tr>
                            <tr><td>T6H</td><td>Dotations aux provisions pour risques et charges</td><td class="text-right"><?= number_format($T6H,0,',',' ') ?></td></tr>
                            <tr><td>T6J</td><td>Dotations aux provisions réglementées</td><td class="text-right"><?= number_format($T6J,0,',',' ') ?></td></tr>
                            <tr><td>T6K</td><td>Pertes sur créances irrécouvrables couvertes</td><td class="text-right"><?= number_format($T6K,0,',',' ') ?></td></tr>
                            <tr><td>T6L</td><td>Pertes sur créances irrécouvrables non couvertes</td><td class="text-right"><?= number_format($T6L,0,',',' ') ?></td></tr>
                            <tr><td>T80</td><td>Charges exceptionnelles</td><td class="text-right"><?= number_format($T80,0,',',' ') ?></td></tr>
                            <tr><td>T81</td><td>Pertes sur exercices antérieurs</td><td class="text-right"><?= number_format($T81,0,',',' ') ?></td></tr>
                            <tr><td>T82</td><td>Impôts sur les excédents</td><td class="text-right"><?= number_format($T82,0,',',' ') ?></td></tr>

                            <tr class="total-row"><td colspan="2"><strong>TOTAL CHARGES</strong></td><td class="text-right"><strong><?= number_format($total_charges,0,',',' ') ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Produits -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><i class="fas fa-arrow-up"></i> PRODUITS</div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>CODE</th><th>LIBELLÉ</th><th class="text-right">Montant (FCFA)</th></tr></thead>
                        <tbody>
                            <!-- V08 -->
                            <tr class="subtotal-row"><td colspan="2">V08 - PRODUITS SUR OPÉRATIONS AVEC LES IF</td><td class="text-right"><?= number_format($V08,0,',',' ') ?></td></tr>
                            <tr><td>V1A</td><td>Intérêts sur comptes ordinaires débiteurs</td><td class="text-right"><?= number_format($V1A,0,',',' ') ?></td></tr>
                            <tr><td>V1B</td><td>Organe financier</td><td class="text-right"><?= number_format($V1B,0,',',' ') ?></td></tr>
                            <tr><td>V1C</td><td>Caisse centrale</td><td class="text-right"><?= number_format($V1C,0,',',' ') ?></td></tr>
                            <tr><td>V1D</td><td>Trésor public</td><td class="text-right"><?= number_format($V1D,0,',',' ') ?></td></tr>
                            <tr><td>V1E</td><td>CCP</td><td class="text-right"><?= number_format($V1E,0,',',' ') ?></td></tr>
                            <tr><td>V1F</td><td>Banques et correspondants</td><td class="text-right"><?= number_format($V1F,0,',',' ') ?></td></tr>
                            <tr><td>V1H</td><td>Établissements financiers</td><td class="text-right"><?= number_format($V1H,0,',',' ') ?></td></tr>
                            <tr><td>V1I</td><td>SFD</td><td class="text-right"><?= number_format($V1I,0,',',' ') ?></td></tr>
                            <tr><td>V1K</td><td>Autres Institutions financières</td><td class="text-right"><?= number_format($V1K,0,',',' ') ?></td></tr>
                            <tr><td>V1L</td><td>Intérêts sur autres comptes de dépôts débiteurs</td><td class="text-right"><?= number_format($V1L,0,',',' ') ?></td></tr>
                            <tr><td>V1Q</td><td>Dépôts à terme constitués</td><td class="text-right"><?= number_format($V1Q,0,',',' ') ?></td></tr>
                            <tr><td>V1R</td><td>Dépôts de garantie constitués</td><td class="text-right"><?= number_format($V1R,0,',',' ') ?></td></tr>
                            <tr><td>V1S</td><td>Autres dépôts constitués</td><td class="text-right"><?= number_format($V1S,0,',',' ') ?></td></tr>
                            <tr><td>V2A</td><td>Intérêts sur comptes de prêts</td><td class="text-right"><?= number_format($V2A,0,',',' ') ?></td></tr>
                            <tr><td>V2C</td><td>Prêts à moins d'un an</td><td class="text-right"><?= number_format($V2C,0,',',' ') ?></td></tr>
                            <tr><td>V2G</td><td>Prêts à terme</td><td class="text-right"><?= number_format($V2G,0,',',' ') ?></td></tr>
                            <tr><td>V2Q</td><td>Autres intérêts</td><td class="text-right"><?= number_format($V2Q,0,',',' ') ?></td></tr>
                            <tr><td>V2S</td><td>Divers intérêts</td><td class="text-right"><?= number_format($V2S,0,',',' ') ?></td></tr>
                            <tr><td>V2T</td><td>Commissions</td><td class="text-right"><?= number_format($V2T,0,',',' ') ?></td></tr>

                            <!-- V3A -->
                            <tr class="subtotal-row"><td colspan="2">V3A - PRODUITS SUR OPÉRATIONS AVEC LES MEMBRES</td><td class="text-right"><?= number_format($V3A,0,',',' ') ?></td></tr>
                            <tr><td>V3B</td><td>Intérêts sur crédits aux membres</td><td class="text-right"><?= number_format($V3B,0,',',' ') ?></td></tr>
                            <tr><td>V3G</td><td>Crédits à court terme</td><td class="text-right"><?= number_format($V3G,0,',',' ') ?></td></tr>
                            <tr><td>V3M</td><td>Crédits à moyen terme</td><td class="text-right"><?= number_format($V3M,0,',',' ') ?></td></tr>
                            <tr><td>V3N</td><td>Crédits à long terme</td><td class="text-right"><?= number_format($V3N,0,',',' ') ?></td></tr>
                            <tr><td>V3R</td><td>Autres intérêts</td><td class="text-right"><?= number_format($V3R,0,',',' ') ?></td></tr>
                            <tr><td>V3T</td><td>Divers intérêts</td><td class="text-right"><?= number_format($V3T,0,',',' ') ?></td></tr>
                            <tr><td>V3X</td><td>Commissions</td><td class="text-right"><?= number_format($V3X,0,',',' ') ?></td></tr>

                            <!-- Agrégats produits -->
                            <tr class="subtotal-row"><td colspan="2">Z31 - MARGE D'INTÉRÊT DÉFICITAIRE</td><td class="text-right"><?= number_format($Z31,0,',',' ') ?></td></tr>
                            <tr><td colspan="2">Z32 - TOTAL PRODUITS D'INTÉRÊTS</td><td class="text-right"><?= number_format($Z32,0,',',' ') ?></td></tr>
                            <tr class="subtotal-row"><td colspan="2">Z33 - AUTRES CHARGES FINANCIÈRES NETTES</td><td class="text-right"><?= number_format($Z33,0,',',' ') ?></td></tr>
                            <tr><td colspan="2">Z34 - AUTRES PRODUITS FINANCIERS NETS</td><td class="text-right"><?= number_format($Z34,0,',',' ') ?></td></tr>
                            <tr><td colspan="2">Z35 - MARGE D'INTÉRÊT DÉFICITAIRE (idem)</td><td class="text-right"><?= number_format($Z35,0,',',' ') ?></td></tr>
                            <tr><td colspan="2">Z36 - CHARGE FINANCIÈRE NETTE</td><td class="text-right"><?= number_format($Z36,0,',',' ') ?></td></tr>
                            <tr class="subtotal-row"><td colspan="2">Z37 - PRODUITS GÉNÉRAUX D'EXPLOITATION</td><td class="text-right"><?= number_format($Z37,0,',',' ') ?></td></tr>

                            <!-- V4B -->
                            <tr class="subtotal-row"><td colspan="2">V4B - PRODUITS SUR OPÉRATIONS SUR TITRES ET DIVERSES</td><td class="text-right"><?= number_format($V4B,0,',',' ') ?></td></tr>
                            <tr><td>V4C</td><td>Produits et profits sur titres de placement</td><td class="text-right"><?= number_format($V4C,0,',',' ') ?></td></tr>
                            <tr><td>V4D</td><td>Intérêts sur crédits au personnel non membre</td><td class="text-right"><?= number_format($V4D,0,',',' ') ?></td></tr>
                            <tr><td>V4E</td><td>Produits sur opérations diverses</td><td class="text-right"><?= number_format($V4E,0,',',' ') ?></td></tr>
                            <tr><td>V4F</td><td>Commissions</td><td class="text-right"><?= number_format($V4F,0,',',' ') ?></td></tr>

                            <!-- V5B -->
                            <tr class="subtotal-row"><td colspan="2">V5B - PRODUITS SUR IMMOBILISATIONS FINANCIERES</td><td class="text-right"><?= number_format($V5B,0,',',' ') ?></td></tr>
                            <tr><td>V5C</td><td>Prêts et titres subordonnés</td><td class="text-right"><?= number_format($V5C,0,',',' ') ?></td></tr>
                            <tr><td>V5D</td><td>Dividendes sur titres de participation</td><td class="text-right"><?= number_format($V5D,0,',',' ') ?></td></tr>
                            <tr><td>V5F</td><td>Produits sur titres d'investissement</td><td class="text-right"><?= number_format($V5F,0,',',' ') ?></td></tr>

                            <!-- V5G -->
                            <tr class="subtotal-row"><td colspan="2">V5G - PRODUITS SUR CREDIT-BAIL ET ASSIMILEES</td><td class="text-right"><?= number_format($V5G,0,',',' ') ?></td></tr>
                            <tr><td>V5H</td><td>Produits sur opérations de crédit-bail</td><td class="text-right"><?= number_format($V5H,0,',',' ') ?></td></tr>
                            <tr><td>V5J</td><td>Loyers</td><td class="text-right"><?= number_format($V5J,0,',',' ') ?></td></tr>
                            <tr><td>V5K</td><td>Reprises de provisions</td><td class="text-right"><?= number_format($V5K,0,',',' ') ?></td></tr>
                            <tr><td>V5L</td><td>Plus-values sur cession</td><td class="text-right"><?= number_format($V5L,0,',',' ') ?></td></tr>
                            <tr><td>V5M</td><td>Autres produits</td><td class="text-right"><?= number_format($V5M,0,',',' ') ?></td></tr>
                            <tr><td>V5N</td><td>Produits sur opérations LOA</td><td class="text-right"><?= number_format($V5N,0,',',' ') ?></td></tr>
                            <tr><td>V5P</td><td>Loyers</td><td class="text-right"><?= number_format($V5P,0,',',' ') ?></td></tr>
                            <tr><td>V5Q</td><td>Reprises de provisions</td><td class="text-right"><?= number_format($V5Q,0,',',' ') ?></td></tr>
                            <tr><td>V5R</td><td>Plus-values sur cession</td><td class="text-right"><?= number_format($V5R,0,',',' ') ?></td></tr>
                            <tr><td>V5S</td><td>Autres produits</td><td class="text-right"><?= number_format($V5S,0,',',' ') ?></td></tr>
                            <tr><td>V5T</td><td>Produits sur opérations de location-vente</td><td class="text-right"><?= number_format($V5T,0,',',' ') ?></td></tr>
                            <tr><td>V5V</td><td>Loyers</td><td class="text-right"><?= number_format($V5V,0,',',' ') ?></td></tr>
                            <tr><td>V5W</td><td>Reprises de provisions</td><td class="text-right"><?= number_format($V5W,0,',',' ') ?></td></tr>
                            <tr><td>V5X</td><td>Plus-values sur cession</td><td class="text-right"><?= number_format($V5X,0,',',' ') ?></td></tr>
                            <tr><td>V5Y</td><td>Autres produits</td><td class="text-right"><?= number_format($V5Y,0,',',' ') ?></td></tr>

                            <!-- V6A -->
                            <tr class="subtotal-row"><td colspan="2">V6A - PRODUITS SUR OPERATIONS DE CHANGE</td><td class="text-right"><?= number_format($V6A,0,',',' ') ?></td></tr>
                            <tr><td>V6B</td><td>Gains sur opération de change</td><td class="text-right"><?= number_format($V6B,0,',',' ') ?></td></tr>
                            <tr><td>V6C</td><td>Commissions</td><td class="text-right"><?= number_format($V6C,0,',',' ') ?></td></tr>

                            <!-- V6F -->
                            <tr class="subtotal-row"><td colspan="2">V6F - PRODUITS SUR OPERATIONS HORS BILAN</td><td class="text-right"><?= number_format($V6F,0,',',' ') ?></td></tr>
                            <tr><td>V6K</td><td>Engagements de financement donnés aux IF</td><td class="text-right"><?= number_format($V6K,0,',',' ') ?></td></tr>
                            <tr><td>V6L</td><td>Engagements de financement donnés aux membres</td><td class="text-right"><?= number_format($V6L,0,',',' ') ?></td></tr>
                            <tr><td>V6N</td><td>Engagements de garantie donnés aux IF</td><td class="text-right"><?= number_format($V6N,0,',',' ') ?></td></tr>
                            <tr><td>V6P</td><td>Engagements de garantie donnés aux membres</td><td class="text-right"><?= number_format($V6P,0,',',' ') ?></td></tr>
                            <tr><td>V6Q</td><td>Engagements sur titres</td><td class="text-right"><?= number_format($V6Q,0,',',' ') ?></td></tr>
                            <tr><td>V6R</td><td>Autres engagements donnés</td><td class="text-right"><?= number_format($V6R,0,',',' ') ?></td></tr>
                            <tr><td>V6S</td><td>Opérations pour compte de tiers</td><td class="text-right"><?= number_format($V6S,0,',',' ') ?></td></tr>

                            <!-- V6U -->
                            <tr class="subtotal-row"><td colspan="2">V6U - PRODUITS SUR PRESTATIONS DE SERVICES FINANCIERS</td><td class="text-right"><?= number_format($V6U,0,',',' ') ?></td></tr>
                            <tr><td>V6V</td><td>Moyens de paiement</td><td class="text-right"><?= number_format($V6V,0,',',' ') ?></td></tr>
                            <tr><td>V6W</td><td>Autres prestations</td><td class="text-right"><?= number_format($V6W,0,',',' ') ?></td></tr>

                            <!-- V7A -->
                            <tr class="subtotal-row"><td colspan="2">V7A - AUTRES PRODUITS D'EXPLOITATION FINANCIERE</td><td class="text-right"><?= number_format($V7A,0,',',' ') ?></td></tr>
                            <tr><td>V7B</td><td>Plus-values sur cession d'éléments d'actif</td><td class="text-right"><?= number_format($V7B,0,',',' ') ?></td></tr>
                            <tr><td>V7C</td><td>Transfert de charges d'exploitation financières</td><td class="text-right"><?= number_format($V7C,0,',',' ') ?></td></tr>
                            <tr><td>V7D</td><td>Divers produits d'exploitation financière</td><td class="text-right"><?= number_format($V7D,0,',',' ') ?></td></tr>

                            <!-- V8A -->
                            <tr class="subtotal-row"><td colspan="2">V8A - VENTES ET VARIATION DE STOCKS</td><td class="text-right"><?= number_format($V8A,0,',',' ') ?></td></tr>
                            <tr><td>V8B</td><td>Marge commerciale</td><td class="text-right"><?= number_format($V8B,0,',',' ') ?></td></tr>
                            <tr><td>V8C</td><td>Vente de marchandises</td><td class="text-right"><?= number_format($V8C,0,',',' ') ?></td></tr>
                            <tr><td>V8D</td><td>Variations négatives de stocks de marchandises</td><td class="text-right"><?= number_format($V8D,0,',',' ') ?></td></tr>

                            <!-- W4A -->
                            <tr class="subtotal-row"><td colspan="2">W4A - PRODUITS DIVERS D'EXPLOITATION</td><td class="text-right"><?= number_format($W4A,0,',',' ') ?></td></tr>
                            <tr><td>W4B</td><td>Redevances pour concessions</td><td class="text-right"><?= number_format($W4B,0,',',' ') ?></td></tr>
                            <tr><td>W4D</td><td>Indemnités de fonction reçues</td><td class="text-right"><?= number_format($W4D,0,',',' ') ?></td></tr>
                            <tr><td>W4G</td><td>Plus-value de cession</td><td class="text-right"><?= number_format($W4G,0,',',' ') ?></td></tr>
                            <tr><td>W4H</td><td>Sur immobilisations incorporelles et corporelles</td><td class="text-right"><?= number_format($W4H,0,',',' ') ?></td></tr>
                            <tr><td>W4J</td><td>Sur immobilisations financières</td><td class="text-right"><?= number_format($W4J,0,',',' ') ?></td></tr>
                            <tr><td>W4K</td><td>Revenus des immeubles hors exploitation</td><td class="text-right"><?= number_format($W4K,0,',',' ') ?></td></tr>
                            <tr><td>W4L</td><td>Transferts de charges d'exploitation non financière</td><td class="text-right"><?= number_format($W4L,0,',',' ') ?></td></tr>
                            <tr><td>W4M</td><td>Charges refacturées</td><td class="text-right"><?= number_format($W4M,0,',',' ') ?></td></tr>
                            <tr><td>W4N</td><td>Charges à répartir sur plusieurs exercices</td><td class="text-right"><?= number_format($W4N,0,',',' ') ?></td></tr>
                            <tr><td>W4P</td><td>Autres transferts de charges</td><td class="text-right"><?= number_format($W4P,0,',',' ') ?></td></tr>
                            <tr><td>W4Q</td><td>Autres produits divers d'exploitation</td><td class="text-right"><?= number_format($W4Q,0,',',' ') ?></td></tr>

                            <!-- W50, W53 -->
                            <tr class="subtotal-row"><td colspan="2">W50 - PRODUCTION IMMOBILISEE</td><td class="text-right"><?= number_format($W50,0,',',' ') ?></td></tr>
                            <tr><td>W51</td><td>Immobilisations corporelles</td><td class="text-right"><?= number_format($W51,0,',',' ') ?></td></tr>
                            <tr><td>W52</td><td>Immobilisations incorporelles</td><td class="text-right"><?= number_format($W52,0,',',' ') ?></td></tr>
                            <tr><td>W53</td><td>Subventions d'exploitation</td><td class="text-right"><?= number_format($W53,0,',',' ') ?></td></tr>

                            <!-- X51, X6B, X80 -->
                            <tr class="subtotal-row"><td colspan="2">X51 - REPRISES D'AMORTISSEMENTS ET PROVISIONS</td><td class="text-right"><?= number_format($X51,0,',',' ') ?></td></tr>
                            <tr><td>X54</td><td>Reprises d'amortissements des immobilisations</td><td class="text-right"><?= number_format($X54,0,',',' ') ?></td></tr>
                            <tr><td>X56</td><td>Reprises de provisions sur immobilisations</td><td class="text-right"><?= number_format($X56,0,',',' ') ?></td></tr>
                            <tr class="subtotal-row"><td colspan="2">X6B - REPRISES DE PROVISIONS ET RECUPERATIONS</td><td class="text-right"><?= number_format($X6B,0,',',' ') ?></td></tr>
                            <tr><td>X6C</td><td>Reprises de provisions sur créances en souffrance</td><td class="text-right"><?= number_format($X6C,0,',',' ') ?></td></tr>
                            <tr><td>X6D</td><td>Céances en souffrance de 6 mois au plus</td><td class="text-right"><?= number_format($X6D,0,',',' ') ?></td></tr>
                            <tr><td>X6E</td><td>Céances en souffrance de 6 à 12 mois</td><td class="text-right"><?= number_format($X6E,0,',',' ') ?></td></tr>
                            <tr><td>X6F</td><td>Céances en souffrance de 12 à 24 mois</td><td class="text-right"><?= number_format($X6F,0,',',' ') ?></td></tr>
                            <tr><td>X6G</td><td>Reprises de provisions pour dépréciations des autres éléments d'actifs</td><td class="text-right"><?= number_format($X6G,0,',',' ') ?></td></tr>
                            <tr><td>X6H</td><td>Reprises de provisions pour risques et charges</td><td class="text-right"><?= number_format($X6H,0,',',' ') ?></td></tr>
                            <tr><td>X6J</td><td>Récupération sur créances amorties</td><td class="text-right"><?= number_format($X6J,0,',',' ') ?></td></tr>
                            <tr><td>X6I</td><td>Reprise de provisions réglementées</td><td class="text-right"><?= number_format($X6I,0,',',' ') ?></td></tr>
                            <tr><td>X80</td><td>Produits exceptionnels</td><td class="text-right"><?= number_format($X80,0,',',' ') ?></td></tr>
                            <tr><td>X81</td><td>Profits sur exercices antérieurs</td><td class="text-right"><?= number_format($X81,0,',',' ') ?></td></tr>

                            <tr class="total-row"><td colspan="2"><strong>TOTAL PRODUITS</strong></td><td class="text-right"><strong><?= number_format($total_produits,0,',',' ') ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Résultat -->
    <div class="card">
        <div class="card-header"><i class="fas fa-balance-scale"></i> RÉSULTAT DE L'EXERCICE</div>
        <div class="info-box" style="text-align:center; display:block;">
            <strong>Résultat = Total Produits - Total Charges</strong><br><br>
            <span class="<?= $resultat_type=='EXCEDENT'?'excedent':'deficit' ?>"><?= number_format(abs($resultat_net),0,',',' ') ?> FCFA</span><br>
            <span style="font-size:0.9rem;">L'exercice <?= $exercice ?> se solde par un <strong><?= $resultat_type ?></strong> de <?= number_format(abs($resultat_net),0,',',' ') ?> FCFA</span>
        </div>
    </div>

    <div class="page-footer">
        <i class="fas fa-calendar-alt"></i> Document généré le <?= date('d/m/Y à H:i:s') ?> – Période : <?= $exercice ?> (<?= ucfirst($type_periode) ?>) arrêté au <?= date('d/m/Y', strtotime($date_fin_periode)) ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
            for (let m=1;m<=12;m++) { const s=(m===currentMois)?'selected':''; const n=new Date(2000,m-1,1).toLocaleString('fr',{month:'long'}); html+=`<option value="${m}" ${s}>${String(m).padStart(2,'0')} - ${n}</option>`; }
            html += '</select>';
        } else if (type === 'trimestre') {
            html = '<label>Trimestre</label><select name="trimestre" id="trimestreSelect">';
            for (let t=1;t<=4;t++) { const s=(t===currentTrimestre)?'selected':''; html+=`<option value="${t}" ${s}>${t}${t===1?'er':'ème'} Trimestre</option>`; }
            html += '</select>';
        } else if (type === 'semestre') {
            html = '<label>Semestre</label><select name="semestre" id="semestreSelect">';
            for (let s=1;s<=2;s++) { const sel=(s===currentSemestre)?'selected':''; html+=`<option value="${s}" ${sel}>${s}${s===1?'er':'e'} semestre</option>`; }
            html += '</select>';
        } else {
            html = '<label>Période</label><input type="text" disabled value="Année complète" style="background:#f3f4f6;">';
        }
        container.innerHTML = html;
    }

    // Exporter PDF avec AJAX (téléchargement direct sans nouvelle page)
    async function exporterPDF() {
        const form = document.getElementById('filtersForm');
        const formData = new FormData(form);
        formData.append('format', 'pdf');
        formData.append('ajax', '1');
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            if (!response.ok) throw new Error('Erreur réseau');
            const blob = await response.blob();
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'DIMF_2080_CompteResultat_<?= $exercice ?>_<?= $type_periode ?>.pdf';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(link.href);
        } catch (e) {
            alert('Erreur lors de la génération du PDF : ' + e.message);
        }
    }

    // Exporter Excel (téléchargement direct)
    function exporterExcel() {
        const form = document.getElementById('filtersForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'format';
        input.value = 'excel';
        form.appendChild(input);
        form.submit();
        form.removeChild(input);
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateDynamicSelect();
        document.getElementById('typePeriodeSelect').addEventListener('change', updateDynamicSelect);
        document.getElementById('btnPdf').addEventListener('click', exporterPDF);
    });
</script>
</body>
</html>