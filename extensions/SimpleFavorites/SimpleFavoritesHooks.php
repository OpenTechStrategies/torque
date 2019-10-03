<?php

class SimpleFavoritesHooks {
	public static function onSkinTemplateNavigation(&$sktemplate, &$links) {
		$favClass = new SimpleFavorites;
		$favClass->simplefavoritesLinks($sktemplate, $links);
	}
	
	
	public static function onUnknownAction( $action, Page $article ) {
		if ($action == 'simplefavorite' || $action == 'unsimplefavorite') {
			// do something
			$title = $article->getTitle();
	
			$doAction = new SimpleFavoriteAction($action,$title,$article);
	
			return false;
		} else {
			// allow other extensions to handle whatever else is out there
			return true;
		}
	}
	
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		$out->addModules( 'ext.simplefavorites' );
		$out->addModuleStyles( 'ext.simplefavorites.style' );
	}
	
	public static function onLoadExtensionSchemaUpdates( $updater = null ) {
		if ( $updater === null ) { // <= 1.16 support
			global $wgExtNewTables, $wgExtModifiedFields;
			$wgExtNewTables[] = array(
					'simplefavoritelist',
					dirname( __FILE__ ) . '/simplefavorites.sql'
			);
		} else { // >= 1.17 support
			$updater->addExtensionUpdate( array( 'addTable', 'simplefavoritelist',
					dirname( __FILE__ ) . '/simplefavorites.sql', true ) );
		}
		return true;
	}
	
	public static function onTitleMoveComplete( &$title, &$nt, $user, $pageid, $redirid ) {
		# Update watchlists
		$oldnamespace = $title->getNamespace() & ~1;
		$newnamespace = $nt->getNamespace() & ~1;
		$oldtitle = $title->getDBkey();
		$newtitle = $nt->getDBkey();
	
		if ( $oldnamespace != $newnamespace || $oldtitle != $newtitle ) {
			SimpleFavorites::duplicateEntries( $title, $nt );
		}
		return true;
	}
	

	public static function onArticleDeleteComplete(&$article, &$user, $reason, $id ){
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete( 'simplefavoritelist', array(
				'fl_namespace' => $article->mTitle->getNamespace(),
				'fl_title' => $article->mTitle->getDBKey() ),
				__METHOD__ );
		return true;
	}
	
	public static function onPersonalUrls( &$personal_urls, &$title ) {
		global $wgUser;
	
		if ( $wgUser->isLoggedIn() ) {
			$url[] = array( 'text' => wfMessage( 'mysimplefavoritelist' )->text(),
					'href' => SpecialPage::getTitleFor( 'SimpleFavoritelist' )->getLocalURL() );
			$personal_urls = wfArrayInsertAfter( $personal_urls, $url, 'watchlist' );
		}
	
		return true;
	}
	
}


