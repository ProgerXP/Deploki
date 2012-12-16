<?php
/*
  Deploki.org - static web revisited
  in Public domain | by Proger_XP | http://proger.i-forge.net/Deploki
  report to GitHub | http://github.com/ProgerXP/Deploki
  Supports PHP 5.2+
*/

class DeplokiError extends Exception { }

class DeplokiFilterError extends Exception {
  public $filter;   //= DeplokiFilter that's thrown the exception

  function __construct($obj, $msg) {
    $this->filter = $obj;
    parent::__construct($msg);
  }
}

class DeplokiConfig {
  /*---------------------------------------------------------------------
  | ENVIRONMENT OPTIONS
  |--------------------------------------------------------------------*/

  public $debug;        //= bool
  public $secret;       //= null, string with at least 32 characters
  public $app;          //= null, string optional application name for pretty output

  public $dkiPath;      //= string path to Deploki root (with deploki.php)
  public $inPath;       //= string path to config, pages, languages, etc.
  public $outPath;      //= string path to put rendered pages to
  public $tempPath;     //= string
  public $mediaPath;    //= string path to put resources (JS, CSS, etc.) to

  public $languages;    //= array of string like 'ru'
  public $media;        //= hash 'name.ext' => array(chains)
  public $reuseMedia;   //= true process media chains once, false redo them on each page
  protected $cachedMedia; //= null, hash used if $reuseMedia is true

  // Local file -> URL mappsings. Entries here are checked in top-down order so
  // include more specific paths (e.g. $tempPath) before parents (e.g. $outPath).
  public $urls            = array(
    // It's possible to list either option names:
    // 'outPath'          => dirname($_SERVER['REQUEST_URI']),
    // ...or absolute paths like this one:
    // '/home/i-forge.net/pub/myproject' => 'http://myproject.i-forge.net',
  );

  public $dirPerms;     //= integer umask for newly created directories like 0777
  public $filePerms;    //= integer umask for newly created files like 0777
  public $viaAliases;   //= hash of string 'alias' => 'realVia'

  /*---------------------------------------------------------------------
  | FILTER-SPECIFIC
  S
  |--------------------------------------------------------------------*/

  //= string 'debug' or 'stage', callable (Filter, Chain), mixed filter-specific
  public $condition       = null;

  /*---------------------------------------------------------------------
  | METHODS
  |--------------------------------------------------------------------*/

  static function loadFrom($_file) {
    require $_file;
    return new self(get_defined_vars());
  }

  static function make($override = array()) {
    if ($override instanceof self) {
      return clone $override;
    } else {
      return new self($override);
    }
  }

  function __construct(array $override = array()) {
    $this->defaults()->override($override);
  }

  // Does not reset $secret.
  function defaults() {
    $this->debug          = $_SERVER['REMOTE_ADDR'] === '127.0.0.1';
    $this->app            = null;

    $dki = $this->dkiPath = dirname(__FILE__);
    $this->inPath         = "$dki/in";
    $this->outPath        = dirname($dki);
    $this->tempPath       = "$dki/temp";
    $this->mediaPath      = dirname($dki).'/media/all';

    $this->languages      = $this->detectLanguagesIn($this->inPath);
    $this->media          = array();
    $this->reuseMedia     = true;
    $this->cachedMedia    = null;

    $this->urls           = array(
      'outPath'           => dirname($_SERVER['REQUEST_URI']),
    );

    $this->dirPerms       = 0755;
    $this->filePerms      = 0744;

    $this->viaAliases     = array(
      'htm'               => 'html',
      'html'              => 'htmlki',
      'wiki'              => 'uwiki',
    );
  }

  //= array ('ru', 'en')
  function detectLanguagesIn($path) {
    $languages = array();

    foreach (scandir($path) as $lang) {
      if (strrchr($lang, '.') === '.php' and strlen($lang) === 5) {
        $languages[] = substr($lang, 0, 2);
      }
    }

    return $languages;
  }

  function override(array $override) {
    foreach ($override as $name => $value) {
      $this->$name = $value;
    }

    return $this;
  }

