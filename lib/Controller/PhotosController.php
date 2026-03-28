<?php

/**
 * Nextcloud - maps
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Piotr Bator <prbator@gmail.com>
 * @copyright Piotr Bator 2017
 */

namespace OCA\Maps\Controller;

use OC\User\NoUserException;
use OCA\Maps\Service\GeophotoService;
use OCA\Maps\Service\PhotofilesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\Exception;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

final class PhotosController extends Controller {
	private $userId;
	private $geophotoService;
	private $photofilesService;
	private $root;

	public function __construct($AppName,
		IRequest $request,
		GeophotoService $GeophotoService,
		PhotofilesService $photofilesService,
		IRootFolder $root,
		$UserId) {
		parent::__construct($AppName, $request);
		$this->userId = $UserId;
		$this->geophotoService = $GeophotoService;
		$this->photofilesService = $photofilesService;
		$this->root = $root;
	}

	/**
	 * @param null $myMapId
	 * @param null $respectNoMediaAndNoimage
	 * @param null $hideImagesOnCustomMaps
	 * @param null $hideImagesInMapsFolder
	 *
	 * @return DataResponse
	 *
	 * @throws Exception
	 * @throws NoUserException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 *
	 * @psalm-return DataResponse<200, mixed, array<never, never>>
	 */
#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function getPhotos($myMapId = null, $respectNoMediaAndNoimage = null, $hideImagesOnCustomMaps = null, $hideImagesInMapsFolder = null): DataResponse {
		$userFolder = $this->root->getUserFolder($this->userId);
		if (is_null($myMapId) || $myMapId === '') {
			$result = $this->geophotoService->getAll($this->userId, $userFolder, $respectNoMediaAndNoimage ?? true, $hideImagesOnCustomMaps ?? false, $hideImagesInMapsFolder ?? true);
		} else {
			$folders = $userFolder->getById($myMapId);
			$folder = array_shift($folders);
			$result = $this->geophotoService->getAll($this->userId, $folder, $respectNoMediaAndNoimage ?? true, $hideImagesOnCustomMaps ?? false, $hideImagesInMapsFolder ?? false);
		}
		return new DataResponse($result);
	}

	/**
	 * @param int|null $myMapId
	 * @param string|null $timezone
	 * @param int $limit
	 * @param int $offset
	 * @param null $respectNoMediaAndNoimage
	 * @param null $hideImagesOnCustomMaps
	 * @param null $hideImagesInMapsFolder
	 *
	 * @return DataResponse
	 *
	 * @throws Exception
	 * @throws NoUserException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 *
	 * @psalm-return DataResponse<200, mixed, array<never, never>>
	 */
#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function getNonLocalizedPhotos(?int $myMapId = null, ?string $timezone = null, int $limit = 250, int $offset = 0, $respectNoMediaAndNoimage = null, $hideImagesOnCustomMaps = null, $hideImagesInMapsFolder = null): DataResponse {
		$userFolder = $this->root->getUserFolder($this->userId);
		if (is_null($myMapId) || $myMapId === '') {
			$result = $this->geophotoService->getNonLocalized($this->userId, $userFolder, $respectNoMediaAndNoimage ?? true, $hideImagesOnCustomMaps ?? false, $hideImagesInMapsFolder ?? true, $timezone, $limit, $offset);
		} else {
			$folders = $userFolder->getById($myMapId);
			$folder = array_shift($folders);
			$result = $this->geophotoService->getNonLocalized($this->userId, $folder, $respectNoMediaAndNoimage ?? true, $hideImagesOnCustomMaps ?? false, $hideImagesInMapsFolder ?? false, $timezone, $limit, $offset);
		}
		return new DataResponse($result);
	}


	/**
	 * @param $paths
	 * @param $lats
	 * @param $lngs
	 * @param bool $directory
	 * @param null $myMapId
	 * @param bool $relative
	 *
	 * @return DataResponse
	 *
	 * @throws NoUserException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws InvalidPathException
	 *
	 * @psalm-return DataResponse<200, mixed, array<never, never>>|DataResponse<200|400, array<never, never>, array<never, never>>
	 */
#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function placePhotos($paths, $lats, $lngs, bool $directory = false, $myMapId = null, bool $relative = false): DataResponse {
		$userFolder = $this->root->getUserFolder($this->userId);
		if (!is_null($myMapId) and $myMapId !== '') {
			if ($directory === 'true') {
				// forbid folder placement in my-maps
				throw new NotPermittedException();
			}

			$folder = $userFolder->getFirstNodeById($myMapId);
			if (!($folder instanceof Folder)) {
				return new DataResponse(statusCode: Http::STATUS_BAD_REQUEST);
			}

			// photo's path is relative to this map's folder => get full path, don't copy
			if ($relative) {
				foreach ($paths as $key => $path) {
					$photoFile = $folder->get($path);
					$paths[$key] = $userFolder->getRelativePath($photoFile->getPath());
				}
			} else {
				// here the photo path is good, copy it in this map's folder if it's not already there
				foreach ($paths as $key => $path) {
					$photoFile = $userFolder->get($path);
					// is the photo in this map's folder?
					if (!$folder->getById($photoFile->getId())) {
						$copiedFile = $photoFile->copy($folder->getPath() . '/' . $photoFile->getName());
						$paths[$key] = $userFolder->getRelativePath($copiedFile->getPath());
					}
				}
			}
		}
		$result = $this->photofilesService->setPhotosFilesCoords($this->userId, $paths, $lats, $lngs, $directory);
		return new DataResponse($result);
	}

	/**
	 * @param $paths
	 *
	 * @return DataResponse
	 *
	 * @psalm-return DataResponse<200, array<never, never>|mixed, array<never, never>>
	 */
#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function resetPhotosCoords($paths, $myMapId = null): DataResponse {
		$userFolder = $this->root->getUserFolder($this->userId);
		$result = [];
		if (sizeof($paths) > 0) {
			$result = $this->photofilesService->resetPhotosFilesCoords($this->userId, $paths);
		}
		if (!is_null($myMapId) and $myMapId !== '') {
			foreach ($paths as $key => $path) {
				$folders = $userFolder->getById($myMapId);
				$folder = array_shift($folders);
				$photoFile = $userFolder->get($path);
				if ($folder->isSubNode($photoFile)) {
					$photoFile->delete();
					unset($paths[$key]);
				}
			}
		}
		return new DataResponse($result);
	}

	/**
	 * @return DataResponse
	 *
	 * @psalm-return DataResponse<200|400, 'Cache cleared'|'Failed to clear Cache', array<never, never>>
	 */
#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function clearCache(): DataResponse {
		$result = $this->geophotoService->clearCache();
		if ($result) {
			return new DataResponse('Cache cleared');
		} else {
			return new DataResponse('Failed to clear Cache', 400);
		}
	}

	/**
	 * @return DataResponse
	 *
	 * @psalm-return DataResponse<200, mixed, array<never, never>>
	 */
#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function getBackgroundJobStatus(): DataResponse {
		return new DataResponse($this->photofilesService->getBackgroundJobStatus($this->userId));
	}

}
