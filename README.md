# Deploki

PHP solution for creating and maintaining static web data or entire sites. Multi-language and standalone.

Licensed in public domain. Uses [HTMLki](http://htmlki.com) for enhanced HTML 5 templating, [jsmin-php](https://github.com/rgrove/jsmin-php) for JavaScript minification and [lessphp](http://leafo.net/lessphp) for LESS → CSS compilation. These libraries can be removed if corresponding filter support is not needed.

Visit author's homepage at http://proger.me. 

## Usage

Deploki files can be placed in arbitrary location or near your own site that you are deploying. By convention files to be deployed (site source) reside under `in/` directory that is either within the site root or one level above it (`../in`). 

Deploki will automatically detect your setup and customize paths accordingly; if it didn't you can always do so manually.

When Deploki is ran via its CLI (`./dki`) it will try to build the data; it can be ran from either `in/` or `..` (containing `in/`), otherwise it will fail to load theconfig file.

All configuration is done in `config.php` that receives all variables set todefault values of `DeplokiConfig` fields (for example, `$outPath` = `DeplokiConfig->outPath`). When it returns these variables will be assigned back to this class instance.

Sample configuration can be found in this repository's `in/` folder.

## Workflow

There are two main concepts: **pages** and **media**. Pages are sequences of filters that produce output (build the actual site); media are template variables passed to pages. 

For example, a site might have `index.html` and `contact.html` as _pages_ and `styles.css` and `jquery.js` as _media_ (assets). Both HTML files receive URLs of both assets.

**Filters**, or **filter chains**, are series of filters - utilities that do some small work like minifying CSS code or writing a target file. Just like in *nix filters are ran sequentally as if lined up by pipes, inheriting whatever work was done by their predecessors.

Deploying process consists of running filters of all defined _pages_ one by one, giving them results of executing filters of all defined _media_ variables.

## Standard filters

By convention filter class names begin with `Dki` followed by filter name in lower case (e.g. `DkiRead`). This is partly because Deploki was written before PHP 5.3 was widely used. Also, like in batch filters are referred to in upper case (e.g. `READ`).

Each filter inherits main deploy options (`config.php`) plus overriden values that can be specified within _filter chain_ as `'filter' => array('option' => 'value')`.

* **RAW** - simply returns whatever data was assigned to **data** option; useful for passing page title, metadata, etc. to a template. Example: `'raw' => array('data' => 'Page caption')`
* **URL** - adds listed **urls** to current filter chain URL list; useful if you need to pass additional URLs to a template without processing any files (normally an URL is pushed into the chain by _WRITE_ after writing an output file). Example: `'url' => array('urls' => '//cdnjs.cloudflare.com/ajax/libs/jquery/2.0.0/jquery.min.js')`
* **READ** - pushes files matching given **masks** (a string or an array of _glob_ patterns) into current filter chain. Example: `'read' => array('masks' => array('lib-*.js', 'site-app.js'))`
* **READFMT** - just like _READ_ but also invokes filter with the same name as just that were read; for example, `'readfmt' => '*.html'` will _READ_ all HTML pages and format them - i.e. pass all _media_ variables, etc. - with _HTML_ filter (HTMLki by default). This is just a shorter form of writing `'read' => '*.html', 'html'`.
* **WRITE** - writes data to **dest** and pushes URLs that can be used in templates to refer to those pages (this is useful for assets like stylesheets or scripts). Example: `'write' => array('dest' => '{OUTPATH}/{FILE}.{TYPE}')`
* **OMITOLD** - removes up-to-date items from ecurrent filter chain by comparing file modification times of source and target files. **dest** parameter specifies where to look for destination files (the same syntax as of _WRITE_).
* **CONCAT** - glues all currently read files together or passes if no **chains** parameter given or passes each of them through those chains placing results back into current filter chain. Can be used both for merging minified assets together and for wrapping pages into a certain template. Example: `'concat' => array('chains' => array('readfmt' => '_template.html'))`
* **MINIFY** - calls one of _MINIFYxxx_ filters where _xxx_ is each file's extension (e.g. `js` or `css`). Takes the same parameters as corresponding _MINIFYxxx_.
* **MINIFYCSS** - compresses CSS code; uses no external libraries and generally produces results cmoparable with YUI's minifier.
* **MINIFYJS** - compresses JS code using variety of available tools; will try to use _yuglify_ and other CLI tools, eventually falling back to [jsmin-php](https://github.com/rgrove/jsmin-php) (needs `jsmin.php` available).
* **PRETTYHTML** - corrects indentation and whitespace in HTML code.
* **CSSURL** - corrects `url(...)` references in CSS code according to given **url** option; useful if in production your stylesheets are placed in a location different from development setup. Example: `'cssurl' => array('url' => 'media/styles/')`
* **VIA** - executes external **script** so it can alter current filter chain; can be thought of as a pluggable filter. The script is loaded with `include` with preassigned `$chain` and `$config` variables (holding all config options given to _VIA_ itself). Example: `'via' => array('script' => 'my-filter.php')`.
* **KEEP** and **OMIT** - filter file names in current filter chain by some **mask** (wildcard or regexp). Example: `'omit' => array('mask' => '_*')`
* **LINK** - complements _WRITE_ - instead of writing current data to a file produces an URL that can be linked to and that will output all that data (to be written) concatenated together. This is useful for assets in debug mode - instead of redeploying application on each change you can simply reload the page in your browser as those files (that would otherwise be merged together, minified and written) are already merged on the fly. 
* **VERSION** - appends version tag to current URLs' query string so that client will always have new version on page load even if old one was cached; maintains version info in `{OUTPATH}/versions.php` - every resource change increments the counter by 1.

### Renderers

These filters transform data from one format to another. This is a logical discretion only, they remain regular Deploki filters.

* **HTMLKI** - formats _READ_ files as [HTMLki](http://htmlki.com) templates (library included with Deploki as `htmlki.php`). Since any valid HTML file is also a valid HTMLki template it will work transparently even if you don't use HTMLki.
* **UWIKI** - formats source text from [UverseWiki markup](http://uverse.i-forge.net/wiki/) into HTML. Library isn't included as it's quite large.
* **LESS** - compiles [LESS-CSS](http://lesscss.org) into normal CSS code. [lessphp](http://leafo.net/lessphp) library is included with Deploki as `lessc.inc.php`.
