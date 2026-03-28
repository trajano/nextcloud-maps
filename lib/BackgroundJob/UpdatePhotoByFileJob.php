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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;
use OCP\ICache;

use OCP\ICacheFactory;

final class UpdatePhotoByFileJob extends QueuedJob {

	/** @var PhotofilesService */
	private PhotofilesService $photofilesService;

	/** @var IRootFolder */
	private IRootFolder $root;

	/** @var ICacheFactory */
	private ICacheFactory $cacheFactory;

	/** @var ICache */
	private ICache $backgroundJobCache;

	/**
	 * UserInstallScanJob constructor.
	 *
	 * A QueuedJob to scan user storage for photos and tracks
	 *
	 */
	public function __construct(
		ITimeFactory $timeFactory,
		IRootFolder $root,
		PhotofilesService $photofilesService,
		ICacheFactory $cacheFactory,
	) {
		parent::__construct($timeFactory);
		$this->photofilesService = $photofilesService;
		$this->root = $root;
		$this->cacheFactory = $cacheFactory;
		$this->backgroundJobCache = $this->cacheFactory->createDistributed('maps:background-jobs');
	}

	/**
	 * @param array{userId: string, fileId: int} $argument
	 */
	public function run($argument): void {
		$userId = $argument['userId'];
		$fileId = $argument['fileId'];
		$userFolder = $this->root->getUserFolder($userId);
		$files = $userFolder->getById($fileId);
		if (empty($files)) {
			return;
		}
		$file = array_shift($files);
		if ($file === false) {
			return;
		}
		$this->photofilesService->updateByFileNow($file);

		$counter = (int)($this->backgroundJobCache->get('recentlyUpdated:' . $userId) ?? 0);
		$this->backgroundJobCache->set('recentlyUpdated:' . $userId, $counter + 1, 60 * 60 * 3);
	}
}
