<?php
header ('Content-type: text/html; charset=ISO-8859-1');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(
    E_ERROR
  | E_PARSE
  | E_CORE_ERROR
  | E_COMPILE_ERROR
  | E_USER_ERROR
  | E_RECOVERABLE_ERROR
);
mysqli_report(MYSQLI_REPORT_OFF);


session_start();
require_once("includes/dbcont.php");
require_once("includes/funcoes2025.php");
require_once("includes/utils.php");
include("includes/varslogin.php");

//require 'includes/utilscheck.php'; // cria $cont (mysqli)
// ========== CONFIG ==========
$REMOTE_BASE_URL = 'https://bigfootsport.pt/artigos';

$IMAGES_DIR_FS  = 'artigos';          // caminho web para imagens
$IMAGES_DIR_WEB   = 'artigos'; // caminho fsico
$IMG_EXTS        = array('jpg','jpeg','png','webp');
$conta =$_SESSION['conta'];
$taxaIva="23";
$site=$_SESSION[siteorigem];
if ($_GET[copiaID]>0)
{
    $ccidCOP=$_GET[copiaID];
    include("includes/copiarIDparaSport.php");
          echo "<script>
              setTimeout(function(){
              window.location.href = 'sacodecompras.php';
              },2000);
          </script>";
}


// ========== CSRF ==========
if (empty($_SESSION['csrf_cart'])) {
  if (function_exists('openssl_random_pseudo_bytes')) {
    $_SESSION['csrf_cart'] = bin2hex(openssl_random_pseudo_bytes(16));
  } else {
    $_SESSION['csrf_cart'] = bin2hex(md5(uniqid(mt_rand(), true), true));
  }
}
$CSRF = $_SESSION['csrf_cart'];

// ========== CONTA/EMAIL DO CLIENTE ==========
$contaEmail = '';
if (isset($_SESSION['conta']) && $_SESSION['conta']!=='') {
  $contaEmail = (string)$_SESSION['conta'];
} elseif (isset($_GET['conta']) && $_GET['conta']!=='') {
  $contaEmail = (string)$_GET['conta'];
}
$esta_a_fechar = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['metodo']));
// ========== VARS DE FATURAO (assegura que existem) ==========
if (!isset($nomefat))   $nomefat   = '';
if (!isset($moradafat)) $moradafat = '';
if (!isset($localfat))  $localfat  = '';
if (!isset($cposfat))   $cposfat   = '';
if (!isset($paisfat))   $paisfat   = 'Portugal';

// ========== HELPERS ==========
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function numv($s){ return (float)$s; }
function intval_safe($s){ return (int)$s; }
function split_cp($cp){
  $cp = trim((string)$cp);
  if (strpos($cp,'-')!==false) return explode('-', $cp, 2);
  return array($cp, '');
}
/*
function findImageForRef($ref, $dirFs, $dirWeb, $exts){
  foreach ($exts as $ext) {
    $fn = $ref.'.'.$ext; $fs = $dirFs.'/'.$fn;
    if (is_file($fs)) return $dirWeb.'/'.$fn;
  }
  return $dirWeb.'/placeholder.jpg';
}
*/
function findImageForRefLocal($ref, $dirFs, $dirWeb, $exts) {
    $dirFs  = rtrim($dirFs, '/');
    $dirWeb = rtrim($dirWeb, '/');

    foreach ($exts as $ext) {
        $fn = $ref . '.' . $ext;
        $fs = $dirFs . '/' . $fn;
        if (is_file($fs)) {
            return $dirWeb . '/' . $fn;
        }
    }
    // placeholder local, se quiseres
    return $dirWeb . '/sf.jpg';
}

function urlExists($url) {
    $headers = @get_headers($url);
    if (!$headers) return false;

    foreach ($headers as $line) {
        if (preg_match('~^HTTP/.*\s(200|304)\s~', $line)) {
            return true;
        }
    }
    return false;
}

function findImageForRefRemote($ref, $baseUrl, $exts) {
    $baseUrl = rtrim($baseUrl, '/');

    foreach ($exts as $ext) {
        $fn  = $ref . '.' . $ext;
        $url = $baseUrl . '/' . $fn;
        if (urlExists($url)) {
            return $url;
        }
    }
    // placeholder remoto
    return $baseUrl . '/sf.jpg';
}

// ========== AO: REMOVER ITEM DO CARRINHO ==========
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='remover') {
  if (!isset($_POST['csrf']) || $_POST['csrf'] !== $CSRF) {
    $err = 'Token invlido. Recarrega a pgina.';
  } else {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id>0) {
      $contaEsc = mysqli_real_escape_string($cont, $contaEmail);
      $sqlDel = "DELETE FROM encomendasonline
                 WHERE id=$id
                   AND (numero IS NULL OR numero=0)
                   AND (conta='$contaEsc')
                 LIMIT 1";
      if (mysqli_query($cont, $sqlDel) && mysqli_affected_rows($cont)>0) {
        $msg = 'Artigo removido do carrinho.';
      } else {
        $err = 'Nao foi possivel remover este artigo.';
      }
    } else {
      $err = 'ID invalido.';
    }
  }
}

// ========== BUSCA ITENS DO CARRINHO ==========
$itens = array(); $totQtd=0; $totVal=0.0;
if ($contaEmail!=='') {
  $contaEsc = mysqli_real_escape_string($cont, $contaEmail);
  $sql = "SELECT id, artigo, descricao, tamanho, quantidade, valorunitario, total,descontoP,descontoV,cupapl, site
          FROM encomendasonline
          WHERE conta='$contaEsc' AND (numero IS NULL OR numero=0)
          ORDER BY id DESC";
         // echo "<br><br><br><br><br>$sql;";
  if ($res = mysqli_query($cont,$sql)) {
    while($row = mysqli_fetch_assoc($res)) {
      $q = intval_safe($row['quantidade']);
      $ccid = $row['id'];
      $descontoP = $row['descontoP'];
      $cupapl = $row['cupapl'];
      $siteOr = $row['site'];
      $vu = isset($row['valorunitario']) ? numv($row['valorunitario']) : 0;
      $pvpsd = isset($row['descontoV']) ? numv($row['descontoV']) : 0;
      $ln = isset($row['total']) ? numv($row['total']) : ($q * $vu);
      $totQtd += $q; $totVal += $ln;
      $row['_qtd']=$q; $row['_vu']=$vu; $row['_linha']=$ln;
      $row['_ccid']=$ccid;
      $itens[] = $row;
    }
    mysqli_free_result($res);
  }
}
if (!isset($_SESSION['portes'])) $_SESSION['portes'] = 0;


