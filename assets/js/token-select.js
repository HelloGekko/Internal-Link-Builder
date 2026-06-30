/**
 * Internal Link Builder - token / chip multi-select with typeahead.
 *
 * Enhances every .ilb-token container. Supports three modes via data-mode:
 *  - static:   pick from a fixed option list (data-options), filtered client-side
 *  - ajax:     search posts/terms by name through admin-ajax (data-source)
 *  - freeform: type arbitrary tags (e.g. meta keys) and press Enter
 *
 * The selection is submitted as hidden inputs named "<data-name>[]".
 */
( function ( $ ) {
	'use strict';

	var config = window.ilbToken || {};
	var i18n = config.i18n || {};

	/**
	 * Initialises one token field.
	 *
	 * @param {HTMLElement} el Container element.
	 */
	function TokenField( el ) {
		this.$root = $( el );
		this.name = this.$root.data( 'name' );
		this.mode = this.$root.data( 'mode' ) || 'freeform';
		this.source = this.$root.data( 'source' ) || '';
		this.placeholder = this.$root.data( 'placeholder' ) || '';
		this.options = this.parseJSON( this.$root.attr( 'data-options' ) );
		this.selected = []; // list of { id, text }
		this.selectedIds = {}; // id (string) => true
		this.searchTimer = null;

		this.build();

		var seed = this.parseJSON( this.$root.attr( 'data-selected' ) );
		for ( var i = 0; i < seed.length; i++ ) {
			this.addToken( seed[ i ], true );
		}
	}

	TokenField.prototype.parseJSON = function ( raw ) {
		if ( ! raw ) {
			return [];
		}
		try {
			var parsed = JSON.parse( raw );
			return $.isArray( parsed ) ? parsed : [];
		} catch ( e ) {
			return [];
		}
	};

	TokenField.prototype.build = function () {
		this.$root.empty().addClass( 'ilb-token-ready' );
		this.$list = $( '<div class="ilb-token-list"></div>' ).appendTo( this.$root );
		this.$input = $( '<input type="text" class="ilb-token-input" autocomplete="off" />' )
			.attr( 'placeholder', this.placeholder )
			.appendTo( this.$root );
		this.$suggest = $( '<div class="ilb-token-suggestions" hidden></div>' ).appendTo( this.$root );

		var self = this;

		this.$input.on( 'input', function () {
			self.onInput();
		} );

		this.$input.on( 'keydown', function ( e ) {
			self.onKeydown( e );
		} );

		this.$input.on( 'blur', function () {
			// Delay so a suggestion click registers first.
			setTimeout( function () {
				self.closeSuggestions();
			}, 150 );
		} );

		this.$root.on( 'mousedown', '.ilb-token-suggestion', function ( e ) {
			e.preventDefault();
			var item = $( this ).data( 'item' );
			self.addToken( item );
			self.$input.val( '' );
			self.closeSuggestions();
			self.$input.focus();
		} );

		this.$list.on( 'click', '.ilb-token-remove', function ( e ) {
			e.preventDefault();
			self.removeToken( $( this ).closest( '.ilb-token-chip' ).data( 'id' ) );
			self.$input.focus();
		} );
	};

	TokenField.prototype.onInput = function () {
		var term = $.trim( this.$input.val() );

		if ( 'freeform' === this.mode ) {
			this.closeSuggestions();
			return;
		}

		if ( '' === term ) {
			this.closeSuggestions();
			return;
		}

		if ( 'static' === this.mode ) {
			this.renderSuggestions( this.filterStatic( term ) );
			return;
		}

		// ajax (debounced)
		var self = this;
		clearTimeout( this.searchTimer );
		this.renderMessage( i18n.searching || 'Searching…' );
		this.searchTimer = setTimeout( function () {
			self.searchAjax( term );
		}, 250 );
	};

	TokenField.prototype.onKeydown = function ( e ) {
		// Enter
		if ( 13 === e.keyCode ) {
			e.preventDefault();
			var term = $.trim( this.$input.val() );

			if ( 'freeform' === this.mode ) {
				if ( '' !== term ) {
					this.addToken( { id: term, text: term } );
					this.$input.val( '' );
				}
				return;
			}

			var $first = this.$suggest.find( '.ilb-token-suggestion' ).first();
			if ( $first.length ) {
				this.addToken( $first.data( 'item' ) );
				this.$input.val( '' );
				this.closeSuggestions();
			}
			return;
		}

		// Backspace on empty input removes the last chip.
		if ( 8 === e.keyCode && '' === this.$input.val() && this.selected.length ) {
			this.removeToken( this.selected[ this.selected.length - 1 ].id );
		}
	};

	TokenField.prototype.filterStatic = function ( term ) {
		var lower = term.toLowerCase();
		var matches = [];
		for ( var i = 0; i < this.options.length; i++ ) {
			var opt = this.options[ i ];
			if ( this.selectedIds[ String( opt.id ) ] ) {
				continue;
			}
			if ( opt.text.toLowerCase().indexOf( lower ) !== -1 || String( opt.id ).toLowerCase().indexOf( lower ) !== -1 ) {
				matches.push( opt );
			}
		}
		return matches;
	};

	TokenField.prototype.searchAjax = function ( term ) {
		var self = this;
		var action = 'term' === this.source ? 'ilb_search_terms' : 'ilb_search_posts';

		$.get( config.ajaxUrl, {
			action: action,
			nonce: config.nonce,
			q: term
		} ).done( function ( response ) {
			var items = ( response && response.success && $.isArray( response.data ) ) ? response.data : [];
			var fresh = [];
			for ( var i = 0; i < items.length; i++ ) {
				if ( ! self.selectedIds[ String( items[ i ].id ) ] ) {
					fresh.push( items[ i ] );
				}
			}
			self.renderSuggestions( fresh );
		} ).fail( function () {
			self.closeSuggestions();
		} );
	};

	TokenField.prototype.renderSuggestions = function ( items ) {
		this.$suggest.empty();

		if ( ! items.length ) {
			this.renderMessage( i18n.noResults || 'No results' );
			return;
		}

		for ( var i = 0; i < items.length; i++ ) {
			$( '<div class="ilb-token-suggestion"></div>' )
				.text( items[ i ].text )
				.data( 'item', items[ i ] )
				.appendTo( this.$suggest );
		}
		this.$suggest.prop( 'hidden', false );
	};

	TokenField.prototype.renderMessage = function ( message ) {
		this.$suggest.empty();
		$( '<div class="ilb-token-message"></div>' ).text( message ).appendTo( this.$suggest );
		this.$suggest.prop( 'hidden', false );
	};

	TokenField.prototype.closeSuggestions = function () {
		this.$suggest.empty().prop( 'hidden', true );
	};

	TokenField.prototype.addToken = function ( item, silent ) {
		if ( ! item || item.id === undefined || item.id === '' ) {
			return;
		}
		var id = String( item.id );
		if ( this.selectedIds[ id ] ) {
			return;
		}

		this.selectedIds[ id ] = true;
		this.selected.push( { id: item.id, text: item.text } );

		var $chip = $( '<span class="ilb-token-chip"></span>' ).attr( 'data-id', id );
		$( '<button type="button" class="ilb-token-remove" aria-label="' + ( i18n.remove || 'Remove' ) + '">&times;</button>' ).appendTo( $chip );
		$( '<span class="ilb-token-text"></span>' ).text( item.text ).appendTo( $chip );
		$( '<input type="hidden" />' ).attr( 'name', this.name + '[]' ).val( item.id ).appendTo( $chip );

		this.$list.append( $chip );

		if ( ! silent ) {
			this.closeSuggestions();
		}
	};

	TokenField.prototype.removeToken = function ( id ) {
		id = String( id );
		if ( ! this.selectedIds[ id ] ) {
			return;
		}
		delete this.selectedIds[ id ];
		for ( var i = 0; i < this.selected.length; i++ ) {
			if ( String( this.selected[ i ].id ) === id ) {
				this.selected.splice( i, 1 );
				break;
			}
		}
		this.$list.find( '.ilb-token-chip' ).filter( function () {
			return String( $( this ).attr( 'data-id' ) ) === id;
		} ).remove();
	};

	$( function () {
		$( '.ilb-token' ).each( function () {
			if ( ! $( this ).hasClass( 'ilb-token-ready' ) ) {
				new TokenField( this );
			}
		} );
	} );
} )( jQuery );
