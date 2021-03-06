<?php

namespace Friendica\Module\Diaspora;

use Friendica\BaseModule;
use Friendica\Core\Protocol;
use Friendica\Core\System;
use Friendica\DI;
use Friendica\Model\Item;
use Friendica\Model\User;
use Friendica\Network\HTTPException;
use Friendica\Protocol\Diaspora;
use Friendica\Util\Strings;

/**
 * This module is part of the Diaspora protocol.
 * It is used for fetching single public posts.
 */
class Fetch extends BaseModule
{
	public static function rawContent(array $parameters = [])
	{
		$app = DI::app();

		// @TODO: Replace with parameter from router
		if (($app->argc != 3) || (!in_array($app->argv[1], ["post", "status_message", "reshare"]))) {
			throw new HTTPException\NotFoundException();
		}

		// @TODO: Replace with parameter from router
		$guid = $app->argv[2];

		// Fetch the item
		$fields = [
			'uid', 'title', 'body', 'guid', 'contact-id', 'private', 'created', 'received', 'app', 'location', 'coord', 'network',
			'event-id', 'resource-id', 'author-link', 'author-avatar', 'author-name', 'plink', 'owner-link', 'attach'
		];
		$condition = ['wall' => true, 'private' => false, 'guid' => $guid, 'network' => [Protocol::DFRN, Protocol::DIASPORA]];
		$item = Item::selectFirst($fields, $condition);
		if (empty($item)) {
			$condition = ['guid' => $guid, 'network' => [Protocol::DFRN, Protocol::DIASPORA]];
			$item = Item::selectFirst(['author-link'], $condition);
			if (empty($item)) {
				$parts = parse_url($item["author-link"]);
				if (empty($parts["scheme"]) || empty($parts["host"])) {
					throw new HTTPException\InternalServerErrorException();
				}
				$host = $parts["scheme"] . "://" . $parts["host"];

				if (Strings::normaliseLink($host) != Strings::normaliseLink(DI::baseUrl()->get())) {
					$location = $host . "/fetch/" . $app->argv[1] . "/" . urlencode($guid);
					System::externalRedirect($location, 301);
				}
			}

			throw new HTTPException\NotFoundException();
		}

		// Fetch some data from the author (We could combine both queries - but I think this is more readable)
		$user = User::getOwnerDataById($item["uid"]);
		if (!$user) {
			throw new HTTPException\NotFoundException();
		}

		$status = Diaspora::buildStatus($item, $user);
		$xml = Diaspora::buildPostXml($status["type"], $status["message"]);

		// Send the envelope
		header("Content-Type: application/magic-envelope+xml; charset=utf-8");
		echo Diaspora::buildMagicEnvelope($xml, $user);

		exit();
	}
}
