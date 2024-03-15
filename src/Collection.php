<?php

namespace Hiraeth;

use Iterator;

/**
 * An extremely simple collection which is meant to be extended
 *
 * @copyright Copyright (c) 2015, Matthew J. Sahagian
 * @author Matthew J. Sahagian [mjs] <msahagian@dotink.org>
 *
 * @license Please reference the LICENSE.md file at the root of this distribution
 *
 * @package Flourish
 */
class Collection implements Iterator
{
	/**
	 * The data in the collection
	 *
	 * @access protected
	 * @var array
	 */
	protected $data;


	/**
	 * A simple dereference cache
	 *
	 * @access protected
	 * @var array
	 */
	protected $cache = array();


	/**
	 * Create a new collection
	 *
	 * @access public
	 * @param array $data The initial data for the collection
	 * @return void
	 */
	public function __construct($data = array())
	{
		$this->data = $data;
	}


	/**
	 * Rewind the collection to the first element
	 *
	 * @access public
	 * @return mixed The first element in the collection
	 */
	public function rewind()
	{
		return reset($this->data);
	}


	/**
	 * Get the current element
	 *
	 * @access public
	 * @return mixed The current element in the collection
	 */
	public function current()
	{
		return current($this->data);
	}


	/**
	 * Get values from the collection
	 *
	 * - If no name is passed, all data is returned
	 * - If an array is passed, only the data whose keys match values in the array is returned
	 * - If a string name is passed, the value for the key is returned, otherwise `$default`
	 *
	 * @example collection/get/with_null.php
	 * @example collection/get/with_string.php
	 * @example collection/get/with_array.php
	 * @param string $name The name of the element
	 * @param mixed $default A default value if the item is not found
	 * @return mixed The value set for the item or the default if not found
	 */
	public function get($name = NULL, $default = NULL)
	{
		if ($name === NULL) {
			return $this->data;
		}

		if (is_array($name)) {
			$data = array();

			foreach ($name as $key) {
				$data[$key] = $this->get($key, $default);
			}

			return $data;
		}

		if ($this->has($name)) {
			return $this->cache[$name];
		}

		return $default;
	}


	/**
	 * Check to see if a value is set, explicitly
	 *
	 * @access public
	 * @param string $name The name of the element
	 * @return boolean TRUE if a value for the name is set, FALSE otherwise
	 */
	public function has($name) {

		if (array_key_exists($name, $this->cache)) {
			return TRUE;
		}

		try {
			$parts = explode('.', $name);
			$head  = &$this->data;

			foreach ($parts as $part) {
				if (is_array($head) && isset($head[$part])) {
					$head = &$head[$part];

				} elseif (is_object($head) && isset($head->$part)) {
					$head = &$head->$part;

				} else {
					throw new NotFoundException();
				}
			}

		} catch (NotFoundException $e) {
			return FALSE;
		}

		$this->cache[$name] = &$head;

		return TRUE;
	}


	/**
	 * Get the current element's name
	 *
	 * @access public
	 * @return string The name of the current element
	 */
	public function key()
	{
		return key($this->data);
	}


	/**
	 * Get and move to the next element
	 *
	 * @access public
	 * @return mixed The next elelment's value
	 */
	public function next()
	{
		return next($this->data);
	}


	/**
	 * Set the value of elements in the collection
	 *
	 * - If only an array is passed, the data is merged into the collection recursively
	 * - If a name and value are passed but the value is NULL, the element is unset
	 * - If a name and non-NULL value are passed, the element by that name is given the value
	 *
	 * @access public
	 * @param string $name The name of the element in the collection
	 * @param mixed $value The value for the element in the collection
	 * @return void
	 */
	public function set($name, $value = NULL)
	{
		if (is_array($values = func_get_arg(0))) {
			foreach ($values as $name => $value) {
				$this->set($name, $value);
			}

		} else {
			$parts = explode('.', $name);

			if ($value === NULL) {
				$end  = array_pop($parts);
				$head = &$this->data;

				foreach ($parts as $part) {
					if (isset($head[$part])) {
						$head = &$head[$part];
					} else {
						return;
					}
				}

				unset($head[$end]);

				$parts[] = $end;

			} else {
				foreach (array_reverse($parts) as $part) {
					$value = [$part => $value];
				}

				$this->data = array_replace_recursive($this->data, $value);
			}

			//
			// Clear the cache
			//

			for ($key = array_shift($parts); count($parts); $key .= '.' . array_shift($parts)) {
				unset($this->cache[$key]);
			}

			unset($this->cache[$name]);
		}
	}


	/**
	 * Check if the current element is valid
	 *
	 * This will look for a NULL key, which signifies the end of the element collection
	 *
	 * @access public
	 * @return boolean TRUE if the current element is valid, FALSE otherwise
	 */
	public function valid()
	{
		return key($this->data) !== NULL;
	}
}
