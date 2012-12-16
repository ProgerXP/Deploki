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
  static $langLines = array();  //= hash of hash loaded language files

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
  public $libPaths;     //= hash of string path

  /*---------------------------------------------------------------------
  | FILTER-SPECIFIC
  |--------------------------------------------------------------------*/

  //= string 'debug' or 'stage', callable (Filter, Chain), mixed filter-specific
  public $condition       = null;

  /*---------------------------------------------------------------------
  | METHODS
  |--------------------------------------------------------------------*/

  static function loadFrom($_file) {
    $isFlat = basename(dirname($_file)) !== 'in';
    $config = self::make()->defaultsAt(dirname($_file), $isFlat);

    extract($config->vars(), EXTR_SKIP);
    require $_file;

    return $config->override(get_defined_vars());
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
  function defaults($inPath = null) {
    $this->debug          = @$_SERVER['REMOTE_ADDR'] === '127.0.0.1';
    $this->app            = null;

    $this->dkiPath        = dirname(__FILE__);
    $this->defaultsAt($this->dkiPath.'/in');

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

  function defaultsAt($inPath, $flat = false) {
    $inPath = rtrim($inPath, '\\/');

    $this->inPath         = $inPath;
    $this->outPath        = $flat ? dirname($inPath) : dirname(dirname($inPath));
    $this->tempPath       = $flat ? "$inPath/temp" : dirname($inPath).'/temp';
    $this->mediaPath      = $this->outPath.'/media/all';

    $this->languages      = $this->detectLanguagesIn($inPath);
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
    $name or Deploki::fail('No filter name given.');

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

  //= string translated & formatted
  //= array if no $format is given and $str points to an array or is omitted
  function langLine($lang, $str = null, array $format = array()) {
    $lines = &self::$langLines[$lang];
    isset($lines) or $lines = (include $this->absolute("$lang.php", $this->inPath));
    $line = isset($str) ? @$lines[$str] : $lines;

    if (is_array($line)) {
      if (func_num_args() < 2) {
        return $line;
      } else {
        Deploki::warn("Language line [$str] refers to an array.");
        return $str;
      }
    } else {
      if (!isset($line)) {
        $line = $str;
        Deploki::warn("Missing language string [$str].");
      }

      return str_replace(array_map(array($this, 'langVar'), $format), $format, $line);
    }
  }

  function langVar($name) {
    is_int($name) and ++$name;
    return "$$name";
  }

  //= string absolute path
  function libPath($lib, $default) {
    isset($this->libPaths[$lib]) and $default = $this->libPaths[$lib];
    return $this->config->absolute($default, $this->config->dkiPath);
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

  static function warn($msg) {
    trigger_error("Deploki: $msg.", E_USER_WARNING);
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
    $this->config = new DeplokiConfig($config);
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
  static function execGetData(DeplokiConfig $config, $filters, $name = null) {
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

        if (strpbrk($filter[0], '?!') !== false) {
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

  function execute() {
    foreach ($this->filters as $filter) { $filter->execute($this); }
    return $this;
  }

  function removeData($key) {
    unset($this->data[$key]);
    unset($this->urls[$key]);
    return $this;
  }
}

/*-----------------------------------------------------------------------
| BASE FILTER CLASSES
|----------------------------------------------------------------------*/

abstract class DeplokiFilter {
  public $config;     //= DeplokiConfig
  public $defaults = array();
  //= string key to create when non-array $config is given; see expandOptions()
  public $shortOption;

  public $name;       //= string 'concat'
  public $chain;      //= null, DeplokiChain last execute(0'd chain

  function __construct(DeplokiConfig $config) {
    $this->name = strtolower(get_class($this));
    substr($this->name, 0, 3) === 'dki' and $this->name = substr($this->name, 3);

    $this->config = $config;
    $this->initialize($config);
  }

  // Called upon class instantination.
  //* $config DeplokiConfig - shortcut to $this->config.
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

  function fail($msg) {
    throw new DeplokiFilterError($this, strtoupper($this->name)." filter $msg.");
  }

  function warn($msg) {
    Deploki::warn(ucfirst($this->name)." filter $msg.");
  }

  function execute(DeplokiChain $chain) {
    if ($this->canExecute($chain)) {
      $this->doExecute($this->chain = $chain, $this->config);
    }
  }

  //* $chain DeplokiChain - shortcut to $this->chain
  //* $config DeplokiConfig - shortcut to $this->config
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

// Renderers are filters that transform one markup into another (e.g. wiki to HTML).
abstract class DeplokiRenderingFilter extends DeplokiFilter {
  // 'configurator' is a function ($config, Filter); if returns non-null value
  // it's used instead of passed $config.
  public $defaults = array('compact' => true, 'configurator' => null,
                           'vars' => array(), 'languages' => true,
                           'media' => true);

  public $shortOption = 'fromData';
  public $rendererConfig;   //= null, object filter-dependent - see configure()
  protected $currentLang;   //= null, str e.g. 'en', set by render()

  // Loads external framework (e.g. HTMLki) if this filter depends on it.
  function load() { }

  //* $config null, mixed filter-dependent - base config to set up.
  function configure($config = null) {
    $config = $this->doConfigure($config);

    if (is_callable( $func = $this->config->configurator )) {
      $res = call_user_func($func, $config, $this);
      isset($res) and $config = $res;
    }

    return $config;
  }

  //* $config null create fresh config, object base config to set up
  abstract protected function doConfigure($config);

  //* $data string - file name or data ('fromData' bool option) to transform.
  //= string transformed data
  //= hash 'suffix' => 'data' - multiple new data (e.g. per language)replacing old
  function render($data) {
    $result = array();
    $media = $this->config->media ? $this->config->mediaVars() : array();

    $languages = $this->config->languages;
    if (!is_array($languages) and !is_string($languages)) {
      $languages = $languages ? $this->config->languages : array(null);
    }

    foreach ((array) $languages as $lang) {
      $vars = $this->config->vars + compact('languages', 'lang') +
              $media + $this->config->vars();

      $rendered = $this->doRender($vars, $data);
      $this->config->compact and $rendered = $this->compact($rendered);

      $this->currentLang = $lang;
      $result[$lang] = $rendered;
    }

    $this->currentLang = null;
    return is_array($languages) ? $result : reset($result);
  }

  //* $vars hash - variables like 'lang' or 'css' (media).
  //* $data string - file name or data ('fromData' bool option) to transform.
  //= string converted data (e.g. HTML)
  abstract protected function doRender(array $vars, $data);

  //= string translated & formatted
  function langLine($str, array $format = array()) {
    if ($this->currentLang) {
      return $this->config->langLine($this->currentLang, $str, $format);
    } else {
      $this->warn('trying to get language line for a non-language page');
      return $str;
    }
  }

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

  // Lightweight HTML minification - collapses successive whitespace into one.
  function compact($html) {
    $html = preg_replace('/(\s)\s*/u', '\\1', $html);
    return $html;
  }
}

/*-----------------------------------------------------------------------
| STANDARD FILTERS
|----------------------------------------------------------------------*/

// Reads listed file paths into their contents.
class DkiRead extends DeplokiFilter {
  public $shortOption = 'masks';

  protected function doExecute($chain, $config) {
    foreach ((array) $config->masks as $mask) {
      $path = $config->absolute($mask, $config->outPath);
      $files = glob($path, GLOB_NOESCAPE | GLOB_BRACE);

      foreach ($files as $path) {
        $data = file_exists($path) ? $this->config->readFile($path) : '';
        $chain->data[$path] = $data;
      }
    }
  }
}

// Writes current data to local files and provides their URLs.
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

// Joins current data or wraps it in given chains, if passed.
class DkiConcat extends DeplokiFilter {
  public $defaults = array('glue' => "\n", 'reuseChains' => true);
  public $shortOption = 'chains';

  protected function doExecute($chain, $config) {
    if ($config->chains) {
      $this->concatWith($config->chains, $chain->data);
    } else {
      $glue = $this->expand($config->glue);
      $chain->data = array(join($glue, $chain->data));
    }
  }

  function concatWith($chains, &$current) {
    foreach ($current as &$data) {
      $merged = '';
      $results = array();

      if ($this->config->reuseChains) {
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

// Calls corresponding minifier to compress CSS, JS or other data.
class DkiMinify extends DeplokiFilter {
  protected function doExecute($chain, $config) {
    if (! $ext = ltrim(strrchr($chain->name, '.'), '.')) {
      $this->fail("expects an extension to be specified for resource".
                  " [{$chain->name}], e.g. as in 'libs.js'");
    }

    foreach ($chain->data as &$data) {
      $data = join(DeplokiChain::execGetData($config, "minify$ext", $chain->name));
    }
  }
}

// Calls external script to do custom operations on current data, URLs, filters, etc.
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

// Filters current data or URLs.
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
        $keep = $isWildcard ? preg_match($mask, $key) : (stripos($key, $mask) !== false);
        if ($keep) { unset($keys[$i]); }
      }
    }

    return $keys;
  }
}

// As KEEP but removes matched data/URLs.
class DkiOmit extends DkiKeep {
  function getRemoving($chain, $config) {
    $keys = array_keys($chain->urls ? $chain->urls : $chain->data);
    return array_diff($keys, parent::getRemoving($chain, $config));
  }
}

// Converts list of file paths into one URL - useful to avoid redeploying on debug.
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

// Adds version token to current URLs to utilize infinite server-side caching.
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

// HTMLki renderer - reads HTML in HTMLki markup and produces regular HTML.
class DkiHtmlki extends DeplokiRenderingFilter {
  protected function initialize($config) {
    parent::initialize($config);
    $this->defaults += array('xhtml' => false);
  }

  function load() {
    if (!class_exists('HTMLki')) {
      $file = $this->config->libPath('htmlki', 'htmlki.php');
      include_once $file;
      class_exists('HTMLki') or $this->fail("cannot load HTMLki from [$file]");
    }
  }

  //* $kiCfg HTMLkiConfig
  protected function doConfigure($kiCfg = null) {
    ($kiCfg instanceof HTMLkiConfig) or $this->fail('received $kiCfg of wrong type');
    $kiCfg or $kiCfg = HTMLki::config();

    $kiCfg->warning = array($this, 'onWarning');
    $kiCfg->addLineBreaks = $this->config->debug;
    $kiCfg->xhtml = $this->config->xhtml;
    $kiCfg->language = array($this, 'langLine');
    $kiCfg->template = array($this, 'onInclude');

    return $kiCfg;
  }

    function onWarning($msg) {
      Deploki::warn("HTMLki warning: $msg");
    }

    //= HTMLkiTemplate
    function onInclude($template, HTMLkiTemplate $parent, HTMLkiTagCall $call) {
      // $SrcFile is set by $this->template().
      $path = $parent->SrcFile ? dirname($parent->SrcFile) : $this->config->inPath;
      $file = rtrim($path, '\\/')."/_$template.html";

      $tpl = $this->template($file, $parent->config());
      $tpl->vars($call->vars + $parent->vars);

      return $tpl;
    }

  protected function doRender(array $vars, $data) {
    if ($this->config->fromData) {
      $tpl = HTMLki::template($data, $this->rendererConfig);
    } else {
      $tpl = $this->template($data, $this->rendererConfig);
    }

    return $tpl->vars($vars)->render();
  }

  function template($file, HTMLkiConfig $kiCfg) {
    $base = basename($file, '.html').'-'.md5($file).'.php';
    $cache = $this->config->absolute("htmlki/$base", $this->config->tempPath);

    if (!is_file($file)) {
      $this->fail("cannot find template file [$file]");
    } elseif (!is_file($cache) or filemtime($cache) < filemtime($file)) {
      $compiled = HTMLki::compileFile($file, $kiCfg);
      $this->config->writeFile($cache, $compiled);
      $tpl = HTMLki::template($compiled, $kiCfg);
    } else {
      $tpl = HTMLki::templateFile($cache, $kiCfg);
    }

    $tpl->SrcFile = $file;
    return $tpl;
  }
}

// UverseWiki renderer - transforms wiki markup into HTML.
class DkiUwiki extends DeplokiRenderingFilter {
  protected function initialize($config) {
    parent::initialize($config);
    // 'fsCharset' needs iconv extension.
    $this->defaults += array('xhtml' => false, 'markup' => 'wiki', 'fsCharset' => null);
  }

  function load() {
    if (!class_exists('UWikiDocument')) {
      $file = $this->config->libPath('uwiki', 'uwiki/uversewiki.php');
      include_once $file;
      class_exists('UWikiDocument') or $this->fail("cannot load UverseWiki from [$file]");
    }
  }

  protected function doConfigure($uwCfg = null) {
    ($kiCfg instanceof HTMLkiConfig) or $this->fail('received $uwCfg of wrong type');
    $uwCfg or $uwCfg = new UWikiSettings;

    $uwCfg->enableHTML5 = !$this->config->xhtml;
    $uwCfg->rootURL = $this->config->urlOf($this->config->outPath);
    $uwCfg->pager = new UWikiFilePager($this->config->inPath);
    $uwCfg->pager->nameConvertor = array($this, 'nameConvertor');

    $uwCfg->LoadFrom(UWikiRootPath.'\\config');
    $uwCfg->LoadFrom($this->config->absolute('uwiki', $this->config->inPath));

    return $uwCfg;
  }

    function nameConvertor($name) {
      if ($charset = $this->config->fsCharset) {
        function_exists('iconv') or $this->fail('needs iconv() to convert FS names');
        $name = iconv('UTF-8', "$charset//IGNORE", $name);

        if (!is_string($name)) {
          $this->fail("cannot convert FS name from UTF-8 to $charset - iconv() error");
        }
      }

      return $name;
    }

  protected function doRender(array $vars, $data) {
    $config->fromData or $data = $this->config->readFile($data);

    $doc = new UWikiDocument($data, $this->rendererConfig);
    $doc->LoadMarkup($this->config->markup);
    $doc->Parse();

    return $doc->ToHTML();
  }
}

Deploki::prepareEnvironment();
$options = DeplokiOptions::loadFrom(Deploki::locateConfig());

$deploki = new Deploki(config);
$deploki->deploy($config->pages);
