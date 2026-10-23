/**
 * The automation editor: the trigger at the top, steps in between, if / else and split
 * branches side by side, and an inspector on the right for the selected item. The whole
 * automation is kept as one object and written to a hidden field as JSON on save; the server
 * cleans it (FW_CRM_Automations::save). Each step shows how many contacts are on it now.
 */
( function () {
	'use strict';

	var canvas = document.getElementById( 'fw-crm-auto-canvas' );
	var inspector = document.getElementById( 'fw-crm-auto-inspector' );
	var form = document.getElementById( 'fw-crm-auto-form' );
	if ( ! canvas || ! inspector || ! form ) {
		return;
	}
	var cfg = JSON.parse( canvas.getAttribute( 'data-config' ) || '{}' );
	var t = cfg.i18n || {};
	var auto = cfg.automation || {};
	auto.steps = Array.isArray( auto.steps ) ? auto.steps : [];
	auto.trigger_settings = auto.trigger_settings && typeof auto.trigger_settings === 'object' && ! Array.isArray( auto.trigger_settings ) ? auto.trigger_settings : {};
	auto.settings = auto.settings || {};
	auto.settings.exit = auto.settings.exit || { match: 'any', rules: [] };
	auto.settings.exit.rules = auto.settings.exit.rules || [];
	var selected = 'trigger';

	/* ---- helpers ------------------------------------------------------ */

	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( 'text' === k ) {
				n.textContent = attrs[ k ];
			} else if ( 'class' === k ) {
				n.className = attrs[ k ];
			} else if ( 0 === k.indexOf( 'on' ) ) {
				n.addEventListener( k.slice( 2 ), attrs[ k ] );
			} else if ( null !== attrs[ k ] && false !== attrs[ k ] ) {
				n.setAttribute( k, attrs[ k ] );
			}
		} );
		( kids || [] ).forEach( function ( k ) {
			if ( null !== k && undefined !== k ) {
				n.appendChild( 'string' === typeof k ? document.createTextNode( k ) : k );
			}
		} );
		return n;
	}

	function uid() {
		return 's' + Math.random().toString( 36 ).slice( 2, 10 );
	}

	function find( list, id, parent ) {
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ].id === id ) {
				return { list: list, index: i, step: list[ i ], parent: parent || null };
			}
			var br = list[ i ].branches || {};
			for ( var k in br ) {
				var f = find( br[ k ], id, list[ i ] );
				if ( f ) {
					return f;
				}
			}
		}
		return null;
	}

	function grouped( registry ) {
		var groups = {};
		Object.keys( registry ).forEach( function ( slug ) {
			var g = registry[ slug ].group || '';
			( groups[ g ] = groups[ g ] || [] ).push( slug );
		} );
		return groups;
	}

	function optionList( select, options, value, placeholder ) {
		if ( placeholder ) {
			select.appendChild( el( 'option', { value: '', text: placeholder } ) );
		}
		Object.keys( options || {} ).forEach( function ( k ) {
			var o = el( 'option', { value: k, text: options[ k ] } );
			if ( String( value ) === String( k ) ) {
				o.selected = true;
			}
			select.appendChild( o );
		} );
		return select;
	}

	function summary( step ) {
		if ( 'wait' === step.type ) {
			var s = step.settings || {};
			if ( 'time' === s.mode ) {
				return ( s.weekday ? ( t.weekdays || {} )[ s.weekday ] : t.anyDay ) + ' ' + ( s.time || '09:00' );
			}
			if ( 'date' === s.mode ) {
				return ( cfg.dateFields[ s.field ] || '' ) + ' · ' + ( t[ s.when || 'on' ] || '' );
			}
			return ( s.amount || 1 ) + ' ' + ( t[ s.unit || 'days' ] || s.unit );
		}
		if ( 'condition' === step.type ) {
			var rules = ( ( step.settings || {} ).conditions || {} ).rules || [];
			return rules.map( function ( r ) {
				var f = cfg.condFields[ r.field ] || {};
				var v = f.options ? ( f.options[ r.value ] || r.value ) : r.value;
				return ( f.label || r.field ) + ' ' + ( cfg.ops[ r.op ] || r.op ) + ( 'empty' === r.op || 'not_empty' === r.op ? '' : ' ' + ( v || '' ) );
			} ).join( ' · ' );
		}
		if ( 'split' === step.type ) {
			var p = parseInt( ( step.settings || {} ).percent || 50, 10 );
			return 'A ' + p + '% · B ' + ( 100 - p ) + '%';
		}
		if ( 'goal' === step.type ) {
			var tr = cfg.triggers[ ( step.settings || {} ).trigger ];
			return tr ? tr.label : '';
		}
		var st = step.settings || {};
		return st.subject || st.tags || st.title || st.url || '';
	}

	function title( step ) {
		if ( 'action' === step.type ) {
			return ( cfg.actions[ step.action ] || {} ).label || step.action;
		}
		return t[ step.type ] || step.type;
	}

	/* ---- the flow ----------------------------------------------------- */

	function render() {
		canvas.innerHTML = '';
		var trig = cfg.triggers[ auto.trigger_type ];
		canvas.appendChild( el( 'button', {
			type: 'button',
			class: 'fw-crm-auto-node fw-crm-auto-node--trigger' + ( 'trigger' === selected ? ' is-selected' : '' ),
			onclick: function () { select( 'trigger' ); }
		}, [
			el( 'span', { class: 'fw-crm-auto-node__kind', text: t.trigger } ),
			el( 'span', { class: 'fw-crm-auto-node__title', text: trig ? trig.label : t.chooseTrigger } )
		] ) );
		canvas.appendChild( el( 'button', {
			type: 'button',
			class: 'fw-crm-auto-node fw-crm-auto-node--settings' + ( 'settings' === selected ? ' is-selected' : '' ),
			onclick: function () { select( 'settings' ); }
		}, [
			el( 'span', { class: 'fw-crm-auto-node__kind', text: t.settings } ),
			el( 'span', { class: 'fw-crm-auto-node__sum', text: t[ { once: 'once', after_finish: 'afterFinish', always: 'always' }[ auto.settings.reentry || 'once' ] ] + ( auto.settings.exit.rules.length ? ' · ' + t.exitRules + ' …' : '' ) } )
		] ) );
		canvas.appendChild( list( auto.steps ) );
		canvas.appendChild( el( 'div', { class: 'fw-crm-auto-end', text: t.end } ) );
		renderInspector();
	}

	function list( steps ) {
		var wrap = el( 'ol', { class: 'fw-crm-auto-list' } );
		wrap.appendChild( adder( steps, 0 ) );
		if ( ! steps.length && steps === auto.steps ) {
			wrap.appendChild( el( 'li', { class: 'fw-crm-auto-empty', text: t.emptyFlow } ) );
		}
		steps.forEach( function ( step, i ) {
			wrap.appendChild( node( step ) );
			wrap.appendChild( adder( steps, i + 1 ) );
		} );
		return wrap;
	}

	function node( step ) {
		var count = cfg.counts[ step.id ] || 0;
		var li = el( 'li', { class: 'fw-crm-auto-item' } );
		var card = el( 'button', {
			type: 'button',
			class: 'fw-crm-auto-node fw-crm-auto-node--' + step.type + ( selected === step.id ? ' is-selected' : '' ) + ( invalid( step ) ? ' is-invalid' : '' ),
			onclick: function () { select( step.id ); }
		}, [
			'action' === step.type ? el( 'span', { class: 'fw-crm-auto-node__kind', text: ( cfg.actions[ step.action ] || {} ).group || t.action } ) : null,
			el( 'span', { class: 'fw-crm-auto-node__title', text: title( step ) } ),
			summary( step ) ? el( 'span', { class: 'fw-crm-auto-node__sum', text: summary( step ) } ) : null,
			count ? el( 'span', { class: 'fw-crm-auto-node__count', text: ( t.inside || '%d' ).replace( '%d', count ) } ) : null
		] );
		li.appendChild( card );
		if ( step.branches ) {
			var keys = 'condition' === step.type ? [ [ 'yes', t.yes ], [ 'no', t.no ] ] : [ [ 'a', t.pathA ], [ 'b', t.pathB ] ];
			li.appendChild( el( 'div', { class: 'fw-crm-auto-branches' }, keys.map( function ( k ) {
				step.branches[ k[ 0 ] ] = step.branches[ k[ 0 ] ] || [];
				return el( 'div', { class: 'fw-crm-auto-branch fw-crm-auto-branch--' + k[ 0 ] }, [
					el( 'span', { class: 'fw-crm-auto-branch__label', text: k[ 1 ] } ),
					list( step.branches[ k[ 0 ] ] )
				] );
			} ) ) );
		}
		return li;
	}

	function adder( steps, at ) {
		var li = el( 'li', { class: 'fw-crm-auto-add' } );
		var menu = el( 'div', { class: 'fw-crm-auto-menu', hidden: 'hidden' } );
		var btn = el( 'button', {
			type: 'button',
			class: 'fw-crm-auto-add__btn',
			'aria-label': t.add,
			'aria-expanded': 'false',
			onclick: function () {
				var open = menu.hasAttribute( 'hidden' );
				document.querySelectorAll( '.fw-crm-auto-menu' ).forEach( function ( m ) { m.setAttribute( 'hidden', 'hidden' ); } );
				if ( open ) {
					menu.removeAttribute( 'hidden' );
				}
				btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			}
		}, [ '+' ] );
		var groups = grouped( cfg.actions );
		Object.keys( groups ).forEach( function ( g ) {
			menu.appendChild( el( 'span', { class: 'fw-crm-auto-menu__group', text: g } ) );
			groups[ g ].forEach( function ( slug ) {
				menu.appendChild( el( 'button', { type: 'button', text: cfg.actions[ slug ].label, onclick: function () {
					insert( steps, at, { id: uid(), type: 'action', action: slug, settings: {} } );
				} } ) );
			} );
		} );
		menu.appendChild( el( 'span', { class: 'fw-crm-auto-menu__group', text: t.wait + ' · ' + t.condition } ) );
		menu.appendChild( el( 'button', { type: 'button', text: t.wait, onclick: function () { insert( steps, at, { id: uid(), type: 'wait', settings: { mode: 'delay', amount: 1, unit: 'days' } } ); } } ) );
		menu.appendChild( el( 'button', { type: 'button', text: t.condition, onclick: function () { insert( steps, at, { id: uid(), type: 'condition', settings: { conditions: { match: 'all', rules: [] } }, branches: { yes: [], no: [] } } ); } } ) );
		menu.appendChild( el( 'button', { type: 'button', text: t.split, onclick: function () { insert( steps, at, { id: uid(), type: 'split', settings: { percent: 50 }, branches: { a: [], b: [] } } ); } } ) );
		menu.appendChild( el( 'button', { type: 'button', text: t.goal, onclick: function () { insert( steps, at, { id: uid(), type: 'goal', settings: { trigger: '', match: {} } } ); } } ) );
		li.appendChild( btn );
		li.appendChild( menu );
		return li;
	}

	function insert( steps, at, step ) {
		steps.splice( at, 0, step );
		selected = step.id;
		render();
	}

	function select( id ) {
		selected = id;
		render();
		var first = inspector.querySelector( 'input, select, textarea' );
		if ( first ) {
			first.focus();
		}
	}

	function invalid( step ) {
		if ( 'action' !== step.type ) {
			return 'goal' === step.type && ! ( step.settings || {} ).trigger;
		}
		var fields = ( cfg.actions[ step.action ] || {} ).fields || {};
		return Object.keys( fields ).some( function ( k ) {
			var v = ( step.settings || {} )[ k ];
			return fields[ k ].required && ( undefined === v || '' === v || ( Array.isArray( v ) && ! v.length ) );
		} );
	}

	/* ---- the inspector ------------------------------------------------ */

	function renderInspector() {
		inspector.innerHTML = '';
		if ( 'trigger' === selected ) {
			return triggerPanel();
		}
		if ( 'settings' === selected ) {
			return settingsPanel();
		}
		var f = find( auto.steps, selected );
		if ( ! f ) {
			inspector.appendChild( el( 'p', { class: 'fw-crm-muted', text: t.pick } ) );
			return;
		}
		var step = f.step;
		step.settings = step.settings || {};
		inspector.appendChild( el( 'h2', { text: title( step ) } ) );
		if ( 'action' === step.type ) {
			fieldsInto( inspector, ( cfg.actions[ step.action ] || {} ).fields || {}, step.settings );
		} else if ( 'wait' === step.type ) {
			waitPanel( step );
		} else if ( 'condition' === step.type ) {
			inspector.appendChild( el( 'p', { class: 'fw-crm-muted', text: t.condHelp } ) );
			step.settings.conditions = step.settings.conditions || { match: 'all', rules: [] };
			rulesInto( inspector, step.settings.conditions );
		} else if ( 'split' === step.type ) {
			fieldsInto( inspector, { percent: { label: t.percent, type: 'number' } }, step.settings );
		} else if ( 'goal' === step.type ) {
			inspector.appendChild( el( 'p', { class: 'fw-crm-muted', text: t.goalHelp } ) );
			step.settings.match = step.settings.match && ! Array.isArray( step.settings.match ) ? step.settings.match : {};
			var sel = triggerSelect( step.settings.trigger, function ( v ) {
				step.settings.trigger = v;
				step.settings.match = {};
				render();
			} );
			inspector.appendChild( field( t.goalTrigger, sel ) );
			if ( step.settings.trigger && cfg.triggers[ step.settings.trigger ] ) {
				fieldsInto( inspector, cfg.triggers[ step.settings.trigger ].fields || {}, step.settings.match );
			}
		}
		inspector.appendChild( el( 'div', { class: 'fw-crm-auto-inspector__tools' }, [
			el( 'button', { type: 'button', class: 'button', text: t.up, disabled: 0 === f.index ? 'disabled' : null, onclick: function () { move( f, -1 ); } } ),
			el( 'button', { type: 'button', class: 'button', text: t.down, disabled: f.index === f.list.length - 1 ? 'disabled' : null, onclick: function () { move( f, 1 ); } } ),
			el( 'button', { type: 'button', class: 'button-link button-link-delete', text: t.remove, onclick: function () {
				f.list.splice( f.index, 1 );
				selected = 'trigger';
				render();
			} } )
		] ) );
	}

	function move( f, by ) {
		var s = f.list.splice( f.index, 1 )[ 0 ];
		f.list.splice( f.index + by, 0, s );
		render();
	}

	function triggerSelect( value, onchange ) {
		var sel = el( 'select', { onchange: function () { onchange( sel.value ); } } );
		sel.appendChild( el( 'option', { value: '', text: t.none } ) );
		var groups = grouped( cfg.triggers );
		Object.keys( groups ).forEach( function ( g ) {
			var og = el( 'optgroup', { label: g } );
			groups[ g ].forEach( function ( slug ) {
				var o = el( 'option', { value: slug, text: cfg.triggers[ slug ].label } );
				o.selected = slug === value;
				og.appendChild( o );
			} );
			sel.appendChild( og );
		} );
		return sel;
	}

	function triggerPanel() {
		inspector.appendChild( el( 'h2', { text: t.trigger } ) );
		inspector.appendChild( field( t.chooseTrigger, triggerSelect( auto.trigger_type, function ( v ) {
			auto.trigger_type = v;
			auto.trigger_settings = {};
			render();
		} ) ) );
		if ( auto.trigger_type && cfg.triggers[ auto.trigger_type ] ) {
			fieldsInto( inspector, cfg.triggers[ auto.trigger_type ].fields || {}, auto.trigger_settings );
		}
	}

	function settingsPanel() {
		inspector.appendChild( el( 'h2', { text: t.settings } ) );
		var sel = optionList( el( 'select', { onchange: function () { auto.settings.reentry = sel.value; render(); } } ), { once: t.once, after_finish: t.afterFinish, always: t.always }, auto.settings.reentry || 'once' );
		inspector.appendChild( field( t.reentry, sel ) );
		inspector.appendChild( el( 'h3', { text: t.exitRules } ) );
		inspector.appendChild( el( 'p', { class: 'fw-crm-muted', text: t.exitHelp } ) );
		rulesInto( inspector, auto.settings.exit );
	}

	function waitPanel( step ) {
		var s = step.settings;
		s.mode = s.mode || 'delay';
		var mode = optionList( el( 'select', { onchange: function () { s.mode = mode.value; render(); } } ), { delay: t.delay, time: t.untilTime, date: t.untilDate }, s.mode );
		inspector.appendChild( field( t.wait, mode ) );
		if ( 'delay' === s.mode ) {
			fieldsInto( inspector, {
				amount: { label: t.amount, type: 'number' },
				unit: { label: '', type: 'select', options: { minutes: t.minutes, hours: t.hours, days: t.days, weeks: t.weeks } }
			}, s );
		} else if ( 'time' === s.mode ) {
			var days = { '': t.anyDay };
			Object.keys( t.weekdays || {} ).forEach( function ( k ) { days[ k ] = t.weekdays[ k ]; } );
			fieldsInto( inspector, { weekday: { label: t.weekday, type: 'select', options: days }, time: { label: t.time, type: 'time' } }, s );
		} else {
			fieldsInto( inspector, {
				field: { label: t.dateField, type: 'select', options: cfg.dateFields },
				when: { label: t.when, type: 'select', options: { on: t.on, before: t.before, after: t.after } },
				days: { label: t.days, type: 'number' },
				time: { label: t.time, type: 'time' }
			}, s );
		}
	}

	function field( label, control, desc, required ) {
		return el( 'label', { class: 'fw-crm-auto-field' + ( required ? ' is-required' : '' ) }, [
			label ? el( 'span', { class: 'fw-crm-auto-field__label', text: label } ) : null,
			control,
			desc ? el( 'small', { class: 'fw-crm-muted', text: desc } ) : null
		] );
	}

	function fieldsInto( parent, fields, values ) {
		Object.keys( fields ).forEach( function ( k ) {
			var f = fields[ k ];
			var v = values[ k ];
			var c;
			var empty = f.required && ( undefined === v || '' === v || ( Array.isArray( v ) && ! v.length ) );
			if ( 'select' === f.type ) {
				c = optionList( el( 'select', { onchange: function () { values[ k ] = c.value; render(); } } ), f.options, v, f.required ? t.none : null );
				if ( ! f.required && ! ( '' in ( f.options || {} ) ) && undefined === v ) {
					values[ k ] = c.value;
				}
			} else if ( 'multiselect' === f.type ) {
				var chosen = Array.isArray( v ) ? v.map( String ) : [];
				c = el( 'span', { class: 'fw-crm-auto-multi' }, Object.keys( f.options || {} ).map( function ( o ) {
					var box = el( 'input', { type: 'checkbox', value: o } );
					box.checked = -1 !== chosen.indexOf( String( o ) );
					box.addEventListener( 'change', function () {
						values[ k ] = Array.prototype.map.call( c.querySelectorAll( 'input:checked' ), function ( b ) { return b.value; } );
						render();
					} );
					return el( 'label', {}, [ box, ' ' + f.options[ o ] ] );
				} ) );
			} else if ( 'checkbox' === f.type ) {
				c = el( 'input', { type: 'checkbox', onchange: function () { values[ k ] = c.checked ? 1 : 0; render(); } } );
				c.checked = !! v && '0' !== String( v );
			} else if ( 'textarea' === f.type ) {
				c = el( 'textarea', { rows: 6, oninput: function () { values[ k ] = c.value; }, onchange: render } );
				c.value = undefined === v ? '' : v;
			} else {
				c = el( 'input', { type: 'number' === f.type ? 'number' : ( 'time' === f.type ? 'time' : 'text' ), oninput: function () { values[ k ] = 'number' === f.type && '' !== c.value ? Number( c.value ) : c.value; }, onchange: render } );
				c.value = undefined === v ? '' : v;
			}
			parent.appendChild( field( f.label, c, f.desc, empty ) );
		} );
	}

	function rulesInto( parent, group ) {
		group.rules = group.rules || [];
		var match = optionList( el( 'select', { onchange: function () { group.match = match.value; render(); } } ), { all: t.all, any: t.any }, group.match || 'all' );
		parent.appendChild( field( t.match, match ) );
		group.rules.forEach( function ( r, i ) {
			var def = cfg.condFields[ r.field ] || {};
			var fieldSel = el( 'select', { onchange: function () { r.field = fieldSel.value; r.op = ( cfg.condFields[ r.field ] || {} ).kind === 'set' ? 'is' : 'eq'; r.value = ''; render(); } } );
			fieldSel.appendChild( el( 'option', { value: '', text: t.none } ) );
			Object.keys( cfg.condFields ).forEach( function ( k ) {
				var o = el( 'option', { value: k, text: cfg.condFields[ k ].label } );
				o.selected = k === r.field;
				fieldSel.appendChild( o );
			} );
			var ops = 'set' === def.kind ? [ 'is', 'is_not' ] : ( 'bool' === def.kind ? [ 'is', 'is_not' ] : ( 'number' === def.kind ? [ 'eq', 'neq', 'gt', 'lt', 'empty', 'not_empty' ] : [ 'eq', 'neq', 'contains', 'not_contains', 'empty', 'not_empty' ] ) );
			var opSel = el( 'select', { onchange: function () { r.op = opSel.value; render(); } } );
			ops.forEach( function ( o ) {
				var opt = el( 'option', { value: o, text: cfg.ops[ o ] } );
				opt.selected = o === r.op;
				opSel.appendChild( opt );
			} );
			var val;
			if ( 'set' === def.kind ) {
				val = optionList( el( 'select', { onchange: function () { r.value = val.value; render(); } } ), def.options, r.value, t.none );
			} else if ( 'bool' === def.kind ) {
				r.value = '1';
				val = el( 'span', { class: 'fw-crm-muted', text: t.yes1 } );
			} else if ( 'empty' === r.op || 'not_empty' === r.op ) {
				val = null;
			} else {
				val = el( 'input', { type: 'number' === def.kind ? 'number' : 'text', value: r.value || '', oninput: function () { r.value = val.value; }, onchange: render } );
			}
			parent.appendChild( el( 'div', { class: 'fw-crm-auto-rule' }, [
				fieldSel, opSel, val,
				el( 'button', { type: 'button', class: 'button-link button-link-delete', 'aria-label': t.remove, text: '×', onclick: function () { group.rules.splice( i, 1 ); render(); } } )
			] ) );
		} );
		parent.appendChild( el( 'button', { type: 'button', class: 'button', text: t.addRule, onclick: function () { group.rules.push( { field: '', op: 'is', value: '' } ); render(); } } ) );
	}

	/* ---- saving ------------------------------------------------------- */

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest( '.fw-crm-auto-add' ) ) {
			document.querySelectorAll( '.fw-crm-auto-menu' ).forEach( function ( m ) { m.setAttribute( 'hidden', 'hidden' ); } );
		}
	} );

	form.addEventListener( 'submit', function () {
		var title = document.getElementById( 'fw-crm-auto-title' );
		document.getElementById( 'fw-crm-auto-json' ).value = JSON.stringify( {
			title: title ? title.value : '',
			trigger_type: auto.trigger_type || '',
			trigger_settings: auto.trigger_settings,
			settings: auto.settings,
			steps: auto.steps
		} );
	} );

	render();
}() );