  function vars() {
    return (array) $this;
  }

  /*---------------------------------------------------------------------
  | UTILITIES
  |--------------------------------------------------------------------*/

  function absolute($path, $base) {
    $path = trim($path);

    if ($path === '') {
      return $base;
    } elseif (strpbrk($path[0], '\\/') !== false) {
      return $path;
    } else {
      return rtrim($base, '\\/')."/$path";
    }
  }

  // Replaces strings of form {VAR} in $name. Automatically adds commonExpandVars().
  function expand($name, array $variables = array()) {
    $from = array();
    $variables += $this->commonExpandVars();

    foreach ($variables as $name => $value) {
      $from[] = '{'.strtoupper($name).'}';
    }

    return str_replace($from, $variables, $name);
  }

  function commonExpandVars() {
    $vars = array();

    foreach ((array) $this as $prop => $value) {
      is_scalar($value) and $vars[$prop] = $value;
    }

    return $vars;
  }

  function resolve($name, array $aliases, $maxHops = 10) {
    while (isset($aliases[$name]) and --$maxHops >= 0) {
      $name = $aliases[$name];
    }

    return $name;
  }

  function mkdir($path) {
    if (!is_dir($path)) {
      mkdir($path, $this->dirPerms, true);
      is_dir($path) or Deploki::fail("Cannot create directory [$path].");
    }
  }

  function writeFile($path, $data) {
    $this->mkdir($path);
    $existed = is_file($dest);
    $ok = file_put_contents($dest, $data, LOCK_EX) == strlen($data);
    $ok or Deploki::fail("Cannot write ".strlen($data)." bytes to file [$dest].");
    $existed or chmod($dest, $this->filePerms);
  }

  function readFile($path) {
    $data = is_file($path) ? file_get_contents($path) : null;
    is_string($data) or Deploki::fail("Cannot read file [$path].");
    return $data;
  }

  function urlOf($file, array $vars) {
    $file = strtr(realpath($file) ? realpath($file) : $file, '\\', '/').'/';

    foreach ($this->urls as $path => $url) {
      if (strpbrk($path[0], '\\/') !== false) {
        $path = $this->absolute($this->$path, $this->outPath);
      }

      $path = rtrim(strtr($path, '\\', '/'), '/').'/';
      if (substr($file, 0, $len = strlen($path)) === $path) {
        return substr($file, $len);
      }
    }

    $urls = array();
    foreach ($this->urls as $path => $url) { $urls = "$path -> $url"; }
    Deploki::fail("Cannot map [$file] to URL; current mappings: ".join(', ', $urls));
  }

  function hash($str) {
    if (strlen($this->secret) < 32) {
      // 32 chars in case we need it for mcrypt's IV in future.
      Deploki::fail('The $secret setting must be set to at least 32-character string.');
    } else {
      return md5($this->secret.'-'.$str);
    }
  }

  // $name can contain spaces (useful if the same key is repeated in chain
  // array: array('concat' => ..., ' concat  ' => ...)).
  // Corresponding 'name.php' will be loaded if class isn't defined yet.
  //
  //= DeplokiFilter descendant (DkiXXX)
  function filter($name) {
    $name = strtolower(trim($name));
    $name or Deploki::fail('No filter name given.')

    $class = 'Dki'.ucfirst($this->resolve($class, $this->viaAliases));

    if (!class_exists($class)) {
      $file = $this->absolute($name, $this->dkiPath);
      is_file($file) and (include_once $file);
    }

    if (!class_exists($class)) {
      Deploki::fail("Unknown filter [$name] - class [$class] is undefined.");
    }

    $obj = new $class($this);
    if (! $obj instanceof DeplokiFilter) {
      Deploki::fail("Class [$class] of filter [$name] must inherit from DeplokiFilter.");
    }

    return $obj;
  }