$SQLvervazio="select * from  `encomendasonline` where conta='$conta' and numero=0 limit 1" ;
$resultvv=mysqli_query($cont, $SQLvervazio);
$nrvv=mysqli_num_rows($resultvv);
if ($nrvv>0)
{

    // --- MODO DE ENTREGA (garante que "levantamento em loja" n□o recalcula portes) ---
$modo_entrega = isset($_SESSION['checkout_comoreceber']) ? $_SESSION['checkout_comoreceber'] : 'transportadora';
// Se vier pela URL (select de loja), assume levantamento em loja
if (isset($_GET['idloja']) && (int)$_GET['idloja'] > 0 && (int)$_GET['idloja'] < 20) {
    $modo_entrega = 'levantaremloja';
    $_SESSION['checkout_comoreceber'] = 'levantaremloja';
    $_SESSION['checkout_idloc'] = (int)$_GET['idloja'];
}
// For□a portes a zero em loja
if ($modo_entrega === 'levantaremloja') {
    $_SESSION['portes'] = 0;
    $_SESSION['taxacobranca'] = 0;
}

// se est□ a finalizar pagamento, N□O recalcula portes (mant□m o valor j□ calculado para a morada escolhida)


if (($_SESSION['loged']=='sim') && ($_SESSION['conta']!=''))
{
    // ? nunca mexer em portes no fecho
    if ($esta_a_fechar) {
        // mant□m exatamente o valor j□ calculado
        // n□o faz nada aqui
    }
    else
    {
        // s□ recalcula ANTES do pagamento
        if ($modo_entrega === 'levantaremloja') {
            $_SESSION['portes'] = 0;
            $_SESSION['taxacobranca'] = 0;
        } else {
            include "includes/calcular_portes.php";
        }
    }
}



    if ($_POST[Tcupao]!='')
    {
        include "includes/aplcup.php";
    }
}

$taxacobranca=0;
$_SESSION[portesacabecaMBcobranca]=0;
$portes=$_SESSION[portes];
$_SESSION[taxacobranca]=0;
if ($_POST['metodo']=='cobranca')
{
    $taxacobranca=$_SESSION[socobranca];
    $_SESSION[taxacobranca]=$_SESSION[socobranca];
    $_SESSION[portesacabecaMBcobranca]=$taxacobranca+$portes;
}
$_SESSION[portestotais]=$portes+$taxacobranca;

$descontoCupao = 0.00;
$totalFinal = max(0, $totVal - $descontoCupao + $portes+$taxacobranca);

// ========== LOJAS (contribuinte < 0) ==========
$lojas = array();
if ($resL = mysqli_query($cont, "SELECT id, nome FROM clientes_locais WHERE contribuinte < 0 and id <= 20 ORDER BY nome ASC")) {
  while($r = mysqli_fetch_assoc($resL)) $lojas[] = $r;
  mysqli_free_result($resL);
}

// ========== MORADAS DO CLIENTE (email = conta) ==========
$enderecos = array();
if ($contaEmail!=='') {
  $sqlE = "SELECT id, nome, morada, localidade, cep4, cep3, pais, telefone
           FROM clientes_locais
           WHERE email='".mysqli_real_escape_string($cont,$contaEmail)."' AND contribuinte >= 0
           ORDER BY id DESC";
  if ($resE = mysqli_query($cont,$sqlE)) {
    while($r = mysqli_fetch_assoc($resE)) $enderecos[] = $r;
    mysqli_free_result($resE);
  }
}

// ========== AO: ADICIONAR NOVA MORADA ==========
$msgAddr=''; $errAddr='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='add_addr') {
  if (!isset($_POST['csrf']) || $_POST['csrf']!==$CSRF) {
    $errAddr = 'Token invlido.';
  } else {
    $nome = trim((string)@$_POST['novo_nome']);
    $mor  = trim((string)@$_POST['novo_morada']);
    $loc  = trim((string)@$_POST['novo_localidade']);
    $cp   = trim((string)@$_POST['novo_cp']);
    $tel  = trim((string)@$_POST['novo_telefone']);
    $pais = trim((string)@$_POST['novo_pais']);
    list($cep4,$cep3)=split_cp($cp);
    if ($nome===''||$mor===''||$loc===''||$pais==='') {
      $errAddr='Preencha pelo menos nome, morada, localidade e pas.';
    } else {
      $q = sprintf("INSERT INTO clientes_locais (contribuinte, nome, morada, localidade, cep4, cep3, telefone, email, pais)
                    VALUES ('','%s','%s','%s','%s','%s','%s','%s','%s')",
        mysqli_real_escape_string($cont,$nome),
        mysqli_real_escape_string($cont,$mor),
        mysqli_real_escape_string($cont,$loc),
        mysqli_real_escape_string($cont,$cep4),
        mysqli_real_escape_string($cont,$cep3),
        mysqli_real_escape_string($cont,$tel),
        mysqli_real_escape_string($cont,$contaEmail),
        mysqli_real_escape_string($cont,$pais)
      );
      if (mysqli_query($cont,$q)) {
        $msgAddr='Nova morada adicionada.';
        // recarrega moradas
        $enderecos = array();
        $resE = mysqli_query($cont,$sqlE);
        if ($resE){ while($r=mysqli_fetch_assoc($resE)) $enderecos[]=$r; mysqli_free_result($resE); }
      } else {
        $errAddr='Erro ao adicionar: '.mysqli_error($cont);
      }
    }
  }
}

