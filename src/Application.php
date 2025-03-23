<?php

namespace Hiraeth;

use Closure;
use SplFileInfo;
use RuntimeException;

use Dotink\Jin;

use Defuse\Crypto\Key;

use Psr\Log\LoggerInterface;
use Psr\Log\AbstractLogger;
use Psr\Container\ContainerInterface;


use SlashTrace\SlashTrace;
use Stringable;

/**
 * Hiraeth application
 *
 * The application handles essentially all low level functionality that is central to any Hiraeth
 * application.  This includes:
 *
 * - Application relative file/folder requisition
 * - Application logging (proxy for registered PSR-3 logger)
 * - Environment / Configuration Setup
 * - Bootstrapping and hand-off
 *
 * This class probably does too much, or not enough.
 */
class Application extends AbstractLogger implements ContainerInterface
{
	/**
	 * A constant regex for absolute path matching
	 *
	 * @var string
	 */
	const REGEX_ABS_PATH = '#^(/|[a-z]+://).*$#';

	const DEF_CLASS = 'class';
	const DEF_TRAIT = 'trait';
	const DEF_IFACE = 'interface';


	/**
	 * A list of interface and/or class aliases
	 *
	 * @access protected
	 * @var array<string, string>
	 */
	protected $aliases = [];


	/**
	 * An instance of our dependency injector/broker
	 *
	 * @access protected
	 * @var Broker|null
	 */
	protected $broker = NULL;


	/**
	 * An instance of our configuration
	 *
	 * @access protected
	 * @var Configuration|null
	 */
	protected $config = NULL;


	/**
	 * A flattened environment collection
	 *
	 * @access protected
	 * @var array<string, string>
	 */
	protected $environment = [];


	/**
	 * Unique application ID
	 *
	 * @access protected
	 * @var string|null
	 */
	protected $id = NULL;


	/**
	 *
	 * @access protected
	 * @var Key|null
	 */
	protected $key = NULL;


	/**
	 * The instance of our PSR-3 Logger
	 *
	 * @access protected
	 * @var LoggerInterface|null
	 */
	protected $logger = NULL;


	/**
	 * The instance of our JIN Parser
	 *
	 * @access protected
	 * @var Jin\Parser|null
	 */
	protected $parser = NULL;


	/**
	 * The dot collection containing release data
	 *
	 * @access protected
	 * @var Jin\Collection|null
	 */
	protected $release = NULL;


	/**
	 * Absolute path to the application root
	 *
	 * @access protected
	 * @var string
	 */
	protected $root = '/';


	/**
	 * Shared boot / application state
	 *
	 * @access protected
	 * @var State|null
	 */
	protected $state = NULL;


	/**
	 * The instance of tracer
	 *
	 * @access protected
	 * @var SlashTrace|null
	 */
	protected $tracer = NULL;


