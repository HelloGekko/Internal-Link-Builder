/**
 * Internal Link Builder - admin settings behaviour.
 *
 * Handles dependent-field enabling, repeatable rows and the maintenance
 * action buttons on the Actions tab.
 */
( function ( $ ) {
	'use strict';

	var settings = window.ilbAdmin || {};
	var i18n = settings.i18n || {};

	/**
	 * Enables/disables rows that depend on a toggle being on.
	 */
	function syncDependentRows() {
		$( 'tr[data-depends-on]' ).each( function () {
			var $row = $( this );
			var dependsOn = $row.data( 'depends-on' );
			var $toggle = $( 'input[data-toggle-key="' + dependsOn + '"]' );

			if ( ! $toggle.length ) {
				return;
			}

			var enabled = $toggle.is( ':checked' );
			$row.toggleClass( 'ilb-row-disabled', ! enabled );
			$row.find( 'input, select, textarea, button' ).not( $toggle ).prop( 'disabled', ! enabled );
		} );
	}

	/**
	 * Creates a new repeatable input row for the given container.
	 *
	 * @param {jQuery} $container Repeatable wrapper.
	 * @return {jQuery} The new row.
	 */
	function buildRepeatableRow( $container ) {
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
	 * Runs a maintenance action via AJAX.
	 *
	 * @param {jQuery} $button The clicked button.
	 */
	function runAction( $button ) {
		var action = $button.data( 'action' );
		var $result = $button.siblings( '.ilb-action-result' );

		if ( 'ilb_cancel_schedules' === action && i18n.confirmCancel ) {
			if ( ! window.confirm( i18n.confirmCancel ) ) {
				return;
			}
		}

		$button.prop( 'disabled', true );
		$result.removeClass( 'ilb-success ilb-error' ).text( i18n.working || 'Working…' );

		$.post( settings.ajaxUrl, {
			action: action,
			nonce: settings.nonce
		} ).done( function ( response ) {
			var message = ( response && response.data && response.data.message ) || '';
			if ( response && response.success ) {
				$result.addClass( 'ilb-success' ).text( message );
			} else {
				$result.addClass( 'ilb-error' ).text( message );
			}
		} ).fail( function () {
			$result.addClass( 'ilb-error' ).text( 'Request failed.' );
		} ).always( function () {
			$button.prop( 'disabled', false );
		} );
	}

	$( function () {
		syncDependentRows();

		$( document ).on( 'change', 'input[data-toggle-key]', syncDependentRows );

		$( document ).on( 'click', '.ilb-repeatable-add', function () {
			var $container = $( this ).closest( '.ilb-repeatable' );
			$container.find( '.ilb-repeatable-rows' ).append( buildRepeatableRow( $container ) );
		} );

		$( document ).on( 'click', '.ilb-repeatable-remove', function () {
			var $rows = $( this ).closest( '.ilb-repeatable-rows' );
			$( this ).closest( '.ilb-repeatable-row' ).remove();
			// Keep at least one empty row for usability.
			if ( ! $rows.children( '.ilb-repeatable-row' ).length ) {
				var $container = $rows.closest( '.ilb-repeatable' );
				$rows.append( buildRepeatableRow( $container ) );
			}
		} );

		$( document ).on( 'click', '.ilb-action-button', function ( e ) {
			e.preventDefault();
			runAction( $( this ) );
		} );
	} );
} )( jQuery );
