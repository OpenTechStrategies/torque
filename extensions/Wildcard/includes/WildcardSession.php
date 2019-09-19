<?php

use MediaWiki\Session\SessionManager;

class WildcardSession {
	public static function touchSession() {
		$session = SessionManager::getGlobalSession();
		$collection = $session['wsWildcard'];
		$collection['timestamp'] = wfTimestampNow();
		$session['wsWildcard'] = $collection;
	}

	public static function enable() {
		$session = SessionManager::getGlobalSession();
		$session->persist();

		$session['wsWildcard']['enabled'] = true;
		self::touchSession();
	}

	public static function disable() {
		$session = SessionManager::getGlobalSession();

		if ( !isset( $session['wsWildcard'] ) ) {
			return;
		}
		$session['wsWildcard']['enabled'] = false;
		self::touchSession();
	}

	public static function isEnabled() {
		$session = SessionManager::getGlobalSession();

		return isset( $session['wsWildcard'] ) &&
			isset( $session['wsWildcard']['enabled'] ) &&
			$session['wsWildcard']['enabled'];
	}
}

?>
