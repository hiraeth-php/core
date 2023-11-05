<?php

namespace Hiraeth;

use SlashTrace\Context\User;
use SlashTrace\EventHandler\EventHandler;

/**
 * The production error/exception handler for slashtrace
 *
 * This handler is a last line of defense for outputting a meaningful error to a client
 * or the console.  Hiraeth will use this handler when it is not in debugging mode and will
 * prevent debug information from being exposed.  Additionally, it will provide base level
 * logging for exceptions and breadcrumbs.
 */
class ProductionHandler implements EventHandler
{
	/**
	 * The application instance
	 *
	 * @var Application|null
	 */
	protected $app = NULL;


	/**
	 * The path of the application, included in error logging
	 *
	 * @var string|null
	 */
	protected $path = NULL;


	/**
	 * The release information of the application, included in error logging
	 *
	 * @var string|null
	 */
	protected $release = NULL;


	/**
	 * The information of the user of the application, included in error logging
	 *
	 * @var mixed[] Application user information
	 */
	protected $user = array();


	/**
	 * Instantiate a Production Handler
	 *
	 * @access public
	 * @param Application $app The application instance for proxying log calls
	 * @return void
	 */
	public function __construct(Application $app)
	{
		$this->app = $app;
	}


	/**
	 * {@inheritDoc}
	 */
	public function handleException($exception): int
	{
		$this->app->error($exception->getMessage(), [
			'file'    => $exception->getFile(),
			'line'    => $exception->getLine(),
			'trace'   => $exception->getTrace(),
			'release' => $this->release,
			'path'    => $this->path,
			'user'    => $this->user
		]);

		if (!$this->app->isDebugging()) {
			if ($this->app->isCLI()) {
				exit($exception->getCode() ?: 1);

			} else {
				header('HTTP/1.1 500 Internal Server Error');
				echo 'Request cannot be completed at this time, please try again later.';
				exit(500);
			}
		}

		return EventHandler::SIGNAL_EXIT;
	}


	/**
	 * {@inheritDoc}
	 */
	public function recordBreadcrumb(string $message, array $context = []): self
	{
		$this->app->debug($message, $context);

		return $this;
	}


	/**
	 * {@inheritDoc}
	 */
	public function setApplicationPath(string $path): self
	{
		$this->path = $path;

		return $this;
	}


	/**
	 * {@inheritDoc}
	 */
	public function setRelease(string $release): self
	{
		$this->release = $release;

		return $this;
	}


	/**
	 * {@inheritDoc}
	 */
	public function setUser(User $user): self
	{
		$this->user = [
			'id'    => $user->getId(),
			'name'  => $user->getName(),
			'email' => $user->getEmail()
		];

		return $this;
	}
}