// ========== FIXAR SELEO PARA RESUMO (sem fechar) ==========
$idloc = isset($_POST['idloc']) ? (int)$_POST['idloc'] : 0;
$moradaEscolhida = '';
if (isset($_POST['fixar_entrega'])) {
  $como = isset($_POST['comoreceber']) ? $_POST['comoreceber'] : 'transportadora';
  if ($como==='levantaremloja') {
    $idloc = isset($_POST['idloja']) ? (int)$_POST['idloja'] : 0;
    if ($idloc>0) {
      $r = mysqli_query($cont, "SELECT nome, morada, localidade, cep4, cep3, pais FROM clientes_locais WHERE id=$idloc");
      if ($r && $d=mysqli_fetch_assoc($r)) {
        $moradaEscolhida = $d['nome'].'  '.$d['morada'].', '.$d['localidade'].' '.$d['cep4'].'-'.$d['cep3'].' '.$d['pais'];
      }
      if ($r) mysqli_free_result($r);
    }
  } else {
    $ent = isset($_POST['entrega']) ? $_POST['entrega'] : 'faturacao';
    if ($ent==='faturacao') {
      list($cp4,$cp3)=split_cp($cposfat);
      $sel = sprintf("SELECT id FROM clientes_locais WHERE email='%s' AND morada='%s' AND localidade='%s' AND cep4='%s' AND cep3='%s' AND pais='%s' LIMIT 1",
        mysqli_real_escape_string($cont,$contaEmail),
        mysqli_real_escape_string($cont,$moradafat),
        mysqli_real_escape_string($cont,$localfat),
        mysqli_real_escape_string($cont,$cp4),
        mysqli_real_escape_string($cont,$cp3),
        mysqli_real_escape_string($cont,$paisfat)
      );
      $idfat = 0;
      if ($rs=mysqli_query($cont,$sel)) { if ($x=mysqli_fetch_assoc($rs)) $idfat=(int)$x['id']; mysqli_free_result($rs); }
      if ($idfat===0) {
        $ins = sprintf("INSERT INTO clientes_locais (contribuinte, nome, morada, localidade, cep4, cep3, telefone, email, pais)
                        VALUES ('','%s','%s','%s','%s','%s','','%s','%s')",
          mysqli_real_escape_string($cont,$nomefat),
          mysqli_real_escape_string($cont,$moradafat),
          mysqli_real_escape_string($cont,$localfat),
          mysqli_real_escape_string($cont,$cp4),
          mysqli_real_escape_string($cont,$cp3),
          mysqli_real_escape_string($cont,$contaEmail),
          mysqli_real_escape_string($cont,$paisfat)
        );
        if (mysqli_query($cont,$ins)) $idfat = (int)mysqli_insert_id($cont);
      }
      $idloc = $idfat;
      $moradaEscolhida = $nomefat.'  '.$moradafat.', '.$localfat.' '.$cposfat.' '.$paisfat;
    } else {
      $idloc = isset($_POST['idend']) ? (int)$_POST['idend'] : 0;
      if ($idloc>0) {
        $r = mysqli_query($cont, "SELECT nome, morada, localidade, cep4, cep3, pais FROM clientes_locais WHERE id=$idloc");
        if ($r && $d=mysqli_fetch_assoc($r)) {
          $moradaEscolhida = $d['nome'].'  '.$d['morada'].', '.$d['localidade'].' '.$d['cep4'].'-'.$d['cep3'].' '.$d['pais'];
        }
        if ($r) mysqli_free_result($r);
      }
    }
  }
  // Guarda sele□□o de entrega para o passo do pagamento (POST seguinte)
  $_SESSION['checkout_comoreceber'] = isset($como) ? $como : 'transportadora';
  $_SESSION['checkout_idloc'] = (int)$idloc;
  $_SESSION['checkout_morada'] = $moradaEscolhida;
}
?>
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<title>Carrinho de Compras | Lojas Bigfoot</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="ISO-8859-1">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/estilo.css">
    <link rel="stylesheet" href="css/slideshow.css">
    <link rel="stylesheet" href="css/banners.css">
    <link rel="stylesheet" href="css/marcas.css">
    <link rel="stylesheet" href="css/topo.css">
    <link rel="stylesheet" href="css/menu.css">
    <link rel="stylesheet" href="css/rodape.css">
    <link rel="stylesheet" href="css/anim.css">
    <link rel="stylesheet" href="css/sacodecompras.css?v=5.0">
    <link rel="stylesheet" href="css/nota_encomenda.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Fonte Inter -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="shortcut icon" href="imagens/favicon.png">
</head>
<body>
    <?php include 'includes/topo.php'; ?>

  <div class="sacowrap">
    <div class="sacoheader">
      <h1>Saco de Compras</h1>
      <div class="sacosmall">Conta: <strong><?php echo h($contaEmail); ?></strong></div>
    </div>

    <?php if ($msg): ?><div class="sacomsg sacook"><?php echo h($msg); ?></div><?php endif; ?>

    <?php if ($msgAddr): ?><div class="sacomsg sacook"><?php echo h($msgAddr); ?></div><?php endif; ?>
    <?php if ($errAddr): ?><div class="sacomsg sacoerr"><?php echo h($errAddr); ?></div><?php endif; ?>

    <div class="sacogrid">
      <!-- LISTA (~60%) -->
      <div class="sacocart-list">
        <?php if (empty($itens)): ?>
          <div class="sacoempty">O seu saco est□ vazio.</div>
        <?php else: ?>
          <?php foreach ($itens as $it):
            $ref=(string)$it['artigo']; $desc=(string)$it['descricao']; $tam=(string)$it['tamanho'];
            $siteOr=(string)$it['site'];
            $ccid=(int)$it['_ccid'];
            $q=(int)$it['_qtd']; $vu=(float)$it['_vu']; $ln=(float)$it['_linha'];
            $pvpsd=(float)$it['descontoV'];
            $dctP=(int)$it['descontoP'];
            $cup=(string)$it['cupapl'];
           //  $img=findImageForRef($ref,$IMAGES_DIR_FS,$IMAGES_DIR_WEB,$IMG_EXTS);

if ($siteOr == 'bigfootsport.pt') {

    // S REMOTO
    $img = findImageForRefRemote($ref, $REMOTE_BASE_URL, $IMG_EXTS);

} else{

    // S LOCAL
    $img = findImageForRefLocal($ref, $IMAGES_DIR_FS, $IMAGES_DIR_WEB, $IMG_EXTS);

}

          ?>
          <div class="sacoitem">
          <?php
              $desSEO=slugify(h($desc));
              $urlart="artigo.php?ref=".h($ref)."&nome=$desSEO";

              if ($siteOr == 'bigfootsport.pt') {
              $urlart="https://bigfootsport.pt/lbf/artigo.php?ref=".h($ref)."&nome=$desSEO";
              } else{

              $urlart="artigo.php?ref=".h($ref)."&nome=$desSEO";
              }

          ?>

            <a href="<?php echo $urlart; ?>"><img src="<?php echo h($img); ?>" alt="<?php echo h($desc); ?>"> </a>
            <div>
              <h3><?php echo h($desc); ?></h3>
              <div class="sacometa">
                Ref: <strong><?php echo h($ref); ?></strong>
                &nbsp;&nbsp; Tamanho: <strong><?php echo h($tam); ?></strong>
                &nbsp;&nbsp; Qtd: <strong><?php echo $q; ?>
                <?php
                if ($dctP>20) {
                  echo "<br></strong><font color='#494949'><strong>Informa&ccedil;&atilde;o:</strong> <i>Este artigo no aceita cup&otilde;es</i></font>";
                }
                if ($dctP>0) {
                  echo "<br></strong><font color='red'>Desconto: <strong>$dctP"."%</font></strong>";
                }
                if ($cup!='') {
                  echo "</strong>&nbsp;&nbsp;<font color='red'>Cup&atilde;o de desconto aplicado</font></strong>";
                }

                ?>


              </div>
              <div class="sacoprice">
              <?php
                 if ($dctP>0) {
                 echo "<span style='font-size:14px;font-weight:300;'>Preo sem desconto: </span><span style='font-size:12px;font-weight:400;text-decoration:line-through;text-decoration-color:red;text-decoration-thickness: 1px;'>  ".number_format($pvpsd, 2, ',', ' ')."</span>&nbsp;&nbsp;";
                }


                    if ($q>0)
                    {
                        echo "Total: ".number_format($ln, 2, ',', ' ');

                    }else
                    {
                        echo "<font color='red'>Total: ".number_format($ln, 2, ',', ' ')."</font>";
                    }
                    echo "<div class='EnviarSiteWrap'><a href='?copiaID=$ccid'><div class='EnviarSite'>Enviar para Carinho de Desporto</div></a><div class='EnviarSiteOrigem'>Origem:$siteOr</div></div>";
                   ?>
              </div>
            </div>
            <div class="sacoactions">
