<?php

use MediaWiki\Session\SessionManager;

class PickSomeSession {
	public static function touchSession() {
		$session = SessionManager::getGlobalSession();
		$collection = $session['wsPickSome'];
		$collection['timestamp'] = wfTimestampNow();
		$session['wsPickSome'] = $collection;
	}

	public static function enable() {
		$session = SessionManager::getGlobalSession();
		$session->persist();

		$session['wsPickSome']['enabled'] = true;
		self::touchSession();
	}

	public static function disable() {
		$session = SessionManager::getGlobalSession();

		if ( !isset( $session['wsPickSome'] ) ) {
			return;
		}
		$session['wsPickSome']['enabled'] = false;
		self::touchSession();
	}

	public static function isEnabled() {
		$session = SessionManager::getGlobalSession();

		return isset( $session['wsPickSome'] ) &&
			isset( $session['wsPickSome']['enabled'] ) &&
			$session['wsPickSome']['enabled'];
	}
}

?>
