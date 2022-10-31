<?php

namespace Hiraeth;

use SplFileInfo;
use RuntimeException;
use Dotink\Jin;

/**
 *
 */
class Configuration
{
	/**
	 * @var SplFileInfo|null
	 */
	protected $cacheDir = NULL;


	/**
	 * @var array<string, Jin\Collection>
	 */
	protected $collections = array();


	/**
	 * @var Jin\Parser|null
	 */
	protected $parser = NULL;


	/**
	 * @var bool
	 */
	protected $stale = FALSE;


	/**
	 *
	 */
	public function __construct(Jin\Parser $parser, SplFileInfo $cache_dir = NULL)
	{
		$this->parser   = $parser;
		$this->cacheDir = $cache_dir;
	}


	/**
	 * @param string $path
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	public function get(string $path, string $key, $default)
	{
		return isset($this->collections[$path])
		 	? $this->collections[$path]->get($key, $default)
			: $default;
	}


	/**
	 * @param string $path
	 * @return Jin\Collection|null
	 */
	public function getCollection($path): ?Jin\Collection
	{
		return isset($this->collections[$path])
			? $this->collections[$path]
			: NULL;
	}


	/**
	 * @return string[]
	 */
	public function getCollectionPaths(): array
	{
		return array_keys($this->collections);
	}


	/**
	 * @param string|SplFileInfo $directory
	 * @param string $source
	 * @return self
	 */
	public function load($directory, string $source = NULL)
	{
		$cache_hash = md5($directory);
		$cache_path = $this->cacheDir
			? $this->cacheDir . '/' . $cache_hash
			: NULL;

		if ($cache_path && is_file($cache_path)) {
			if (!is_readable($cache_path)) {
				//
				// TODO: Throw Exception
				//
			}

			$data = include($cache_path);

			if (is_array($data)) {
				$this->collections = $data;

				return $this;
			}
		}

		if ($source) {
			$this->loadFromDirectory($directory . '/' . 'default');
			$this->loadFromDirectory($directory . '/' . $source);

		} else {
			$this->loadFromDirectory($directory);
		}

		$this->collections = $this->parser->all();

		if ($cache_path) {
			if (is_file($cache_path) && !is_writable($cache_path)) {
				//
				// TODO: Throw Exception
				//

			} elseif (!is_writable(dirname($cache_path))) {
				//
				// TODO: Throw Exception
				//

			} else {
				file_put_contents($cache_path, sprintf(
					'<?php return %s;',
					var_export($this->collections, TRUE)
				));
			}
		}

		return $this;
	}


	/**
	 * @param string|SplFileInfo $directory
	 * @param string|SplFileInfo $base
	 * @return self
	 */
	protected function loadFromDirectory($directory, $base = NULL)
	{
		if (!$base) {
			$base = $directory;
		}

		if (!is_dir($directory)) {
			throw new \RuntimeException(sprintf(
				'Failed to load from configuration directory "%s", does not exist.',
				$directory
			));
		}

		$target_files    = glob($directory . DIRECTORY_SEPARATOR . '*.jin');
		$sub_directories = glob($directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);

		if ($target_files) {
			foreach ($target_files as $target_file) {
				$this->parser->parse(
					file_get_contents($target_file) ?: '',
					trim(sprintf(
						'%s' . DIRECTORY_SEPARATOR . '%s',
						str_replace($base, '', $directory),
						pathinfo($target_file, PATHINFO_FILENAME)
					), '/\\')
				);

				$this->stale = TRUE;
			}
		}

		if ($sub_directories) {
			foreach ($sub_directories as $sub_directory) {
				$this->loadFromDirectory($sub_directory, $base);
			}
		}

		return $this;
	}
}
