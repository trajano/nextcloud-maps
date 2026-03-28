<?php

/**
 * Nextcloud - maps
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2019
 */

namespace OCA\Maps\Controller;

use OCA\DAV\CardDAV\CardDavBackend;
use OCA\Maps\Service\AddressService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Contacts\IManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IAvatarManager;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;
use Sabre\VObject\Property\Text;
use Sabre\VObject\Reader;

final class ContactsController extends Controller {
	private string $userId;
	private IManager $contactsManager;
	private AddressService $addressService;
	private IDBConnection $dbconnection;
	private CardDavBackend $cdBackend;
	private IAvatarManager $avatarManager;
	private IRootFolder $root;
	private IURLGenerator $urlGenerator;
	private int $geoDistanceMax; // Max distance in meters to consider that 2 addresses are the same location

	/**
	 * @param $AppName
	 * @param IRequest $request
	 * @param IDBConnection $dbconnection
	 * @param IManager $contactsManager
	 * @param AddressService $addressService
	 * @param $UserId
	 * @param CardDavBackend $cdBackend
	 * @param IAvatarManager $avatarManager
	 * @param IRootFolder $root
	 */
	public function __construct(
		string $AppName,
		IRequest $request,
		IDBConnection $dbconnection,
		IManager $contactsManager,
		AddressService $addressService,
		string $UserId,
		CardDavBackend $cdBackend,
		IAvatarManager $avatarManager,
		IRootFolder $root,
		IURLGenerator $urlGenerator) {
		parent::__construct($AppName, $request);
		$this->userId = $UserId;
		$this->avatarManager = $avatarManager;
		$this->contactsManager = $contactsManager;
		$this->addressService = $addressService;
		$this->dbconnection = $dbconnection;
		$this->cdBackend = $cdBackend;
		$this->root = $root;
		$this->urlGenerator = $urlGenerator;
		$this->geoDistanceMax = 5;
	}
	/**
	 *
	 * Converts a geo string as a float array
	 *
	 * @param string formatted as "lat;lon"
	 */
	private function geoAsFloatArray(string $geo): ?array {
		$parts = explode(';', $geo);
		if (count($parts) !== 2) {
			return null;
		}

		return [
			(float)$parts[0],
			(float)$parts[1],
		];
	}

	/**
	 * Check if a geographical address duplicates an earlier address.
	 *
	 * @param list<array{0: float, 1: float}> $prevGeo
	 * @param array{0: float, 1: float} $geo
	 *
	 * @psalm-return int<-1, max>
	 */
	private function isNewAddress(array $prevGeo, array $geo): int {
		$result = -1;
		$counter = 0;
		foreach ($prevGeo as $prev) {
			if ($this->getDistance($prev, $geo) <= $this->geoDistanceMax) {
				$result = $counter;
				break;
			}
			$counter++;
		}
		return $result;
	}