  //= hash 'js' => array('media/all/libs.js', ...)
  function mediaVars() {
    if ($this->reuseMedia and isset($this->cachedMedia)) {
      return $this->cachedMedia;
    } else {
      $result = array();

      $batchConfig = clone $this;
      $batchConfig->media = array();   // to avoid recursion.
      $batch = new DeplokiBatch($batchConfig);
      $files = $batch->process($this->media);

      foreach ($files as $name => $chain) {
        $ext = ltrim(strrchr($name, '.'), '.');
        $ext === '' and $ext = $name;

        isset($result[$ext]) or $result[$ext] = array();
        $values = $chain->urls ? $chain->urls : $chain->data;
        $result[$ext] = array_merge($result[$ext], $values);
      }

      $this->reuseMedia and $this->cachedMedia = $result;
      return $result;
    }
  }
}

/*-----------------------------------------------------------------------
| BASE CLASSES
|----------------------------------------------------------------------*/

class Deploki {
  public $config;     //= DeplokiConfig

  static function fail($msg) {
    throw new DeplokiError($msg);
  }

  static function locateConfig($baseName = 'config.php') {
    $path = dirname(__FILE__).'/';
    $file = $path.$baseName;
    is_file($file) or $file = $path."in/$baseName";
    is_file($file) or self::fail("Cannot locate $baseName.");
    return $file;
  }

  static function prepareEnvironment() {
    error_reporting(E_ALL & ~E_NOTICE);
    ini_set('display_errors', 'on');
    set_exception_handler(array('Deploki', 'onException'));
    set_error_handler(array('Deploki', 'onError'), error_reporting());

    function_exists('mb_internal_encoding') and mb_internal_encoding('UTF-8');
  }

  static function onException($e) {
    if ($e instanceof Exception) {
      $msg = '<h2>'.preg_replace('/([a-z])([A-Z])/', '\1 \2', get_class($e)).'</h2>'.
             'in <strong>'.htmlspecialchars($e->getFile().':'.$e->getLine()).'</strong>'.
             '<pre>'.htmlspecialchars($e->getMessage()).'</pre>'.
             '<hr>'.
             '<pre>'.$e->getTraceAsString().'</pre>';
    } else {
      $msg = '<h2>Exception occurred</h2>'.
             '<pre>'.htmlspecialchars(var_export($e, true)).'</pre>';
    }

    echo $msg;
    exit(1);
  }

  static function onError($code, $msg, $file = null, $line = null) {
    self::onException( new ErrorException($msg, $code, 0, $file, $line) );
  }

  //* $config array, DeplokiConfig
  static function make($config = array()) {
    return new self($config);
  }

  //* $config array, DeplokiConfig
  function __construct($config = array()) {
    $this->config = DeplokiConfig::make($config);
  }

  function set($option, $value = false) {
    if (isset($this->$option)) {
      $this->$option = $value;
    } else {
      $this->config->$option = $value;
    }

    return $this;
  }

  function deploy(array $pages) {
    $batch = new DeplokiBatch($this->config);
    return $batch->process($pages);
  }
}

class DeplokiBatch {
  public $config;       //= DeplokiConfig

  function __construct(DeplokiConfig $config) {
    $this->config = clone $config;
  }

  //= hash of DeplokiChain
  function parse(array $chains) {
    foreach ($chain as $name => &$chain) {
      $chain = new DeplokiChain($this->config, $chain, $name);
    }

    return $chain;
  }

  //= hash of DeplokiChain that were ran
  function process(array $pages) {
    $pages = $this->parse($pages);
    foreach ($pages as $chain) { $chain->execute(); }
    return $pages;
  }
}

class DeplokiChain {
  public $config;             //= DeplokiConfig
  public $filters = array();  //= array of DeplokiFilter

  public $name;               //= null, string
  public $data = array();     //= array of string
  public $urls = array();     //= array of string

