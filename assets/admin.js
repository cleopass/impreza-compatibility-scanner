/**
 * Impreza Compatibility Scanner — admin scan driver.
 *
 * Plain JavaScript (no jQuery). Flow:
 *   1. GET the front-end probe URL (same origin, uses the logged-in cookies) so the server can
 *      store the front-end shortcode registry. Failure is not fatal.
 *   2. POST ics_start, then POST ics_batch repeatedly until the scan is completed.
 *   3. Cancel posts ics_cancel; Resume continues with ics_batch from the saved cursor.
 */
( function () {
	'use strict';

	var cfg = window.icsAdmin;
	if ( ! cfg ) {
		return;
	}

	var el = {
		start: document.getElementById( 'ics-start' ),
		resume: document.getElementById( 'ics-resume' ),
		restart: document.getElementById( 'ics-restart' ),
		cancel: document.getElementById( 'ics-cancel' ),
		progress: document.getElementById( 'ics-progress' ),
		fill: document.getElementById( 'ics-progress-fill' ),
		text: document.getElementById( 'ics-progress-text' )
	};

	var running = false;
	var cancelRequested = false;
	var retries = 0;
	var MAX_RETRIES = 5;

	function setText( msg ) {
		el.text.textContent = msg;
	}

	function show( node, visible ) {
		if ( visible ) {
			node.removeAttribute( 'hidden' );
		} else {
			node.setAttribute( 'hidden', '' );
		}
	}

	function setRunningUi( isRunning ) {
		running = isRunning;
		el.start.disabled = isRunning;
		show( el.cancel, isRunning );
		show( el.progress, true );
		if ( isRunning ) {
			show( el.resume, false );
			show( el.restart, false );
		}
	}

	function format( template, a, b ) {
		return template.replace( '%1$s', a ).replace( '%2$s', b );
	}

	function renderProgress( p ) {
		el.fill.style.width = ( p.percent || 0 ) + '%';
		var line = p.phaseText || '';
		if ( 'posts' === p.phase ) {
			line += ' — ' + format( cfg.i18n.progress, p.processed, p.total );
		}
		setText( line + ' (' + ( p.percent || 0 ) + '%)' );
	}

	function post( action ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( res ) {
			return res.text().then( function ( raw ) {
				var json = null;
				try {
					json = JSON.parse( raw );
				} catch ( e ) {
					// Non-JSON answer (PHP fatal error, timeout page, …).
				}
				return { status: res.status, json: json, raw: raw };
			} );
		} );
	}

	function probe() {
		setText( cfg.i18n.probing );
		return fetch( cfg.probeUrl, { credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( 'probe' );
				}
				return true;
			} )
			.catch( function () {
				setText( cfg.i18n.probeFailed );
				return false;
			} );
	}

	function fail( message ) {
		setRunningUi( false );
		show( el.resume, true );
		show( el.restart, true );
		setText( cfg.i18n.error + ' ' + message );
	}

	function nextBatch() {
		if ( cancelRequested ) {
			return;
		}
		post( 'ics_batch' ).then( function ( r ) {
			if ( cancelRequested ) {
				return;
			}
			if ( r.json && r.json.success ) {
				retries = 0;
				renderProgress( r.json.data );
				if ( 'completed' === r.json.data.status ) {
					setText( cfg.i18n.completed );
					window.location.reload();
					return;
				}
				nextBatch();
				return;
			}
			// 409 = another batch still holds the lock; anything else may be a transient failure.
			var msg = r.json && r.json.data && r.json.data.message ? r.json.data.message : ( 'HTTP ' + r.status );
			if ( r.json && r.json.data && 'ics_not_running' === r.json.data.code ) {
				fail( msg );
				return;
			}
			if ( retries < MAX_RETRIES ) {
				retries++;
				setText( cfg.i18n.retrying + ' (' + msg + ')' );
				window.setTimeout( nextBatch, 2000 * retries );
				return;
			}
			fail( msg );
		} ).catch( function ( e ) {
			if ( retries < MAX_RETRIES ) {
				retries++;
				setText( cfg.i18n.retrying );
				window.setTimeout( nextBatch, 2000 * retries );
				return;
			}
			fail( String( e ) );
		} );
	}

	function startScan() {
		cancelRequested = false;
		retries = 0;
		setRunningUi( true );
		el.fill.style.width = '0%';
		probe().then( function () {
			setText( cfg.i18n.starting );
			return post( 'ics_start' );
		} ).then( function ( r ) {
			if ( ! r.json || ! r.json.success ) {
				fail( r.json && r.json.data && r.json.data.message ? r.json.data.message : 'HTTP ' + r.status );
				return;
			}
			renderProgress( r.json.data );
			nextBatch();
		} ).catch( function ( e ) {
			fail( String( e ) );
		} );
	}

	function resumeScan() {
		cancelRequested = false;
		retries = 0;
		setRunningUi( true );
		nextBatch();
	}

	function cancelScan() {
		if ( ! window.confirm( cfg.i18n.confirmCancel ) ) {
			return;
		}
		cancelRequested = true;
		setText( cfg.i18n.cancelling );
		post( 'ics_cancel' ).then( function () {
			setRunningUi( false );
			el.fill.style.width = '0%';
			setText( cfg.i18n.cancelled );
		} );
	}

	el.start.addEventListener( 'click', startScan );
	el.restart.addEventListener( 'click', startScan );
	el.resume.addEventListener( 'click', resumeScan );
	el.cancel.addEventListener( 'click', cancelScan );

	// Warn before leaving while a scan is running (it would pause until resumed).
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( running && ! cancelRequested ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	// Show stored progress of an interrupted scan.
	if ( cfg.state && 'running' === cfg.state.status ) {
		el.fill.style.width = ( cfg.state.percent || 0 ) + '%';
	}
}() );
