<?php
/*
  Deploki.org - static web revisited
  in Public domain | by Proger_XP | http://proger.i-forge.net/Deploki
  report to GitHub | http://github.com/ProgerXP/Deploki
  Supports PHP 5.2+
*/

function dkiPath($path) {
  return rtrim(strtr($path, '\\', '/'), '/');
}

function dkiIsAbs($path) {
  return ($path = trim($path)) !== '' and
         (strpbrk($path[0], '\\/') !== false  or strpos($path, ':') !== false);
}

// Unlike (array) it won't convert objects.
//= array always
function dkiArray($value) {
  return is_array($value) ? $value : array($value);
}

function dkiGet($array, $key, $default = null) {
  return (is_array($array) and array_key_exists($key, $array)) ? $array[$key] : $default;
}

function dkiLang($num, $word, $plural = 's') {
  return $num." $word".($num === 1 ? '' : $plural);
}

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
  public $langLineWarn; //= bool if a missing language line should emit a warning or not
  public $viaAliases;   //= hash of string 'alias' => 'realVia'
  public $perFilter;    //= hash of hash, hash of DeplokiConfig
  public $logger;       //= callable ($msg, DeplokiFilter = null)
  public $nestingLevel; //= int depth of current filter chain (> 0)

  /*---------------------------------------------------------------------
  | METHODS
  |--------------------------------------------------------------------*/

  static function has($option) {
    static $keys;
    $keys or $keys = (array) (new self);
    return array_key_exists($option, $keys);
  }

  static function locate($path = null, $baseName = 'config.php') {
    $path = dkiPath($path ? $path : dirname(__FILE__)).'/';
    $file = $path.$baseName;
    is_file($file) or $file = $path."in/$baseName";
    is_file($file) or Deploki::fail("Cannot locate $baseName in [$path].");
    return $file;
  }

  static function loadFrom($_file) {
    $config = self::make()->defaultsAt(dirname($_file));
    extract($config->vars(), EXTR_SKIP);

    require $_file;
    return $config->override(get_defined_vars());
  }

  //* $default string becomes array, array, null - value to return on empty result.
  //= array ('ru', 'en')
  static function detectLanguagesIn($path, $default = 'en') {
    $languages = array();

    if (is_dir($path)) {
      foreach (scandir($path) as $lang) {
        if (strrchr($lang, '.') === '.php' and strlen($lang) === 6) {
          $languages[] = substr($lang, 0, 2);
        }
      }
    }

    $languages or $languages = (array) $default;
    return $languages;
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
    $this->debug          = (dkiGet($_SERVER, 'REMOTE_ADDR') === '127.0.0.1'
                             or getenv('comspec'));
    $this->app            = null;

    $this->dkiPath        = dirname(__FILE__);
    $this->defaultsAt($this->dkiPath.'/in');

    $this->media          = array();

    $this->urls           = array(
      'outPath'           => dirname(dkiGet($_SERVER, 'REQUEST_URI')),
    );

    $this->dirPerms       = 0755;
    $this->filePerms      = 0744;
    $this->langLineWarn   = true;

    $this->viaAliases     = array(
      'htm'               => 'html',
      'html'              => 'htmlki',
      'wiki'              => 'uwiki',
    );

    // '' applies to all filters.
    $this->perFilter      = array('' => array());
    $this->logger         = null;
    $this->nestingLevel   = 0;

    return $this;
  }

  function defaultsAt($inPath, $outPath = null, $tempPath = null) {
    $inPath = dkiPath($inPath);
    $outPath = dkiPath($outPath);
    $tempPath = dkiPath($tempPath);
    $dkiPath = dkiPath(dirname(__FILE__));

    if (!$outPath) {
      // If in/ is located under Deploki's library root this typically corresponds to
      // this structure: server_root/, root/deploy/, root/deploy/in, root/deploy/temp/.
      // If not we'll assume it's one level inside the output dir (root/, root/in/).
      $outPath = dirname($inPath) === $dkiPath ? dirname($dkiPath) : dirname($inPath);
    }

    if (!$tempPath) {
      // temp/ is typically always on the same dir level as in/ except when in/ and
      // Deploki are located in the same place (root/deploy/, root/deploy/temp/).
      $tempPath = $inPath === $outPath ? "$inPath/temp" : dirname($inPath).'/temp';
    }

    $this->inPath         = $inPath;
    $this->outPath        = $outPath;
    $this->tempPath       = $tempPath;
    $this->mediaPath      = "$outPath/media/all";

    $this->languages      = self::detectLanguagesIn($inPath);
    return $this;
  }

  function override(array $override) {
    foreach ($override as $name => $value) {
      "$name" !== '' and $this->$name = $value;
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
    if (($path = trim($path)) === '') {
      return $base;
    } elseif (dkiIsAbs($path)) {
      return $path;
    } else {
      return dkiPath($base)."/$path";
    }
  }

  // Replaces strings of form {VAR} in $name. Automatically adds commonExpandVars().
  function expand($str, array $variables = array()) {
    if (strrchr($str, '{') === false or strrchr($str, '}') === false) {
      return $str;
    } else {
      $from = array();
      $variables += $this->commonExpandVars();

      foreach ($variables as $name => $value) {
        $from[] = '{'.strtoupper($name).'}';
      }

      return str_replace($from, $variables, $str);
    }
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
    $this->mkdir(dirname($path));
    $existed = is_file($path);
    $ok = file_put_contents($path, $data, LOCK_EX) == strlen($data);
    $ok or Deploki::fail("Cannot write ".strlen($data)." bytes to file [$path].");
    $existed or chmod($path, $this->filePerms);
  }

  function readFile($path) {
    $data = is_file($path) ? file_get_contents($path) : null;
    is_string($data) or Deploki::fail("Cannot read file [$path].");
    return $data;
  }

  function urlOf($file) {
    $file = dkiPath(realpath($file) ? realpath($file) : $file);
    $mappings = array();

    foreach ($this->urls as $path => $url) {
      dkiIsAbs($path) or $path = $this->$path;
      $path = dkiPath($path).'/';
      $mappings[] = "$path -> $url";

      if (substr("$file/", 0, $len = strlen($path)) === $path) {
        return dkiPath($url).'/'.substr($file, $len);
      }
    }

    Deploki::fail("Cannot map [$file] to URL; current mappings: ".join(', ', $mappings));
  }

  function hash($str) {
    if (strlen($this->secret) < 32) {
      // 32 chars in case we need it for mcrypt's IV in future.
      Deploki::fail('The $secret option must be set to at least 32-character string.');
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

    $class = 'Dki'.ucfirst($this->resolve($name, $this->viaAliases));

    if (!class_exists($class)) {
      $file = $this->absolute($name, $this->dkiPath);
      is_file($file) and (include_once $file);

      if (!class_exists($class)) {
        Deploki::fail("Unknown filter [$name] - class [$class] is undefined and".
                      " it wasn't found in [$file].");
      }
    }

    $obj = new $class($this);
    if (! $obj instanceof DeplokiFilter) {
      Deploki::fail("Class [$class] of filter [$name] must inherit from DeplokiFilter.");
    }

    return $obj;
  }

  //= hash 'js' => array('media/all/libs.js', ...)
  function mediaVars() {
    if (! $this->media instanceof DeplokiBatch) {
      if ($this->logger and $count = count($this->media)) {
        call_user_func($this->logger, "- MEDIA VARS ($count) -", $this);
      }

      $batchConfig = clone $this;
      $batchConfig->media = array();   // to avoid recursion.
      $this->media = new DeplokiBatch($batchConfig, $this->media);
      $this->media->cache = true;
    }

    $result = array();

    foreach ($this->media->process() as $name => $chain) {
      // $name = name[.[ext]]; vars containing the same 'ext' are joined
      // together; vars without a dot not but their values are joined into
      // a string; vars ending on '.' are are passed to the template as
      // is (even if they're not scalar).
      $ext = ltrim(strrchr($name, '.'), '.');
      $values = $chain->urls ? $chain->urls : $chain->data;

      if (strrchr($name, '.') === false) {
        $values = dkiArray($values);
        if (count($values) == 1 and !is_scalar(reset($values))) {
          $result[$name] = reset($values);
        } else {
          $result[$name] = join($values);
        }
      } elseif ($ext === '') {
        $result[rtrim($name, '.')] = $values;
      } else {
        isset($result[$ext]) or $result[$ext] = array();
        $result[$ext] = array_merge($result[$ext], $values);
      }
    }

    return $result;
  }

  function addMedia($name, $chain) {
    if ($this->media instanceof DeplokiBatch) {
      $this->media->add(array($name => $chain));
    } else {
      $this->media[$name] = $chain;
    }
  }

  function hasMedia($name) {
    if ($this->media instanceof DeplokiBatch) {
      $this->media->has($name);
    } else {
      return isset($this->media[$name]);
    }
  }

  //= string translated & formatted
  //= array if no $format is given and $str points to an array or is omitted
  function langLine($lang, $str = null, array $format = array()) {
    $lines = dkiGet(self::$langLines, $lang);
    isset($lines) or $lines = (include $this->absolute("$lang.php", $this->inPath));
    $line = isset($str) ? dkiGet($lines, $str) : $lines;

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
        $this->langLineWarn and Deploki::warn("Missing language string [$str].");
      }

      return str_replace(array_map(array($this, 'langVar'), $format), $format, $line);
    }
  }

  function langVar($name) {
    is_int($name) and ++$name;
    return "$$name";
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
    trigger_error("Deploki: $msg", E_USER_WARNING);
  }

  static function fileVars($path, $typeVar, $nameVar, $pathVar = null) {
    $pathVar and $pathVar = array($pathVar => dirname($path));

    return ((array) $pathVar) + array(
      $typeVar            => $ext = ltrim(strrchr($path, '.'), '.'),
      $nameVar            => basename($path, ".$ext"),
    );
  }

  // From http://proger.i-forge.net/Various_format_parsing_functions_for_PHP/ein
  //* $args array - PHP's $argv, list of raw command-line options given by index.
  //* $arrays array - listed keys will be always arrays in returned 'options'.
  //= array with 3 arrays described above (flags, options, index)
  function parseCL($args, $arrays = array()) {
    $arrays = array_flip($arrays);
    $flags = $options = $index = array();

    foreach ($args as $i => &$arg) {
      if ($arg !== '') {
        if ($arg[0] == '-') {
          if ($arg === '--') {
            $index = array_merge($index, array_slice($args, $i + 1));
            break;
          } elseif ($argValue = ltrim($arg, '-')) {
            if ($arg[1] == '-') {
              strrchr($argValue, '=') === false and $argValue .= '=';
              list($key, $value) = explode('=', $argValue, 2);
              $key = strtolower($key);
              isset($value) or $value = true;

              if (preg_match('/^(.+)\[(.*)\]$/', $key, $matches)) {
                list(, $key, $subKey) = $matches;
              } else {
                $subKey = null;
              }

              if ($subKey !== null and isset( $arrays[$key] )) {
                $subKey === '' ? $options[$key][] = $value
                               : $options[$key][$subKey] = $value;
              } else {
                isset( $arrays[$key] ) and $value = array($value);
                $options[$key] = $value;
              }
            } else {
              $flags += array_flip( str_split($argValue) );
            }
          }
        } else {
          $index[] = $arg;
        }
      }
    }

    $flags and $flags = array_combine(array_keys($flags), array_fill(0, count($flags), true));
    return compact('flags', 'options', 'index');
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
      $msg = array(
        '<h2>'.preg_replace('/([a-z])([A-Z])/', '\1 \2', get_class($e)).'</h2>',
        'in <strong>'.htmlspecialchars($e->getFile().':'.$e->getLine()).'</strong>',
        '<pre>'.htmlspecialchars($e->getMessage()).'</pre>',
        '<hr>',
        '<pre>'.$e->getTraceAsString().'</pre>',
      );
    } else {
      $msg = array(
        '<h2>Exception occurred</h2>',
        '<pre>'.htmlspecialchars(var_export($e, true)).'</pre>',
      );
    }

    if (defined('STDIN' or !empty($_SERVER['TERM']))) {
      echo join(PHP_EOL, array_map('strip_tags', $msg)), PHP_EOL;
    } else {
      echo join("\n", $msg);
    }

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
    $this->prepare($pages);
    $batch = new DeplokiBatch($this->config, $pages);
    return $batch->process();
  }

  function prepare(array &$pages) {
    foreach ((array) $this->config as $option => $value) {
      if (substr($option, -4) === 'Path' and !dkiIsAbs($value)) {
        $this->config->$option = dkiPath(getcwd()).'/'.dkiPath($value);
      }
    }

    // preprocess media so it's cached for all batches we're going to run.
    is_array($this->config->media) and $this->config->mediaVars();

    foreach ($pages as $key => &$page) {
      if (!isset($page['write']) and !in_array('write', $page)) {
        is_int($key) and $key = '{FILE}.html';;
        $page['write'] = $this->config->absolute($key, $this->config->outPath);
      }
    }
  }
}

