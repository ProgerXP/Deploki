<?php
$oldWD = getcwd();
chdir(dirname(__FILE__));

$self = __FILE__;
if (function_exists('readlink')) {
  while ($target = readlink($self)) { $self = $target; }
}

require_once dirname($self).'/deploki.php';
Deploki::prepareEnvironment();
$config = DeplokiConfig::loadFrom(DeplokiConfig::locate($oldWD));

$hash = $files = array();

foreach ($_GET as $key => $file) {
  if (is_int($key)) {
    $files[] = $file;
    $hash[] = "$key=".urlencode($file);
  }
}

if ($config->hash(join('|', $hash)) !== $_GET['hash']) {
  Deploki::fail('Wrong hash for this combination of files to read.');
}

$mimes = array('.css' => 'text/css', '.js' => 'text/javascript');
$mime = @$_GET['mime'];
$mime or $mime = $mimes[ strrchr($files[0], '.') ];
$charset = @$_GET['charset'] ? $_GET['charset'] : 'utf-8';
$mime and header("Content-Type: $mime; charset=$charset");

foreach ($files as $file) {
  readfile($file);
 echo "\n";
}