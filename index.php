<?php
chdir(dirname(__FILE__));

require_once 'deploki.php';
Deploki::prepareEnvironment();
$options = DeplokiOptions::loadFrom(Deploki::locateConfig());

$canDebug = $options->debug;
$options->debug &= empty($_REQUEST['stage']);

if ($patch = &$_FILES['patch'] and $patch['error'] != 4) {
  if !class_exists('ZipArchive')) {
    Deploki::fail('Cannot patch without ZipArchive extension class.');
  }

  $zip = new ZipArchive;
  if ($zip->open($patch['tmp_name']) !== true) {
    Deploki::fail('Cannot read uploaded patch as ZIP.');
  }

  $dest = $options->debug ? 'patch' : $options->outPath;
  $options->mkdir($dest);

  $zip->extractTo($dest) or Deploki::fail('ZipArchive->extractTo() has failed.');
  $url = '.?'.$_SERVER['QUERY_STRING'].'&patched='.$zip->numFiles;
  $zip->close();

  header('Location: '.$url);
  exit;
}

if (@$_POST['perform'] === 'ret') {
  // so it's possible to refresh the page and redeploy without POST resubmission warning.
  header('Location: .?perform=ret');
  exit;
}

if (!empty($_REQUEST['perform'])) {
  $deploki = new Deploki($options);
  $deploki->deploy($options->pages);

  if ($_REQUEST['perform'] != 'ret') {
    $url = ltrim($_REQUEST['perform'], '0..9') === '' ? '..' : $_REQUEST['perform'];
    header("Location: $url");
    exit;
  }
}

$patched = &$_REQUEST['patched'];

$interface = DeplokiChain::make($options, array(
  'read' => $options->absolute('_deploy.html', $options->dkiPath),
  'htmlki' => array('vars' => get_defined_vars() + $options->vars()),
));

echo join($interface->execute()->data);