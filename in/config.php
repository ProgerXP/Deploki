<?php

$secret = 'Set to 32 chars or longer';
$app = 'Sample site';

$media = array(
  'libs.js' => array(
    'read' => 'js/libs/*.js',
    '!concat', '!minify', '!write',
    '?link',
  ),
  'env.js' => array(
    'read' => "$inPath/??.php",
    'via' => 'js-env',
    '!minify',
    'write' => '{NAME}.{LANG}.{EXT}',
    'keep' => '.{LANG}.',
  ),
  'footer' => array(
    'read' => "$inPath/_counter.html",
  ),
);

$pages = array();

  foreach (array('htm', 'html', 'wiki') as $ext) {
    $pages[] = array(
      'read' => "$inPath/*.$ext",
      'omit' => '_*',
      $ext,
      'concat' => array(null, array(
        'read' => "$inPath/_template.$ext",
        $ext,
      )),
      'write' => "$outPath/{FILE}.{EXT}",
    );
  );
