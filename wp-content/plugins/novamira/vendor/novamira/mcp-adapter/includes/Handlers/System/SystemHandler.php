<?php
/**
 * System method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace Novamira\Vendor\WP\MCP\Handlers\System;

use Novamira\Vendor\WP\McpSchema\Common\AbstractDataTransferObject;
use Novamira\Vendor\WP\McpSchema\Common\Protocol\DTO\Result;

/**
 * Handles system-related MCP methods.
 */
class SystemHandler {
	/**
	 * Handles the ping request.
	 *
	 * @return \Novamira\Vendor\WP\McpSchema\Common\AbstractDataTransferObject Empty result DTO per MCP specification.
	 */
	public function ping(): AbstractDataTransferObject {
		return Result::fromArray( array() );
	}
}
