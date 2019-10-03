<?php
/*
 Copyright (C) 2015 Jeremy Lemley

 This program is free software: you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation, either version 3 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program.  If not, see <http://www.gnu.org/licenses/>.

 */

$wgExtensionCredits['specialpage'][] = array(
		'path' => __FILE__,
		'name' => 'SimpleFavorites',
		'author' => 'Jeremy Lemley',
		'descriptionmsg' => 'simplefavorites-desc',
		'version' => '1.1.2',
		'url' => 'https://www.mediawiki.org/wiki/Extension:SimpleFavorites',
);

// Take credit for your work.
$wgExtensionCredits['api'][] = array(
		'path' => __FILE__,
		'name' => 'SimpleFavorites API',
		'author' => 'Jeremy Lemley',
		'descriptionmsg' => 'simplefavorites-api-desc',
		'version' => '1.1.2',

);


$dir = dirname(__FILE__) . '/';

$wgResourceModules['ext.simplefavorites'] = array(
		'scripts' => array('/modules/page.simplefavorite.ajax.js','/modules/simplefavorites.js'),
		'dependencies' => array(
				'mediawiki.api',
				'mediawiki.RegExp',
		'user.tokens'),
		'localBasePath' => __DIR__,
		'remoteExtPath' => 'SimpleFavorites',
		'messages' => array(
				'simplefavoriteerrortext',
				'tooltip-ca-simplefavorite',
				'tooltip-ca-unsimplefavorite',
				'simplefavoriteing',
				'unsimplefavoriteing',
				'simplefavoritethispage',
				'unsimplefavoritethispage',
				'simplefavorite',
				'unsimplefavorite',
				'addedsimplefavoritetext',
				'removedsimplefavoritetext'
		)
		
);

//Resource loader for js and css
$wgResourceModules['ext.simplefavorites.style'] = array(
		'styles' => '/modules/simplefavorites.css',
		'localBasePath' => dirname( __FILE__ ),
		'remoteExtPath' => 'SimpleFavorites',
		'position' => 'top'

);


// Set up i18n
$wgMessagesDirs['SimpleFavorites'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['MyExtensionAlias'] = __DIR__ . '/SpecialSimpleFavorites.alias.php'; 


// Classes
$wgAutoloadClasses['SimpleFavorites'] = $dir . 'SimpleFavorites_body.php';
$wgAutoloadClasses['SimpleFavoriteAction'] = $dir . 'SimpleFavoritesActions.php';
$wgAutoloadClasses['SpecialSimpleFavoritelist'] = $dir . 'SpecialSimpleFavoritelist.php';
$wgAutoloadClasses['ViewSimpleFavorites'] = $dir . 'SpecialSimpleFavoritelist.php';
$wgAutoloadClasses['SimpleFavoritesHooks'] = $dir . 'SimpleFavoritesHooks.php';

// API
$wgAutoloadClasses['ApiSimpleFavorite'] = $dir . 'api/ApiSimpleFavorite.php';
$wgAPIModules['simplefavorite'] = 'ApiSimpleFavorite';

// Hooks
$wgHooks['SkinTemplateNavigation'][] = 'SimpleFavoritesHooks::onSkinTemplateNavigation';
//$wgHooks['SkinTemplateNavigation'][] = 'getSimpleFavoritesLinks';
$wgHooks['UnknownAction'][] = 'SimpleFavoritesHooks::onUnknownAction';
//$wgHooks['UnknownAction'][] = 'favActions';
$wgHooks['BeforePageDisplay'][] = 'SimpleFavoritesHooks::onBeforePageDisplay';
//$wgHooks['BeforePageDisplay'][] = 'onBeforePageDisplay';
//$wgHooks['ParserFirstCallInit'][] = 'ParseSimpleFavorites';
$wgHooks['TitleMoveComplete'][] = 'SimpleFavoritesHooks::onTitleMoveComplete';
$wgHooks['ArticleDeleteComplete'][] = 'SimpleFavoritesHooks::onArticleDeleteComplete';
$wgHooks['PersonalUrls'][] = 'SimpleFavoritesHooks::onPersonalUrls';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'SimpleFavoritesHooks::onLoadExtensionSchemaUpdates';

// Special Page
$wgSpecialPages['SimpleFavoritelist'] = 'SpecialSimpleFavoritelist';
