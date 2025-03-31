<?php

namespace Hiraeth;

use Closure;
use RuntimeException;

class State
{
	/**
	 * The pplication instance to which this state belongs
	 *
	 * @var Application|null
	 */
	private $app = NULL;


	/**
	 * A collection of stored data in the state
	 *
	 * @var array<string, mixed>
	 */
	private $data = [];


	/**
	 * A list of stored methods / callables in the state
	 *
	 * @var array<string, callable>
	 */
	private $methods = [];


	/**
	 * @param Application $app The application instance to which this state belongs
	 */
	public function __construct(Application $app)
	{
		$this->app = $app;
	}


	/**
	 * @param string $method The name of the method to call
	 * @param mixed $args The arguments passed to the method
	 * @return mixed
	 */
	public function __call($method, $args) {
		$method = strtolower($method);

		if (!isset($this->methods[$method])) {
			throw new RuntimeException(sprintf(
				'No registered callable at "%s"',
				$method
			));
		}

		return $this->methods[$method](...$args);
	}


	/**
	 * @param string $name The name of the property to get
	 * @return mixed
	 */
	public function __get($name)
	{
		return $this->data[$name] ?? NULL;
	}


	/**
	 * @param string $name The name of the property / method to set
	 * @param mixed $value The value to set to it
	 * @return void
	 */
	public function __set($name, $value)
	{
		if (is_callable($value)) {
			if ($value instanceof Closure) {
				$this->methods[strtolower($name)] = Closure::bind($value, $this->app);
			} else {
				$this->methods[strtolower($name)] = $value;
			}

		} else {
			$this->data[$name] = $value;

		}
	}



}