	/**
	 * Normalize args, inverting how broker normally handles them
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	static protected function args(array $args): array
	{
		return array_combine(
			array_map(
				function($key) {
					if (!is_numeric($key)) {
						if ($key[0] == '-') {
							$key = substr($key, 1);
						} else {
							$key = sprintf(':%s', $key);
						}
					}

					return $key;
				},
				array_keys($args)
			),
			array_values($args)
		);
	}

	/**
	 * Construct the application
	 *
	 * @access public
	 * @param string $root_path The absolute path to the application root
	 * @param string $env_file The relative path to the .env file
	 * @param string $release_file The relative path to the .release file
	 * @return void
	 */
	public function __construct(string $root_path, string $env_file = '.env', string $release_file = 'local/.release')
	{
		$this->root   = $root_path;
		$this->broker = new Broker();
		$this->tracer = new SlashTrace();
		$this->state  = new State($this);
		$this->parser = new Jin\Parser([
			'app' => $this
		], [
			'env'  => $this->getEnvironment(...),

			//
			// Handle directories
			//

			'dir'  => function($path) {
				if (is_array($path)) {
					return array_map(
						fn($directory) => $this->getDirectory($directory, TRUE)->getPathname(),
						$path
					);
				}

				return $this->getDirectory($path, TRUE)->getPathname();
			},

			//
			// Handle files
			//

			'file' => function($path) {
				if (is_array($path)) {
					return array_map(
						fn($file) => $this->getFile($file)->getPathname(),
						$path
					);
				}

				return $this->getFile($path)->getPathname();
			}
		]);

		$this->broker->share($this);
		$this->broker->share($this->broker);
		$this->broker->share($this->parser);

		$this->tracer->prependHandler(new ProductionHandler($this));
		$this->tracer->prependHandler(new DebuggingHandler($this));
		$this->tracer->register();

		if (!$this->hasDirectory($this->root)) {
			throw new RuntimeException(sprintf(
				'Invalid root path "%s" specified, not a directory.',
				$this->root
			));
		}

		if ($this->hasFile($release_file)) {
			$this->release = $this->parser->parse(
				file_get_contents($this->getFile($release_file)) ?: ''
			);

		} else {
			$this->release = $this->parser->parse('NAME = Unknown Release');

		}

		if ($this->hasFile($env_file)) {
			$this->environment = ($_ENV += $this->parser
				->parse(file_get_contents($this->getFile($env_file)) ?: '')
				->flatten('_'));

			foreach ($this->environment as $name => $value) {
				@putenv(sprintf('%s=%s', $name, $value));
			}
		}

		umask($this->getEnvironment('UMASK', 0002));
		date_default_timezone_set($this->getEnvironment('TIMEZONE', 'UTC'));
	}


	/**
	 * Get the application state
	 */
	public function __invoke(): State {
		return $this->state;
	}


	/**
	 * @return void
	 */
	public function exec(?Closure $post_boot = NULL)
	{
//		ini_set('display_errors', 0);
//		ini_set('display_startup_errors', 0);

		$bootables    = [];
		$this->config = new Configuration(
			$this->parser,
			$this->getEnvironment('CACHING', TRUE)
				? $this->getDirectory('storage/cache', TRUE)
				: NULL
		);

		$this->config->load(
			$this->getEnvironment('CONFIG_DIR', $this->getDirectory('config')),
			$this->getEnvironment('CONFIG_SRC', NULL)
		);

		foreach ($this->getConfig('*', 'application.aliases', []) as $aliases) {
			foreach ($aliases as $interface => $target) {
				if (!interface_exists($interface) && !class_exists($interface)) {
					class_alias($target, $interface);
				}

				$this->broker->alias($interface, $target);
			}
		}

		foreach ($this->getConfig('*', 'application.delegates', []) as $delegates) {
			foreach ($delegates as $delegate) {
				if (!isset(class_implements($delegate)[Delegate::class])) {
					throw new RuntimeException(sprintf(
						'Cannot register delegate "%s", does not implemented Hiraeth\Delegate',
						$delegate
					));
				}

				$this->broker->delegate($delegate::getClass(), $delegate);
			}
		}

		foreach ($this->getConfig('*', 'application.providers', []) as $providers) {
			foreach ($providers as $provider) {
				if (!isset(class_implements($provider)[Provider::class])) {
					throw new RuntimeException(sprintf(
						'Cannot register provider "%s", does not implemented Hiraeth\Provider',
						$provider
					));
				}

				foreach ($provider::getInterfaces() as $interface) {
					if ($interface == self::class) {
						$bootables[] = $provider;
						continue;
					}

					$this->broker->prepare($interface, fn($obj, Broker $broker) => $broker->execute($provider, [$obj]));
				}
			}
		}

		while($provider = array_shift($bootables)) {
			$this->broker->execute($provider, [$this->state, $this]);
		}

		if ($this->has(LoggerInterface::class)) {
			$this->logger = $this->get(LoggerInterface::class);
		}

		$this->tracer->setApplicationPath($this->getDirectory()->getRealPath());
		$this->tracer->setRelease($this->release->toJson());

		$this->record('Booting Completed', (array) $this());

		if ($post_boot) {
			$code = $this->broker->execute(Closure::bind($post_boot, $this, $this));

			if (ob_get_level()) {
				ob_end_flush();
			}

			flush();
			exit($code);
		}
	}


