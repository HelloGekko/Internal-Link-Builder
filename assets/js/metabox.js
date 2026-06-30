/**
 * Internal Link Builder - post metabox behaviour.
 *
 * Tab switching, repeatable rows and the live "configured keyword blacklist"
 * overview.
 */
( function ( $ ) {
	'use strict';

	var data = window.ilbMetabox || {};
	var i18n = data.i18n || {};

	/**
	 * Builds a new repeatable row for a container.
	 *
	 * @param {jQuery} $container Repeatable wrapper.
	 * @return {jQuery} New row.
	 */
	function buildRow( $container ) {
		var name = $container.data( 'name' );
		var placeholder = $container.data( 'placeholder' ) || '';

		var $row = $( '<div class="ilb-repeatable-row"></div>' );
		$( '<input type="text" />' )
			.attr( 'name', name + '[]' )
			.attr( 'placeholder', placeholder )
			.appendTo( $row );
		$( '<button type="button" class="button-link ilb-repeatable-remove">&times;</button>' )
			.attr( 'aria-label', i18n.remove || 'Remove' )
			.appendTo( $row );

		return $row;
	}

	/**
	 * Refreshes the read-only overview from the content blacklist inputs.
	 */
	function syncOverview() {
		var $overview = $( '#ilb-mb-overview' );
		if ( ! $overview.length ) {
			return;
		}

		var sourceClass = $overview.data( 'source' );
		var values = [];

		$( '.' + sourceClass ).find( 'input[type="text"]' ).each( function () {
			var val = $.trim( this.value );
			if ( val && values.indexOf( val ) === -1 ) {
				values.push( val );
			}
		} );

		if ( ! values.length ) {
			$overview.html( $( '<em></em>' ).text( i18n.noBlocked || '' ) );
			return;
		}

		var $list = $( '<ul></ul>' );
		$.each( values, function ( i, val ) {
			$( '<li></li>' ).append( $( '<code></code>' ).text( val ) ).appendTo( $list );
		} );
		$overview.empty().append( $list );
	}

	$( function () {
		// Tabs.
		$( document ).on( 'click', '.ilb-mb-tab', function ( e ) {
			e.preventDefault();
			var target = $( this ).attr( 'href' );

			$( '.ilb-mb-tab' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );

			$( '.ilb-mb-panel' ).removeClass( 'is-active' );
			$( target ).addClass( 'is-active' );
		} );

		// Repeatable add/remove.
		$( document ).on( 'click', '.ilb-metabox .ilb-repeatable-add', function () {
			var $container = $( this ).closest( '.ilb-repeatable' );
			$container.find( '.ilb-repeatable-rows' ).append( buildRow( $container ) );
		} );

		$( document ).on( 'click', '.ilb-metabox .ilb-repeatable-remove', function () {
			var $rows = $( this ).closest( '.ilb-repeatable-rows' );
			$( this ).closest( '.ilb-repeatable-row' ).remove();
			if ( ! $rows.children( '.ilb-repeatable-row' ).length ) {
				$rows.append( buildRow( $rows.closest( '.ilb-repeatable' ) ) );
			}
			syncOverview();
		} );

		// Live overview.
		$( document ).on( 'input', '.ilb-content-blacklist input[type="text"]', syncOverview );
		$( document ).on( 'click', '.ilb-content-blacklist .ilb-repeatable-add', syncOverview );
	} );
} )( jQuery );
