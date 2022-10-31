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
	protected $_app = NULL;


	/**
	 * A list of stored methods / callables in the state
	 *
	 * @var array<string, callable>
	 */
	protected $_methods = array();


	/**
	 * @param Application $app The application instance to which this state belongs
	 */
	public function __construct(Application $app)
	{
		$this->_app = $app;
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
				$this->_methods[strtolower($name)] = Closure::bind($value, $this->_app);
			} else {
				$this->_methods[strtolower($name)] = $value;
			}

		} else {
			$this->$value = $value;

		}
	}


	/**
	 * @param string $method The name of the method to call
	 * @param mixed $args The arguments passed to the method
	 * @return mixed
	 */
	public function __call($method, $args) {
		$method = strtolower($method);

		if (!isset($this->_methods[$method])) {
			throw new RuntimeException(sprintf(
				'No registered callable at "%s"',
				$method
			));
		}

		return $this->_methods[$method](...$args);
	}
}
