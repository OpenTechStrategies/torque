/**
 * Additional mw.Api methods to assist with (un)favoriting wiki pages.
 * @since 1.19
 */


( function ( mw, $ ) {

	/**
	 * @context {mw.Api}
	 */
	function doSimpleFavoriteInternal( page, success, err, addParams ) {
		var params = {
			action: 'simplefavorite',
			title: String( page ),
			token: mw.user.tokens.get( 'simplefavorite' ),
			uselang: mw.config.get( 'wgUserLanguage' )
		};
		function ok( data ) {
			// this doesn't appear to be needed, and it breaks 1.23.
			//success( data.simplefavorite ); 
			
		}
		if ( addParams ) {
			$.extend( params, addParams );
		}
		return this.post( params, { ok: ok, err: err } );
	}

	$.extend( mw.Api.prototype, {
		/**
		 * Convinience method for 'action=simplefavorite'.
		 *
		 * @param page {String|mw.Title} Full page name or instance of mw.Title
		 * @param success {Function} Callback to which the simplefavorite object will be passed.
		 * SimpleFavorite object contains properties 'title' (full pagename), 'simplefavorited' (boolean) and
		 * 'message' (parsed HTML of the 'addedsimplefavoritetext' message).
		 * @param err {Function} Error callback (optional)
		 * @return {jqXHR}
		 */
		simplefavorite: function ( page, success, err ) {
			return doSimpleFavoriteInternal.call( this, page, success, err );
		},
		/**
		 * Convinience method for 'action=simplefavorite&unsimplefavorite=1'.
		 *
		 * @param page {String|mw.Title} Full page name or instance of mw.Title
		 * @param success {Function} Callback to which the simplefavorite object will be passed.
		 * SimpleFavorite object contains properties 'title' (full pagename), 'simplefavorited' (boolean) and
		 * 'message' (parsed HTML of the 'removedsimplefavoritetext' message).
		 * @param err {Function} Error callback (optional)
		 * @return {jqXHR}
		 */
		unsimplefavorite: function ( page, success, err ) {
			return doSimpleFavoriteInternal.call( this, page, success, err, { unsimplefavorite: 1 } );
		}

	} );

}( mediaWiki, jQuery ) );