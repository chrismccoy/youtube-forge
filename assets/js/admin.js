/**
 * YouTube Forge admin.
 *
 * Handles the scan (one chunk per request, live terminal log), the API key and
 * post type settings, and the report Trash actions
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var scanBtn    = document.getElementById( 'ytf-scan' );
		var output     = document.getElementById( 'ytf-output' );
		var terminal   = document.getElementById( 'ytf-terminal' );
		var counts     = document.getElementById( 'ytf-counts' );
		var reportBox  = document.getElementById( 'ytf-report' );
		var dismissBtn = document.getElementById( 'ytf-dismiss' );

		/**
		 * GET JSON from a REST route with the nonce header.
		 */
		function get( url, params ) {
			var query = [];
			var key;
			for ( key in params ) {
				if ( Object.prototype.hasOwnProperty.call( params, key ) ) {
					query.push( encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] ) );
				}
			}
			var sep = ( url.indexOf( '?' ) === -1 ) ? '?' : '&';
			return fetch( url + ( query.length ? sep + query.join( '&' ) : '' ), {
				method: 'GET',
				headers: {
					'X-WP-Nonce': YTF.nonce
				}
			} ).then( function ( r ) {
				return r.json();
			} );
		}

		/**
		 * POST JSON to a REST route with the nonce header.
		 */
		function post( url, body ) {
			return fetch( url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': YTF.nonce
				},
				body: JSON.stringify( body )
			} ).then( function ( r ) {
				return r.json();
			} );
		}

		/**
		 * Only http(s) URLs become links. The report is built from data now, but
		 * a URL still ends up in an href, and that is the one place a stored
		 * value could still do something if it were, say, a javascript: URI.
		 */
		function safeUrl( url ) {
			var value = String( url === null || url === undefined ? '' : url ).trim();
			return /^https?:\/\//i.test( value ) ? value : '';
		}

		/**
		 * Build an element. Text is always set with textContent, never innerHTML.
		 */
		function el( tag, className, text ) {
			var node = document.createElement( tag );
			if ( className ) {
				node.className = className;
			}
			if ( text !== undefined && text !== null && text !== '' ) {
				node.textContent = text;
			}
			return node;
		}

		/**
		 * One column of a row, holding the per link values stacked in step with
		 * the other two stacked columns.
		 */
		function stackCell( className, values, build ) {
			var cell = el( 'td', className );
			values.forEach( function ( value ) {
				var item = el( 'div', 'ytf-stack-item' );
				var inner = build( value );
				if ( inner ) {
					item.appendChild( inner );
				}
				cell.appendChild( item );
			} );
			return cell;
		}

		/**
		 * The Actions cell: a state badge for a post that has since gone, or the
		 * View, Edit, and Trash controls.
		 */
		function actionsCell( row ) {
			var cell = el( 'td', 'ytf-actions' );

			if ( row.state === 'trashed' || row.state === 'deleted' ) {
				cell.appendChild( el(
					'span',
					'ytf-trashed-label ytf-state-' + row.state,
					row.state === 'trashed' ? YTF.i18n.trashedLabel : YTF.i18n.deletedLabel
				) );
				return cell;
			}

			var viewUrl = safeUrl( row.viewUrl );
			if ( viewUrl ) {
				var view = el( 'a', 'button button-small', YTF.i18n.viewLabel );
				view.href = viewUrl;
				view.target = '_blank';
				view.rel = 'noopener';
				cell.appendChild( view );
				cell.appendChild( document.createTextNode( ' ' ) );
			}

			var editUrl = safeUrl( row.editUrl );
			if ( editUrl ) {
				var edit = el( 'a', 'button button-primary button-small', YTF.i18n.editLabel );
				edit.href = editUrl;
				cell.appendChild( edit );
				cell.appendChild( document.createTextNode( ' ' ) );
			}

			var trash = el( 'button', 'button button-small ytf-trash', YTF.i18n.trashLabel );
			trash.type = 'button';
			trash.setAttribute( 'data-post', row.postID );
			trash.setAttribute( 'data-count', row.count );
			cell.appendChild( trash );

			return cell;
		}

		/**
		 * One table row: a post and every broken link found in it.
		 */
		function reportRow( row, withActions ) {
			var tr = el( 'tr' );
			tr.setAttribute( 'data-post', row.postID );
			tr.setAttribute( 'data-links', row.count );

			var links = row.links || [];

			tr.appendChild( stackCell( null, links, function ( link ) {
				return el( 'span', 'ytf-status', link.status );
			} ) );

			tr.appendChild( stackCell( 'ytf-url', links, function ( link ) {
				var url = safeUrl( link.url );
				if ( ! url ) {
					return document.createTextNode( String( link.url || '' ) );
				}
				var a = el( 'a', null, link.url );
				a.href = url;
				a.target = '_blank';
				a.rel = 'noopener';
				return a;
			} ) );

			tr.appendChild( stackCell( null, links, function ( link ) {
				return document.createTextNode( String( link.location || '' ) );
			} ) );

			var titleCell = el( 'td', null, row.title );
			if ( row.multiText ) {
				titleCell.appendChild( document.createTextNode( ' ' ) );
				titleCell.appendChild( el( 'span', 'ytf-multi', row.multiText ) );
			}
			tr.appendChild( titleCell );

			tr.appendChild( el( 'td', 'ytf-col-type', row.typeLabel ) );

			if ( withActions ) {
				tr.appendChild( actionsCell( row ) );
			}

			return tr;
		}

		/**
		 * The results table, headings and all.
		 */
		function reportTable( data ) {
			var table = el( 'table', 'wp-list-table widefat striped ytf-report-table' );
			var thead = el( 'thead' );
			var headRow = el( 'tr' );
			var cols = data.columns || {};

			[
				[ cols.status, null ],
				[ cols.url, null ],
				[ cols.location, null ],
				[ cols.title, null ],
				[ cols.type, 'ytf-col-type' ]
			].forEach( function ( pair ) {
				var th = el( 'th', pair[ 1 ], pair[ 0 ] );
				th.setAttribute( 'scope', 'col' );
				headRow.appendChild( th );
			} );

			if ( data.withActions ) {
				var actionsTh = el( 'th', 'ytf-col-actions', cols.actions );
				actionsTh.setAttribute( 'scope', 'col' );
				headRow.appendChild( actionsTh );
			}

			thead.appendChild( headRow );
			table.appendChild( thead );

			var tbody = el( 'tbody' );
			( data.rows || [] ).forEach( function ( row ) {
				tbody.appendChild( reportRow( row, data.withActions ) );
			} );
			table.appendChild( tbody );

			return table;
		}

		/**
		 * The "Of the links found: N trashed, N deleted." line. The counts sit in
		 * their own spans so trashing a row can update them in place, so the
		 * translated sentence is split around its two placeholders.
		 */
		function handledLine( handled ) {
			var p = el( 'p' );
			var parts = String( handled.template || '%1$s %2$s' ).split( '%1$s' );
			var tail = ( parts[ 1 ] || '' ).split( '%2$s' );

			p.appendChild( document.createTextNode( parts[ 0 ] || '' ) );
			p.appendChild( el( 'span', 'ytf-body-trashed', String( handled.trashed ) ) );
			p.appendChild( document.createTextNode( ' ' + ( handled.trashedLabel || '' ) ) );
			p.appendChild( document.createTextNode( tail[ 0 ] || '' ) );
			p.appendChild( el( 'span', 'ytf-body-deleted', String( handled.deleted ) ) );
			p.appendChild( document.createTextNode( ' ' + ( handled.deletedLabel || '' ) ) );
			p.appendChild( document.createTextNode( tail[ 1 ] || '' ) );

			return p;
		}

		/**
		 * Build a whole report into a container, replacing whatever was there.
		 * Everything is created as nodes and set with textContent: no markup
		 * comes back from the server, so none is parsed here.
		 */
		function renderReport( container, data ) {
			container.textContent = '';

			if ( ! data ) {
				return;
			}

			var frag = document.createDocumentFragment();

			if ( data.startedText ) {
				frag.appendChild( el( 'p', null, data.startedText ) );
			}

			var rows = data.rows || [];

			if ( rows.length && data.withActions ) {
				var toolbar = el( 'p', 'ytf-report-actions' );
				var trashAll = el( 'button', 'button button-small ytf-trash-all', data.trashAllText );
				trashAll.type = 'button';
				toolbar.appendChild( trashAll );
				toolbar.appendChild( document.createTextNode( ' ' ) );
				toolbar.appendChild( el( 'span', 'ytf-trash-all-status' ) );
				frag.appendChild( toolbar );
			}

			if ( rows.length ) {
				frag.appendChild( reportTable( data ) );
			}

			if ( data.endedText ) {
				frag.appendChild( el( 'p', null, data.endedText ) );
			}

			if ( data.summaryText ) {
				var summary = el( 'p' );
				summary.appendChild( el( 'strong', null, data.summaryText ) );
				frag.appendChild( summary );
			}

			if ( data.uncheckedText ) {
				frag.appendChild( el( 'p', null, data.uncheckedText ) );
			}

			if ( data.handled ) {
				frag.appendChild( handledLine( data.handled ) );
			}

			if ( data.resolved ) {
				var resolved = el( 'p', 'ytf-resolved-line' + ( data.resolved.visible ? '' : ' is-hidden' ) );
				resolved.appendChild( el( 'span', 'ytf-resolved', data.resolved.text ) );
				frag.appendChild( resolved );
			}

			( data.notes || [] ).forEach( function ( note ) {
				var p = el( 'p' );
				p.appendChild( el( 'em', null, note ) );
				frag.appendChild( p );
			} );

			if ( data.errorsText ) {
				frag.appendChild( el( 'h3', null, data.errorsHeading ) );
				var errors = el( 'p' );
				data.errorsText.split( '\n' ).forEach( function ( line, index ) {
					if ( index ) {
						errors.appendChild( document.createElement( 'br' ) );
					}
					errors.appendChild( document.createTextNode( line ) );
				} );
				frag.appendChild( errors );
			}

			container.appendChild( frag );
		}

		/**
		 * Show a styled message modal with a single dismiss button. Used for
		 * failures, so errors land in the same place as every other message
		 * rather than in a browser alert.
		 */
		function showAlert( message ) {
			return showConfirm( message, null, null, true );
		}

		/**
		 * Show a styled confirm modal, optionally listing affected URLs. With
		 * noticeOnly the Cancel button is left out and the modal is a message.
		 */
		function showConfirm( message, urls, confirmLabel, noticeOnly ) {
			return new Promise( function ( resolve ) {
				var overlay = document.createElement( 'div' );
				overlay.className = 'ytf-modal-overlay';

				var box = document.createElement( 'div' );
				box.className = 'ytf-modal';
				box.setAttribute( 'role', 'dialog' );
				box.setAttribute( 'aria-modal', 'true' );

				var p = document.createElement( 'p' );
				p.textContent = message;
				box.appendChild( p );

				if ( urls && urls.length ) {
					var ul = document.createElement( 'ul' );
					ul.className = 'ytf-modal-urls';
					urls.forEach( function ( u ) {
						var li = document.createElement( 'li' );
						li.textContent = u;
						ul.appendChild( li );
					} );
					box.appendChild( ul );
				}

				var actions = document.createElement( 'div' );
				actions.className = 'ytf-modal-actions';

				var cancel = document.createElement( 'button' );
				cancel.type = 'button';
				cancel.className = 'button';
				cancel.textContent = YTF.i18n.cancel;

				var del = document.createElement( 'button' );
				del.type = 'button';
				del.className = noticeOnly ? 'button' : 'button ytf-modal-delete';
				del.textContent = noticeOnly
					? YTF.i18n.close
					: ( confirmLabel || YTF.i18n.deleteLabel );

				if ( ! noticeOnly ) {
					actions.appendChild( cancel );
				}
				actions.appendChild( del );
				box.appendChild( actions );
				overlay.appendChild( box );
				document.body.appendChild( overlay );
				del.focus();

				/**
				 * Remove the modal and resolve the promise.
				 */
				function close( value ) {
					document.removeEventListener( 'keydown', onKey );
					if ( overlay.parentNode ) {
						overlay.parentNode.removeChild( overlay );
					}
					resolve( value );
				}

				/**
				 * Close the modal as cancelled when Escape is pressed.
				 */
				function onKey( ev ) {
					if ( ev.key === 'Escape' ) {
						close( false );
					}
				}
				cancel.addEventListener( 'click', function () {
					close( false );
				} );
				del.addEventListener( 'click', function () {
					close( true );
				} );
				overlay.addEventListener( 'click', function ( ev ) {
					if ( ev.target === overlay ) {
						close( false );
					}
				} );
				document.addEventListener( 'keydown', onKey );
			} );
		}

		/**
		 * Recount a Logs table after a row was trashed and update its summary
		 * numbers (trashed, deleted) in place
		 */
		function updateLogSummary( table ) {
			var details = table.closest ? table.closest( 'details' ) : null;
			var scope = details || table;

			function sumLinks( sel ) {
				var found = table.querySelectorAll( sel );
				var total = 0;
				var i, tr;
				for ( i = 0; i < found.length; i++ ) {
					tr = found[ i ].closest( 'tr' );
					total += tr ? ( parseInt( tr.getAttribute( 'data-links' ), 10 ) || 1 ) : 1;
				}
				return total;
			}

			function setText( sel, value ) {
				var el = scope.querySelector( sel );
				if ( el ) {
					el.textContent = value;
				}
			}
			setText( '.ytf-body-trashed', sumLinks( '.ytf-state-trashed' ) );
			setText( '.ytf-body-deleted', sumLinks( '.ytf-state-deleted' ) );

			var resolvedEl = scope.querySelector( '.ytf-resolved-line' );
			if ( resolvedEl ) {
				resolvedEl.classList.toggle( 'is-hidden', table.querySelectorAll( '.ytf-trash' ).length > 0 );
			}
		}

		document.addEventListener( 'click', function ( e ) {
			var btn = ( e.target && e.target.closest ) ? e.target.closest( '.ytf-trash' ) : null;
			if ( ! btn ) {
				return;
			}
			e.preventDefault();
			var postId = parseInt( btn.getAttribute( 'data-post' ), 10 );
			if ( ! postId ) {
				return;
			}

			var scopeRow = btn.closest( 'tr' );
			var urls = [];
			if ( scopeRow ) {
				var urlLinks = scopeRow.querySelectorAll( '.ytf-url a' );
				var u;
				for ( u = 0; u < urlLinks.length; u++ ) {
					urls.push( urlLinks[ u ].textContent );
				}
			}
			var count = urls.length || parseInt( btn.getAttribute( 'data-count' ), 10 ) || 1;
			var message = ( count === 1 )
				? YTF.i18n.trashRemoveOne
				: YTF.i18n.trashRemoveMany.replace( '%d', count );

			showConfirm( message, urls, YTF.i18n.trashLabel ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				btn.disabled = true;
				var inLogs = !! ( btn.closest && btn.closest( '#ytf-logs-list' ) );
				post( YTF.restTrash, { postID: postId } ).then( function ( res ) {
					if ( res && res.ok ) {
						var buttons = document.querySelectorAll( '.ytf-trash[data-post="' + postId + '"]' );
						var tables = [];
						var i, tr, tbl, cell;
						for ( i = 0; i < buttons.length; i++ ) {
							tbl = buttons[ i ].closest( 'table' );
							if ( tbl && tables.indexOf( tbl ) === -1 ) {
								tables.push( tbl );
							}
							if ( inLogs ) {
								cell = buttons[ i ].closest( 'td' );
								if ( cell ) {
									cell.textContent = '';
									cell.appendChild( el( 'span', 'ytf-trashed-label ytf-state-trashed', YTF.i18n.trashedLabel ) );
								}
							} else {
								tr = buttons[ i ].closest( 'tr' );
								if ( tr && tr.parentNode ) {
									tr.parentNode.removeChild( tr );
								}
							}
						}
						for ( i = 0; i < tables.length; i++ ) {
							if ( inLogs ) {
								updateLogSummary( tables[ i ] );
							} else if ( ! tables[ i ].querySelector( 'tbody tr' ) && tables[ i ].parentNode ) {
								tables[ i ].parentNode.removeChild( tables[ i ] );
							}
						}
					} else {
						btn.disabled = false;
						showAlert( ( res && res.message ) ? res.message : YTF.i18n.trashError );
					}
				} ).catch( function () {
					btn.disabled = false;
					showAlert( YTF.i18n.trashError );
				} );
			} );
		} );

		document.addEventListener( 'click', function ( e ) {
			var btn = ( e.target && e.target.closest ) ? e.target.closest( '.ytf-trash-all' ) : null;
			if ( ! btn ) {
				return;
			}
			e.preventDefault();

			var scope = btn.closest( 'details' ) || document.getElementById( 'ytf-report' ) || document;
			var table = scope.querySelector ? scope.querySelector( '.ytf-report-table' ) : null;
			if ( ! table ) {
				return;
			}
			var inLogs = !! ( btn.closest && btn.closest( '#ytf-logs-list' ) );
			var statusEl = scope.querySelector ? scope.querySelector( '.ytf-trash-all-status' ) : null;

			var trashBtns = table.querySelectorAll( '.ytf-trash[data-post]' );
			var seen = {};
			var ids = [];
			var i;
			for ( i = 0; i < trashBtns.length; i++ ) {
				var pid = parseInt( trashBtns[ i ].getAttribute( 'data-post' ), 10 );
				if ( pid && ! seen[ pid ] ) {
					seen[ pid ] = 1;
					ids.push( pid );
				}
			}
			if ( ! ids.length ) {
				return;
			}

			showConfirm( YTF.i18n.confirmTrashAll.replace( '%d', ids.length ), null, YTF.i18n.trashLabel ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				btn.disabled = true;
				var batchSize = parseInt( YTF.trashBatch, 10 ) || 50;
				var total = ids.length;
				var done = 0;
				var skipped = 0;
				var affected = [];

				function setStatus() {
					if ( statusEl ) {
						statusEl.textContent = YTF.i18n.trashAllProgress.replace( '%1$d', done ).replace( '%2$d', total );
					}
				}
				setStatus();

				function applyTrashed( list ) {
					var a, b;
					for ( a = 0; a < list.length; a++ ) {
						var sel = '.ytf-trash[data-post="' + list[ a ] + '"]';
						var rows = inLogs ? document.querySelectorAll( sel ) : table.querySelectorAll( sel );
						for ( b = 0; b < rows.length; b++ ) {
							if ( inLogs ) {
								var owner = rows[ b ].closest( 'table' );
								if ( owner && affected.indexOf( owner ) === -1 ) {
									affected.push( owner );
								}
								var cell = rows[ b ].closest( 'td' );
								if ( cell ) {
									cell.textContent = '';
									cell.appendChild( el( 'span', 'ytf-trashed-label ytf-state-trashed', YTF.i18n.trashedLabel ) );
								}
							} else {
								var tr = rows[ b ].closest( 'tr' );
								if ( tr && tr.parentNode ) {
									tr.parentNode.removeChild( tr );
								}
							}
						}
					}
				}

				function finish() {
					if ( inLogs ) {
						var t;
						for ( t = 0; t < affected.length; t++ ) {
							updateLogSummary( affected[ t ] );
						}
						btn.disabled = false;
					} else if ( ! table.querySelector( 'tbody tr' ) ) {
						var toolbar = btn.closest( '.ytf-report-actions' );
						if ( table.parentNode ) {
							table.parentNode.removeChild( table );
						}
						if ( toolbar && toolbar.parentNode ) {
							toolbar.parentNode.removeChild( toolbar );
						}
					} else {
						btn.disabled = false;
					}
					if ( statusEl ) {
						statusEl.textContent = skipped
							? YTF.i18n.trashAllSkipped.replace( '%d', skipped )
							: '';
					}
				}

				function nextBatch( index ) {
					if ( index >= total ) {
						finish();
						return;
					}
					var batch = ids.slice( index, index + batchSize );
					post( YTF.restTrashBulk, { postIDs: batch } ).then( function ( res ) {
						if ( res && res.ok ) {
							var trashed = res.trashed || [];
							applyTrashed( trashed );
							done += trashed.length;
							skipped += ( batch.length - trashed.length );
							setStatus();
							nextBatch( index + batchSize );
						} else {
							btn.disabled = false;
							if ( statusEl ) {
								statusEl.textContent = YTF.i18n.trashAllError;
							}
						}
					} ).catch( function () {
						btn.disabled = false;
						if ( statusEl ) {
							statusEl.textContent = YTF.i18n.trashAllError;
						}
					} );
				}
				nextBatch( 0 );
			} );
		} );

		/**
		 * Load a saved scan's report the first time its entry is opened.
		 */
		var logsList = document.getElementById( 'ytf-logs-list' );
		if ( logsList ) {
			logsList.addEventListener( 'toggle', function ( e ) {
				var details = e.target;
				if ( ! details || details.tagName !== 'DETAILS' || ! details.open ) {
					return;
				}

				var body = details.querySelector( '.ytf-log-body' );
				loadLogReport( body );
			}, true );

			var openBodies = logsList.querySelectorAll( 'details[open] .ytf-log-body' );
			var b;
			for ( b = 0; b < openBodies.length; b++ ) {
				loadLogReport( openBodies[ b ] );
			}
		}

		/**
		 * Fetch and render one saved scan, once.
		 */
		function loadLogReport( body ) {
			if ( ! body || body.getAttribute( 'data-loaded' ) ) {
				return;
			}
			body.setAttribute( 'data-loaded', '1' );

			get( YTF.restLogReport, { index: body.getAttribute( 'data-index' ) } ).then( function ( r ) {
				if ( r && r.report ) {
					renderReport( body, r.report );
				}
			} ).catch( function () {
				body.removeAttribute( 'data-loaded' );
			} );
		}

		var clearLogsBtn = document.getElementById( 'ytf-clear-logs' );
		if ( clearLogsBtn ) {
			clearLogsBtn.addEventListener( 'click', function () {
				showConfirm( YTF.i18n.confirmClear ).then( function ( ok ) {
					if ( ! ok ) {
						return;
					}
					clearLogsBtn.disabled = true;
					post( YTF.restClearLogs, {} ).then( function ( res ) {
						if ( res && res.ok ) {
							var list = document.getElementById( 'ytf-logs-list' );
							if ( list && list.parentNode ) {
								var msg = document.createElement( 'p' );
								msg.textContent = YTF.i18n.noScans;
								list.parentNode.replaceChild( msg, list );
							}
							if ( clearLogsBtn.parentNode ) {
								clearLogsBtn.parentNode.removeChild( clearLogsBtn );
							}
						} else {
							clearLogsBtn.disabled = false;
							showAlert( YTF.i18n.clearError );
						}
					} ).catch( function () {
						clearLogsBtn.disabled = false;
						showAlert( YTF.i18n.clearError );
					} );
				} );
			} );
		}

		/**
		 * Render the key status list (name: verified or invalid) into a container.
		 */
		function renderHealth( container, statuses ) {
			container.innerHTML = '';
			Object.keys( statuses ).forEach( function ( name ) {
				var ok  = statuses[ name ];
				var div = document.createElement( 'div' );
				div.textContent = name + ': ' + ( ok ? YTF.i18n.verified : YTF.i18n.invalid );
				div.className = ok ? 'ytf-ok' : 'ytf-bad';
				container.appendChild( div );
			} );
		}

		/**
		 * Save and Verify button to the save setting route.
		 */
		function bindSetting( btnId, inputId, statusId, healthId, savedId ) {
			var btn    = document.getElementById( btnId );
			var input  = document.getElementById( inputId );
			var status = document.getElementById( statusId );
			var health = healthId ? document.getElementById( healthId ) : null;
			var saved  = savedId ? document.getElementById( savedId ) : null;
			if ( ! btn || ! input || ! status ) {
				return;
			}
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				status.textContent = '';
				status.className = 'ytf-setting-status';
				if ( health ) {
					health.innerHTML = '';
				}
				post( YTF.restSetting, { field: btn.getAttribute( 'data-field' ), value: input.value.trim() } ).then( function ( r ) {
					btn.disabled = false;
					if ( health && r && r.statuses ) {
						renderHealth( health, r.statuses );
					}
					if ( ! r || ! r.ok ) {
						status.textContent = ( r && r.error ) ? r.error : YTF.i18n.error;
						status.className = 'ytf-setting-status ytf-bad';
						return;
					}
					status.textContent = '';
					status.className = 'ytf-setting-status';
					input.value = '';
					if ( saved ) {
						saved.textContent = r.valueMask
							? ( saved.getAttribute( 'data-label' ) + ' ' + r.valueMask )
							: saved.getAttribute( 'data-none' );
					}
				} ).catch( function () {
					btn.disabled = false;
					status.textContent = YTF.i18n.error;
					status.className = 'ytf-setting-status ytf-bad';
				} );
			} );
		}

		bindSetting( 'ytf-key-save', 'ytf-key', 'ytf-key-status', 'ytf-key-health', 'ytf-key-saved' );

		var ptSaveBtn  = document.getElementById( 'ytf-pt-save' );
		var ptResetBtn = document.getElementById( 'ytf-pt-reset' );

		/**
		 * Post the given post type slugs, then the saved list and status.
		 */
		function savePostTypes( types ) {
			var ptStatus = document.getElementById( 'ytf-pt-status' );
			var i;
			if ( ptSaveBtn ) {
				ptSaveBtn.disabled = true;
			}
			if ( ptResetBtn ) {
				ptResetBtn.disabled = true;
			}
			if ( ptStatus ) {
				ptStatus.textContent = '';
				ptStatus.className = 'ytf-setting-status';
			}
			post( YTF.restPostTypes, { types: types } ).then( function ( r ) {
				if ( ptSaveBtn ) {
					ptSaveBtn.disabled = false;
				}
				if ( ptResetBtn ) {
					ptResetBtn.disabled = false;
				}
				if ( ! r || ! r.ok ) {
					if ( ptStatus ) {
						ptStatus.textContent = YTF.i18n.error;
						ptStatus.className = 'ytf-setting-status ytf-bad';
					}
					return;
				}
				var saved = r.types || [];
				var all = document.querySelectorAll( '.ytf-pt' );
				for ( i = 0; i < all.length; i++ ) {
					all[ i ].checked = saved.indexOf( all[ i ].value ) !== -1;
				}
				if ( ptStatus ) {
					ptStatus.textContent = YTF.i18n.saved;
					ptStatus.className = 'ytf-setting-status';
					setTimeout( function () {
						if ( ptStatus.textContent === YTF.i18n.saved ) {
							ptStatus.textContent = '';
						}
					}, 2500 );
				}
			} ).catch( function () {
				if ( ptSaveBtn ) {
					ptSaveBtn.disabled = false;
				}
				if ( ptResetBtn ) {
					ptResetBtn.disabled = false;
				}
				if ( ptStatus ) {
					ptStatus.textContent = YTF.i18n.error;
					ptStatus.className = 'ytf-setting-status ytf-bad';
				}
			} );
		}

		if ( ptSaveBtn ) {
			ptSaveBtn.addEventListener( 'click', function () {
				var boxes = document.querySelectorAll( '.ytf-pt:checked' );
				var types = [];
				var i;
				for ( i = 0; i < boxes.length; i++ ) {
					types.push( boxes[ i ].value );
				}
				savePostTypes( types );
			} );
		}

		if ( ptResetBtn ) {
			ptResetBtn.addEventListener( 'click', function () {
				savePostTypes( [ 'post' ] );
			} );
		}

		var resetBtn = document.getElementById( 'ytf-reset' );
		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function () {
				showConfirm( YTF.i18n.confirmReset ).then( function ( ok ) {
					if ( ! ok ) {
						return;
					}
					var resetStatus = document.getElementById( 'ytf-reset-status' );
					resetBtn.disabled = true;
					post( YTF.restReset, {} ).then( function ( res ) {
						resetBtn.disabled = false;
						if ( ! res || ! res.ok ) {
							if ( resetStatus ) {
								resetStatus.textContent = YTF.i18n.resetError;
								resetStatus.className = 'ytf-setting-status ytf-bad';
							}
							return;
						}
						var savedLine = document.getElementById( 'ytf-key-saved' );
						if ( savedLine ) {
							savedLine.textContent = savedLine.getAttribute( 'data-none' );
						}
						var keyInput = document.getElementById( 'ytf-key' );
						if ( keyInput ) {
							keyInput.value = '';
						}
						var keyStatus = document.getElementById( 'ytf-key-status' );
						if ( keyStatus ) {
							keyStatus.textContent = '';
							keyStatus.className = 'ytf-setting-status';
						}
						var keyHealth = document.getElementById( 'ytf-key-health' );
						if ( keyHealth ) {
							keyHealth.innerHTML = '';
						}
						if ( resetStatus ) {
							resetStatus.textContent = '';
							resetStatus.className = 'ytf-setting-status';
						}
					} ).catch( function () {
						resetBtn.disabled = false;
						if ( resetStatus ) {
							resetStatus.textContent = YTF.i18n.resetError;
							resetStatus.className = 'ytf-setting-status ytf-bad';
						}
					} );
				} );
			} );
		}

		if ( ! terminal ) {
			return;
		}

		var cursor  = 0;
		var polling = false;
		var scanRun = 0;

		/**
		 * Show or hide the terminal and report. Hidden until a scan starts.
		 */
		function showOutput( show ) {
			if ( output ) {
				output.style.display = show ? '' : 'none';
			}
		}

		/**
		 * Append log lines to the terminal, color them by prefix, then scroll to
		 * the newest line.
		 */
		function append( lines ) {
			var i, line, div;
			for ( i = 0; i < lines.length; i++ ) {
				line = lines[ i ];
				div  = document.createElement( 'div' );
				div.textContent = line;
				if ( line.indexOf( '[FOUND]' ) === 0 ) {
					div.className = 'ytf-line-ok';
				} else if ( line.indexOf( '[API ERROR]' ) === 0 || line.indexOf( '[TIME LIMIT]' ) === 0 || line.indexOf( '[RETRY]' ) === 0 ) {
					div.className = 'ytf-line-warn';
				} else if ( line.charAt( 0 ) === '[' ) {
					div.className = 'ytf-line-broken';
				} else if ( line.indexOf( 'complete' ) !== -1 ) {
					div.className = 'ytf-line-done';
				}
				terminal.appendChild( div );
			}
			terminal.scrollTop = terminal.scrollHeight;
		}

		/**
		 * Update the counts line from a scan.
		 */
		function setCounts( s ) {
			var text = s.total + ' posts, ' + s.checked + ' checked, ' + s.broken + ' broken';
			if ( s.unchecked ) {
				text += ', ' + s.unchecked + ' unchecked';
			}
			counts.textContent = text;
		}

		/**
		 * Apply a scan list to the page: append new log lines, update counts,
		 * and on completion render the report and show Dismiss.
		 */
		function handle( s ) {
			if ( s.log && s.log.length ) {
				append( s.log );
			}
			cursor = s.cursor;
			setCounts( s );
			if ( s.done ) {
				polling = false;
				scanBtn.disabled = false;
				if ( dismissBtn ) {
					dismissBtn.style.display = '';
				}
				if ( s.report ) {
					var forRun = scanRun;
					get( YTF.restScanReport ).then( function ( r ) {
						if ( forRun !== scanRun ) {
							return;
						}
						if ( r && r.report ) {
							renderReport( reportBox, r.report );
						}
					} ).catch( function () {} );
				}
			}
		}

		/**
		 * Advance the scan by one batch, then schedule the next one while the scan is still running.
		 */
		function poll() {
			if ( ! polling ) {
				return;
			}
			post( YTF.restScan, { action: 'next', cursor: cursor } ).then( function ( s ) {
				handle( s );
				if ( s.status === 'running' ) {
					setTimeout( poll, 500 );
				}
			} ).catch( function () {
				polling = false;
				scanBtn.disabled = false;
				append( [ YTF.i18n.error ] );
			} );
		}

		var scanMsg = document.getElementById( 'ytf-scan-msg' );

		/**
		 * Show a scan message (an error) above the output, or clear it.
		 */
		function setScanMsg( text ) {
			if ( scanMsg ) {
				scanMsg.textContent = text || '';
				scanMsg.className = text ? 'ytf-scan-msg ytf-bad' : 'ytf-scan-msg';
			}
		}

		if ( scanBtn ) {
			scanBtn.addEventListener( 'click', function () {
				if ( polling ) {
					return;
				}
				setScanMsg( '' );
				scanBtn.disabled = true;
				post( YTF.restScan, { action: 'start', cursor: 0 } ).then( function ( s ) {
					if ( s.error ) {
						setScanMsg( s.error );
						scanBtn.disabled = false;
						return;
					}
					scanRun++;
					terminal.textContent = '';
					reportBox.textContent = '';
					if ( dismissBtn ) {
						dismissBtn.style.display = 'none';
					}
					cursor = 0;
					showOutput( true );
					handle( s );
					polling = true;
					poll();
				} ).catch( function () {
					setScanMsg( YTF.i18n.error );
					scanBtn.disabled = false;
				} );
			} );
		}

		if ( dismissBtn ) {
			dismissBtn.addEventListener( 'click', function () {
				if ( polling ) {
					return;
				}
				scanRun++;
				terminal.textContent = '';
				reportBox.textContent = '';
				counts.textContent   = '';
				setScanMsg( '' );
				dismissBtn.style.display = 'none';
				showOutput( false );
			} );
		}

		get( YTF.restScanState, { cursor: 0 } ).then( function ( s ) {
			if ( ! s ) {
				return;
			}
			if ( s.done ) {
				showOutput( true );
				handle( s );
				return;
			}
			if ( s.status !== 'running' ) {
				return;
			}
			showOutput( true );
			handle( s );
			scanBtn.disabled = true;
			polling = true;
			poll();
		} ).catch( function () {} );
	} );
}() );
