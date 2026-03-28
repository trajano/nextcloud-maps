<?php

/**
 * Nextcloud - maps
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2019
 */

namespace OCA\Maps\BackgroundJob;

use OCA\Maps\Service\PhotofilesService;
use OCA\Maps\Service\TracksService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class UserInstallScanJob extends QueuedJob {

	private IJobList $jobList;
	private IConfig $config;
	private IUserManager $userManager;
	private PhotofilesService $photofilesService;
	private TracksService $tracksService;

	/**
	 * UserInstallScanJob constructor.
	 *
	 * A QueuedJob to scan user storage for photos and tracks
	 *
	 * @param IJobList $jobList
	 */
	public function __construct(ITimeFactory $timeFactory, IJobList $jobList,
		IUserManager $userManager,
		IConfig $config,
		PhotofilesService $photofilesService,
		TracksService $tracksService) {
		parent::__construct($timeFactory);
		$this->config = $config;
		$this->jobList = $jobList;
		$this->userManager = $userManager;
		$this->photofilesService = $photofilesService;
		$this->tracksService = $tracksService;
	}

	/**
	 * @param array{userId: string} $argument
	 */
	public function run($argument): void {
		$userId = $argument['userId'];
		\OCP\Server::get(LoggerInterface::class)->debug('Launch user install scan job for ' . $userId . ' cronjob executed');
		// scan photos and tracks for given user
		$this->rescanUserPhotos($userId);
		$this->rescanUserTracks($userId);
		$this->config->setUserValue($userId, 'maps', 'installScanDone', 'yes');
	}

	private function rescanUserPhotos(string $userId): void {
		iterator_count($this->photofilesService->rescan($userId));
	}

	private function rescanUserTracks(string $userId): void {
		iterator_count($this->tracksService->rescan($userId));
	}

}