  //= array of string
  static function execGetData(DeplokiConfig $config, $filters, $name = null)
    return (array) self::make($config, $filters, $name)->execute()->data;
  }

  static function make(DeplokiConfig $config, $filters, $name = null) {
    return new self($config, $filters, $name);
  }

  function __construct(DeplokiConfig $config, $filters, $name = null) {
    $this->config = clone $config;
    $this->name = $name;
    $this->filters = $this->parse($filters);
  }

  //= array of DeplokiFilter
  function parse($filters) {
    is_array($filters) or $filters = array($filters);

    foreach ($filters as $filter => &$options) {
      if (! $options instanceof DeplokiFilter) {
        if (is_int($filter)) {
          if (is_string($options)) {
            $filter = $options;
            $options = array();
          } else {
            $filter = $options['filter'];
          }
        }

        $baseConfig = clone $this->config;

        if (strpbrk($filter, '?!') !== false) {
          $baseConfig->condition = $filter[0] === '?' ? 'debug' : 'stage';
          $filter = substr($filter, 1);
        }

        $filter = $baseConfig->filter($filter);
        $filter->config->override($filter->normalize($options));
        $options = $filter;
      }
    }

    return $filters;
  }

  //= array of string data
  function execute() {
    foreach ($this->filters as $filter) {
      $this->filter->execute($this);
    }

    return $this;
  }

  function removeData($key) {
    unset($this->data[$key]);
    unset($this->urls[$key]);
    return $this;
  }
}

abstract class DeplokiFilter {
  public $config;   //= DeplokiConfig
  public $defaults = array();
  //= string key to create when $config isn't given as an array; see expand()
  public $shortOption;

  public $name;     //= string 'concat'
  public $chain;    //= null, DeplokiChain last execute(0'd chain

  function fail($msg) {
    throw new DeplokiFilterError($this, 'The '.ucfirst($this->name)." filter $msg.");
  }

  function __construct(DeplokiConfig $config) {
    $this->name = strtolower(get_class($this));
    substr($this->name, 0, 3) === 'dki' and $this->name = substr($this->name, 3);

    $this->config = $config;
    $this->initialize($config);
  }

  //* $config DeplokiConfig
  protected function initialize($config) { }

  //* $config hash, mixed short option for expandOptions()
  //= hash
  function normalize($config) {
    is_array($config) or $config = (array) $this->expandOptions($config);
    return $config + $this->defaults + array($this->shortOption => null);
  }

  function expandOptions($option) {
    if (isset($this->shortOption)) {
      return array($this->shortOption => $option);
    } else {
      $this->fail('doesn\'t support short option');
    }
  }

  function execute(DeplokiChain $chain) {
    if ($this->canExecute($chain)) {
      $this->doExecute($this->chain = $chain, $this->config);
    }
  }

  //* $chain DeplokiChain
  //* $config DeplokiConfig
  protected abstract function doExecute($chain, $config);

  function canExecute(DeplokiChain $chain) {
    $can = true;

    switch ($cond = $this->config->condition) {
    case 'debug':   $can &= $this->config->debug; break;
    case 'stage':   $can &= !$this->config->debug; break;
    default:        is_callable($cond) and $can &= call_user_func($cond, $this, $chain);
    }

    return (bool) $can;
  }

  function expand($name, array $vars = array()) {
    $vars += array(
      'type'            => $ext = ltrim(strrchr($this->chain->name, '.'), '.'),
      'name'            => basename($this->chain->name, ".$ext"),
    );

    return $this->config->expand($name, $vars);
  }
}

/*-----------------------------------------------------------------------
| STANDARD FILTERS
|----------------------------------------------------------------------*/

class DkiRead extends DeplokiFilter {
  public $shortOption = 'masks';

  protected function doExecute($chain, $config) {
    foreach ((array) $config->masks as $mask) {
      $path = $config->absolute($mask, $config->outPath);
      $files = glob($path, GLOB_NOESCAPE | GLOB_BRACE);

      foreach ($files as $path) {
        $chain->data[$path] = $this->config->readFile($path);
      }
    }
  }
}

class DkiConcat extends DeplokiFilter {
  public $defaults = array('chains' => array(), 'glue' => "\n",
                           'reuseChain' => true);

  protected function doExecute($chain, $config) {
    if ($config->chains) {
      $this->concatWith($config->chains, $chain->data);
    } else {
      $glue = $this->expand($config->glue);
      $chain->data = array(join($glue, $chain->data));
    }
  }

