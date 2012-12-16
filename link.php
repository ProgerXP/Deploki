<?php
chdir(dirname(__FILE__));

require_once 'deploki.php';
Deploki::prepareEnvironment();
$options = DeplokiOptions::loadFrom(Deploki::locateConfig());

$files = array();
foreach ($_GET as $key => $file) { is_int($key) and $files[] = $file; }

if ($options->hash(join('|', $files)) !== $_GET['hash']) {
  Deploki::fail('Wrong hash for thiscombination of files to read.');
}

foreach ($files as $file) {
  readfile($file);
 echo "\n";
}