<form method="post" class="confirmar-remocao" style="margin:0">
  <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
  <input type="hidden" name="action" value="remover">
  <input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>">
  <?php
  if ($q>0)
  {
        echo "<button type=\"submit\">Remover</button>";
  }
  else
  {
       echo "<div class='infocred'>Cr&eacute;dito<br>devolu&ccedil;&atilde;o</div>";
  }
  ?>

</form>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- SIDEBAR (~35%) -->
      <div class="sacosidebar">
        <h2 style="margin:0 0 10px 0; font-size:1.2rem;">Resumo</h2>
        <div class="sacosummary-row"><span>Itens</span><strong><?php echo (int)$totQtd; ?></strong></div>
        <div class="sacosummary-row"><span>Subtotal</span><strong> <?php echo number_format($totVal, 2, ',', ' '); ?></strong></div>
        <div class="sacosummary-row"><span>Portes</span><strong> <?php echo number_format($portes, 2, ',', ' '); ?></strong></div>
        <?php
        if ($_POST['metodo']=='cobranca')
        {
            Echo "<div class=\"sacosummary-row\"><span>Taxa de cobrana</span><strong> ".number_format($_SESSION[socobranca], 2, ',', ' ')."</strong></div>";
            Echo "        <div class=\"sacosummary-row\"><span><i>Na cobrana  obrigatrio pagar o transporte por MB, neste caso: <strong>".number_format($_SESSION[portesacabecaMBcobranca], 2, ',', ' ')."</strong></i></span></div>";



        }
        ?>


        <div class="sacosummary-row"><span><i><?php echo $_SESSION[obsportes]; ?></i></span></div>
        <?php
        /*************ATUALIZAR VARS DE SESSO COM ELEMENTOS PARA O PAGAMENTO***************/
        $_SESSION[ENCitems]=(int)$totQtd;
        $_SESSION[ENCtotSportes]=$totVal;
        $_SESSION[portes]=$portes;
        $_SESSION[totalFinal]=$totalFinal;
        /********$_SESSION[obsportes]****/

        /*************FIM ATUALIZAR VARS DE SESSO COM ELEMENTOS PARA O PAGAMENTO***************/
        ?>
        <?php if ($descontoCupao > 0): ?>
          <div class="sacosummary-row"><span>Desconto</span><strong>-  <?php echo number_format($descontoCupao, 2, ',', ' '); ?></strong></div>
        <?php endif; ?>
        <div class="sacosummary-row sacototal"><span>Total</span><strong> <?php echo number_format($totalFinal, 2, ',', ' '); ?></strong></div>






      <?php


     if (($_SESSION[loged]=='sim') && ($_SESSION[conta]!=''))
    {
      include "onde_entrega.php";
        include "pede_cupao.php";
    }


      if ($_SESSION[errocupao]!='')
      {
            echo "<label class=\"sacolabel\" style=\"margin-top:5px;color:red;\">".$_SESSION[errocupao]."</label>";
      }
      if ($_SESSION[INFOCUPAO]!='')
      {
            echo "<label class=\"sacolabel\" style=\"margin-top:5px;color:green;\">".$_SESSION[INFOCUPAO]."</label>";
      }
/**************METODOS DE PAGAMENTO***************************/

    if (($_SESSION[loged]=='sim') && ($_SESSION[conta]!=''))
    {
        include "metodos_pagamento.php";
    }
    else
   {
          echo "<a href='login.php' style='text-decoration:none;'><div class='infobt'>Para finalizar a sua compra, □ necess□rio iniciar sess□o com a sua conta de cliente.
          Aceda aqui ao in□cio de sess□o ou crie um registo de cliente.</div></a>";
   }
