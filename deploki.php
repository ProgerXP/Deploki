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

function dkiGet($array, $key, $default = null) {
  return (is_array($array) and isset($array[$key])) ? $array[$key] : $default;
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
  public $viaAliases;   //= hash of string 'alias' => 'realVia'
  public $perFilter;    //= hash of hash, hash of DeplokiConfig

  /*---------------------------------------------------------------------
  | FILTER-SPECIFIC
  |--------------------------------------------------------------------*/

  //= string 'debug' or 'stage', callable (Filter, Chain), mixed filter-specific
  public $condition       = null;

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
    $this->debug          = dkiGet($_SERVER, 'REMOTE_ADDR') === '127.0.0.1';
    $this->app            = null;

    $this->dkiPath        = dirname(__FILE__);
    $this->defaultsAt($this->dkiPath.'/in');

    $this->media          = array();

    $this->urls           = array(
      'outPath'           => dirname(dkiGet($_SERVER, 'REQUEST_URI')),
    );

    $this->dirPerms       = 0755;
    $this->filePerms      = 0744;

    $this->viaAliases     = array(
      'htm'               => 'html',
      'html'              => 'htmlki',
      'wiki'              => 'uwiki',
    );

    $this->perFilter      = array();

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
      $batchConfig = clone $this;
      $batchConfig->media = array();   // to avoid recursion.
      $this->media = new DeplokiBatch($batchConfig, $this->media);
      $this->media->cache = true;
    }

    $result = array();

    foreach ($this->media->process() as $name => $chain) {
      $ext = ltrim(strrchr($name, '.'), '.');
      $values = $chain->urls ? $chain->urls : $chain->data;

      if ($ext === '') {
        $result[$name] = join($values);
      } else {
        isset($result[$ext]) or $result[$ext] = array();
        $result[$ext] = array_merge($result[$ext], $values);
      }
    }

    return $result;
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
        Deploki::warn("Missing language string [$str].");
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
    $this->prepare();
    $batch = new DeplokiBatch($this->config, $pages);
    return $batch->process();
  }

  function prepare() {
    foreach ((array) $this->config as $option => $value) {
      if (substr($option, -4) === 'Path' and !dkiIsAbs($value)) {
        $this->config->$option = dkiPath(getcwd()).'/'.dkiPath($value);
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

    foreach ($this->chains as $key => $chain) {
      if ($this->cache and isset($this->cached[$key])) {
        $result[$key] = clone $this->cached[$key];
      } else {
        $chain = clone $chain;
        $result[$key] = $chain->execute();
        $this->cache and $this->cached[$key] = clone $chain;
      }
    }

    return $result;
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

  static function make(DeplokiConfig $config, $filters, $name = null) {
    return new self($config, $filters, $name);
  }

  function __construct(DeplokiConfig $config, $filters, $name = null) {
    $this->config = clone $config;
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

        $perFilter = dkiGet($baseConfig->perFilter, $filter->name);
        $options = $filter->expandOptions($options) + ((array) $perFilter);

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
    if (is_array($option)) {
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
      $vars = $this->config->vars + compact('languages', 'language', 'src') +
              $media + $this->config->vars();

      $rendered = $this->doRender($vars, $data);
      if ($on = $this->config->compact or ($on === null and !$this->config->debug)) {
        $rendered = $this->compact($rendered);
      }

      $this->currentLang = $language;
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
    return $html;
  }
}

/*-----------------------------------------------------------------------
| STANDARD FILTERS
|----------------------------------------------------------------------*/

// Puts input as it is into the chain's data.
class DkiRaw extends DeplokiFilter {
  public $shortOption = 'data';

  protected function doExecute($chain, $config) {
    $chain->data = (array) $config->data + $chain->data;
  }
}

// Reads listed file paths into their contents.
class DkiRead extends DeplokiFilter {
  public $shortOption = 'masks';

  protected function doExecute($chain, $config) {
    $files = array();

    foreach ((array) $config->masks as $mask) {
      $path = $config->absolute($mask, $config->outPath);
      $files = array_merge($files, glob($path, GLOB_NOESCAPE | GLOB_BRACE));
    }

    $files = array_unique($files);

    // note that all items in $files exist thanks to glob().
    foreach ($files as $path) {
      $chain->data[$path] = $this->config->readFile($path);
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
        'src'             => $src,
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
      $this->write($dest, $data, $vars);
    }
  }

  function write($dest, $data, array $vars) {
    $this->config->writeFile($dest, $data);
    $this->chain->urls[$vars['src']] = $this->urlOf($dest, $vars);
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

// Removes already up-to-date files from the chain.
class DkiOmitold extends DkiWrite {
  public $shortOption = 'dest';

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
      if ($config->chains) {
        $this->concatWith($config->chains, $chain->data);
      } else {
        $glue = $this->expand($config->glue);
        $chain->data = array('concat' => join($glue, $chain->data));
      }
    }
  }

  function concatWith($chains, &$current) {
    foreach ($current as &$data) {
      $merged = '';
      $cached = array();
      $title = $this->config->title;

      foreach ((array) $chains as $name => $chain) {
        if ($chain === null) {
          $merged .= $data;
        } elseif (isset($cached[$name])) {
          $merged .= $cached[$name];
        } else {
          $chainConfig = $this->config;

          if (!$this->config->cacheChain) {
            $chainConfig = clone $chainConfig;

            if (!isset($title)) {
              $replace = $this->config->cutTitle ? '' : null;
              $title = $this->cutHeading($data, $replace);
              $title = $title ? strip_tags(trim($title[2])) : '';
            }

            $vars = array('body' => $data, 'title' => $title);

            foreach ($vars as $name => &$value) {
              if (!isset($chainConfig->media[$name])) {
                $chainConfig->media[$name] = array('raw' => $value);
              }
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
      $minifier = DeplokiChain::make($config, "minify$ext", $chain->name);
      $minifier->data[$key] = $data;
      $data = join($minifier->execute()->data);
    }
  }
}

// Minifies CSS code.
class DkiMinifycss extends DeplokiFilter {
  protected function doExecute($chain, $config) {
    foreach ($chain->data as &$data) { $data = $this->minify($data, $config); }
  }

  // From http://proger.i-forge.net/CSS_enchant-minification_layer_for_PHP/OSq.
  function minify($css, DeplokiConfig $config) {
    $css = preg_replace('~\s+~', ' ', $css);

    if (preg_last_error() != 0) {
      $this->fail('can\'t run preg_replace() - make sure that CSS is encoded in UTF-8');
    }

    $hex = '([a-zA-Z0-9])';
    $path = '[a-zA-Z0-9_\-.\#,*>+\s\[\]=\~\|:\(\)]';
    $regexp = '/\*.*?\*/ | '.$path.'{1,50} \s*\{\s*}\s* | \s*([;:{,}])\s+ | ;\s*(})\s* | '.
              "\\b(?<!-)0(\.\d) | (\#) $hex\\5$hex\\6$hex\\7 | \s+([+>,])\s+";

    return trim(preg_replace("~$regexp~x", '\1\2\3\4\5\6\7\8', $css));
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
    $toKeep = $this->getKeeping($chain, $config);
    $all = array_keys($chain->urls ? $chain->urls : $chain->data);
    array_map(array($chain, 'removeData'), array_diff($all, $toKeep));
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
      $query[] = 'hash='.$config->hash(join('|', $query));

      if ($linker = $config->linker) {
        $linker = $this->config->absolute($linker, $this->config->inPath);
      } else {
        $linker = $this->config->absolute('link.php', $this->config->dkiPath);
      }

      $url = $this->config->urlOf($linker);
      $chain->urls['link'] = $url.'?'.join('&', $query).'&'.$config->query;
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

  function isLoaded() {
    return class_exists('HTMLki');
  }

  //* $kiCfg HTMLkiConfig
  protected function doConfigure($kiCfg) {
    $kiCfg or $kiCfg = HTMLki::config();
    ($kiCfg instanceof HTMLkiConfig) or $this->fail('received $kiCfg of wrong type');

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
      $file = dkiPath($path)."/_$template.html";

      $tpl = $this->template($file, $parent->config());
      $tpl->vars($call->vars + $parent->vars());

      return $tpl;
    }

  protected function doRender(array $vars, $data) {
    if ($this->config->fromData) {
      $tpl = $this->templateFromData($data, $this->rendererConfig);
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
    $this->defaults += array('xhtml' => false, 'markup' => 'wiki',
                             'fsCharset' => null);
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

    $doc = new UWikiDocument($data);
    $doc->settings = $this->rendererConfig;
    $markup = $this->config->markup === 'wiki' ? 'wacko' : $this->config->markup;
    $doc->LoadMarkup($markup);
    $doc->Parse();

    return $doc->ToHTML();
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