	/**
	 * @param string $alias The alias of the dependency to make (class name or interface name usually)
	 * @param array<string, mixed> $args Optional named constructor arguments
	 */
	public function get($alias, $args = [])
	{
		return $this->broker->make($alias, static::args($args));
	}


	/**
	 * Get configuration data from all configs with a key
	 *
	 * @access public
	 * @param string $key The value to retrieve from the collection (dot separated)
	 * @param mixed $default The default value, should the data not exist
	 * @return array<string, mixed> The values as retrieved, keyed by collection(s), or defaults
	 */
	public function getAllConfigs(string $key, $default = NULL): array
	{
		$value = [];

		foreach ($this->config->getCollectionPaths() as $path) {
			if (!$this->config->getCollection($path)->has($key)) {
				continue;
			}

			$value[$path] = $this->getConfig($path, $key, $default);
		}

		return $value;
	}


	/**
	 * Get configuration data from a configuration collection
	 *
	 * @access public
	 * @param string $path The collection path from which to fetch data
	 * @param string $key The value to retrieve from the collection (dot separated)
	 * @param mixed $default The default value, should the data not exist
	 * @return mixed The value/array of values as retrieved from collection(s), or default
	 */
	public function getConfig(string $path, string $key, $default = NULL)
	{
		if ($path == '*') {
			$value = $this->getAllConfigs($key, $default);

		} elseif ($path == '~') {
			$value = $this->getAllConfigs($key, []);

			if (count($value)) {
				$value = array_replace_recursive(...array_reverse(array_values($value))) + $default;
			}

		} elseif ($path == '+') {
			$value = array_sum($this->getAllConfigs($key, $default));

		} else {
			$value = $this->config->get($path, $key, $default);

			if (!is_null($default) && !is_object($default)) {
				settype($value, gettype($default));
			}

			if (is_array($default)) {
				$value += $default;
			}

		}

		return $value;
	}


	/**
	 * Get a directory for an app relative path
	 *
	 * @access public
	 * @param string $path The relative path for the directory, e.g. 'writable/public'
	 * @param bool $create Whether or not the directory should be created if it does not exist
	 * @return SplFileInfo An SplFileInfo object referencing the directory
	 */
	public function getDirectory(?string $path = NULL, bool $create = FALSE): SplFileInfo
	{
		if (!$path || !preg_match(static::REGEX_ABS_PATH, $path)) {
			$path = $this->root . DIRECTORY_SEPARATOR . $path;
		}

		$info   = new SplFileInfo($path);
		$exists = @file_exists($info->getPathname());

		if (!$exists && $create) {
			$result = @mkdir($info->getPathname(), 0777, TRUE);

			if (!$result) {
				throw new RuntimeException(sprintf(
					'Failed creating "%s", not writable or not supported',
					$info->getPathname()
				));
			}
		}

		return $info;
	}


	/**
	 * Get a value or all values from the environment
	 *
	 * If no arguments are supplied, this method will return all environment data as an
	 * array.
	 *
	 * @access public
	 * @param string $name The name of the environment variable
	 * @param mixed $default The default data, should the data not exist in the environment
	 * @return mixed The value as retrieved from the environment, or default
	 */
	public function getEnvironment(?string $name = NULL, mixed $default = NULL): mixed
	{
		if (array_key_exists($name, $this->environment)) {
			$value = $this->environment[$name];
		} else {
			$value = $default;
		}

		if (!is_null($default) && !is_object($default)) {
			settype($value, gettype($default));
		}

		if (is_array($default)) {
			$value += $default;
		}

		return $value;
	}


	/**
	 * Get a file for an app relative path
	 *
	 * @access public
	 * @param string $path The relative path for the file, e.g. 'writable/public/logo.jpg'
	 * @param bool $create Whether or not the file should be created if it does not exist
	 * @return SplFileInfo An SplFileInfo object referencing the directory
	 */
	public function getFile(string $path, bool $create = FALSE): SplFileInfo
	{
		if (!$path || !preg_match(static::REGEX_ABS_PATH, $path)) {
			$path = $this->root . DIRECTORY_SEPARATOR . $path;
		}

		$info   = new SplFileInfo($path);
		$exists = @file_exists($info->getPathname());

		if (!$exists && $create) {
			$this->getDirectory($info->getPath(), TRUE);
			$info->openfile('w')->fwrite('');
		}


		return $info;
	}