/***************FIM DE METODOS PAG********************************/



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['metodo'])) {
    // Normaliza valores vindos do formul□rio (evita espa□os invis□veis / \r\n)
    $metodo = trim($_POST['metodo']);
    $metodo = preg_replace('/\s+/', '', $metodo);

    $erros = [];

    // --- Mant□m sele□□o de entrega no fecho da encomenda (PHP 5.6) ---
    $como = isset($_POST['comoreceber']) ? $_POST['comoreceber'] : (isset($_SESSION['checkout_comoreceber']) ? $_SESSION['checkout_comoreceber'] : '');
    $idloc = isset($_POST['idloc']) ? (int)$_POST['idloc'] : (isset($_SESSION['checkout_idloc']) ? (int)$_SESSION['checkout_idloc'] : 0);
if ($como !== 'levantaremloja' && $idloc <= 0) {

    $contaEsc = mysqli_real_escape_string($cont, $conta);
    $sqlFatId = "SELECT id
                 FROM clientes_locais
                 WHERE email='$contaEsc'
                 ORDER BY id
                 LIMIT 1";

    $rsFat = mysqli_query($cont, $sqlFatId);
    if ($rsFat && ($rowFat = mysqli_fetch_assoc($rsFat))) {
        $idloc = (int)$rowFat['id'];
        $_SESSION['checkout_idloc'] = $idloc;
    }
}
    if ($como === 'levantaremloja') {
        // levantamento em loja: sem portes
        $_SESSION['portes'] = 0;
        $_SESSION['taxacobranca'] = 0;
    }

/********RESUMO DA ENCOMENDA**********/
$coef=1+23/100;
$incide=$_SESSION[totalFinal]/$coef;
$iva=round($_SESSION[totalFinal]-$incide,2);


$queryprox="select numero from `encomendasonline` order by abs(numero) desc limit 1";
$resprox = mysqli_query($cont, $queryprox);
$auxprox = mysqli_fetch_assoc($resprox);
$prox=intval($auxprox[numero]);
$prox=$prox+1;

/*****PROX ELIMINAR**********/
//   $prox=15708;
/***************/

if ($como === 'levantaremloja' || $idloc <= 20) {
   $transp='Levantar em loja';
} else {
   $transp='CTT';
}

$portes=$_SESSION[portes];
$taxacobranca=$_SESSION[taxacobranca];
/******APAGAR SE J EXISTIREM PORTES LANCADOS NA ENCOMENDA*****************/
$sqldel="DELETE from `encomendasonline`  where numero ='$prox' and conta='$conta' and artigo='Portes'";
$resdel = mysqli_query($cont, $sqldel);
/**************************************************************************/

if ($portes >0)
{
$sqlportes="INSERT INTO `encomendasonline` (`id`,`numero`,`conta`, `data`, `ip`, `artigo`,`descricao`, `tamanho`, `quantidade`, `valorunitario`, `total`, `situacao`, `situacao1`, `formapagamento`,`site`,`transporte`) VALUES ('','$prox' ,'$conta','$hoje', '$ip', 'Portes','Custo de transporte', '', '1', ' $portes', ' $portes', 'por validar', 'a processar', '$formp','$site', '$transp' );";
$resportes = mysqli_query($cont, $sqlportes);
//echo "<hr>$sqlportes<hr>";
}
if ($taxacobranca >0)
{
$sqltaxacobranca="INSERT INTO `encomendasonline` (`id`,`numero`,`conta`, `data`, `ip`, `artigo`,`descricao`, `tamanho`, `quantidade`, `valorunitario`, `total`, `situacao`, `situacao1`, `formapagamento`,`site`,`transporte`) VALUES ('','$prox' ,'$conta','$hoje', '$ip', 'Portes','Taxa de servio de cobrana', '', '1', ' $taxacobranca', ' $taxacobranca', 'por validar', 'a processar', '$formp','$site', '$transp' );";
$restaxacobranca = mysqli_query($cont, $sqltaxacobranca);
//echo "<hr>$sqltaxacobranca<hr>";
}

$sqlfecha="update `encomendasonline`  set numero ='$prox', idloc ='$idloc',data  ='$hoje',formapagamento  ='$metodo' ,transporte  ='$transp'  where numero ='0' and conta='$conta'";
$resfecha = mysqli_query($cont, $sqlfecha);
//echo "<hr>$sqlfecha<hr>";
$_SESSION[numeroEncomenda]=$prox;

/********FIM DE RESUMO DA ENCOMENDA**********/
    switch ($metodo) {
        case 'multibanco':
            /**********API EASYPAY MB*******************/
            $nom = $_SESSION['nomeuser'];
            $nom = clean($nom);
            $mail = $conta;
            $nrencomenda=$prox;
            $descenc = "Encomenda nr.:$nrencomenda";
            $_SESSION['totaldaencomenda']=$_SESSION[totalFinal];
            $totaldaencomenda_mbw = $_SESSION['totaldaencomenda'];
            $totaldaencomenda1=$_SESSION[totalFinal];
            $ep_user   = 'AMNAF111217'; // legacy (no necessrio na 2.0)
            $ep_cin    = '6898';        // legacy
            $ep_entity = '10611';       // legacy
            $flag      = 'PT';
            $expira    = ''; // ISO-8601 UTC se usares expirao (ex.: '2025-09-30T23:59:00Z')
            // === Credenciais API 2.0 (mantidas) ===
            $api_chave = 'b5f59011-7711-4bb0-a16f-38875ff94c61';
            $api_id    = 'aef8a8ce-d126-4591-a6b2-c5dc79f573f3';
            // === Corpo do pedido /single (API 2.0) ===
            $body = [
              "key"      => (string)$nrencomenda,   // tua chave de correlao
              "method"   => "mb",                   // Multibanco
              "type"     => "sale",                 // sale = autoriza+captura (conceito)
              "value"    => (float)$totaldaencomenda1,
              "currency" => "EUR",
              "capture"  => [
                "transaction_key" => (string)$nrencomenda,
                "descriptive"     => "Encomenda $nrencomenda",
              ],
              // recomendar passar dados do cliente
              "customer" => [
                "name"  => $nom,
                "email" => $mail,
              ],
            ];

            // Expirao (se quiseres). Na 2.0 deve ir em multibanco.expiration_time (ISO-8601 UTC)
            if (!empty($expira)) {
              $body["multibanco"] = ["expiration_time" => $expira];
            }

            // === Chamada cURL ===
            $headers = [
              "AccountId: $api_id",
              "ApiKey: $api_chave",
              "Content-Type: application/json",
            ];

            // PROD: https://api.prod.easypay.pt/2.0/single
            // TEST: https://api.test.easypay.pt/2.0/single
            $curlOpts = [
              CURLOPT_URL            => "https://api.prod.easypay.pt/2.0/single",
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_POST           => 1,
              CURLOPT_TIMEOUT        => 60,
              CURLOPT_POSTFIELDS     => json_encode($body),
              CURLOPT_HTTPHEADER     => $headers,
            ];
            $curl = curl_init();
            curl_setopt_array($curl, $curlOpts);
            $response_body = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curl_err  = curl_error($curl);
            curl_close($curl);

            if ($response_body === false || $http_code >= 400) {
              // trata erro de forma apropriada ao teu sistema
              // ex.: log, fallback de mensagem, etc.
              // die("Erro easypay ($http_code): $curl_err");
            }

            $arr_pag = json_decode($response_body, true);

            // === Valores devolvidos (entity/reference) ===
            $MBent = isset($arr_pag['method']['entity'])    ? $arr_pag['method']['entity']    : null;
            $MBref = isset($arr_pag['method']['reference']) ? $arr_pag['method']['reference'] : null;
            $MBval = $totaldaencomenda1; // mantm varivel que j usas


            $sqlMB="UPDATE  `encomendasonline` SET refmb='$MBref', valormb='$MBval', entidade='$MBent' WHERE numero='$nrencomenda'";
            $resMB = mysqli_query($cont, $sqlMB);
            /**********FIM API EASYPAY MB*******************/

            echo "<script>
            location = \"resumo.php?ne=$nrencomenda&r=OK\";
            </script>";
            break;

           // include_once("nota_encomenda.php");
          ///  header("Location: resumo.php?ne=".$nrencomenda."&r=OK");
           // exit;
        case 'mbway':
            $tel = isset($_POST['mb_phone']) ? preg_replace('/\D+/', '', $_POST['mb_phone']) : '';
            if (!preg_match('/^9\d{8}$/', $tel)) {
                $erros[] = 'Telemvel MB Way invlido.';
            } else {
                $_SESSION['telmbw'] = $tel;
            }
            $nom = $_SESSION['nomeuser'];
            $telemovel =$_SESSION['telmbw'];
            $nom = clean($nom);
            $mail = $conta;
            $nrencomenda=$prox;
            $descenc = "Encomenda:$nrencomenda  Tel:$telemovel";
            $totaldaencomenda_mbw = $_SESSION[totalFinal];
            $totaldaencomenda1 = $totaldaencomenda_mbw;

            $ep_user   = 'AMNAF111217'; // legacy (no usado na 2.0)
            $ep_cin    = '6898';        // legacy
            $ep_entity = '10611';       // legacy
            $flag      = 'PT';
            $expira    = '';            // no aplicvel a MB WAY em 2.0

            $api_chave = 'b5f59011-7711-4bb0-a16f-38875ff94c61';
            $api_id    = 'aef8a8ce-d126-4591-a6b2-c5dc79f573f3';

            // === Corpo do pedido (API 2.0 /single) ===
            $body = [
              "key"      => (string)$nrencomenda,
              "method"   => "mbw",                 // MB WAY
              "type"     => "sale",                // autorizao + captura
              "value"    => (float)$totaldaencomenda1,
              "currency" => "EUR",
              "capture"  => [
                "transaction_key" => (string)$nrencomenda,
                "descriptive"     => "Encomenda $nrencomenda",
                // "capture_date"  => "$hoje" // opcional; se no precisas, remove
              ],
              "customer" => [
                "name"            => $nom,
                "email"           => $mail,
                "key"             => "MBWAY $nrencomenda",
                "phone_indicative"=> "351",        // sem '+'
                "phone"           => $telemovel,
                "fiscal_number"   => "PT123456789" // se tiveres o NIF real do cliente, usa-o
              ],
            ];

            // === cURL ===
            $headers = [
              "AccountId: $api_id",
              "ApiKey: $api_chave",
              "Content-Type: application/json",
              // Recomendo idempotncia para evitar duplicados em reenvios:
              // "Idempotency-Key: ".$nrencomenda,
            ];

            $curlOpts = [
              CURLOPT_URL            => "https://api.prod.easypay.pt/2.0/single", // usa api.test no sandbox
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_POST           => 1,
              CURLOPT_TIMEOUT        => 60,
              CURLOPT_POSTFIELDS     => json_encode($body),
              CURLOPT_HTTPHEADER     => $headers,
            ];

            $curl = curl_init();
            curl_setopt_array($curl, $curlOpts);
            $response_body = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curl_err  = curl_error($curl);
            curl_close($curl);

            $arr_pag = json_decode($response_body, true);

            // (opcional) validao bsica
            if ($response_body === false || !is_array($arr_pag) || $http_code >= 400) {
              // trata o erro conforme o teu sistema (log, mensagem, etc.)
              // die("Erro easypay ($http_code): $curl_err | $response_body");
            }

            // === Dados de retorno ===
            // Em MB WAY no h entity/reference (so de Multibanco). Mantemos as variveis:
            $MBent = isset($arr_pag['method']['entity'])    ? $arr_pag['method']['entity']    : null; // deve ficar null
            $MBref = isset($arr_pag['method']['reference']) ? $arr_pag['method']['reference'] : null; // deve ficar null
            $MBval = $totaldaencomenda1;

            // Guarda o ID do single para reconciliao por webhook:
            $single_id = isset($arr_pag['id']) ? $arr_pag['id'] : null;

            // Podes tambm inspecionar um estado imediato (por ex., "pending"):
            $status_inicial = isset($arr_pag['status']) ? $arr_pag['status'] : null;
            /***********************FIM DE PAGAMENTO MBWAY*************************************/

            echo "<script>
            location = \"resumo.php?ne=$nrencomenda&r=OK\";
            </script>";
            break;
            /*
            include_once("nota_encomenda.php");
            header("Location: resumo.php?ne=".$nrencomenda."&r=OK");
            exit;
            */

        case 'visa':
            $_SESSION[linkvisa] = '';
            $nrencomenda = $prox;

            // Guarda o link para pagamento.php (página de captura de cartão)
            $link = 'pagamento.php';
            $_SESSION[linkvisa] = $link;

            echo "<a href='$link'><div class='botaovisa' aria-label='Pagar com carto crdito'>Pagar com carto crdito</div></a>";
            break;

        case 'paypal':
            $descenc = "Encomenda:$nrencomenda";
            $totaldaencomenda1 = $_SESSION[totalFinal];
            $conta=$_SESSION[conta];
            $pais=$_SESSION[pais];
            $mor=$_SESSION[mor];
            $zip=$_SESSION[cpt4]."-".$_SESSION[cpt3];
            $tel=$_SESSION[tel];
            $loc=$_SESSION[loc];
            echo "<form action=\"https://www.paypal.com/cgi-bin/webscr\" method=\"post\">
            <input type=\"hidden\" name=\"cmd\" value=\"_xclick\">
            <input type=\"hidden\" name=\"business\" value=\"online@lojasbigfoot.com\">
            <input type=\"hidden\" name=\"lc\" value=\"PT\">
            <input type=\"hidden\" name=\"item_name\" value=\"$descenc\">
            <input type=\"hidden\" name=\"amount\" value=\"$totaldaencomenda1\">
            <input type=\"hidden\" name=\"currency_code\" value=\"EUR\">
            <INPUT TYPE=\"hidden\" NAME=\"return\" value=\"http://www.lojasbigfoot.com/resumo.php?ne=$nrencomenda&r=OK\">
            <input type=\"hidden\" name=\"button_subtype\" value=\"services\">
            <input type=\"hidden\" name=\"no_note\" value=\"0\">
            <input type=\"hidden\" name=\"address1\" value=\"$mor\">
            <input type=\"hidden\" name=\"city\" value=\"$loc\">
            <input type=\"hidden\" name=\"first_name\" value=\"$nom\">
            <input type=\"hidden\" name=\"last_name\" value=\"nom\">
            <input type=\"hidden\" name=\"zip\" value=\"$zip\">
            <input type=\"hidden\" name=\"country\" value=\"$pais\">
            <input type=\"hidden\" name=\"email\" value=\"$conta\">
            <input type=\"hidden\" name=\"night_ phone_a\" value=\"$tel\">
            <input type=\"hidden\" name=\"bn\" value=\"PP-BuyNowBF:$paypallogo:NonHostedGuest\">
            <input type=\"hidden\" name=\"image_url\" value=\"http://www.lojasbigfoot.com/images/logo.png\" class='ajusta_box' border=\"0\" name=\"submit\" alt=\"PayPal - The safer, easier way to pay online!\">
            <input type=\"image\" src=\"http://www.lojasbigfoot.com/imagens/btpaypal.png\"  class=\"just_pp\" border=\"0\" name=\"submit\" alt=\"PayPal - The safer, easier way to pay online!\">
            <img alt=\"\" border=\"0\" src=\"https://www.paypalobjects.com/en_US/i/scr/pixel.gif\" width=\"1\" height=\"1\">
            ";
            echo "</form>";
            break;

        case 'transf':
            $email=$conta;
            $nrencomenda=$prox;
            $sqltrf="UPDATE  `encomendasonline` SET refmb='', valormb='', entidade='' WHERE numero='$nrencomenda'";
            $restrf = mysqli_query($cont, $sqltrf);

            $tex=gettransf("PT");
            $_SESSION[transtext]=$tex;

            echo "
            <script>
            location = \"resumo.php?ne=$nrencomenda&r=OK\";
            </script>";
            break;
             /*
            include_once("nota_encomenda.php");
            header("Location: resumo.php?ne=".$nrencomenda."&r=OK");
            exit;
             */

        case 'cobranca':
            /**********API EASYPAY MB*******************/
            $nom = $_SESSION['nomeuser'];
            $nom = clean($nom);
            $mail = $conta;
            $nrencomenda=$prox;
            $descenc = "Encomenda nr.:$nrencomenda";
            $_SESSION['totaldaencomenda']=$_SESSION[portesacabecaMBcobranca];
            $totaldaencomenda_mbw =$_SESSION[portesacabecaMBcobranca];
            $totaldaencomenda1=$_SESSION[portesacabecaMBcobranca];
            $ep_user   = 'AMNAF111217'; // legacy (no necessrio na 2.0)
            $ep_cin    = '6898';        // legacy
            $ep_entity = '10611';       // legacy
            $flag      = 'PT';
            $expira    = ''; // ISO-8601 UTC se usares expirao (ex.: '2025-09-30T23:59:00Z')
            // === Credenciais API 2.0 (mantidas) ===
            $api_chave = 'b5f59011-7711-4bb0-a16f-38875ff94c61';
            $api_id    = 'aef8a8ce-d126-4591-a6b2-c5dc79f573f3';
            // === Corpo do pedido /single (API 2.0) ===
            $body = [
              "key"      => (string)$nrencomenda,   // tua chave de correlao
              "method"   => "mb",                   // Multibanco
              "type"     => "sale",                 // sale = autoriza+captura (conceito)
              "value"    => (float)$totaldaencomenda1,
              "currency" => "EUR",
              "capture"  => [
                "transaction_key" => (string)$nrencomenda,
                "descriptive"     => "Encomenda $nrencomenda",
              ],
              // recomendar passar dados do cliente
              "customer" => [
                "name"  => $nom,
                "email" => $mail,
              ],
            ];

            // Expirao (se quiseres). Na 2.0 deve ir em multibanco.expiration_time (ISO-8601 UTC)
            if (!empty($expira)) {
              $body["multibanco"] = ["expiration_time" => $expira];
            }

            // === Chamada cURL ===
            $headers = [
              "AccountId: $api_id",
              "ApiKey: $api_chave",
              "Content-Type: application/json",
            ];

            // PROD: https://api.prod.easypay.pt/2.0/single
            // TEST: https://api.test.easypay.pt/2.0/single
            $curlOpts = [
              CURLOPT_URL            => "https://api.prod.easypay.pt/2.0/single",
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_POST           => 1,
              CURLOPT_TIMEOUT        => 60,
              CURLOPT_POSTFIELDS     => json_encode($body),
              CURLOPT_HTTPHEADER     => $headers,
            ];
            $curl = curl_init();
            curl_setopt_array($curl, $curlOpts);
            $response_body = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curl_err  = curl_error($curl);
            curl_close($curl);

            if ($response_body === false || $http_code >= 400) {
              // trata erro de forma apropriada ao teu sistema
              // ex.: log, fallback de mensagem, etc.
              // die("Erro easypay ($http_code): $curl_err");
            }

            $arr_pag = json_decode($response_body, true);

            // === Valores devolvidos (entity/reference) ===
            $MBent = isset($arr_pag['method']['entity'])    ? $arr_pag['method']['entity']    : null;
            $MBref = isset($arr_pag['method']['reference']) ? $arr_pag['method']['reference'] : null;
            $MBval = $totaldaencomenda1; // mantm varivel que j usas


            $sqlMB="UPDATE  `encomendasonline` SET refmb='$MBref', valormb='$MBval', entidade='$MBent' WHERE numero='$nrencomenda'";
            $resMB = mysqli_query($cont, $sqlMB);
            /**********FIM API EASYPAY MB*******************/

            echo "
            <script>
            location = \"resumo.php?ne=$nrencomenda&r=OK\";
            </script>";
            break;
             /*
            include_once("nota_encomenda.php");
            header("Location: resumo.php?ne=".$nrencomenda."&r=OK");
            exit;
            */
        default:
            $erros[] = 'Metodo invalido.';
    }

    include_once("nota_encomenda.php");


}
?>

      </div>
    </div>
  </div>






