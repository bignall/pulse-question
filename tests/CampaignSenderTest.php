<?php
/**
 * Unit tests for FluentCampaign::mailerSettings (plain PHP, no WordPress needed).
 *
 * @package PulseQuestion
 */

declare(strict_types=1);

namespace PulseQuestion\Tests;

use PHPUnit\Framework\TestCase;
use PulseQuestion\Email\FluentCampaign;

final class CampaignSenderTest extends TestCase {

	public function testNameAndAddressMakeACustomSender(): void {
		$this->assertSame(
			array(
				'from_name'      => 'Pulse Question',
				'from_email'     => 'news@example.com',
				'reply_to_name'  => 'Pulse Question',
				'reply_to_email' => 'hello@example.com',
				'is_custom'      => 'yes',
			),
			FluentCampaign::mailerSettings( ' Pulse Question ', 'news@example.com', 'hello@example.com' )
		);
	}

	public function testNoReplyToLeavesItEmpty(): void {
		$sender = FluentCampaign::mailerSettings( 'Pulse Question', 'news@example.com', '' );
		$this->assertSame( 'yes', $sender['is_custom'] );
		$this->assertSame( '', $sender['reply_to_email'] );
		$this->assertSame( '', $sender['reply_to_name'] );
	}

	public function testANameAloneUsesFluentCrmsSender(): void {
		$this->assertSame( 'no', FluentCampaign::mailerSettings( 'Pulse Question', '', 'hello@example.com' )['is_custom'] );
		$this->assertSame( 'no', FluentCampaign::mailerSettings( '', '', '' )['is_custom'] );
	}
}
