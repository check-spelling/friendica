<?php
/**
 * @copyright Copyright (C) 2010-2021, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Friendica\Module\Diaspora;

use Friendica\App;
use Friendica\BaseModule;
use Friendica\Core\Config\Capability\IManageConfigValues;
use Friendica\Core\L10n;
use Friendica\Model\User;
use Friendica\Network\HTTPException;
use Friendica\Protocol\Diaspora;
use Friendica\Util\Network;
use Friendica\Util\Profiler;
use Psr\Log\LoggerInterface;

/**
 * This module is part of the Diaspora protocol.
 * It is used for receiving single posts either for public or for a specific user.
 */
class Receive extends BaseModule
{
	/** @var IManageConfigValues */
	protected $config;

	public function __construct(L10n $l10n, App\BaseURL $baseUrl, App\Arguments $args, LoggerInterface $logger, Profiler $profiler, IManageConfigValues $config, array $server, array $parameters = [])
	{
		parent::__construct($l10n, $baseUrl, $args, $logger, $profiler, $server, $parameters);

		$this->config = $config;
	}

	protected function post(array $request = [], array $post = [])
	{
		$enabled = $this->config->get('system', 'diaspora_enabled', false);
		if (!$enabled) {
			$this->logger->info('Diaspora disabled.');
			throw new HTTPException\ForbiddenException($this->t('Access denied.'));
		}

		if ($this->parameters['type'] === 'public') {
			$this->receivePublic();
		} else if ($this->parameters['type'] === 'users') {
			$this->receiveUser();
		}
	}

	/**
	 * Receive a public Diaspora posting
	 *
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private  function receivePublic()
	{
		$this->logger->info('Diaspora: Receiving post.');

		$msg = $this->decodePost();

		$this->logger->info('Diaspora: Dispatching.');

		Diaspora::dispatchPublic($msg);
	}

	/**
	 * Receive a Diaspora posting for a user
	 *
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private function receiveUser()
	{
		$this->logger->info('Diaspora: Receiving post.');

		$importer = User::getByGuid($this->parameters['guid']);

		$msg = $this->decodePost(false, $importer['prvkey'] ?? '');

		$this->logger->info('Diaspora: Dispatching.');

		if (Diaspora::dispatch($importer, $msg)) {
			throw new HTTPException\OKException();
		} else {
			// We couldn't process the content.
			// To avoid the remote system trying again we send the message that we accepted the content.
			throw new HTTPException\AcceptedException();
		}
	}

	/**
	 * Decodes a Diaspora message based on the posted data
	 *
	 * @param string $privKey The private key of the importer
	 * @param bool   $public  True, if the post is public
	 *
	 * @return array
	 * @throws HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private function decodePost(bool $public = true, string $privKey = '')
	{
		if (empty($_POST['xml'])) {

			$postdata = Network::postdata();

			if (empty($postdata)) {
				throw new HTTPException\InternalServerErrorException('Missing postdata.');
			}

			$this->logger->info('Diaspora: Message is in the new format.');

			$msg = Diaspora::decodeRaw($postdata, $privKey);
		} else {

			$xml = urldecode($_POST['xml']);

			$this->logger->info('Diaspora: Decode message in the old format.');
			$msg = Diaspora::decode($xml, $privKey);

			if ($public && !$msg) {
				$this->logger->info('Diaspora: Decode message in the new format.');
				$msg = Diaspora::decodeRaw($xml, $privKey);
			}
		}

		$this->logger->info('Diaspora: Post decoded.');
		$this->logger->debug('Diaspora: Decoded message.', ['msg' => $msg]);

		if (!is_array($msg)) {
			throw new HTTPException\InternalServerErrorException('Message is not an array.');
		}

		return $msg;
	}
}