	/**
	 * Get distance between two geo points.
	 *
	 * @param array{0: float, 1: float} $coordsA GPS coordinates of first point
	 * @param array{0: float, 1: float} $coordsB GPS coordinates of second point
	 */
	private function getDistance(array $coordsA, array $coordsB): float {
		$latA = deg2rad($coordsA[0]);
		$lonA = deg2rad($coordsA[1]);
		$latB = deg2rad($coordsB[0]);
		$lonB = deg2rad($coordsB[1]);
		$earthRadius = 6378137.0; // in m
		$dlon = ($lonB - $lonA) / 2.0;
		$dlat = ($latB - $latA) / 2.0;
		$a = (sin($dlat) * sin($dlat)) + cos($latA) * cos($latB) * (sin($dlon) * sin($dlon
		));
		$d = 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));
		return $d * $earthRadius;
	}

	/**
	 * @param mixed $addressBook
	 */
	private function getAddressBookUriFromEntry(mixed $addressBook): ?string {
		if (!is_object($addressBook) || !method_exists($addressBook, 'getUri')) {
			return null;
		}

		/** @var mixed $uri */
		$uri = $addressBook->getUri();
		return is_string($uri) ? $uri : null;
	}

	private function getCardData(int $bookId, string $uri): ?string {
		$card = $this->cdBackend->getContact($bookId, $uri);
		if (!is_array($card)) {
			return null;
		}

		/** @var mixed $cardData */
		$cardData = $card['carddata'] ?? null;
		return is_string($cardData) ? $cardData : null;
	}

	private function getMapFolder(int $myMapId): ?Folder {
		$userFolder = $this->root->getUserFolder($this->userId);
		$folders = $userFolder->getById($myMapId);
		if (empty($folders)) {
			return null;
		}

		$folder = array_shift($folders);
		return $folder instanceof Folder ? $folder : null;
	}

	private function getMapFileById(Folder $mapsFolder, int $fileId): ?File {
		$files = $mapsFolder->getById($fileId);
		if (empty($files)) {
			return null;
		}

		$file = array_shift($files);
		return $file instanceof File ? $file : null;
	}

	private function getOrCreateMapFile(Folder $mapsFolder, string $uri): ?File {
		try {
			$file = $mapsFolder->get($uri);
		} catch (NotFoundException $e) {
			if (!$mapsFolder->isCreatable()) {
				return null;
			}
			$file = $mapsFolder->newFile($uri);
		}

		return $file instanceof File ? $file : null;
	}

	/**
	 *
	 * get contacts with coordinates
	 *
	 * @NoAdminRequired
	 *
	 * @param int|string|null $myMapId
	 *
	 * @return DataResponse
	 *
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OC\User\NoUserException
	 */
	public function getContacts(int|string|null $myMapId = null): DataResponse {
		$resolvedMapId = is_int($myMapId) ? $myMapId : (is_string($myMapId) && ctype_digit($myMapId) ? (int)$myMapId : null);
		if ($resolvedMapId === null) {
			$contacts = $this->contactsManager->search('', ['GEO', 'ADR'], ['types' => false]);
			$addressBooks = $this->contactsManager->getUserAddressBooks();
			$result = [];
			$userid = trim($this->userId);

			foreach ($contacts as $c) {
				if (!is_array($c)) {
					continue;
				}
				$addressBookKey = $c['addressbook-key'] ?? null;
				$uidValue = $c['UID'] ?? null;
				$uri = $c['URI'] ?? null;
				if (!is_numeric($addressBookKey) || !is_string($uidValue) || !is_string($uri)) {
					continue;
				}
				$addressBookUri = $this->getAddressBookUriFromEntry($addressBooks[(int)$addressBookKey] ?? null);
				if ($addressBookUri === null) {
					continue;
				}
				$uid = trim($uidValue);

				$url = $this->directUrlToContact($uid, $addressBookUri);

				// we don't give users, just contacts
				if (strcmp($uri, 'Database:' . $uidValue . '.vcf') !== 0
					and strcmp($uid, $userid) !== 0
				) {
					// if the contact has a geo attibute use it
					if (array_key_exists('GEO', $c)) {
						/** @var mixed $geo */
						$geo = $c['GEO'];
						if (is_string($geo) && strlen($geo) > 1) {
							$result[] = [
								'FN' => (isset($c['FN']) && is_string($c['FN'])) ? $c['FN'] : ((isset($c['N']) && is_string($c['N'])) ? ($this->N2FN($c['N']) ?? '???') : '???'),
								'URI' => $uri,
								'UID' => $uidValue,
								'URL' => $url,
								'ADR' => '',
								'ADRTYPE' => '',
								'HAS_PHOTO' => (isset($c['PHOTO']) && $c['PHOTO'] !== null),
								'BOOKID' => (int)$addressBookKey,
								'BOOKURI' => $addressBookUri,
								'GEO' => $geo,
								'GROUPS' => $c['CATEGORIES'] ?? null,
								'isDeletable' => true,
								'isUpdateable' => true,
							];
						} elseif (is_countable($geo) && count($geo) > 0 && is_iterable($geo)) {
							$geoValues = array_filter($geo, 'is_string');
							foreach ($geoValues as $g) {
								if (strlen($g) > 1) {
									$result[] = [
										'FN' => (isset($c['FN']) && is_string($c['FN'])) ? $c['FN'] : ((isset($c['N']) && is_string($c['N'])) ? ($this->N2FN($c['N']) ?? '???') : '???'),
										'URI' => $uri,
										'UID' => $uidValue,
										'URL' => $url,
										'ADR' => '',
										'ADRTYPE' => '',
										'HAS_PHOTO' => (isset($c['PHOTO']) && $c['PHOTO'] !== null),
										'BOOKID' => (int)$addressBookKey,
										'BOOKURI' => $addressBookUri,
										'GEO' => $g,
										'GROUPS' => $c['CATEGORIES'] ?? null,
										'isDeletable' => true,
										'isUpdateable' => true,
									];
								}
							}
						}
					}
					// anyway try to get it from the address
					$cardData = $this->getCardData((int)$addressBookKey, $uri);
					if ($cardData !== null) {
						$vcard = Reader::read($cardData);
						if (isset($vcard->ADR) && is_countable($vcard->ADR) && count($vcard->ADR) > 0) {
							/** @var list<array{0: float, 1: float}> $prevGeo */
							$prevGeo = [];
							$prevRes = [];
							$addresses = $vcard->ADR instanceof \Traversable ? array_filter(iterator_to_array($vcard->ADR, false), 'is_object') : [];
							array_walk($addresses, function (object $adr) use (&$prevGeo, &$prevRes, &$result, $c, $uri, $uidValue, $url, $addressBookKey, $addressBookUri): void {
								if (!method_exists($adr, 'getValue') || !method_exists($adr, 'parameters')) {
									return;
								}
								$geo = $this->addressService->addressToGeo($adr->getValue(), $uri);
								$geof = $this->geoAsFloatArray($geo);
								if ($geof === null) {
									return;
								}
								/** @var array{0: float, 1: float} $geof */
								$duplicatedIndex = $this->isNewAddress($prevGeo, $geof);
								$adrtype = '';
								/** @var mixed $parameters */
								$parameters = $adr->parameters();
								if (is_array($parameters) && isset($parameters['TYPE']) && is_object($parameters['TYPE']) && method_exists($parameters['TYPE'], 'getValue')) {
									/** @var mixed $typeValue */
									$typeValue = $parameters['TYPE']->getValue();
									$adrtype = is_string($typeValue) ? $typeValue : '';
								}
								if (strlen($geo) > 1) {
									$adrValue = $adr->getValue();
									if (!is_string($adrValue)) {
										return;
									}
									if ($duplicatedIndex < 0) {
										$prevGeo[] = $geof;
										$prevRes[] = count($result);
										$result[] = [
											'FN' => (isset($c['FN']) && is_string($c['FN'])) ? $c['FN'] : ((isset($c['N']) && is_string($c['N'])) ? ($this->N2FN($c['N']) ?? '???') : '???'),
											'URI' => $uri,
											'UID' => $uidValue,
											'URL' => $url,
											'ADR' => $adrValue,
											'ADRTYPE' => [$adrtype],
											'HAS_PHOTO' => (isset($c['PHOTO']) && $c['PHOTO'] !== null),
											'BOOKID' => (int)$addressBookKey,
											'BOOKURI' => $addressBookUri,
											'GEO' => $geo,
											'GROUPS' => $c['CATEGORIES'] ?? null,
											'isDeletable' => true,
											'isUpdateable' => true,
										];
									} else {
										// Concatenate AddressType to the corresponding record
										$resultIndex = $prevRes[$duplicatedIndex] ?? null;
										if ($resultIndex !== null && isset($result[$resultIndex]['ADRTYPE']) && is_array($result[$resultIndex]['ADRTYPE'])) {
											array_push($result[$resultIndex]['ADRTYPE'], $adrtype);
											$result[$resultIndex]['isUpdateable'] = false;
											$result[$resultIndex]['isDeletable'] = false;
											$result[$resultIndex]['isShareable'] = false;
										}
									}
								}
							});
						}
					}
				}
			}
			return new DataResponse($result);
		} else {
			//Fixme add contacts for my-maps
			$result = [];
			$folder = $this->getMapFolder($resolvedMapId);
			if ($folder === null) {
				return new DataResponse($result);
			}
			$files = $folder->search('.vcf');
			foreach ($files as $file) {
				if (!$file instanceof File) {
					continue;
				}
				//				$cards = explode("END:VCARD\r\n", $file->getContent());
				$cards = [$file->getContent()];
				foreach ($cards as $card) {
					$vcard = Reader::read($card . "END:VCARD\r\n");
					if (isset($vcard->GEO)) {
						$geo = $vcard->GEO;
						$geoValue = $geo->getValue();
						if (strlen($geoValue) > 1) {
							$result[] = $this->vCardToArray($file, $vcard, $geoValue);
						}
					}
					if (isset($vcard->ADR) && is_countable($vcard->ADR) && count($vcard->ADR) > 0) {
						/** @var list<array{0: float, 1: float}> $prevGeo */
						$prevGeo = [];
						$prevRes = [];
						$addresses = $vcard->ADR instanceof \Traversable ? array_filter(iterator_to_array($vcard->ADR, false), 'is_object') : [];
						array_walk($addresses, function (object $adr) use (&$prevGeo, &$prevRes, &$result, $file, $vcard): void {
							if (!method_exists($adr, 'getValue') || !method_exists($adr, 'parameters')) {
								return;
							}
							$geo = $this->addressService->addressToGeo($adr->getValue(), $file->getId());
							$geof = $this->geoAsFloatArray($geo);
							if ($geof === null) {
								return;
							}
							/** @var array{0: float, 1: float} $geof */
							$duplicatedIndex = $this->isNewAddress($prevGeo, $geof);
							$adrtype = '';
							/** @var mixed $parameters */
							$parameters = $adr->parameters();
							if (is_array($parameters) && isset($parameters['TYPE']) && is_object($parameters['TYPE']) && method_exists($parameters['TYPE'], 'getValue')) {
								/** @var mixed $typeValue */
								$typeValue = $parameters['TYPE']->getValue();
								$adrtype = is_string($typeValue) ? $typeValue : '';
							}
							if (strlen($geo) > 1) {
								/** @var mixed $adrValue */
								$adrValue = $adr->getValue();
								if (!is_string($adrValue)) {
									return;
								}
								if ($duplicatedIndex < 0) {
									$prevGeo[] = $geof;
									$prevRes[] = count($result);
									$result[] = $this->vCardToArray($file, $vcard, $geo, $adrtype, $adrValue, $file->getId());
								} else {
									$resultIndex = $prevRes[$duplicatedIndex] ?? null;
									if ($resultIndex !== null && isset($result[$resultIndex]['ADRTYPE']) && is_array($result[$resultIndex]['ADRTYPE'])) {
										array_push($result[$resultIndex]['ADRTYPE'], $adrtype);
										$result[$resultIndex]['isUpdateable'] = false;
										$result[$resultIndex]['isDeletable'] = false;
										$result[$resultIndex]['isShareable'] = false;
									}
								}
							}
						});
					}
				}
			}
			return new DataResponse($result);
		}
	}

	/**
	 * @param string $contactUid
	 * @param string $addressBookUri
	 */
	private function directUrlToContact(string $contactUid, string $addressBookUri): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('contacts.contacts.direct', [
				'contact' => $contactUid . '~' . $addressBookUri
			])
		);
	}

	/**
	 * @param Node $file
	 * @param \Sabre\VObject\Document $vcard
	 * @param string $geo
	 * @param string|null $adrtype
	 * @param string|null $adr
	 * @param int|null $fileId
	 *
	 * @return (\Sabre\VObject\Property|bool|int|mixed|null|string)[]
	 *
	 * @throws NotFoundException
	 * @throws \OCP\Files\InvalidPathException
	 *
	 * @psalm-return array{FN: mixed|string, UID: mixed|null, HAS_PHOTO: bool, FILEID: int|null, ADR: string, ADRTYPE: string, PHOTO: ''|\Sabre\VObject\Property, GEO: string, GROUPS: string, isDeletable: bool, isUpdateable: bool}
	 */
	private function vCardToArray(Node $file, \Sabre\VObject\Document $vcard, string $geo, ?string $adrtype = null, ?string $adr = null, ?int $fileId = null): array {
		$FNArray = $vcard->FN ? $vcard->FN->getJsonValue() : [];
		/** @var mixed $fn */
		$fn = array_shift($FNArray);
		$NArray = $vcard->N ? $vcard->N->getJsonValue() : [];
		/** @var mixed $n */
		$n = array_shift($NArray);
		if (!is_null($n)) {
			if (is_array($n)) {
				/** @var mixed $firstName */
				$firstName = array_shift($n);
				$n = is_string($firstName) ? $this->N2FN($firstName) : null;
			} elseif (is_string($n)) {
				$n = $this->N2FN($n);
			}

		}
		$UIDArray = $vcard->UID !== null ? $vcard->UID->getJsonValue() : [];
		/** @var mixed $uid */
		$uid = array_shift($UIDArray);
		$groups = $vcard->CATEGORIES;
		if (!is_null($groups)) {
			$groups = $groups->getValue();
		} else {
			$groups = '';
		}
		$result = [
			'FN' => $fn ?? $n ?? '???',
			'UID' => $uid,
			'HAS_PHOTO' => (isset($vcard->PHOTO) && $vcard->PHOTO !== null),
			'FILEID' => $fileId,
			'ADR' => $adr ?? '',
			'ADRTYPE' => $adrtype ?? '',
			'PHOTO' => $vcard->PHOTO ?? '',
			'GEO' => $geo,
			'GROUPS' => $groups,
			'isDeletable' => $file->isDeletable(),
			'isUpdateable' => $file->isUpdateable(),
		];
		return $result;
	}

	/**
	 * @param string $n
	 *
	 * @return null|string
	 */
	private function N2FN(string $n): string|null {
		if ($n === '') {
			return null;
		}

		$spl = explode(';', $n);
		if (count($spl) >= 4) {
			return $spl[3] . ' ' . $spl[1] . ' ' . $spl[0];
		}

		return null;
	}

	/**
	 *
	 * get all contacts
	 *
	 * @NoAdminRequired
	 *
	 * @param string $query
	 *
	 * @return DataResponse
	 *
	 * @psalm-return DataResponse<200, list{0?: array{FN: mixed|string, URI: mixed, UID: mixed, BOOKID: mixed, READONLY: ''|mixed, BOOKURI: mixed, HAS_PHOTO: bool, HAS_PHOTO2: bool},...}, array<never, never>>
	 */
	public function searchContacts(string $query = ''): DataResponse {
		$contacts = $this->contactsManager->search($query, ['FN'], ['types' => false]);
		$booksReadOnly = $this->getAddressBooksReadOnly();
		$addressBooks = $this->contactsManager->getUserAddressBooks();
		$result = [];
		$userid = trim($this->userId);
		foreach ($contacts as $c) {
			if (!is_array($c)) {
				continue;
			}
			$uidValue = $c['UID'] ?? null;
			$uri = $c['URI'] ?? null;
			$addressBookKey = $c['addressbook-key'] ?? null;
			if (!is_string($uidValue) || !is_string($uri) || !is_numeric($addressBookKey)) {
				continue;
			}
			$uid = trim($uidValue);
			// we don't give users, just contacts
			if (strcmp($uri, 'Database:' . $uidValue . '.vcf') !== 0
				and strcmp($uid, $userid) !== 0
			) {
				$addressBookUri = $this->getAddressBookUriFromEntry($addressBooks[(int)$addressBookKey] ?? null);
				if ($addressBookUri === null) {
					continue;
				}
				$result[] = [
					'FN' => (isset($c['FN']) && is_string($c['FN'])) ? $c['FN'] : ((isset($c['N']) && is_string($c['N'])) ? ($this->N2FN($c['N']) ?? '???') : '???'),
					'URI' => $uri,
					'UID' => $uidValue,
					'BOOKID' => (int)$addressBookKey,
					'READONLY' => $booksReadOnly[(int)$addressBookKey] ?? '',
					'BOOKURI' => $addressBookUri,
					'HAS_PHOTO' => (isset($c['PHOTO'])),
					'HAS_PHOTO2' => (isset($c['PHOTO']) && $c['PHOTO'] !== ''),
				];
			}
		}
		return new DataResponse($result);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $bookid
	 * @param string $uri
	 * @param string $uid
	 * @param float|null $lat
	 * @param float|null $lng
	 * @param string $attraction
	 * @param string $house_number
	 * @param string $road
	 * @param string $postcode
	 * @param string $city
	 * @param string $state
	 * @param string $country
	 * @param string $type
	 * @param string|null $address_string
	 * @param int|null $fileId
	 * @param int|null $myMapId
	 *
	 * @return DataResponse
	 *
	 * @throws \OCP\DB\Exception
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OC\User\NoUserException
	 *
	 * @psalm-return DataResponse<200|400|404, string, array<never, never>>
	 */
	public function placeContact(
		string $bookid,
		string $uri,
		string $uid,
		?float $lat,
		?float $lng,
		string $attraction = '',
		string $house_number = '',
		string $road = '',
		string $postcode = '',
		string $city = '',
		string $state = '',
		string $country = '',
		string $type = '',
		?string $address_string = null,
		?int $fileId = null,
		?int $myMapId = null): DataResponse {
		if ($myMapId === null) {
			// do not edit 'user' contact even myself
			if (strcmp($uri, 'Database:' . $uid . '.vcf') === 0
				or strcmp($uid, $this->userId) === 0
			) {
				return new DataResponse('Can\'t edit users', 400);
			} else {
				// check addressbook permissions
				if (!$this->addressBookIsReadOnly($bookid)) {
					if ($lat !== null && $lng !== null) {
						// we set the geo tag
						if ($attraction === '' && $house_number === '' && $road === '' && $postcode === '' && $city === '' && $state === '' && $country === '' && $address_string === null) {
							$result = $this->contactsManager->createOrUpdate(['URI' => $uri, 'GEO' => (string)$lat . ';' . (string)$lng], $bookid);
						}
						// we set the address
						elseif ($address_string === null) {
							$street = trim($attraction . ' ' . $house_number . ' ' . $road);
							$stringAddress = ';;' . $street . ';' . $city . ';' . $state . ';' . $postcode . ';' . $country;
							// set the coordinates in the DB
							$lat = floatval($lat);
							$lng = floatval($lng);
							$this->setAddressCoordinates($lat, $lng, $stringAddress, $uri);
							// set the address in the vcard
							$cardData = $this->getCardData((int)$bookid, $uri);
							if ($cardData !== null) {
								$vcard = Reader::read($cardData);
								;
								$vcard->add(new Text($vcard, 'ADR', ['', '', $street, $city, $state, $postcode, $country], ['TYPE' => $type]));
								$result = $this->cdBackend->updateCard($bookid, $uri, $vcard->serialize());
							}
						} else {
							$cardData = $this->getCardData((int)$bookid, $uri);
							if ($cardData !== null) {
								$vcard = Reader::read($cardData);
								;
								$vcard->add(new Text($vcard, 'ADR', explode(';', $address_string), ['TYPE' => $type]));
								$result = $this->cdBackend->updateCard($bookid, $uri, $vcard->serialize());
							}
						}
					} else {
						// TODO find out how to remove a property
						// following does not work properly
						$result = $this->contactsManager->createOrUpdate(['URI' => $uri, 'GEO' => null], $bookid);
					}
					return new DataResponse('EDITED');
				} else {
					return new DataResponse('READONLY', 400);
				}
			}
		} else {
			$mapsFolder = $this->getMapFolder($myMapId);
			if ($mapsFolder === null) {
				return new DataResponse('MAP NOT FOUND', 404);
			}
			if (is_null($fileId)) {
				$card = $this->getCardData((int)$bookid, $uri);
				$file = $this->getOrCreateMapFile($mapsFolder, $uri);
				if ($file === null) {
					return new DataResponse('CONTACT NOT WRITABLE', 400);
				}
			} else {
				$file = $this->getMapFileById($mapsFolder, $fileId);
				if ($file === null) {
					return new DataResponse('CONTACT NOT FOUND', 404);
				}
				$card = $file->getContent();
			}
			if (!$file->isUpdateable()) {
				return new DataResponse('CONTACT NOT WRITABLE', 400);
			}
			if ($card !== null && $card !== '') {
				$vcard = Reader::read($card);
				if ($lat !== null && $lng !== null) {
					if ($attraction === '' && $house_number === '' && $road === '' && $postcode === '' && $city === '' && $state === '' && $country === '' && $address_string === null) {
						$vcard->add('GEO', (string)$lat . ';' . (string)$lng);
					} elseif ($address_string === null) {
						$street = trim($attraction . ' ' . $house_number . ' ' . $road);
						$stringAddress = ';;' . $street . ';' . $city . ';' . $state . ';' . $postcode . ';' . $country;
						// set the coordinates in the DB
						$lat = floatval($lat);
						$lng = floatval($lng);
						$this->setAddressCoordinates($lat, $lng, $stringAddress, $uri);
						$vcard = Reader::read($card);
						$vcard->add('ADR', ['', '', $street, $city, $state, $postcode, $country], ['TYPE' => $type]);
					} else {
						$stringAddress = $address_string;
						// set the coordinates in the DB
						$lat = floatval($lat);
						$lng = floatval($lng);
						$this->setAddressCoordinates($lat, $lng, $stringAddress, $uri);
						$vcard = Reader::read($card);
						$vcard->add('ADR', explode(';', $address_string), ['TYPE' => $type]);
					}
				} else {
					$vcard->remove('GEO');
				}
				$file->putContent($vcard->serialize());
				return new DataResponse('EDITED');
			}
			return new DataResponse('CONTACT NOT FOUND', 404);
		}

	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $bookid
	 * @param string $uri
	 * @param int $myMapId
	 * @param int|null $fileId
	 *
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OC\User\NoUserException
	 */
	public function addContactToMap(string $bookid, string $uri, int $myMapId, ?int $fileId = null): DataResponse {
		$mapsFolder = $this->getMapFolder($myMapId);
		if ($mapsFolder === null) {
			return new DataResponse('MAP NOT FOUND', 404);
		}
		if (is_null($fileId)) {
			$card = $this->getCardData((int)$bookid, $uri);
			$file = $this->getOrCreateMapFile($mapsFolder, $uri);
			if ($file === null) {
				return new DataResponse('CONTACT NOT WRITABLE', 400);
			}
		} else {
			$file = $this->getMapFileById($mapsFolder, $fileId);
			if ($file === null) {
				return new DataResponse('CONTACT NOT FOUND', 404);
			}
			$card = $file->getContent();
		}
		if (!$file->isUpdateable()) {
			return new DataResponse('CONTACT NOT WRITABLE', 400);
		}
		if ($card !== null && $card !== '') {
			$vcard = Reader::read($card);
			$file->putContent($vcard->serialize());
			return new DataResponse('DONE');
		}

		return new DataResponse('CONTACT NOT FOUND', 404);
	}

	/**
	 * @param string $bookid
	 * @return bool
	 */
	private function addressBookIsReadOnly(string $bookid): bool {
		$userBooks = $this->cdBackend->getAddressBooksForUser('principals/users/' . $this->userId);
		foreach ($userBooks as $book) {
			if (!is_array($book) || !isset($book['id'])) {
				continue;
			}
			if ((int)$book['id'] === (int)$bookid) {
				return (isset($book['{http://owncloud.org/ns}read-only']) and (bool)$book['{http://owncloud.org/ns}read-only']);
			}
		}
		return true;
	}

	/**
	 * @return bool[]
	 *
	 * @psalm-return array<bool>
	 */
	private function getAddressBooksReadOnly(): array {
		$booksReadOnly = [];
		$userBooks = $this->cdBackend->getAddressBooksForUser('principals/users/' . $this->userId);
		foreach ($userBooks as $book) {
			if (!is_array($book) || !isset($book['id'])) {
				continue;
			}
			$ro = (isset($book['{http://owncloud.org/ns}read-only']) and (bool)$book['{http://owncloud.org/ns}read-only']);
			$booksReadOnly[(int)$book['id']] = $ro;
		}
		return $booksReadOnly;
	}

	/**
	 * @param float $lat
	 * @param float $lng
	 * @param string $adr
	 * @param string $uri
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	private function setAddressCoordinates(float $lat, float $lng, string $adr, string $uri): void {
		$qb = $this->dbconnection->getQueryBuilder();
		$adr_norm = strtolower((string)preg_replace('/\s+/', '', $adr));

		$qb->select('id')
			->from('maps_address_geo')
			->where($qb->expr()->eq('adr_norm', $qb->createNamedParameter($adr_norm, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('object_uri', $qb->createNamedParameter($uri, IQueryBuilder::PARAM_STR)));
		$req = $qb->executeQuery();
		$result = $req->fetchAll();
		$req->closeCursor();
		$qb = $this->dbconnection->getQueryBuilder();
		if ($result !== []) {
			$id = (string)($result[0]['id'] ?? '');
			$qb->update('maps_address_geo')
				->set('lat', $qb->createNamedParameter($lat, IQueryBuilder::PARAM_STR))
				->set('lng', $qb->createNamedParameter($lng, IQueryBuilder::PARAM_STR))
				->set('object_uri', $qb->createNamedParameter($uri, IQueryBuilder::PARAM_STR))
				->set('looked_up', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR)));
			$qb->executeStatement();
		} else {
			$qb->insert('maps_address_geo')
				->values([
					'adr' => $qb->createNamedParameter($adr, IQueryBuilder::PARAM_STR),
					'adr_norm' => $qb->createNamedParameter($adr_norm, IQueryBuilder::PARAM_STR),
					'object_uri' => $qb->createNamedParameter($uri, IQueryBuilder::PARAM_STR),
					'lat' => $qb->createNamedParameter($lat, IQueryBuilder::PARAM_STR),
					'lng' => $qb->createNamedParameter($lng, IQueryBuilder::PARAM_STR),
					'looked_up' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
				]);
			$qb->executeStatement();
		}
	}


	/**
	 *
	 * get contacts with coordinates
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @param string $name
	 *
	 * @return DataDisplayResponse
	 *
	 * @throws NotFoundException
	 * @throws \OCP\Files\NotPermittedException
	 *
	 * @psalm-return DataDisplayResponse<200, array<never, never>>
	 */
	public function getContactLetterAvatar(string $name): DataDisplayResponse {
		$av = $this->avatarManager->getGuestAvatar($name);
		$avatarContent = $av->getFile(64)->getContent();
		return new DataDisplayResponse($avatarContent);
	}

	/**
	 *
	 * removes the address from the vcard
	 * and delete corresponding entry in the DB
	 *
	 * @NoAdminRequired
	 *
	 * @param string $bookid
	 * @param string $uri
	 * @param string $uid
	 * @param string $adr
	 * @param string $geo
	 * @param ?int $fileId
	 * @param ?int $myMapId
	 *
	 * @return DataResponse
	 *
	 * @psalm-return DataResponse<200|400, 'DELETED'|'FAILED'|'READONLY', array<never, never>>
	 */
	public function deleteContactAddress(string $bookid, string $uri, string $uid, string $adr, string $geo, ?int $fileId = null, ?int $myMapId = null): DataResponse {

		// vcard
		$cardData = $this->getCardData((int)$bookid, $uri);
		if ($cardData !== null) {
			$vcard = Reader::read($cardData);
			//$bookId = $card['addressbookid'];
			if (!$this->addressBookIsReadOnly($bookid)) {
				foreach ($vcard->children() as $property) {
					if (!$property instanceof \Sabre\VObject\Property) {
						continue;
					}
					if ($property->name === 'ADR') {
						$cardAdr = $property->getValue();
						if ($cardAdr === $adr) {
							$vcard->remove($property);
							break;
						}
					} elseif ($property->name === 'GEO') {
						$cardAdr = $property->getValue();
						if ($cardAdr === $geo) {
							$vcard->remove($property);
							break;
						}
					}
				}
				$this->cdBackend->updateCard($bookid, $uri, $vcard->serialize());
				// no need to cleanup db here, it will be done when catching vcard change hook
				return new DataResponse('DELETED');
			} else {
				return new DataResponse('READONLY', 400);
			}
		} else {
			return new DataResponse('FAILED', 400);
		}
	}
}