class DeplokiBatch {
  public $config;       //= DeplokiConfig
  public $chains;       //= hash of DeplokiChain
  public $cache = false;
  protected $cached = array();  //= hash of DeplokiChain

  function __construct(DeplokiConfig $config, array $chains) {
    $this->config = clone $config;
    $this->chains = $this->parse($chains);
  }

  //= hash of DeplokiChain
  function parse(array $chains) {
    $result = array();

    foreach ($chains as $name => &$chain) {
      if (is_string($name) and strpbrk($name[0], '!?')) {
        if (!!$this->config->debug !== ($name[0] === '?')) {
          continue;
        } else {
          $name = substr($name, 1);
        }
      }

      if (! $chain instanceof DeplokiChain) {
        $chain = new DeplokiChain($this->config, $chain, $name);
      }

      $result[$name] = $chain;
    }

    return $result;
  }

  //= hash of DeplokiChain that were ran
  function process() {
    $result = array();
    $logger = $this->config->loggeræ;

    foreach ($this->chains as $key => $chain) {
      if ($this->cache and isset($this->cached[$key])) {
        $result[$key] = clone $this->cached[$key];
      } else {
        if ($logger and (count($chain) != 1 or $chain[0]->name != 'raw')) {
          call_user_func($logger, $key, $this);
        }

        $chain = clone $chain;
        $result[$key] = $chain->execute();
        $this->cache and $this->cached[$key] = clone $chain;
      }
    }

    return $result;
  }

