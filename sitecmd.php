<?php

/**
 * @copyright Copyright (c) 2009-2011 Nick Trew
 * @author    Nick Trew <nick@sitecmd.com>
 * @license   http://www.sitecmd.com/license
 *
 * @package   Sitecmd
 * @link      http://www.sitecmd.com/
 *
 * @version   2.1.0
 */

class sitecmd
{
	public static $version      = '2.1.0';
	public static $flourish_rev = NULL;
	public static $timer        = 0;
	public static $sapi         = PHP_SAPI;

	private static $attr        = array();

	/**
	 * Initialise Sitecmd and Flourish (and anything else).
	 */
	public static function init()
	{
		// Start the timer
		self::$timer = microtime(TRUE);

		// Set the initial root path
		self::set('paths.sitecmd', dirname(__FILE__));

		// Include Flourish library files
		$flourish_path = self::$attr['paths']['sitecmd'].
			DIRECTORY_SEPARATOR.'flourish';

		if (!file_exists($flourish_path.DIRECTORY_SEPARATOR.'classes'))
		{
			echo self::_('The directory <code>'.$flourish_path.'</code> does
				not exist or is empty.<br />
				If you cloned sitecmd with Git, make sure you run
				<code>git submodule init && git submodule update</code>
				within the <code>'.self::$attr['paths']['sitecmd'].
				'</code> directory.');
			exit(2); // No such file or directory
		}

		include $flourish_path.DIRECTORY_SEPARATOR.'classes'.
			DIRECTORY_SEPARATOR.'fLoader.php';

		// Check for a specific loader type
		switch (self::$attr['flourish']['loader'])
		{
			case 'eager':
				fLoader::eager();
			break;

			case 'lazy':
				fLoader::lazy();
			break;

			default:
				fLoader::best();
		}

		// Check for compatible PHP version
		if (!fCore::checkVersion('5.1'))
		{
			self::_('sitecmd requires PHP version 5.1 or higher.');
			exit(1); // Operation not permitted
		}

		self::initEnvironment();
		self::initErrorHandling();

		$file = self::initRequest();

		self::set('sitecmd.timer', round(microtime(TRUE)-self::$timer, 4));

		// Include the requested file
		fBuffer::startCapture();
		include $file->getPath();
		$content = fBuffer::stopCapture();

		// Handle SSL redirects (temporary redirect)
		if (isset(self::$attr['url']['ssl']) &&
			isset(self::$attr['page']['ssl']) &&
			!self::$attr['request']['ssl'])
		{
			header('Location: '.self::$attr['url']['ssl'].'/'.
				self::$attr['url']['request']);
			exit;
		}

		sitecmd::runEvent('file-content', array(&$content));

		// Send content-type header
		if (isset(self::$attr['page']['content-type']))
		{
			$content_type = self::$attr['page']['content-type'];
			$charset = preg_match('/^text\//i', $content_type)
				? '; charset=utf-8' : '';
			header('Content-Type: '.$content_type.$charset);
		}
		else
		{
			fHTML::sendHeader();
		}

		return $content;
	}

