<?php

/**
 * Nextcloud - Maps
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2019
 */

namespace OCA\Maps\Controller;

use OCA\Maps\Service\DevicesService;
use OCP\App\IAppManager;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Share\IManager;

final class DevicesApiController extends ApiController {

	private string $userId;
	private ?Folder $userfolder = null;
	private IConfig $config;
	private IManager $shareManager;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private mixed $dbdblquotes = null;
	private mixed $defaultDeviceId = null;
	private IL10N $l;
	private DevicesService $devicesService;
	protected $appName;

	public function __construct(string $AppName,
		IRequest $request,
		IRootFolder $rootFolder,
		IConfig $config,
		IManager $shareManager,
		IAppManager $appManager,
		IUserManager $userManager,
		IGroupManager $groupManager,
		IL10N $l,
		DevicesService $devicesService,
		string $UserId) {
		parent::__construct($AppName, $request,
			'PUT, POST, GET, DELETE, PATCH, OPTIONS',
			'Authorization, Content-Type, Accept',
			1728000);
		$this->devicesService = $devicesService;
		$this->appName = $AppName;
		$this->userId = $UserId;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->l = $l;
		// IConfig object
		$this->config = $config;
		if ($UserId !== '') {
			// path of user files folder relative to DATA folder
			$this->userfolder = $rootFolder->getUserFolder($UserId);
		}
		$this->shareManager = $shareManager;
	}

	/**
	 * @param string|int $apiversion
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	#[\OCP\AppFramework\Http\Attribute\CORS]
	public function getDevices(string|int $apiversion): Response {
		$now = new \DateTime();

		$devices = $this->devicesService->getDevicesFromDB($this->userId);

		$etag = md5((string)json_encode($devices));
		if ($this->request->getHeader('If-None-Match') === '"' . $etag . '"') {
			return new DataResponse([], Http::STATUS_NOT_MODIFIED);
		}
		return (new DataResponse($devices))
			->setLastModified($now)
			->setETag($etag);
	}

	/**
	 *
	 *
	 *
	 * @param int $pruneBefore
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	#[\OCP\AppFramework\Http\Attribute\CORS]
	public function getDevicePoints(int $id, int $pruneBefore = 0): DataResponse {
		$points = $this->devicesService->getDevicePointsFromDB($this->userId, $id, $pruneBefore);
		return new DataResponse($points);
	}

	/**
	 *
	 *
	 *
	 * @param string|int $apiversion
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	#[\OCP\AppFramework\Http\Attribute\CORS]
	public function addDevicePoint(string|int $apiversion, mixed $lat, mixed $lng, mixed $timestamp = null, ?string $user_agent = null, mixed $altitude = null, mixed $battery = null, mixed $accuracy = null): DataResponse {
		if (is_numeric($lat) and is_numeric($lng)) {
			$timestamp = $this->normalizeOptionalNumber($timestamp);
			$altitude = $this->normalizeOptionalNumber($altitude);
			$battery = $this->normalizeOptionalNumber($battery);
			$accuracy = $this->normalizeOptionalNumber($accuracy);
			$ts = $timestamp;
			if ($timestamp === null) {
				$ts = (new \DateTime())->getTimestamp();
			}
			$ua = $user_agent;
			if ($ua === null) {
				$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
			}
			$deviceId = $this->devicesService->getOrCreateDeviceFromDB($this->userId, $ua);
			$pointId = $this->devicesService->addPointToDB($deviceId, (float)$lat, (float)$lng, $ts, $altitude, $battery, $accuracy);
			return new DataResponse([
				'deviceId' => $deviceId,
				'pointId' => $pointId
			]);
		} else {
			return new DataResponse($this->l->t('Invalid values'), 400);
		}
	}

	/**
	 *
	 *
	 *
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	#[\OCP\AppFramework\Http\Attribute\CORS]
	public function editDevice(int $id, string $color): DataResponse {
		$device = $this->devicesService->getDeviceFromDB($id, $this->userId);
		if ($device !== null) {
			if (strlen($color) > 0) {
				$this->devicesService->editDeviceInDB($id, $color, null);
				$editedDevice = $this->devicesService->getDeviceFromDB($id, $this->userId);
				return new DataResponse($editedDevice);
			} else {
				return new DataResponse($this->l->t('Invalid values'), 400);
			}
		} else {
			return new DataResponse($this->l->t('No such device'), 400);
		}
	}

	/**
	 *
	 *
	 *
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	#[\OCP\AppFramework\Http\Attribute\CORS]
	public function deleteDevice(int $id): DataResponse {
		$device = $this->devicesService->getDeviceFromDB($id, $this->userId);
		if ($device !== null) {
			$this->devicesService->deleteDeviceFromDB($id);
			return new DataResponse('DELETED');
		} else {
			return new DataResponse($this->l->t('No such device'), 400);
		}
	}

	/**
	 * @return float|int|null
	 */
	private function normalizeOptionalNumber(mixed $value): float|int|null {
		if (!is_numeric($value)) {
			return null;
		}
		return is_int($value) ? $value : (float)$value;
	}

}
