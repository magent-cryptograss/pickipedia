<?php

namespace MediaWiki\Extension\PickiPediaVerification;

use MediaWiki\Hook\EditFilterMergedContentHook;
use MediaWiki\User\UserGroupManager;
use IContextSource;
use Content;
use TextContent;
use Status;
use MediaWiki\MediaWikiServices;

/**
 * Hooks for PickiPediaVerification extension.
 *
 * Intercepts edits from bot accounts and ensures they include
 * verification markers (status=proposed or Bot_proposes template).
 * Rejects bot edits that don't follow the verification workflow.
 */
class Hooks implements EditFilterMergedContentHook {

	private UserGroupManager $userGroupManager;

	public function __construct( UserGroupManager $userGroupManager ) {
		$this->userGroupManager = $userGroupManager;
	}

	/**
	 * Hook: EditFilterMergedContent
	 *
	 * Validates that bot edits include verification markers.
	 * Rejects edits that don't comply with the verification workflow.
	 *
	 * @param IContextSource $context
	 * @param Content $content
	 * @param Status $status
	 * @param string $summary
	 * @param User $user
	 * @param bool $minoredit
	 * @return bool
	 */
	public function onEditFilterMergedContent(
		$context,
		$content,
		$status,
		$summary,
		$user,
		$minoredit
	) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$title = $context->getTitle();

		// Only apply to configured namespaces
		$allowedNamespaces = $config->get( 'PickiPediaVerificationNamespaces' );
		if ( !in_array( $title->getNamespace(), $allowedNamespaces ) ) {
			return true;
		}

		// Check if user is in a bot group
		if ( !$this->isVerificationRequired( $user, $config ) ) {
			return true;
		}

		// Only handle text content
		if ( !$content instanceof TextContent ) {
			return true;
		}

		$text = $content->getText();

		// Check if properly marked as proposed/unverified
		if ( $this->isProperlyMarked( $text ) ) {
			return true;
		}

		// Reject the edit with helpful message
		$status->fatal( 'pickipediaverification-bot-needs-proposed' );
		$status->value = false;

		wfDebugLog( 'PickiPediaVerification',
			"Rejected bot edit from {$user->getName()} on {$title->getPrefixedText()} - missing verification markers"
		);

		return false;
	}

	/**
	 * Check if user is in a group that requires verification.
	 */
	private function isVerificationRequired( $user, $config ): bool {
		$userGroups = $this->userGroupManager->getUserGroups( $user );

		// Check if user is in an exempt group (bypasses verification entirely)
		$exemptGroups = $config->get( 'PickiPediaVerificationExemptGroups' );
		if ( !empty( array_intersect( $userGroups, $exemptGroups ) ) ) {
			return false;
		}

		// Check if user is in a bot group (requires verification)
		$botGroups = $config->get( 'PickiPediaVerificationBotGroups' );
		return !empty( array_intersect( $userGroups, $botGroups ) );
	}

	/**
	 * Check if content is properly marked as proposed/unverified.
	 */
	private function isProperlyMarked( string $text ): bool {
		// Check for Bot_proposes template
		if ( preg_match( '/\{\{Bot_proposes/i', $text ) ) {
			return true;
		}

		// Check for status=proposed in template parameters
		if ( preg_match( '/\|\s*status\s*=\s*proposed/i', $text ) ) {
			return true;
		}

		// Check for status=unverified
		if ( preg_match( '/\|\s*status\s*=\s*unverified/i', $text ) ) {
			return true;
		}

		return false;
	}
}