	/**
	 *
	 */
	public function getId(): string
	{
		if (!isset($this->id)) {
			$this->id = md5(uniqid(static::class));
		}

		return $this->id;
	}


	/**
	 *
	 */
	public function getKey(): Key
	{
		if (!$this->key) {
			if (!$this->hasFile('storage/key')) {
				$this->key = Key::createNewRandomKey();

				$this->getFile('storage/key', TRUE)->openFile('w')->fwrite(sprintf(
					'<?php return %s;',
					var_export($this->key->saveToAsciiSafeString(), TRUE)
				));

			} else {
				$this->key = Key::loadFromAsciiSafeString(
					include($this->getFile('storage/key')->getRealPath())
				);
			}
		}

		return $this->key;
	}


	/**
	 * Get release information from the .release file
	 *
	 * @param string $name The name of the specific data to get (optional)
	 * @param mixed $default The default value if that data cannot be found
	 * @return mixed The release data stored in the .release file
	 */
	public function getRelease(?string $name = NULL, $default = NULL)
	{
		return $this->release->get($name, $default);
	}


	/**
	 * {@inheritDoc}
	 */
	public function has(string $alias): bool
	{
		return class_exists($alias) || $this->broker->has($alias);
	}


	/**
	 * @param string|SplFileInfo $path An absolute or applicaiton relative path or file info object
	 * @return bool Whether or not the provided path is readable and is a directory
	 */
	public function hasDirectory($path): bool
	{
		if (!$path || !preg_match(static::REGEX_ABS_PATH, $path)) {
			$path = $this->root . DIRECTORY_SEPARATOR . $path;
		}

		return is_readable($path) && is_dir($path);
	}


	/**
	 * @param string|SplFileInfo $path An absolute or applicaiton relative path or file info object
	 * @return bool Whether or not the provided path is readabiel and is a file
	 */
	public function hasFile($path): bool
	{
		if (!$path || !preg_match(static::REGEX_ABS_PATH, $path)) {
			$path = $this->root . DIRECTORY_SEPARATOR . $path;
		}

		return is_readable($path) && is_file($path);
	}


	/**
	 * @return bool Whether or not the application request seems to be via CLI
	 */
	public function isCLI(): bool
	{
		return (
			defined('STDIN')
			|| php_sapi_name() === 'cli'
			|| !array_key_exists('REQUEST_METHOD', $_SERVER)
		);
	}


	/**
	 *
	 */
	public function isDebugging(): bool
	{
		if (!$this->environment) {
			return FALSE;
		}

		return $this->getEnvironment('DEBUG', FALSE);
	}


	/**
	 * Logs a message with an arbitrary level.
	 *
	 * @param mixed $level
	 * @param string $message
	 * @param mixed[] $context
	 * @return void
	 */
	public function log($level, string|Stringable $message, array $context = []): void
	{
		if (isset($this->logger)) {
			$this->logger->log($level, $message, $context);
		}
	}


	/**
	 * @param string $message
	 * @param mixed[] $context
	 */
	public function record(string $message, array $context = []): Application
	{
		$this->tracer->recordBreadcrumb($message, $context);

		return $this;
	}


	/**
	 * @param string|callable $target A callable target to execute
	 * @param array<string, mixed> $parameters A list of name parameters for the callable
	 * @return mixed The return result of executing the target
	 */
	public function run($target, array $parameters = [])
	{

		return $this->broker->execute($target, static::args($parameters));
	}


	/**
	 *
	 */
	public function setHandler(string $handler): Application
	{
		$this->tracer->prependHandler($this->get($handler));

		return $this;
	}


	/**
	 *
	 */
	public function share(object $instance): object
	{
		$this->broker->share($instance);

		return $instance;
	}

	/**
	 * @param class-string $class
	 */
	public function void(string $class, string $type): void
	{
		$parts = explode('\\', $class);
		$name  = array_pop($parts);

		eval(sprintf(
			'%s %s %s{}',
			count($parts)
				? sprintf('namespace %s;', implode('\\', $parts))
				: null,
			$type,
			$name
		));
	}
}
