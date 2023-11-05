<?php

namespace Hiraeth;

use Exception;
use SlashTrace\EventHandler\DebugHandler;
use SlashTrace\EventHandler\EventHandler;

/**
 * The debugging error/exception handler for slashtrace
 *
 * This class should be, by and large, simply an extension of the built in slashtrace debugger.
 * It provides a placeholder for overloading some functionality if need be, but should mostly
 * just extend it.
 */
class DebuggingHandler extends DebugHandler
{
	/**
	 * The application instance
	 *
	 * @var Application|null
	 */
	protected $app = NULL;


	/**
	 * Construct a new debugging handler
	 */
	public function __construct(Application $app)
	{
		$this->app = $app;
	}


	/**
	 * {@inheritDoc}
	 */
	public function handleException(Exception $exception): int
	{
		if ($this->app->isDebugging()) {
			return parent::handleException($exception);
		}

		return EventHandler::SIGNAL_CONTINUE;
	}
}