  function concatWith($chains, $current) {
    foreach ((array) $current as &$data) {
      $merged = '';
      $results = array();

      if ($this->config->reuseChain) {
        foreach ((array) $chains as $name => $chain) {
          $results[$name] = DeplokiChain::execGetData($this->config, $chain, $name);
        }
      }

      foreach ((array) $chains as $name => $chain) {
        if ($chain === null) {
          $merged .= $data;
        } elseif (isset($results[$name])) {
          $merged .= $results[$name];
        } else {
          $merged .= DeplokiChain::execGetData($this->config, $chain, $name);
        }
      }

      $data = $merged;
    }
  }
}

class DkiMinify extends DeplokiFilter {
  protected function doExecute($chain, $config) {
    if (! $ext = ltrim(strrchr($chain->name, '.'), '.')) {
      $this->fail("expects an extension to be specified for resource".
                  " [{$chain->name}], e.g. as in 'libs.js'");
    }

    $filter = 'minify'.strtolower($ext);
    foreach ($chain->data as &$data) {
      $data = DeplokiChain::execGetData($config, $filter, $chain->name);
    }
  }
}

class DkiVia extends DeplokiFilter {
  public $shortOption = 'script';

  protected function doExecute($chain, $config) {
    $name = $config->resolve($config->script, $config->viaAliases);
    strrchr(basename($name), '.') === false and $name .= '.php';
    $file = $config->absolute($name, $config->inPath);
    $this->execScript($file);
  }

  function execScript($_file) {
    is_file($_file) or $_file->fail("cannot find script [$file] to execute");

    $chain = $this->chain;
    $config = $this->config;
    return include $_file;
  }
}

class DkiWrite extends DeplokiFilter {
  // 'url' may contain '{VAR}'s or be a function ($file, array $vars, Filter).
  // 'dest' can be a function (Filter, array $vars).
  public $defaults = array('url' => null);
  public $shortOption = 'dest';

  protected function doExecute($chain, $config) {
    foreach ($chain->data as $src => &$data) {
      $vars = array(
        'path'            => dirname($src),
        'ext'             => $ext = ltrim(strrchr(basename($src), '.'), '.'),
        'file'            => basename($src, ".$ext"),
      );

      if (is_callable( $dest = $config->dest )) {
        $dest = call_user_func($dest, $this, $vars);
      } else {
        $dest = $this->expand($dest, $vars);
      }

      $dest = $config->absolute($dest, $config->mediaPath);
      $config->writeFile($dest, $data);

      $chain->urls[$src] = $this->urlOf($dest, $vars);
    }
  }

  function urlOf($file, array $vars) {
    $url = $this->config->url;

    if (is_callable($url)) {
      return call_user_func($url, $file, $vars, $this);
    } elseif ($url) {
      return $this->expand($url, $vars);
    } else {
      return $this->config->urlOf($file);
    }
  }
}

class DkiKeep extends DeplokiFilter {
  public $shortOption = 'mask';

  protected function doExecute($chain, $config) {
    $toRemove = $this->getRemoving($chain, $config);
    array_map(array($chain, 'removeData'), $toRemove);
  }

  function getRemoving($chain, $config) {
    $keys = array_keys($chain->urls ? $chain->urls : $chain->data);

    if (is_callable( $mask = $config->mask )) {
      $keys = array_filter($keys, $mask);
    } else {
      $mask = $this->expand($mask);
      $isWildcard = strpbrk($mask, '?*') !== false;

      if ($isWildcard) {
        $mask = '~'.preg_quote($mask, '~').'~i';
        $mask = strtr($mask, array('\\?' => '.', '\\*' => '.*?'));
      }

      $isWildcard |= $mask[0] === '~';

      foreach ($keys as $i => $key) {
        $keep = $isWildcard ? preg_match($mask, $key) : (stripos($key, $mask) !== false));
        if ($keep) { unset($keys[$i]); }
      }
    }

    return $keys;
  }
}