  function has($chain) {
    return isset($this->chains[$chain]);
  }

  function add($chains) {
    $chains = $this->parse(dkiArray($chains));
    $this->chains = $chains + $this->chains;
    $this->cached = array_diff_key($this->cached, $chains);
    return $this;
  }
}

class DeplokiChain {
  public $config;             //= DeplokiConfig
  public $filters = array();  //= array of DeplokiFilter

  public $name;               //= null, string
  public $data;               //= array of string
  public $urls;               //= array of string

  //= array of string
  static function execGetData(DeplokiConfig $config, $filters, $name = null) {
    return (array) self::make($config, $filters, $name)->execute()->data;
  }

  //= array of string
  static function execWithData(DeplokiConfig $config, $filters, array $data, $name = null) {
    $chain = DeplokiChain::make($config, $filters, $name);
    $chain->data = $data;
    return $chain->execute()->data;
  }

  static function make(DeplokiConfig $config, $filters, $name = null) {
    return new self($config, $filters, $name);
  }

  function __construct(DeplokiConfig $config, $filters, $name = null) {
    $this->config = clone $config;
    ++$this->config->nestingLevel;

    $this->name = $name;
    $this->filters = $this->parse($filters);
    $this->reset();
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

        $options = $filter->expandOptions($options) +
                   ((array) dkiGet($baseConfig->perFilter, $filter->name)) +
                   ((array) dkiGet($baseConfig->perFilter, ''));

        $filter->config->override($filter->normalize($options));
        $options = $filter;
      }
    }

    return $filters;
  }

  function reset() {
    $this->data = array();
    $this->urls = array();
    return $this;
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
    $same = array_filter(array_keys($this->defaults), array('DeplokiConfig', 'has'));
    if ($same) {
      $same = join(', ', $same);
      $this->fail("options [$same] have the same name as global options");
    }

    return $this->expandOptions($config) + $this->defaults +
           array($this->shortOption => null);
  }

  //= hash always
  function expandOptions($option) {
    if (is_array($option) and !isset($option[0])) {
      return $option;
    } elseif (isset($this->shortOption)) {
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

  function log($msg) {
    $this->config->logger and call_user_func($this->config->logger, $msg, $this);
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

    switch ($cond = &$this->config->condition) {
    case 'debug':   $can &= $this->config->debug; break;
    case 'stage':   $can &= !$this->config->debug; break;
    default:
      if (is_callable($cond)) {
        $can &= call_user_func($cond, $this, $chain);
      } elseif (is_bool($cond)) {
        $can &= $cond;
      }
    }

    $can or $this->log("skipped by condition".($cond ? " '$cond'" : ''));
    return (bool) $can;
  }

  function expand($name, array $vars = array()) {
    $vars += Deploki::fileVars($this->chain->name, 'type', 'name');
    return $this->config->expand($name, $vars);
  }
}

// Transforms passed data to another requivalent representation (e.g. more compact).
abstract class DeplokiMinifyingFilter extends DeplokiFilter {
  public $logSuffix = '';   //= string to add to "X -> Y = Z bytes" output.

  protected function doExecute($chain, $config) {
    foreach ($chain->data as &$data) { $data = $this->minify($data, $config); }
  }

  function minify($code, DeplokiConfig $config) {
    $original = strlen($code);
    $code = $this->doMinify($code, $config);

    $this->log($this->numFmt($original).' -> '.$this->numFmt(strlen($code)).
               ' = '.$this->numFmt(strlen($code) - $original).' bytes'.
               $this->logSuffix);

    return $code;
  }

  protected abstract function doMinify($code, $config);

  function numFmt($num) {
    return number_format($num, 0, '.', ' ');
  }
}

// Renderers are filters that transform one markup into another (e.g. wiki to HTML).
abstract class DeplokiRenderingFilter extends DeplokiFilter {
  // 'configurator' is a function ($config, DeplokiFilter); if returns non-null value
  // it's used instead of passed $config.
  public $defaults = array('fromData' => true, 'compact' => null,
                           'configurator' => null, 'vars' => array(),
                           'perLanguages' => true, 'withMedia' => true);

  public $shortOption = 'fromData';

  public $libPath;          //= string defaults to $this->name + '.php'
  public $rendererConfig;   //= null, object filter-dependent - see configure()
  protected $currentLang;   //= null, str e.g. 'en', set by render()

  protected function initialize($config) {
    parent::initialize($config);
    $this->defaults += array('libPath' => $this->libPath);
  }

  //= bool indicating if the external framework (if any) is loaded; see load()
  abstract function isLoaded();

  // Loads external framework (e.g. HTMLki) if this filter depends on it.
  function load() {
    if (!$this->isLoaded()) {
      $file = $this->config->libPath ? $this->config->libPath : $this->name.'.php';
      $file = $this->config->absolute($file, $this->config->dkiPath);

      include_once $file;
      $this->isLoaded() or $this->fail("cannot load the library from [$file]");
    }
  }

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
  function render($data, $src = null) {
    $result = array();
    $media = $this->config->withMedia ? $this->config->mediaVars() : array();

    $languages = $this->config->perLanguages;
    if (!is_array($languages) and !is_string($languages)) {
      $languages = $languages ? $this->config->languages : array(null);
    }

    $languages = (array) $languages;
    $languages or $this->warn('is given an empty language list to render into');

    foreach ($languages as $language) {
      $this->currentLang = $language;

      $vars = $this->config->vars + compact('languages', 'language', 'src') +
              $media + $this->config->vars();

      $rendered = $this->doRender($vars, $data);
      if ($on = $this->config->compact or ($on === null and !$this->config->debug)) {
        $rendered = $this->compact($rendered);
      }

      $result[$language] = $rendered;
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
    $this->log('rendering '.dkiLang(count($datas), 'item'));

    foreach ($datas as $key => &$data) {
      $rendered = $this->render($data, $key);

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
    $html = preg_replace('/\s*(\n)\s*|(\s)\s+/u', '\1\2', $html);
    return trim($html);
  }
}

/*-----------------------------------------------------------------------
| STANDARD FILTERS
|----------------------------------------------------------------------*/

// Puts input as it is into the chain's data overwriting existing items.
class DkiRaw extends DeplokiFilter {
  public $shortOption = 'data';

  protected function doExecute($chain, $config) {
    $chain->data = dkiArray($config->data) + $chain->data;
  }
}

// Adds given URL(s) to the chain's URL list overwriting existing items.
class DkiUrl extends DeplokiFilter {
  public $shortOption = 'urls';

  protected function doExecute($chain, $config) {
    $chain->urls = dkiArray($config->urls) + $chain->urls;
  }
}

// Reads listed file paths into their contents.
class DkiRead extends DeplokiFilter {
  public $shortOption = 'masks';

  protected function doExecute($chain, $config) {
    $files = array();

    foreach ((array) $config->masks as $mask) {
      $path = $config->absolute($this->expand($mask), $config->outPath);
      $files = array_merge($files, glob($path, GLOB_NOESCAPE | GLOB_BRACE));
    }

    $files = array_unique($files);

    // note that all items in $files exist thanks to glob().
    foreach ($files as $path) { $chain->data[$path] = $this->read($path); }
  }

  function read($file) {
    $this->log($file);
    return $this->config->readFile($file);
  }
}

// The same as READ but also formats the data as if {EXT} filter was applied to it.
class DkiReadfmt extends DkiRead {
  function read($file) {
    $data = parent::read($file);
    $ext = ltrim(strrchr($file, '.'), '.');

    if ($ext) {
      $data = join(DeplokiChain::execWithData($this->config, $ext, array($file => $data),
                                              $this->chain->name.".$ext"));
    }

    return $data;
  }
}

// Writes current data to local files and provides their URLs.
class DkiWrite extends DeplokiFilter {
  // 'url' may contain '{VAR}'s or be a function ($file, array $vars, DeplokiFilter).
  // 'dest' can be a function (DeplokiFilter, array $vars).
  public $defaults = array('url' => null, 'default' => '{NAME}.{TYPE}',
                           'root' => '{MEDIAPATH}');
  public $shortOption = 'dest';

  protected function doExecute($chain, $config) {
    foreach ($chain->data as $src => &$data) {
      $vars = Deploki::fileVars($src, 'ext', 'file', 'path') + compact('src');

      $lang = (string) strrchr($src, '_');
      if (in_array($lang = substr($lang, 1), $this->config->languages)) {
        $vars['lang'] = $lang;
      }

      $dest = $config->dest;
      $dest or $dest = $config->default;

      if (is_callable($dest)) {
        $dest = call_user_func($dest, $this, $vars);
      } else {
        strpos($dest, '{HASH}') === false or $vars['hash'] = md5($data);
        $dest = $this->expand($dest, $vars);
      }

      $dest = $config->absolute($dest, $config->expand($config->root));
      $this->write($dest, $data, $vars);
    }
  }

  function write($dest, $data, array $vars) {
    $this->config->writeFile($dest, $data);

    if ($url = $this->urlOf($dest, $vars)) {
      $this->chain->urls[$vars['src']] = $url;
    } else {
      $url = '-';
    }

    $this->log("$vars[src] -> $dest -> @$url");
  }

  function urlOf($file, array $vars) {
    $url = $this->config->url;

    if (is_callable($url)) {
      return call_user_func($url, $file, $vars, $this);
    } elseif ($url) {
      return $this->expand($url, $vars);
    } elseif ($url === null) {
      return $this->config->urlOf($file);
    }
  }
}

// Removes already up-to-date files from the chain.
class DkiOmitold extends DkiWrite {
  public $shortOption = 'dest';

  protected function initialize($config) {
    parent::initialize($config);
    $this->defaults['default'] = '{FILE}.html';
    $this->defaults['root'] = '{OUTPATH}';
  }

  protected function doExecute($chain, $config) {
    $was = count($chain->data);
    parent::doExecute($chain, $config);

    $delta = $was - count($chain->data);
    $this->log('ignored '.dkiLang($delta, 'file'));
  }

  function write($dest, $data, array $vars) {
    $old = (is_file($dest) and filemtime($dest) >= filemtime($vars['src']));
    $old and $this->chain->removeData($vars['src']);
  }
}

// Joins current data or wraps it in given chains, if passed.
class DkiConcat extends DeplokiFilter {
  // 'title' can be null (detect <hX> in data) or an (empty) string (title).
  // If 'cutTitle' is true <hX>...</hX> will be removed from the data. If it's
  // false/null it's be kept. If it's a string it's replaced with that string instead.
  public $defaults = array('glue' => "\n", 'cacheChain' => false, 'title' => null,
                           'cutTitle' => false);
  public $shortOption = 'chains';

  // Private fields for cutHeading() to overcome the absense of PHP 5.3 closures.
  protected $hcHeading;
  protected $hcReplace;

  protected function doExecute($chain, $config) {
    if ($chain->data) {
      $count = dkiLang(count($chain->data), 'input item');

      if ($config->chains) {
        $this->log($count);
        $this->concatWith($config->chains, $chain->data);
      } else {
        $glue = $this->expand($config->glue);
        $chain->data = array('concat' => join($glue, $chain->data));

        trim($glue) === '' and $glue = chunk_split(strtoupper(bin2hex($glue)), 2, ' ');
        $glue === '' or $glue = " with $glue";
        $this->log("joined $count$glue");
      }
    }
  }

  function concatWith($chains, &$current) {
    foreach ($current as $src => &$data) {
      $merged = '';
      $cached = array();
      $title = $this->config->title;

      foreach ((array) $chains as $name => $chain) {
        if ($chain === null) {
          $merged .= $data;
        } elseif (isset($cached[$name])) {
          $merged .= $cached[$name];
        } else {
          $chainConfig = clone $this->config;
          $chainConfig->override(Deploki::fileVars($src, 'catExt', 'catFile', 'catPath'));

          if (!$this->config->cacheChain) {
            if (!isset($title)) {
              $replace = $this->config->cutTitle ? '' : null;
              $title = $this->cutHeading($data, $replace);
              $title = $title ? strip_tags(trim($title[2])) : '';
            }

            $vars = array('body' => $data, 'title' => $title);

            foreach ($vars as $name => &$value) {
              $chainConfig->addMedia($name, array('raw' => $value));
            }
          }

          $result = join(DeplokiChain::execGetData($chainConfig, $chain, $name));
          $this->config->cacheChain and $cached[$name] = $result;
          $merged .= $result;
        }
      }

      $data = $merged;
    }
  }

  //= null if $html has no <hX> tag, array ('<hX>title...</hX>', 'hX', 'title...')
  function cutHeading(&$html, $replace = '') {
    $this->hcHeading = null;
    $this->hcReplace = $replace;

    $regexp = '~<(h[1-6])\b[^>]*>(.*?)</\1>~us';
    $html = preg_replace_callback($regexp, array($this, 'headingCutter'), $html, 1);
    return $this->hcHeading;
  }

  function headingCutter($match) {
    $this->hcHeading = $match;
    return $this->hcReplace === null ? $match[0] : $this->hcReplace;
  }
}

// Calls corresponding minifier to compress CSS, JS or other data.
class DkiMinify extends DeplokiFilter {
  protected function doExecute($chain, $config) {
    if (! $ext = ltrim(strrchr($chain->name, '.'), '.')) {
      $this->fail("expects an extension to be specified for resource".
                  " [{$chain->name}], e.g. as in 'libs.js'");
    }

    foreach ($chain->data as $key => &$data) {
      $data = join(DeplokiChain::execWithData($config, "minify$ext",
                                              array($key => $data), $chain->name));
    }
  }
}

// Minifies CSS code.
class DkiMinifycss extends DeplokiMinifyingFilter {
  // From http://proger.i-forge.net/CSS_enchant-minification_layer_for_PHP/OSq.
  protected function doMinify($code, $config) {
    $code = preg_replace('~\s+~', ' ', $code);

    if (preg_last_error() != 0) {
      $this->fail('can\'t run preg_replace() - make sure that CSS is encoded in UTF-8');
    }

    $hex = '([a-zA-Z0-9])';
    $path = '[a-zA-Z0-9_\-.\#,*>+\s\[\]=\~\|:\(\)]';
    $regexp = '/\*.*?\*/ | '.$path.'{1,50} \s*\{\s*}\s* | \s*([;:{,}])\s+ | ;\s*(})\s* | '.
              "\\b(?<!-)0(\.\d) | (\#) $hex\\5$hex\\6$hex\\7 | \s+([+>,])\s+";

    return trim(preg_replace("~$regexp~x", '\1\2\3\4\5\6\7\8', $code));
  }
}

// Minifies JavaScript code.
class DkiMinifyjs extends DeplokiMinifyingFilter {
  // in libPaths, if value is prefixed with '@' process exit code isn't checked.
  public $defaults = array('tool' => 'auto', 'libPaths' => array(
    // YUI UglifyJS for Node.js: https://github.com/yui/yuglify
    'yuglify'             => 'yuglify --terminal --type js',
    // UglifyJS2 for Node.js: https://github.com/mishoo/UglifyJS2
    // uglifyjs* only support /dev/stdin on *nix.
    'uglifyjs2'           => 'uglifyjs2 {TEMP}',
    // UglifyJS for Node.js: https://github.com/mishoo/UglifyJS
    'uglifyjs'            => 'uglifyjs {TEMP}',
    // https://github.com/rgrove/jsmin-php
    'jsmin-php'           => 'jsmin.php',
  ));

  public $shortOption = 'tool';

  protected function doMinify($code, $config) {
    $tool = $this->loadTool($config->tool);
    $this->logSuffix = " via $tool";
    $paths = $this->config->libPaths;

    if ($tool === 'jsmin-php') {
      return JSMin::minify($code);
    } else {
      $streams = array(array('pipe', 'r'), array('pipe', 'w'));
      defined('STDERR') and $streams[] = STDERR;

      $cmd = ltrim($paths[$tool], '@');
      $temp = strpos($cmd, '{TEMP}') ? $this->makeTemp($code) : null;
      $temp and $cmd = str_replace('{TEMP}', escapeshellarg($temp), $cmd);
      $proc = proc_open($cmd, $streams, $pipes);

      if (!is_resource($proc)) {
        $this->fail("has failed to start [$cmd]");
      } else {
        $temp or fwrite($pipes[0], $code);
        fclose(array_shift($pipes));

        $code = stream_get_contents($pipes[0]);
        fclose(array_shift($pipes));

        $exitCode = proc_close($proc);

        if ($exitCode != 0 and $paths[$tool][0] !== '@') {
          $this->fail("has failed to call [$cmd] - exit code $exitCode");
        }
      }

      $temp and unlink($temp);
      return $code;
    }
  }

  function makeTemp($data) {
    do {
      $file = $this->name.'-'.time().'-'.mt_rand(0, 999).'.tmp';
      $file = $this->config->absolute($file, $this->config->tempPath);
    } while (is_file($file));

    $this->config->writeFile($file, $data);
    return $file;
  }

  function loadTool($tool) {
    $paths = $this->config->libPaths;
    $jsminLib = $this->config->absolute($paths['jsmin-php'], $this->config->dkiPath);

    if ($tool === 'auto') {
      if ($this->hasCmd($paths['yuglify'])) {
        $tool = 'yuglify';
      } else if ($this->hasCmd($paths['uglifyjs2'])) {
        $tool = 'uglifyjs2';
      } else if ($this->hasCmd($paths['uglifyjs'])) {
        $tool = 'uglifyjs';
      } elseif (is_file($jsminLib)) {
        $tool = 'jsmin-php';
      } else {
        $this->fail('can detect no known minifier');
      }
    }

    if ($tool === 'jsmin-php') {
      class_exists('JSMin') or include_once $jsminLib;
      class_exists('JSMin') or $this->fail("cannot load JSMin class from [$file]");
    } elseif (empty($paths[$tool])) {
      $this->fail("was specified an unknown tool [$tool]");
    }

    return $tool;
  }

  function hasCmd($cmd) {
    exec(strtok($cmd, ' ').' --help 2>NUL', $output, $code);
    return $code == 0;
  }
}

// Fixes url(...) paths in CSS - useful when storing joined/minified styles elsewhere.
class DkiCssurl extends DeplokiFilter {
  public $defaults = array('url' => '../');
  public $shortOption = 'url';

  protected function doExecute($chain, $config) {
    foreach ($chain->data as &$data) {
      $data = preg_replace('~(\burl\(["\']?)([^"\']+?)(["\']?\))~u',
                           "$1{$config->url}$2$3", $data);
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
    $this->log($_file);

    $chain = $this->chain;
    $config = $this->config;
    return include $_file;
  }
}

// Filters current data or URLs.
class DkiKeep extends DeplokiFilter {
  public $shortOption = 'mask';

  protected function doExecute($chain, $config) {
    $toKeep = $this->getKeeping($chain, $config);
    $all = array_keys($chain->urls ? $chain->urls : $chain->data);

    $toRemove = array_diff($all, $toKeep);
    array_map(array($chain, 'removeData'), $toRemove);
    $this->log('ignored '.dkiLang(count($toRemove), 'item'));
  }

  function getKeeping($chain, $config) {
    $check = $chain->urls ? $chain->urls : $chain->data;

    if (is_callable( $mask = $config->mask )) {
      $check = array_filter($check, $mask);
    } else {
      $mask = $this->expand($mask);
      $isRegExp = $mask[0] === '~';
      $isWildcard = (!$isRegExp and strpbrk($mask, '?*') !== false);

      if ($isWildcard) {
        $mask = '~^'.preg_quote($mask, '~').'$~i';
        $mask = strtr($mask, array('\\?' => '.', '\\*' => '.*?'));
        $isRegExp = true;
      }

      foreach (array_keys($check) as $key) {
        $value = $isWildcard ? basename($key) : dkiPath($key);
        $keep = $isRegExp ? preg_match($mask, $value) : (stripos($value, $mask) !== false);
        if (!$keep) { unset($check[$key]); }
      }
    }

    return array_keys($check);
  }
}

// As KEEP but removes matched data/URLs.
class DkiOmit extends DkiKeep {
  function getKeeping($chain, $config) {
    $all = array_keys($chain->urls ? $chain->urls : $chain->data);
    return array_diff($all, parent::getKeeping($chain, $config));
  }
}

// Converts list of file paths into one URL - useful to avoid redeploying on debug.
class DkiLink extends DeplokiFilter {
  // 'query' can contain 'mime=image/jpeg' and 'charset=cp1251'.
  public $defaults = array('query' => '');
  public $shortOption = 'linker';

  protected function doExecute($chain, $config) {
    $query = array();

    foreach ($chain->data as $path => $data) {
      is_int($path) and $path = $data;
      $real = realpath($path) and $query[] = count($query).'='.urlencode($real);
    }

    if ($query) {
      $this->log(dkiLang(count($chain->data), 'linked resource'));
      $query[] = 'hash='.$config->hash(join('|', $query));

      if ($linker = $config->linker) {
        $linker = $this->config->absolute($linker, $this->config->inPath);
      } else {
        $linker = $this->config->absolute('link.php', $this->config->dkiPath);
      }

      $url = $this->config->urlOf($linker);
      $chain->urls[$chain->name] = $url.'?'.join('&', $query).'&'.$config->query;
    }
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
          $versions[$key]['md5'] = $hash;
          $hasObsolete = true;
          $this->log(basename($url).' is obsolete - now v'.++$ver);
        }

        $url .= strrchr($url, '?') === false ? '?' : '&';
        $url .= $this->expand($config->query, compact('ver'));
      }
    }

    if ($hasObsolete) {
      $export = "<?php\nreturn ".var_export($versions, true).';';
      $config->writeFile($store, $export);
    } else {
      $this->log('nothing obsolete');
    }
  }
}

// HTMLki renderer - reads HTML in HTMLki markup and produces regular HTML.
class DkiHtmlki extends DeplokiRenderingFilter {
  protected function initialize($config) {
    parent::initialize($config);
    $this->defaults += array('xhtml' => false);
  }

  function isLoaded() {
    return class_exists('HTMLki');
  }

  //* $kiCfg HTMLkiConfig
  protected function doConfigure($kiCfg) {
    $kiCfg or $kiCfg = HTMLki::config();
    ($kiCfg instanceof HTMLkiConfig) or $this->fail('received $kiCfg of wrong type');

    $kiCfg->warning = array($this, 'onWarning');
    // note that $addLineBreaks will be in effect if a template wasn't precompiled.
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
      if (strpbrk($template, '\\/') === false) {
        $template = "_$template";
      } else {
        $template = dirname($template).'/_'.basename($template);
      }

      // $SrcFile is set by $this->template().
      $path = $parent->SrcFile ? dirname($parent->SrcFile) : $this->config->inPath;
      $file = dkiPath($path)."/$template.html";

      $tpl = $this->template($file, $parent->config());
      $tpl->vars($call->vars + $parent->vars());

      return $tpl;
    }

  protected function doRender(array $vars, $data) {
    if ($this->config->fromData) {
      $tpl = $this->templateFromData($data, $this->rendererConfig, dkiGet($vars, 'src'));
    } else {
      $tpl = $this->template($data, $this->rendererConfig);
    }

    return $tpl->vars($vars)->render();
  }

  function template($file, HTMLkiConfig $kiCfg) {
    return $this->templateFromData($this->config->readFile($file), $kiCfg, $file);
  }

  function templateFromData($data, HTMLkiConfig $kiCfg, $file = null) {
    $base = $file ? basename($file, '.html').'-' : '';
    $base .= md5($data).'.php';
    $cache = $this->config->absolute("htmlki/$base", $this->config->tempPath);

    if ($file and !is_file($file)) {
      $this->fail("cannot find template file [$file]");
    } elseif (!is_file($cache) or !$file or filemtime($cache) < filemtime($file)) {
      $compiled = HTMLki::compile($data, $kiCfg);
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
  public $libPath = 'uwiki/uversewiki.php';

  protected function initialize($config) {
    parent::initialize($config);

    // 'fsCharset' needs iconv extension.
    $this->defaults += array(
      'xhtml'             => false,
      'markup'            => 'wiki',
      'fsCharset'         => null,

      // can be false to inline attachments into generated HTML.
      'attachments'       => array(
        // each member can be null to leave alone attachments of that type.
        '*'               => array(
          'concat', '?minify', 'write' => '{MEDIAPATH}/{HASH}.{TYPE}',
        ),
      ),
    );
  }

  function isLoaded() {
    return class_exists('UWikiDocument');
  }

  protected function doConfigure($uwCfg) {
    $uwCfg or $uwCfg = new UWikiSettings;
    ($uwCfg instanceof UWikiSettings) or $this->fail('received $uwCfg of wrong type');

    $uwCfg->enableHTML5 = !$this->config->xhtml;
    $uwCfg->rootURL = rtrim($this->config->urlOf($this->config->outPath), '/').'/';
    $uwCfg->pager = new UWikiFilePager($this->config->inPath);
    $uwCfg->pager->nameConvertor = array($this, 'nameConvertor');

    $uwCfg->LoadFrom(UWikiRootPath.'/config');
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
    $this->config->fromData or $data = $this->config->readFile($data);
    $attachments = $this->config->attachments;

    $doc = new UWikiDocument($data);
    $doc->settings = clone $this->rendererConfig;
    $attachments and $doc->MergeAttachmentsOfNestedDocs();

    if ($src = dkiPath(dkiGet($vars, 'src')) and
        strpos($src, $base = dkiPath($doc->settings->pager->GetBaseDir()).'/') === 0) {
      $src = substr($src, strlen($base));
    }

    $doc->settings->pager->SetCurrent("/$src");

    $markup = $this->config->markup === 'wiki' ? 'wacko' : $this->config->markup;
    $doc->LoadMarkup($markup);
    $doc->Parse();
    $html = $doc->ToHTML();

    $this->config->addMedia('uwiki.', array('raw' => $doc));
    // some attachments might become available after document rendering.
    $attachments and $this->attachments($doc, $attachments);

    return $html;
  }

  function attachments(UWikiDocument $doc, $modes) {
    if ($modes) {
      foreach ($doc->attachments as $type => $list) {
        $var = "attachments.$type";

        for ($i = 1; $this->config->hasMedia($var); ++$i) {
          $var = "attachments_$i.$type";
        }

        $filters = dkiGet($modes, $type, dkiGet($modes, '*'));
        // spaces are to avoid collision if 'raw' is also used by the user.
        $filters = array(' raw  ' => array('data' => $list)) + $filters;
        $this->config->addMedia($var, $filters);
      }
    }
  }
}

// Transforms LESS stylesheet sinto regular CSS. Depends on http://leafo.net/lessphp.
class DkiLess extends DeplokiRenderingFilter {
  public $libPath = 'lessc.inc.php';

  function isLoaded() {
    return class_exists('lessc');
  }

  protected function doConfigure($lessc) {
    return $lessc ? $lessc : (new lessc);
  }

  protected function doRender(array $vars, $data) {
    $this->config->fromData or $data = $this->config->readFile($data);

    $this->rendererConfig->setImportDir($this->config->inPath);
    isset($vars['src']) and $this->rendererConfig->setImportDir(dirname($vars['src']));

    try {
      return $this->rendererConfig->compile($data);
    } catch (Exception $ex) {
      $this->fail("cannot compile [$data]");
    }
  }
}
