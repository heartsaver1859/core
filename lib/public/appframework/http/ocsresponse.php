<?php
/**
 * ownCloud - App Framework
 *
 * @author Bernhard Posselt
 * @copyright 2015 Bernhard Posselt <dev@bernhard-posselt.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Public interface of ownCloud for apps to use.
 * AppFramework\HTTP\JSONResponse class
 */

namespace OCP\AppFramework\Http;

use OCP\AppFramework\Http;

use OC_OCS;

/**
 * A renderer for OCS responses
 */
class OCSResponse extends Response {

	private $data;
	private $format;
	private $statuscode;
	private $message;
	private $tag;
	private $tagattribute;
	private $dimension;
	private $itemscount;
	private $itemsperpage;

	/**
	 * generates the xml or json response for the API call from an multidimenional data array.
	 * @param string $format
	 * @param string $status
	 * @param string $statuscode
	 * @param string $message
	 * @param array $data
	 * @param string $tag
	 * @param string $tagattribute
	 * @param int $dimension
	 * @param int|string $itemscount
	 * @param int|string $itemsperpage
	 */
	public function __construct($format, $status, $statuscode, $message,
								$data=[], $tag='', $tagattribute='',
								$dimension=-1, $itemscount='',
								$itemsperpage='') {
		$this->format = $format;
		$this->status = $status;
		$this->statuscode = $statuscode;
		$this->message = $message;
		$this->data = $data;
		$this->tag = $tag;
		$this->tagattribute = $tagattribute;
		$this->dimension = $dimension;
		$this->itemscount = $itemscount;
		$this->itemsperpage = $itemsperpage;

		// set the correct header based on the format parameter
		if ($format === 'json') {
			$this->addHeader(
				'Content-Type', 'application/json; charset=utf-8'
			);
		} else {
			$this->addHeader(
				'Content-Type', 'application/xml; charset=utf-8'
			);
		}
	}


	public function render() {
		return OC_OCS::generateXml(
			$this->format, $this->status, $this->statuscode, $this->message,
			$this->data, $this->tag, $this->tagattribute, $this->dimension,
			$this->itemscount, $this->itemsperpage
		);
	}


}