<div class="bloco-banners-marcas">

    <?php include("includes/rodape.php"); ?>

</div>
<div id="app-modals"></div>

<script>
(function(){
  function byName(n){ var el=document.querySelector('input[name="'+n+'"]:checked'); return el?el.value:null; }
  function show(id,v){ var e=document.getElementById(id); if(e) e.style.display=v?'block':'none'; }
  function sync(){
    var como = byName('comoreceber') || 'transportadora';
    show('bloco-loja', como==='levantaremloja');
    show('bloco-transp', como==='transportadora');

    var ent = byName('entrega') || 'faturacao';
    show('bloco-fat',   como==='transportadora' && ent==='faturacao');
    show('bloco-outra', como==='transportadora' && ent==='outramorada');

    // Atualiza hidden idloc automaticamente (faturao -> ficar decidido no servidor ao fixar)
    if (como==='transportadora' && ent==='outramorada') {
      var sel=document.querySelector('select[name="idend"]');
      if (sel) document.getElementById('idloc').value = sel.value || 0;
    }
  }
  document.addEventListener('change', function(e){
    if (e.target.name==='comoreceber' || e.target.name==='entrega' || e.target.name==='idend' || e.target.name==='idloja') {
      sync();
    }
  });
  sync();
})();
</script>






<script>
(function(){
  const form = document.getElementById('formPagamento');
  if (!form) return;

  const blocks = Array.prototype.slice.call(form.querySelectorAll('.metodos-pagamento-bloco'));
  const btn = document.getElementById('btnFinalizar');

  function phoneWrapFor(block){
    return block ? block.querySelector('.mbway-phone') : null;
  }
  function isPhoneValid(input){
    if (!input) return true;
    const v = (input.value || '').replace(/\D+/g,'');
    return /^9\d{8}$/.test(v);
  }

  function updateUI(){
    // estado visual selecionado
    blocks.forEach(function(b){
      const r = b.querySelector('input[type="radio"]');
      b.classList.toggle('selected', !!(r && r.checked));
    });

    // mostrar/esconder telefone do MB WAY
    blocks.forEach(function(b){
      const requiresPhone = (b.dataset.metodo === 'mbway');
      const wrap = phoneWrapFor(b);
      if (!wrap) return;

      const r = b.querySelector('input[type="radio"]');
      const checked = !!(r && r.checked);

      wrap.style.display = (requiresPhone && checked) ? 'block' : 'none';
      wrap.setAttribute('aria-hidden', (requiresPhone && checked) ? 'false' : 'true');
    });

    // habilitar boto
    const checkedRadio = form.querySelector('input[name="metodo"]:checked');
    let canSubmit = !!checkedRadio;

    if (canSubmit && checkedRadio.value === 'mbway') {
      const bloco = checkedRadio.closest('.metodos-pagamento-bloco');
      const wrap = phoneWrapFor(bloco);
      const input = wrap ? wrap.querySelector('input[name="mb_phone"]') : null;
      canSubmit = isPhoneValid(input);
    }

    if (btn) {
      btn.disabled = !canSubmit;
      btn.classList.toggle('enabled', canSubmit);
    }
  }

  // clique no bloco seleciona o radio
  blocks.forEach(function(b){
    b.addEventListener('click', function(e){
      if (e.target && e.target.name === 'mb_phone') return;
      const r = b.querySelector('input[type="radio"]');
      if (r) r.checked = true;
      updateUI();
    });
  });

  // validao dinmica do telefone
  form.addEventListener('input', function(e){
    if (e.target && e.target.name === 'mb_phone') updateUI();
  });

  // impede submit se invlido
  form.addEventListener('submit', function(e){
    if (btn && btn.disabled) { e.preventDefault(); return; }
  });

  updateUI();
})();
</script>



