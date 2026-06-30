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

		initIndexStatus();
	} );

	/* ---------------------------------------------------------------------- *
	 * Index status & browser-driven generation.
	 * ---------------------------------------------------------------------- */

	var statusPoll = null;

	/**
	 * Updates the status counts and progress bar.
	 *
	 * @param {Object} data Status payload.
	 */
	function renderStatus( data ) {
		var $root = $( '.ilb-index-status' );
		if ( ! $root.length || ! data ) {
			return;
		}

		$root.find( '.ilb-stat-keywords' ).text( data.keywords );
		$root.find( '.ilb-stat-links' ).text( data.links );

		var total = parseInt( data.total, 10 ) || 0;
		var processed = parseInt( data.processed, 10 ) || 0;

		if ( data.running ) {
			$root.find( '.ilb-stat-state' ).text( i18n.running || 'Generating…' );
			showProgress( processed, total );
		} else {
			$root.find( '.ilb-stat-state' ).text( i18n.idle || 'Idle' );
		}
	}

	/**
	 * Shows and updates the progress bar.
	 *
	 * @param {number} processed Processed count.
	 * @param {number} total     Total count.
	 */
	function showProgress( processed, total ) {
		var $progress = $( '.ilb-progress' );
		var pct = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : 0;

		$progress.prop( 'hidden', false );
		$progress.find( '.ilb-progress-fill' ).css( 'width', pct + '%' );
		$progress.find( '.ilb-progress-label' ).text(
			( i18n.progress || '%1$d / %2$d' ).replace( '%1$d', processed ).replace( '%2$d', total )
			+ '  (' + pct + '%)'
		);
	}

	/**
	 * Fetches the current status once.
	 *
	 * @param {Function} [cb] Optional callback with the data.
	 */
	function fetchStatus( cb ) {
		$.post( settings.ajaxUrl, {
			action: 'ilb_index_status',
			nonce: settings.nonce
		} ).done( function ( response ) {
			if ( response && response.success ) {
				renderStatus( response.data );
				if ( cb ) {
					cb( response.data );
				}
			}
		} );
	}

	/**
	 * Runs one generation step and chains to the next until done.
	 *
	 * @param {Object} state  Current {phase, offset} (null to begin).
	 * @param {jQuery} $button The generate button.
	 */
	function generationStep( state, $button ) {
		var payload = {
			action: 'ilb_run_generation',
			nonce: settings.nonce,
			step: state ? 'continue' : 'begin'
		};
		if ( state ) {
			payload.phase = state.phase;
			payload.offset = state.offset;
		}

		$.post( settings.ajaxUrl, payload ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				finishGeneration( $button );
				return;
			}
			var data = response.data;
			showProgress( data.processed, data.total );
			$( '.ilb-index-status .ilb-stat-state' ).text( i18n.running || 'Generating…' );

			if ( data.done ) {
				finishGeneration( $button );
				return;
			}
			generationStep( { phase: data.phase, offset: data.offset }, $button );
		} ).fail( function () {
			finishGeneration( $button );
		} );
	}

	/**
	 * Restores the UI after a generation run.
	 *
	 * @param {jQuery} $button The generate button.
	 */
	function finishGeneration( $button ) {
		$button.prop( 'disabled', false );
		$( '.ilb-index-status .ilb-stat-state' ).text( i18n.complete || 'Done' );
		fetchStatus();
	}

	/**
	 * Wires up the status panel on the Actions tab.
	 */
	function initIndexStatus() {
		var $root = $( '.ilb-index-status' );
		if ( ! $root.length ) {
			return;
		}

		fetchStatus();

		// Poll while a background generation is running.
		if ( '1' === String( $root.data( 'running' ) ) ) {
			statusPoll = window.setInterval( function () {
				fetchStatus( function ( data ) {
					if ( ! data.running && statusPoll ) {
						window.clearInterval( statusPoll );
						statusPoll = null;
					}
				} );
			}, 3000 );
		}

		$root.on( 'click', '.ilb-generate-button', function ( e ) {
			e.preventDefault();
			var $button = $( this );
			$button.prop( 'disabled', true );
			$( '.ilb-progress' ).prop( 'hidden', false );
			generationStep( null, $button );
		} );
	}
} )( jQuery );