	private static function initEnvironment()
	{
		// Find the environment file
		if (defined('SITECMD_ENV_FILE') && file_exists(SITECMD_ENV_FILE))
		{
			include SITECMD_ENV_FILE;
		}
		else
		{
			echo self::_('No environment file found.<br />Please ensure that
				you have specified it with the SITECMD_ENV_FILE constant.');
			exit(2); // No such file or directory
		}

		// Check the environment is an array
		if (isset($environment) && is_array($environment))
		{
			self::set($environment);
		}
		else
		{
			echo self::_('The environment file is empty, not an array or
				the array is not named <code>environment</code>.');
			exit(124); // Wrong medium type
		}

		// Check a web root path has been set
		if (!isset(self::$attr['paths']['root']))
		{
			echo self::_('No root path is set in your environment file.');
			exit(1); // Operation not permitted
		}

		if (self::$sapi != 'cli')
		{
			// Set URL root if not specified
			if (!self::$attr['url']['root'])
			{
				$url = str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']);
				self::set('url.root', fURL::getDomain().$url);
			}

			// Is there an extension at the end of the URL?
			$extension = explode('.', pathinfo(fURL::get(),
				PATHINFO_BASENAME), 2);
			array_shift($extension);
			$extension = implode('.', $extension);

			// Get the current request
			$path = parse_url(self::$attr['url']['root'], PHP_URL_PATH);
			$url  = trim(substr(fURL::get(), strlen($path)), '/');

			if (substr($url, -strlen($extension)) === $extension)
			{
				$url = substr_replace($url, '', -strlen($extension)-1);
			}

			self::set('request.ssl', preg_match('#^https://#i',
				fURL::getDomain()));
			self::set('url.request', $url);
			self::set('url.query', fURL::getQueryString());
			self::set('url.extension', $extension);
		}

		// Include events file
		$events_file = self::$attr['paths']['events'];

		if (isset($events_file))
		{
			if (file_exists($events_file))
			{
				include $events_file;
			}
		}
		else
		{
			if (file_exists(self::$attr['paths']['sitecmd'].
				DIRECTORY_SEPARATOR.'events.php'))
			{
				include self::$attr['paths']['sitecmd'].
					DIRECTORY_SEPARATOR.'events.php';
			}
		}
	}

	private static function initErrorHandling()
	{
		$site_errors     = self::$attr['error']['errors'];
		$site_exceptions = self::$attr['error']['exceptions'];
		$error_mail      = self::$attr['error']['mail'];
		$error_file      = self::$attr['error']['file'];

		// Are we logging to a file?
		if (in_array('file', array($site_errors, $site_exceptions)) &&
			isset($error_file))
		{
			$site_errors = $error_file;
		}

		// Are we logging to mail?
		if (in_array('mail', array($site_errors, $site_exceptions)) &&
			is_array($error_mail) && !empty($error_mail))
		{
			// Check each address for validity
			foreach ($error_mail as $key => $recipient)
			{
				// Invalid email address, so remove it
				if (!preg_match(fEmail::EMAIL_REGEX, $recipient))
				{
					unset($error_mail[$key]);
				}
			}

			// Set the mail recipients for Flourish
			$site_errors     = implode(',', $error_mail);
			$site_exceptions = implode(',', $error_mail);

			// Do we need to use a mail server?
			if (self::$attr['mail']['host'] !== NULL)
			{
				$smtp = new fSMTP(self::$attr['mail']['host'],
					self::$attr['mail']['port'],
					self::$attr['mail']['secure'],
					self::$attr['mail']['timeout']);

				if (self::$attr['mail']['username'] !== NULL)
				{
					$smtp->authenticate(self::$attr['mail']['username'],
						self::$attr['mail']['password']);
				}

				fCore::configureSMTP($smtp, self::$attr['mail']['from']);
			}
		}

		error_reporting(self::$attr['error']['level']);
		fCore::enableDebugging(self::$attr['error']['debug']);

		if (self::$attr['error']['context'] === FALSE)
		{
			fCore::disableContext();
		}

		if ($site_errors !== FALSE)
		{
			fCore::enableErrorHandling($site_errors);
		}

		if ($site_exceptions !== FALSE)
		{
			fCore::enableExceptionHandling($site_exceptions);
		}
	}

	private static function initRequest()
	{
		$file_path = self::$attr['paths']['root'];

		if (self::$sapi == 'cli')
		{
			$filename = self::initCLIRequest();
			$extension = '';

			// Does this file have an extension?
			if (strpos($filename, '.') !== FALSE)
			{
				list($filename, $extension) = explode('.', $filename, 2);

				// Ignore .php if manually specified
				if ($extension == 'php')
				{
					$extension = '';
				}
			}
		}
		else
		{
			$filename  = self::initWebRequest();
			$extension = self::$attr['url']['extension'];
		}

		// Resolve a path to the filename
		$file = $file_path.DIRECTORY_SEPARATOR.$filename.'.'.
			(($extension) ? $extension : 'php');

		try
		{
			// Create a fFile object
			$file = new fFile($file);

			self::runEvent('request-file', array(&$file));

			// Output this file directly if not PHP
			if ($extension)
			{
				// Use readfile() if CLI, to prevent sending HTTP headers
				if (self::$sapi == 'cli')
				{
					readfile($file->getPath());
				}
				else
				{
					$file->output(TRUE, $file->getName());
				}

				exit;
			}
		}
		// File does not exist, is empty, is a directory or is not readable
		catch (fValidationException $e)
		{
			if (self::$sapi == 'cli')
			{
				echo self::_('`'.$file.'` does not exist');
				exit(2); // No such file or directory
			}

			fHTML::sendHeader();
			header('HTTP/1.1 404 Not Found', TRUE, 404);

			self::runEvent('request-404', array(&$file));

			try
			{
				// Does a 404 route exist?
				if (isset(self::$attr['url']['routes']['_404']))
				{
					$file_404 = self::$attr['url']['routes']['_404'];
				}
				else
				{
					throw new fValidationException('No _404 route found');
				}

				// Create an fFile object
				$file = new fFile($file_path.DIRECTORY_SEPARATOR.$file_404.'.php');
			}
			catch (fValidationException $e)
			{
				echo '<code>404 Not Found</code>';
				exit;
			}
		}

		self::set('request.file', $file);
		return $file;
	}

	private static function initCLIRequest()
	{
		$args    = func_get_args();
		$options = array();
		$route   = NULL;

		self::runEvent('pre-cli', array(&$args));

		for ($i = 1; $i < $_SERVER['argc']; $i++)
		{
			$option = $_SERVER['argv'][$i];

			if (!isset($option))
			{
				break;
			}

			// Is this an internal option?
			if (substr($option, 0, 1) === '-' && substr($option, 0, 2) != '--')
			{
				$option = substr($option, 1);

				switch ($option)
				{
					case 'version': // Sitecmd and Flourish versions
						echo self::getVersion();
						break;

					default:
						echo self::_('Unrecognised option');
						exit(38); // Function not implemented
				}

				// We don't want to handle any other options
				exit(0);
			}

			// If this isn't an option, assume it's a route (URL)
			if (substr($option, 0, 2) !== '--')
			{
				$file = $option;
				continue;
			}

			$option = substr($option, 2);

			// Is this an '--option=value' or a boolean flag?
			if (strpos($option, '='))
			{
				$bits = explode('=', $option, 2);

				$options[$bits[0]] = $bits[1];
			}
			else
			{
				$key = preg_replace('/^no-/i', '', $option);

				$options[$key] = (bool) !preg_match('/^no-/i', $option);
			}
		}

		self::set('cli', $options);

		// Assign the default route file if no path is specified
		if (!isset($file))
		{
			$file = self::$attr['url']['routes']['_default'];
		}

		return $file;
	}

	private static function initWebRequest()
	{
		$routes       = self::$attr['url']['routes'];
		$request      = self::$attr['url']['request'];
		$query_string = self::$attr['url']['query'];

		// Add .php to the extensions, to prevent it overriding checks
		self::add('url.extensions', 'php');

		// Set to avoid multiple calls
		$extension  = self::$attr['url']['extension'];
		$extensions = self::$attr['url']['extensions'];

		// Handle custom virtual file extensions
		if (isset($extensions) && is_array($extensions))
		{
			foreach ($extensions as $ext)
			{
				if ($extension === $ext)
				{
					header('HTTP/1.1 301 Moved Permanently', TRUE, 301);
					header('Location: '.self::$attr['url']['root'].'/'.
						$request.($query_string ? '?'.$query_string : ''));
					exit;
				}
			}
		}

		// Do we want to add slashes or not?
		if (!$extension && $request)
		{
			// Add a slash
			if (self::$attr['url']['slashes'] &&
				substr(fURL::get(), -1) !== '/')
			{
				header('HTTP/1.1 301 Moved Permanently', TRUE, 301);
				header('Location: '.self::$attr['url']['root'].'/'.$request.
					'/'.($query_string ? '?'.$query_string : ''));
				exit;
			}
			// Remove a slash
			else if (!self::$attr['url']['slashes'] &&
				substr(fURL::get(), -1) === '/')
			{
				header('HTTP/1.1 301 Moved Permanently', TRUE, 301);
				header('Location: '.self::$attr['url']['root'].'/'.
					rtrim($request, '/').($query_string
					? '?'.$query_string : ''));
				exit;
			}
		}

		// Assign the default route if this is the URL root
		if (!$request)
		{
			return $routes['_default'];
		}

		// Is this a static route?
		if (isset($routes[$request]))
		{
			// Is this referencing a special route (_404, etc)?
			if (strpos($routes[$request], '_') === 0)
			{
				return $routes[$routes[$request]];
			}

			// Is this an external redirect?
			if (strpos($routes[$request], '://') !== FALSE)
			{
				header('HTTP/1.1 301 Moved Permanently', TRUE, 301);
				header('Location: '.$routes[$request]);
				exit;
			}

			return $routes[$request];
		}

		// Look for a match if it's a dynamic route
		foreach ($routes as $route => $file)
		{
			$route = preg_replace('#/:([\w]+)\?+#i',
				'(?:/(?P<$1>[^/]+))?', $route);
			$route = preg_replace('#:([\w]+)#i', '(?P<$1>[^/]+)', $route);

			if (preg_match('#^'.$route.'$#D', $request, $matches))
			{
				// Set GET values
				foreach ($matches as $key => $value)
				{
					// Look for named parameters
					if (is_string($key))
					{
						/**
						 * Routes need to be ordered with required parameters
						 * first, followed by optional parameters.
						 *
						 * Something like 'page/:optional?/:required' will
						 * return FALSE here, as the URL values are being
						 * assigned to the wrong parameters.
						 */
						if (empty($value))
						{
							return FALSE;
						}

						$_GET[$key] = $value;
					}
				}

				// Match found
				return $file;
			}
		}

		// No route match found, but this might be a direct file request
		return $request;
	}

	public static function getVersion()
	{
		$flourish_file = self::$attr['paths']['sitecmd'].DIRECTORY_SEPARATOR.
			'flourish'.DIRECTORY_SEPARATOR.'classes'.
			DIRECTORY_SEPARATOR.'flourish.rev';

		if (file_exists($flourish_file))
		{
			$flourish_rev = file_get_contents($flourish_file);
			$flourish_rev = preg_match('#(r[0-9]+)#', $flourish_rev, $matches);
		}

		$out = 'sitecmd '.self::$version;

		if (isset($flourish_rev) && isset($matches))
		{
			$out .= '<br />Flourish '.$matches[1];
			self::$flourish_rev = $matches[1];
		}

		return self::_($out);
	}

	/**
	 * Return a formatted string that looks good on the CLI and browser.
	 *
	 * It's suggested that you use this method whenever you need to print
	 * something that could show both in the browser and CLI.
	 */
	public static function _($input)
	{
		// Global replacements
		$replacements = array
		(
			'/\s{2,}/' => ' ', // Convert 2 or more spaces
		);

		foreach ($replacements as $pattern => $replacement)
		{
			$input = preg_replace($pattern, $replacement, $input);
		}

		// CLI-only replacements
		if (self::$sapi === 'cli')
		{
			$replacements = array
			(
				'/<br\s*\/?>/i'           => "\n", // Convert <br> or <br />
				'/<\/?p>/i'               => "\n\n", // Convert <p> and </p>
				'/<\/?em>/i'              => '_', // Convert emphasis
				'/(<\/?b>|<\/?strong>)/i' => '*', // Convert strong/bold
				'/<\/?code>/i'            => '`', // Convert code elements
			);

			foreach ($replacements as $pattern => $replacement)
			{
				$input = preg_replace($pattern, $replacement, $input);
			}

			$input = strip_tags($input);
			$input .= "\n";
		}

		return $input;
	}

	private static function mergeRecursive(array $primary, array $secondary)
	{
		foreach ($secondary as $key => &$value)
		{
			if (is_array($value) && isset($primary[$key]) &&
				is_array($primary[$key]))
			{
				$primary[$key] = self::mergeRecursive($primary[$key], $value);
			}
			else
			{
				$primary[$key] = $value;
			}
		}

		return $primary;
	}

	private static function setRecursive($key, $value = NULL)
	{
		if (is_array($key) && count($key) > 0 && $value === NULL)
		{
			return $key;
		}
		else
		{
			$next = NULL;

			if (strpos($key, '.') !== FALSE)
			{
				list($key, $next) = explode('.', $key, 2);
			}

			if (empty($next) && $next !== '0')
			{
				$level[$key] = $value;
			}
			else
			{
				$level[$key] = self::setRecursive($next, $value);
			}

			return $level;
		}
	}

	public static function set($key, $value = NULL)
	{
		self::$attr = self::mergeRecursive(self::$attr,
			self::setRecursive($key, $value));

		return $value;
	}

	public static function add($key, $value = NULL)
	{
		$attr = self::get($key);

		if (is_array($attr))
		{
			$attr[] = $value;
		}
		else
		{
			$attr = array($value);
		}

		self::set($key, $attr);
	}

	public static function get($key, $default = NULL, $parent = NULL)
	{
		$parent = ($parent === NULL) ? self::$attr : $parent;
		$next   = NULL;

		if (strpos($key, '.') !== FALSE)
		{
			list($key, $next) = explode('.', $key, 2);
		}

		if (!$next && $next !== '0')
		{
			if (array_key_exists($key, $parent))
			{
				return $parent[$key];
			}
			else
			{
				return ($default === NULL) ? FALSE : $default;
			}
		}
		else
		{
			if (array_key_exists($key, $parent))
			{
				return self::get($next, $default, $parent[$key]);
			}
			else
			{
				return ($default === NULL) ? FALSE : $default;
			}
		}
	}

	public static function addEvent($event, $callback, $priority = 0)
	{
		self::$attr['sitecmd']['events'][$event][$priority][] = $callback;
		ksort(self::$attr['sitecmd']['events'][$event]);
		sort(self::$attr['sitecmd']['events'][$event][$priority]);
	}

	public static function runEvent($event, array $args = array())
	{
		if (!isset(self::$attr['sitecmd']['events']))
		{
			return FALSE;
		}

		$events = self::$attr['sitecmd']['events'];

		if (!isset($events[$event]))
		{
			return FALSE;
		}

		foreach ($events[$event] as $priority => $value)
		{
			foreach ($value as $key => $callback)
			{
				if (is_callable($callback))
				{
					call_user_func_array($callback, $args);
				}
			}
		}
	}

	/**
	 * Create and return an internal URL.
	 *
	 * See http://www.sitecmd.com/docs/url
	 */
	public static function url($path, $ssl = NULL)
	{
		// Split query string if set
		if (strpos($path, '?') !== FALSE)
		{
			list($path, $query_string) = explode('?', $path, 2);
		}

		// HTTP or HTTPS root?
		if (isset(self::$attr['url']['ssl']) && $ssl === TRUE)
		{
			$root = self::$attr['url']['ssl'];
		}
		else
		{
			$root = ($ssl === FALSE) ? preg_replace('#^https:#', 'http:',
				self::$attr['url']['root']) : self::$attr['url']['root'];
		}

		$out = $root.'/'.(($path) ? $path : '');

		$out .= ($path && self::$attr['url']['slashes']) ? '/' : '';
		$out .= (isset($query_string)) ? '?'.$query_string : '';

		return $out;
	}
}