<script>
function showConfirm(message, {title="Confirmar", okText="Remover", cancelText="Cancelar"} = {}) {
  return new Promise(resolve => {
    const root = document.getElementById('app-modals');
    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.innerHTML = `
      <div class="modal" role="dialog" aria-modal="true" tabindex="-1">
        <div class="modal-header">${title}</div>
        <div class="modal-body">${message}</div>
        <div class="modal-footer">
          <button type="button" class="modal-btn cancel">${cancelText}</button>
          <button type="button" class="modal-btn danger ok">${okText}</button>
        </div>
      </div>`;
    root.appendChild(backdrop);

    const close = (val) => { backdrop.remove(); resolve(val); };
    backdrop.querySelector('.ok').addEventListener('click',   () => close(true));
    backdrop.querySelector('.cancel').addEventListener('click',() => close(false));
    backdrop.addEventListener('click', e => { if (e.target === backdrop) close(false); });
    document.addEventListener('keydown', function onKey(e){
      if (e.key === 'Escape') { e.preventDefault(); close(false); }
      if (e.key === 'Enter')  { e.preventDefault(); close(true); }
      document.removeEventListener('keydown', onKey);
    });
    backdrop.querySelector('.modal').focus();
  });
}

// Intercetar todos os forms com a classe "confirmar-remocao"
document.addEventListener('submit', async (e) => {
  const form = e.target.closest('form.confirmar-remocao');
  if (!form) return;

  e.preventDefault(); // impede o submit imediato

  const ok = await showConfirm('Remover este artigo do saco?', {
    title: 'Remover',
    okText: 'Remover',
    cancelText: 'Cancelar'
  });

  if (ok) {
    // submeter programaticamente sem voltar a abrir a modal
    HTMLFormElement.prototype.submit.call(form);
  }
});
</script>


