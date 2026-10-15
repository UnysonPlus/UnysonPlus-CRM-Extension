/**
 * The segment builder. Draws a rule tree (one level of groups) from the JSON in
 * #fw-crm-rules-json, writes it back on every change, and asks the server for a
 * live count. The field registry and operators come from the page (data-config).
 */
( function () {
	'use strict';

	var root = document.getElementById( 'fw-crm-rules' );
	var input = document.getElementById( 'fw-crm-rules-json' );
	if ( ! root || ! input ) {
		return;
	}

	var cfg = JSON.parse( root.getAttribute( 'data-config' ) );
	var t = cfg.i18n;
	var tree;
	try {
		tree = JSON.parse( input.value );
	} catch ( e ) {
		tree = null;
	}
	if ( ! tree || ! Array.isArray( tree.rules ) ) {
		tree = { match: 'all', rules: [] };
	}
	if ( ! tree.rules.length ) {
		tree.rules.push( { field: '', op: '', value: '' } );
	}

	var uid = 0;

	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( 'text' === k ) {
				n.textContent = attrs[ k ];
			} else if ( 'class' === k ) {
				n.className = attrs[ k ];
			} else {
				n.setAttribute( k, attrs[ k ] );
			}
		} );
		( kids || [] ).forEach( function ( c ) {
			if ( c ) {
				n.appendChild( c );
			}
		} );
		return n;
	}

	function fmt( s, v ) {
		return s.replace( '%s', v );
	}

	function opsFor( field ) {
		var f = cfg.fields[ field ];
		return f ? cfg.operators[ f.type ] || {} : {};
	}

	function needsValue( op ) {
		return [ 'empty', 'not_empty', 'is_true', 'is_false' ].indexOf( op ) === -1;
	}

	function isDays( op ) {
		return [ 'in_last_days', 'not_in_last_days', 'in_next_days' ].indexOf( op ) !== -1;
	}

	/* --- Controls ------------------------------------------------------- */

	function matchSelect( node, labelText ) {
		var sel = el( 'select', { 'aria-label': labelText } );
		[ 'all', 'any' ].forEach( function ( m ) {
			var o = el( 'option', { value: m, text: t[ 'match_' + m ] } );
			if ( node.match === m ) {
				o.selected = true;
			}
			sel.appendChild( o );
		} );
		sel.addEventListener( 'change', function () {
			node.match = sel.value;
			changed();
		} );
		return sel;
	}

	function sentence( template, control ) {
		var parts = template.split( '%s' );
		return el( 'span', { class: 'fw-crm-rules__match' }, [
			document.createTextNode( parts[ 0 ] ),
			control,
			document.createTextNode( parts[ 1 ] || '' ),
		] );
	}

	function fieldSelect( rule ) {
		var sel = el( 'select', { 'aria-label': t.field, class: 'fw-crm-rule__field' } );
		sel.appendChild( el( 'option', { value: '', text: t.pick } ) );
		var groups = {};
		Object.keys( cfg.fields ).forEach( function ( key ) {
			var f = cfg.fields[ key ];
			var g = groups[ f.group ];
			if ( ! g ) {
				g = groups[ f.group ] = el( 'optgroup', { label: f.group } );
				sel.appendChild( g );
			}
			var o = el( 'option', { value: key, text: f.label } );
			if ( rule.field === key ) {
				o.selected = true;
			}
			g.appendChild( o );
		} );
		sel.addEventListener( 'change', function () {
			rule.field = sel.value;
			var ops = Object.keys( opsFor( rule.field ) );
			rule.op = ops[ 0 ] || '';
			rule.value = defaultValue( rule );
			render();
			changed();
			var again = root.querySelector( '[data-rid="' + rule._id + '"] .fw-crm-rule__op' );
			if ( again ) {
				again.focus();
			}
		} );
		return sel;
	}

	function defaultValue( rule ) {
		var f = cfg.fields[ rule.field ];
		if ( ! f ) {
			return '';
		}
		if ( 'choice' === f.type || 'tags' === f.type ) {
			return [];
		}
		if ( 'activity' === f.type ) {
			return { type: Object.keys( f.choices || {} )[ 0 ] || '', days: 30 };
		}
		if ( 'date' === f.type && isDays( rule.op ) ) {
			return 30;
		}
		return '';
	}

	function opSelect( rule ) {
		var sel = el( 'select', { 'aria-label': t.operator, class: 'fw-crm-rule__op' } );
		var ops = opsFor( rule.field );
		Object.keys( ops ).forEach( function ( op ) {
			var o = el( 'option', { value: op, text: ops[ op ].replace( ' …', '' ).replace( '…', '' ) } );
			if ( rule.op === op ) {
				o.selected = true;
			}
			sel.appendChild( o );
		} );
		sel.disabled = ! rule.field;
		sel.addEventListener( 'change', function () {
			var wasDays = isDays( rule.op );
			rule.op = sel.value;
			if ( wasDays !== isDays( rule.op ) ) {
				rule.value = defaultValue( rule );
			}
			render();
			changed();
		} );
		return sel;
	}

	function valueControl( rule ) {
		var f = cfg.fields[ rule.field ];
		if ( ! f || ! needsValue( rule.op ) ) {
			return null;
		}
		var wrap = el( 'span', { class: 'fw-crm-rule__value' } );

		if ( 'choice' === f.type || 'tags' === f.type ) {
			var keys = Object.keys( f.choices || {} );
			if ( ! keys.length ) {
				wrap.appendChild( el( 'span', { class: 'fw-crm-muted', text: t.none_yet } ) );
				return wrap;
			}
			var box = el( 'span', { class: 'fw-crm-rule__choices', role: 'group', 'aria-label': t.value } );
			var chosen = Array.isArray( rule.value ) ? rule.value : [];
			keys.forEach( function ( k ) {
				var id = 'fw-crm-c' + ( ++uid );
				var cb = el( 'input', { type: 'checkbox', id: id, value: k } );
				cb.checked = chosen.indexOf( k ) !== -1;
				cb.addEventListener( 'change', function () {
					var now = Array.isArray( rule.value ) ? rule.value.slice() : [];
					var i = now.indexOf( k );
					if ( cb.checked && i === -1 ) {
						now.push( k );
					} else if ( ! cb.checked && i !== -1 ) {
						now.splice( i, 1 );
					}
					rule.value = now;
					changed();
				} );
				box.appendChild( el( 'label', { class: 'fw-crm-check', for: id }, [ cb, document.createTextNode( ' ' + f.choices[ k ] ) ] ) );
			} );
			wrap.appendChild( box );
			return wrap;
		}

		if ( 'activity' === f.type ) {
			var v = rule.value && 'object' === typeof rule.value ? rule.value : defaultValue( rule );
			rule.value = v;
			var typeSel = el( 'select', { 'aria-label': t.value } );
			Object.keys( f.choices || {} ).forEach( function ( k ) {
				var o = el( 'option', { value: k, text: f.choices[ k ] } );
				if ( v.type === k ) {
					o.selected = true;
				}
				typeSel.appendChild( o );
			} );
			typeSel.addEventListener( 'change', function () {
				rule.value.type = typeSel.value;
				changed();
			} );
			var days = el( 'input', { type: 'number', min: '1', value: String( v.days || 30 ), 'aria-label': t.days, class: 'fw-crm-rule__days' } );
			days.addEventListener( 'input', function () {
				rule.value.days = parseInt( days.value, 10 ) || 0;
				changed();
			} );
			wrap.appendChild( typeSel );
			wrap.appendChild( days );
			wrap.appendChild( el( 'span', { text: t.days } ) );
			return wrap;
		}

		var type = 'text';
		if ( 'number' === f.type || ( 'date' === f.type && isDays( rule.op ) ) ) {
			type = 'number';
		} else if ( 'date' === f.type ) {
			type = 'date';
		}
		var inp = el( 'input', { type: type, value: null === rule.value || undefined === rule.value ? '' : String( rule.value ), 'aria-label': t.value } );
		if ( 'number' === type ) {
			inp.setAttribute( 'step', 'any' );
		}
		inp.addEventListener( 'input', function () {
			rule.value = inp.value;
			changed();
		} );
		wrap.appendChild( inp );
		if ( 'date' === f.type && isDays( rule.op ) ) {
			wrap.appendChild( el( 'span', { text: t.days } ) );
		}
		return wrap;
	}

	/* --- Tree ----------------------------------------------------------- */

	function ruleRow( rule, list, index ) {
		rule._id = rule._id || 'r' + ( ++uid );
		var remove = el( 'button', { type: 'button', class: 'fw-crm-unlink', 'aria-label': t.remove }, [
			el( 'span', { class: 'dashicons dashicons-no-alt', 'aria-hidden': 'true' } ),
		] );
		remove.addEventListener( 'click', function () {
			list.splice( index, 1 );
			render();
			changed();
		} );
		return el( 'div', { class: 'fw-crm-rule', 'data-rid': rule._id }, [
			fieldSelect( rule ),
			opSelect( rule ),
			valueControl( rule ),
			remove,
		] );
	}

	function addButtons( node, depth ) {
		var bar = el( 'div', { class: 'fw-crm-rules__add' } );
		var addRule = el( 'button', { type: 'button', class: 'button button-small' }, [
			el( 'span', { class: 'dashicons dashicons-plus-alt2', 'aria-hidden': 'true' } ),
			document.createTextNode( t.add_rule ),
		] );
		addRule.addEventListener( 'click', function () {
			node.rules.push( { field: '', op: '', value: '' } );
			render();
			var fields = root.querySelectorAll( '.fw-crm-rule__field' );
			if ( fields.length ) {
				fields[ fields.length - 1 ].focus();
			}
		} );
		bar.appendChild( addRule );
		if ( 0 === depth ) {
			var addGroup = el( 'button', { type: 'button', class: 'button button-small' }, [
				el( 'span', { class: 'dashicons dashicons-category', 'aria-hidden': 'true' } ),
				document.createTextNode( t.add_group ),
			] );
			addGroup.addEventListener( 'click', function () {
				node.rules.push( { match: 'all' === node.match ? 'any' : 'all', rules: [ { field: '', op: '', value: '' } ] } );
				render();
			} );
			bar.appendChild( addGroup );
		}
		return bar;
	}

	function group( node, depth, parentList, index ) {
		var box = el( 'div', { class: 'fw-crm-rules__group' + ( depth ? ' is-nested' : '' ) } );
		var head = el( 'div', { class: 'fw-crm-rules__head' }, [
			sentence( depth ? t.group_match : t.match, matchSelect( node, depth ? t.group_match.replace( '%s', '' ) : t.match.replace( '%s', '' ) ) ),
		] );
		if ( depth ) {
			var rm = el( 'button', { type: 'button', class: 'fw-crm-unlink', 'aria-label': t.remove_grp }, [
				el( 'span', { class: 'dashicons dashicons-no-alt', 'aria-hidden': 'true' } ),
			] );
			rm.addEventListener( 'click', function () {
				parentList.splice( index, 1 );
				render();
				changed();
			} );
			head.appendChild( rm );
		}
		box.appendChild( head );
		node.rules.forEach( function ( r, i ) {
			box.appendChild( r.rules ? group( r, depth + 1, node.rules, i ) : ruleRow( r, node.rules, i ) );
		} );
		box.appendChild( addButtons( node, depth ) );
		return box;
	}

	function render() {
		root.textContent = '';
		root.appendChild( group( tree, 0 ) );
	}

	/* --- Sync + preview -------------------------------------------------- */

	function clean( node ) {
		return {
			match: node.match,
			rules: node.rules.map( function ( r ) {
				return r.rules ? clean( r ) : { field: r.field, op: r.op, value: r.value };
			} ),
		};
	}

	var timer = null;
	var seq = 0;
	var countEl = document.getElementById( 'fw-crm-segment-count' );
	var descEl = document.getElementById( 'fw-crm-segment-describe' );

	function changed() {
		input.value = JSON.stringify( clean( tree ) );
		clearTimeout( timer );
		timer = setTimeout( preview, 350 );
	}

	function preview() {
		var mine = ++seq;
		countEl.textContent = t.counting;
		var body = new FormData();
		body.append( 'action', 'fw_crm_segment_preview' );
		body.append( '_ajax_nonce', cfg.nonce );
		body.append( 'rules', input.value );
		fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( mine !== seq || ! res || ! res.success ) {
					return;
				}
				if ( null === res.data.count ) {
					countEl.textContent = t.empty;
					descEl.textContent = '';
					return;
				}
				countEl.textContent = 1 === res.data.count ? t.count_one : fmt( t.count, res.data.count.toLocaleString() );
				descEl.textContent = res.data.describe;
			} )
			.catch( function () {
				countEl.textContent = '';
			} );
	}

	render();
	changed();
}() );
