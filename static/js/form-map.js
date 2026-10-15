/**
 * CRM → "Save the answers" table in a contact form's Settings tab.
 *
 * Reads the form's fields from the Form Fields tab of the same modal (the hidden
 * `…[form]` input holds them as JSON), offers a target per field, and stores the
 * choices as JSON in the option's hidden value (`…[action_crm_map]`):
 *   { fields: { <field id>: <target> }, deal_title: <field id|'-'>, date: <field id|'-'> }
 * A field with no stored choice shows (and gets, on the server) its automatic target;
 * the rules match FW_CRM_Form_Map::auto_target().
 */
(function () {
	'use strict';

	var RULES = [
		['company', /\b(company|organi[sz]ation|business)\b/],
		['first_name', /\b(first|given)\s*name\b/],
		['last_name', /\b(last\s*name|surname|family\s*name)\b/],
		['name', /\bname\b/],
		['phone', /\b(phone|mobile|tel|telephone|cell)\b/],
		['job_title', /\b(job|position|role)\b/],
		['postcode', /\b(post\s*code|zip)\b/],
		['city', /\b(city|town)\b/],
		['region', /\b(state|province|region|county)\b/],
		['country', /\bcountry\b/],
		['address_line1', /\b(address|street)\b/]
	];

	function text(s) {
		var d = document.createElement('div');
		d.innerHTML = String(s || '');
		return (d.textContent || '').trim();
	}

	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') { e.textContent = attrs[k]; } else { e.setAttribute(k, attrs[k]); }
		});
		(kids || []).forEach(function (k) { if (k) { e.appendChild(k); } });
		return e;
	}

	function auto(item, data) {
		var type = item.type || '';
		var label = text(item.options && item.options.label).toLowerCase();
		if (type === 'email' || type === 'consent' || data.skip.indexOf(type) !== -1) { return type; }
		for (var i = 0; i < data.custom.length; i++) {
			if (label && data.custom[i].label.toLowerCase() === label) { return 'cf:' + data.custom[i].key; }
		}
		if (['text', 'number', 'member-username'].indexOf(type) === -1) { return 'timeline'; }
		var keys = data.targets.map(function (t) { return t.key; });
		for (var r = 0; r < RULES.length; r++) {
			if (keys.indexOf(RULES[r][0]) !== -1 && RULES[r][1].test(label)) { return RULES[r][0]; }
		}
		return 'timeline';
	}

	function scopeOf(box) {
		return box.closest('.fw-options-modal') || box.closest('form') || document;
	}

	function field(scope, name) {
		return scope.querySelector('[name$="[' + name + ']"]');
	}

	function items(scope) {
		var input = field(scope, 'form');
		var list = [];
		try {
			var v = JSON.parse(input ? input.value : '[]');
			list = Array.isArray(v) ? v : (v && v.json ? JSON.parse(v.json) : []);
		} catch (e) { list = []; }
		return list.filter(function (i) { return i && i.shortcode && i.type !== 'form-header-title'; });
	}

	function stored(hidden) {
		try {
			var m = JSON.parse(hidden.value || '{}');
			return { fields: m.fields || {}, deal_title: m.deal_title || '', date: m.date || '' };
		} catch (e) {
			return { fields: {}, deal_title: '', date: '' };
		}
	}

	function save(hidden, map) {
		hidden.value = JSON.stringify(map);
		if (window.jQuery) { window.jQuery(hidden).trigger('change'); }
	}

	function switchOn(scope, name) {
		var inputs = scope.querySelectorAll('[name$="[' + name + ']"]');
		for (var i = 0; i < inputs.length; i++) {
			var x = inputs[i];
			// The switch option is a checkbox: checked = its right-hand ("On") value.
			if (x.type === 'checkbox') { return x.checked; }
			if (x.type === 'radio') { if (x.checked) { return x.value === 'yes'; } continue; }
			if (x.value === 'yes') { return true; }
		}
		return false;
	}

	function row(scope, name) {
		var f = field(scope, name);
		return f ? f.closest('.fw-backend-option') : null;
	}

	function render(box) {
		var scope = scopeOf(box);
		var data = JSON.parse(box.getAttribute('data-fw-crm-form-map'));
		var holder = box.closest('.fw-option-html');
		var hidden = holder && holder.parentElement ? holder.parentElement.querySelector('.fw-option-html-value') : null;
		if (!hidden) { return; }
		var map = stored(hidden);
		var list = items(scope);
		var t = data.i18n;
		var on = switchOn(scope, 'action_crm_contact');
		var create = (field(scope, 'action_crm_create') || {}).value || '';

		// The rows that only matter with the switch on, or for one kind of record.
		['action_crm_map', 'action_crm_update', 'action_crm_create'].forEach(function (n) {
			var r = row(scope, n);
			if (r) { r.style.display = on ? '' : 'none'; }
		});
		var rp = row(scope, 'action_crm_pipeline');
		if (rp) { rp.style.display = on && create === 'deal' ? '' : 'none'; }
		var rt = row(scope, 'action_crm_task_type');
		if (rt) { rt.style.display = on && create === 'task' ? '' : 'none'; }

		box.innerHTML = '';
		if (!list.length) {
			box.appendChild(el('p', { 'class': 'fw-crm-form-map__note', text: t.noFields }));
			return;
		}
		if (!list.some(function (i) { return i.type === 'email'; })) {
			box.appendChild(el('p', { 'class': 'fw-crm-form-map__warn', text: t.noEmail }));
		}

		var tbody = el('tbody');
		list.forEach(function (item) {
			var id = item.shortcode;
			var label = text(item.options && item.options.label) || id;
			var a = auto(item, data);
			var cell;
			if (a === 'email') {
				cell = el('span', { 'class': 'fw-crm-form-map__fixed', text: t.email });
			} else if (a === 'consent') {
				cell = el('span', { 'class': 'fw-crm-form-map__fixed', text: (item.options && item.options.purpose === 'newsletter') ? t.consent : t.consentAny });
			} else if (data.skip.indexOf(a) !== -1) {
				return; // Anti-spam, hidden, files and secrets are not offered.
			} else {
				var sel = el('select', { 'data-field': id, 'aria-label': t.saveTo + ': ' + label });
				sel.appendChild(el('option', { value: 'timeline', text: t.timeline }));
				var g1 = el('optgroup', { label: t.contact });
				data.targets.forEach(function (o) { g1.appendChild(el('option', { value: o.key, text: o.label })); });
				sel.appendChild(g1);
				var g2 = el('optgroup', { label: t.customs });
				data.custom.forEach(function (o) { g2.appendChild(el('option', { value: 'cf:' + o.key, text: o.label })); });
				g2.appendChild(el('option', { value: 'new', text: t.newField.replace('%s', label) }));
				sel.appendChild(g2);
				var chosen = map.fields[id] || a;
				// A "new" field that has been created since shows as the field it now is.
				if (chosen === 'new') {
					for (var c = 0; c < data.custom.length; c++) {
						if (data.custom[c].label.toLowerCase() === label.toLowerCase()) { chosen = 'cf:' + data.custom[c].key; break; }
					}
				}
				if (!sel.querySelector('option[value="' + chosen.replace(/"/g, '') + '"]')) { chosen = a; }
				sel.value = chosen;
				sel.addEventListener('change', function () {
					var m = stored(hidden);
					m.fields[id] = sel.value;
					save(hidden, m);
				});
				cell = sel;
			}
			tbody.appendChild(el('tr', {}, [el('th', { scope: 'row', text: label }), el('td', {}, [cell])]));
		});
		box.appendChild(el('table', { 'class': 'fw-crm-form-map__table' }, [
			el('thead', {}, [el('tr', {}, [el('th', { scope: 'col', text: t.field }), el('th', { scope: 'col', text: t.saveTo })])]),
			tbody
		]));

		if (create) {
			var named = list.filter(function (i) { return ['select', 'radio', 'text'].indexOf(i.type) !== -1; });
			var dated = list.filter(function (i) { return i.type === 'date'; });
			var titleSel = el('select', { 'aria-label': create === 'deal' ? t.dealTitle : t.taskTitle });
			titleSel.appendChild(el('option', { value: '-', text: t.formTitle }));
			named.forEach(function (i) { titleSel.appendChild(el('option', { value: i.shortcode, text: text(i.options && i.options.label) || i.shortcode })); });
			var defTitle = (named.filter(function (i) { return i.type === 'select' || i.type === 'radio'; })[0] || {}).shortcode || '-';
			titleSel.value = map.deal_title && titleSel.querySelector('option[value="' + map.deal_title + '"]') ? map.deal_title : defTitle;
			var dateSel = el('select', { 'aria-label': t.date });
			dateSel.appendChild(el('option', { value: '-', text: t.noDate }));
			dated.forEach(function (i) { dateSel.appendChild(el('option', { value: i.shortcode, text: text(i.options && i.options.label) || i.shortcode })); });
			dateSel.value = map.date && dateSel.querySelector('option[value="' + map.date + '"]') ? map.date : (dated[0] ? dated[0].shortcode : '-');
			titleSel.addEventListener('change', function () { var m = stored(hidden); m.deal_title = titleSel.value; save(hidden, m); });
			dateSel.addEventListener('change', function () { var m = stored(hidden); m.date = dateSel.value; save(hidden, m); });
			box.appendChild(el('div', { 'class': 'fw-crm-form-map__record' }, [
				el('label', {}, [el('span', { text: create === 'deal' ? t.dealTitle : t.taskTitle }), titleSel]),
				el('label', {}, [el('span', { text: t.date }), dateSel])
			]));
		}
		box.appendChild(el('p', { 'class': 'fw-crm-form-map__note', text: t.note }));
	}

	function renderVisible() {
		var boxes = document.querySelectorAll('.fw-crm-form-map[data-fw-crm-form-map]');
		for (var i = 0; i < boxes.length; i++) {
			var b = boxes[i];
			// Also render hidden ones once, so the row toggles apply before the tab is shown.
			if (b.getClientRects().length || !b.hasAttribute('data-rendered')) {
				b.setAttribute('data-rendered', '1');
				try { render(b); } catch (e) { if (window.console) { console.error(e); } }
			}
		}
	}

	var timer = null;
	function soon() {
		clearTimeout(timer);
		timer = setTimeout(renderVisible, 60);
	}

	// Re-render when a tab is opened (the Form Fields tab may have changed) and when
	// a CRM switch or the "Also create" choice changes.
	document.addEventListener('click', function (e) {
		if (e.target.closest && e.target.closest('.fw-options-modal, .fw-options-tabs-wrapper, .ui-tabs-nav')) { soon(); }
	}, true);
	document.addEventListener('change', function (e) {
		var n = e.target && e.target.name ? e.target.name : '';
		if (/\[action_crm_(contact|create)\]/.test(n)) { soon(); }
	}, true);
	if (window.fwEvents && window.fwEvents.on) {
		window.fwEvents.on('fw:options:init', soon);
	}
	new MutationObserver(function (list) {
		for (var i = 0; i < list.length; i++) {
			for (var j = 0; j < list[i].addedNodes.length; j++) {
				var n = list[i].addedNodes[j];
				if (n.nodeType === 1 && (n.matches('.fw-crm-form-map') || n.querySelector('.fw-crm-form-map'))) { soon(); return; }
			}
		}
	}).observe(document.documentElement, { childList: true, subtree: true });
})();