<?php
$scrollToPayments = false;

// Caso 1: veio do select de moradas (?pay=1)
if (isset($_GET['pay']) && $_GET['pay'] == '1') {
  $scrollToPayments = true;
}

// Caso 2: veio de POST VISA/PAYPAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['metodo'])) {
  $m = preg_replace('/\s+/', '', trim($_POST['metodo']));
  if ($m === 'visa' || $m === 'paypal') {
    $scrollToPayments = true;
  }
}
?>
<script>
(function(){
  function go(){
    var el = document.getElementById('idformpag');
    if(!el) return;

    // ?? AQUI  que entra o try/catch
    try {
      el.scrollIntoView({ behavior:'smooth', block:'start' });
    } catch(e) {
      el.scrollIntoView(true);
    }

    window.scrollBy(0, -20); // ajuste se tiveres header fixo
  }

<?php if ($scrollToPayments) { ?>
window.addEventListener('load', function(){
  setTimeout(go, 50);
});
<?php } ?>
})();
</script>





<?php
/*
 TODO: lembrar de comprar leite e pao quando sair do trabalho hoje a noite
 o gato do vizinho entrou outra vez pela janela e comeu o queijo do frigorifico
 descobri que se misturares sumo de laranja com cafe fica horrivel nao facam
 a Maria disse que o filme de ontem era bom mas eu adormeci nos primeiros dez
 minutos e so acordei quando caiu o balde de pipocas no chao foi uma vergonha

 nota: o Fernando perdeu as chaves outra vez sao quatro vezes so este mes e
 ele continua a culpar o cao mas o cao nem sequer chega a gaveta das chaves
 acho que ele devia por um daqueles rastreadores bluetooth nas chaves todas

 coisas para nao esquecer esta semana:
 - regar as plantas da varanda especialmente a que esta quase a morrer
 - devolver o livro a biblioteca ja tem tres semanas de atraso meu deus
 - ligar ao electricista por causa da luz da cozinha que pisca as vezes
 - cancelar aquela subscricao que nunca uso e pago todo mes sem razao
 - comprar pilhas para o comando da televisao AA nao AAA como da outra vez
 - ver se o pneu do carro esta com ar suficiente parece que esta meio murcho
 - experimentar aquela receita de bolo de chocolate que vi no youtube ontem
 - marcar consulta no dentista ja devia ter ido ha seis meses atras pelo menos
 - limpar o armario do quarto tem roupa la dentro que nao uso desde dois mil e
 dezasseis nao faz sentido nenhum guardar aquilo tudo so ocupa espaco util
 - perguntar ao Ze se ainda tem aquele berbequim emprestado preciso dele agora

 o outro dia li que os polvos tem tres coracoes e sangue azul o que e muito
 estranho mas ao mesmo tempo fascinante a natureza e mesmo impressionante as
 vezes fico a pensar como e que estas coisas evoluiram ao longo dos milhoes e
 milhoes de anos ate chegarem a este ponto sinceramente nao sei como funciona

 reflexoes profundas das tres da manha quando nao consigo dormir:
 - se os peixes bebem agua ou so a respiram ninguem me soube responder
 - porque e que chamamos laranjas as laranjas mas nao chamamos amarelas
 - as bananas ou vermelhas as morangos nao faz sentido nenhum pensando
 - sera que os pinguins tem joelhos ou aquilo e tudo tornozelo parece q
 - se eu cavar um buraco ate ao outro lado da terra saio na australia ou
 - nao e porque a terra nao funciona assim de forma exacta mas seria giro
 - quantas formigas existem no mundo todo deve ser um numero gigantesco

 receita do bolo que a minha avo fazia e que nunca mais consegui replicar
 bem precisa de duzentos gramas de farinha tres ovos um copo de acucar um
 bocado de manteiga e um pacote de fermento mas o segredo era qualquer csa
 coisa que ela punha que nunca me quis dizer qual era e agora ja nao posso
 o wifi do cafe ao lado e melhor que o meu e eu pago sessenta euros por ms
 por esta ligacao miseravel que nem aguenta uma chamada de video sem cair
 a cada cinco minutos e dizem que chega a trezentos megas mas na rea
*/
?>
</body>
</html>