class DkiOmit extends DkiKeep {
  function getRemoving($chain, $config) {
    $keys = array_keys($chain->urls ? $chain->urls : $chain->data);
    return array_diff($keys, parent::getRemoving($chain, $config));
  }
}

class DkiLink extends DeplokiFilter {
  protected function doExecute($chain, $config) {
    $query = array();

    foreach ($chain->data as $path) {
      $real = realfile($path) and $query[] = '0='.urlencode($real);
    }

    $query[] = 'hash='.$config->hash(join('|', $query));

    $linker = $this->config->absolute('link.php', $this->config->dkiPath);
    $url = $this->config->urlOf($linker);
    $chain->urls['linked'] = $url.'?'.join('&', $query);
  }
}

class DkiVersion extends DeplokiFilter {
  public $defaults = array('query' => '{VER}', 'store' => 'versions.php');
  public $shortOption = 'query';

  protected function doExecute($chain, $config) {
    $store = $config->absolute($config->store, $config->mediaPath);
    $versions = is_file($store) ? ((array) (include $store)) : array();
    $hasObsolete = false;

    foreach ($chain->urls as $src => &$url) {
      if (isset($chain->data[$src])) {
        $hash = md5($chain->data[$src]);
        $key = $chain->name."/$src";

        $ver = &$versions[$key]['version'];
        if ($ver <= 0 or $versions[$key]['md5'] !== $hash) {
          $hasObsolete = true;
          ++$ver;
        }

        $url .= strrchr($url, '?') === false ? '?' : '&';
        $url .= $this->expand($config->query, compact('ver'));
      }
    }

    if ($hasObsolete) {
      $export = "<?php\nreturn ".var_export($versions, true).';';
      $config->writeFile($store, $export);
    }
  }
}

abstract class DeplokiRenderingFilter extends DeplokiFilter {
  // 'configurator' is a function ($config, Filter); if returns non-null value
  // it's used instead of passed $config.
  public $defaults = array('compact' => true, 'configurator' => null,
                           'vars' => array(), 'languages' => true,
                           'media' => true);

  public $shortOption = 'fromData';
  public $rendererConfig;   //= null, object filter-dependent

  // Loads external framework (e.g. HTMLki) if this filter depends on it.
  function load() { }

  function configure($config = null) {
    $config = $this->doConfigure($config);

    if (is_callable( $func = $this->config->configurator )) {
      $res = call_user_func($func, $config, $this);
      isset($res) and $config = $res;
    }

    return $config;
  }

  abstract protected function doConfigure($cfg = null);

  function render($data) {
    $result = array();
    $media = $this->config->media ? $this->config->mediaVars() : array();

    $languages = $this->config->languages;
    if (!is_array($languages) and !is_string($languages)) {
      $languages = $languages ? $this->config->languages : null;
    }

    foreach ((array) $languages as $lang) {
      $vars = $this->config->vars + compact('languages', 'lang') +
              $media + $this->config->vars();

      $rendered = $this->doRender($vars, $tpl);
      $this->config->compact and $rendered = $this->compact($rendered);

      $result[$lang] = $rendered;
    }

    return is_array($languages) ? $result : reset($result);
  }

  abstract protected function doRender(array $vars, $data);

  protected function doExecute($chain, $config) {
    $this->load();
    $this->rendererConfig = $this->configure();
    $datas = $chain->data;

    foreach ($datas as $key => &$data) {
      $rendered = $this->render($data);

      if (is_array($rendered)) {
        $chain->removeData($key);

        foreach ($rendered as $suffix => &$data) {
          $chain->data["{$key}_$suffix"] = $data;
        }
      } else {
        $chain->data[$key] = $rendered;
      }
    }
  }

  function compact($html) {
    $this->config->debug or $html = preg_replace('/(\s)\s*/u', '\\1', $html);
    return $html;
  }
}

class DkiHtmlki extends DeplokiRenderingFilter {
  protected function initialize($config) {
    $this->defaults += array('xhtml' => false, 'failOnWarning' => true);
  }

  function load() {
    if (!class_exists('HTMLki')) {
      $file = $this->config->absolute('htmlki.php', $this->config->dkiPath);
      include_once $file;
      class_exists('HTMLki') or $this->fail("cannot load HTMLki from [$file]");
    }
  }

  function template($file, HTMLkiConfig $config) {
    $base = basename($file, '.html').'-'.md5($file).'.php';
    $cache = $this->config->absolute("htmlki/$base", $this->config->tempPath);

    if (!is_file($file)) {
      $this->fail("cannot find template file [$file]");
    } elseif (!is_file($cache) or filemtime($cache) < filemtime($file)) {
      $compiled = HTMLki::compileFile($file, $config);
      $this->config->writeFile($cache, $compiled);
      $tpl = HTMLki::template($compiled, $config);
    } else {
      $tpl = HTMLki::templateFile($cache, $config);
    }

    $tpl->SrcFile = $file;
    return $tpl;
  }

  protected function doConfigure(HTMLkiConfig $cfg = null) {
    $cfg or $cfg = HTMLki::config();

    $cfg->warning = array($this, 'onWarning');
    $cfg->addLineBreaks = $this->config->debug;
    $cfg->xhtml = $this->config->xhtml;
    $cfg->language = array($this->config, 'langLine'),
    $cfg->template = array($this, 'onInclude');

    return $cfg;
  }

    function onWarning($msg) {
      if ($this->config->failOnWarning) {
        $this->fail('has catched HTMLki warning: '.$msg);
      } else {
        trigger_error('HTMLki warning: '.$msg, E_USER_WARNING);
      }
    }

    //= HTMLkiTemplate
    function onInclude($template, HTMLkiTemplate $parent, HTMLkiTagCall $call) {
      // $SrcFile is set by $this->template().
      $path = $parent->SrcFile ? dirname($parent->SrcFile) : $this->config->inPath;
      $file = rtrim($path, '\\/')."/_$template.html";
      $config = $parent->config();

      $tpl = $this->template($file, $config);
      $tpl->vars($call->vars + $parent->vars);
      return $tpl;
    }

  protected function doRender(array $vars, $data) {
    if ($config->fromData) {
      $tpl = HTMLki::template($data, $this->rendererConfig);
    } else {
      $tpl = $this->template($data, $this->rendererConfig);
    }

    return $tpl->vars($vars)->render();
  }
}

class DkiUwiki extends DeplokiRenderingFilter {
  protected function initialize($config) {
    // 'fsCharset' needs iconv extension.
    $this->defaults += array('xhtml' => false, 'markup' => 'wiki', 'fsCharset' => null);
  }

  function load() {
    if (!class_exists('UWikiDocument')) {
      $file = $this->config->absolute('uwiki/uversewiki.php', $this->config->dkiPath);
      include_once $file;
      class_exists('UWikiDocument') or $this->fail("cannot load UverseWiki from [$file]");
    }
  }

  protected function doConfigure(UWikiSettings $cfg = null) {
    $cfg or $cfg = new UWikiSettings;

    $cfg->enableHTML5 = !$this->config->xhtml;
    $cfg->rootURL = $this->config->urlOf($this->config->outPath);
    $cfg->pager = new UWikiFilePager($this->config->inPath);
    $cfg->pager->nameConvertor = array($this, 'nameConvertor');

    $cfg->LoadFrom(UWikiRootPath.'\\config');
    $cfg->LoadFrom($this->config->absolute('uwiki', $this->config->inPath));

    return $cfg;
  }

    function nameConvertor($str) {
      if ($charset = $this->config->fsCharset) {
        function_exists('iconv') or $this->fail('needs iconv() to convert FS names');
        $str = iconv('UTF-8', "$charset//IGNORE", $str);

        if (!is_string($str)) {
          $this->fail("cannot convert FS name from UTF-8 to [$charset] - iconv() error");
        }
      }

      return $str;
    }

  protected function doRender(array $vars, $data) {
    $config->fromData or $data = $this->config->readFile($data);
    $doc = new UWikiDocument($data, $this->rendererConfig);
    $doc->LoadMarkup($this->config->markup);
    $doc->Parse();
    return $doc->ToHTML();
  }
}
