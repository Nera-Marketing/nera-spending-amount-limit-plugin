/**
 * Nera Spending Limit — Account Details page.
 * Vanilla JS: amount slider, limit type, custom calendar (day/week/month/year),
 * remove-with-confirmation, and AJAX save.
 */
(function () {
	'use strict';

	var cfg = window.neraSpendLimit;
	if (!cfg) {
		return;
	}

	var root = document.getElementById('nera-sl-root');
	if (!root) {
		return;
	}

	var i18n = cfg.i18n || {};

	// ---- Elements ----
	var elToggle = document.getElementById('nera-sl-enabled-toggle');
	var elFields = root.querySelector('.nera-sl-fields');
	var elNumber = document.getElementById('nera-sl-amount');
	var elType = document.getElementById('nera-sl-type');
	var elSubtypeWrap = root.querySelector('.nera-sl-custom-subtype');
	var elSubtype = document.getElementById('nera-sl-subtype');
	var elCalWrap = root.querySelector('.nera-sl-custom-calendar');
	var elCalHint = root.querySelector('.nera-sl-calendar-hint');
	var elCal = document.getElementById('nera-sl-calendar');
	var elChips = document.getElementById('nera-sl-chips');
	var elMsg = document.getElementById('nera-sl-message');
	var elSave = document.getElementById('nera-sl-save');
	var elStatus = document.getElementById('nera-sl-status');
	var dlg = document.getElementById('nera-sl-confirm');

	// ---- State ----
	var startCfg = cfg.config || {};
	var periods = new Set(
		startCfg.type === 'custom' && Array.isArray(startCfg.custom_periods)
			? startCfg.custom_periods
			: []
	);
	var viewDate = new Date(); // For day/week/month calendars (visible month).
	var viewYearBase = viewDate.getFullYear() - (viewDate.getFullYear() % 12); // year grid origin.

	// ---- Helpers ----
	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}
	function dayToken(d) {
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	}
	function mondayOf(d) {
		var x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
		var dow = (x.getDay() + 6) % 7; // 0 = Monday.
		x.setDate(x.getDate() - dow);
		return x;
	}
	function monthToken(y, m) {
		return y + '-' + pad(m + 1);
	}
	function show(el, visible) {
		if (el) {
			el.classList.toggle('hidden', !visible);
		}
	}
	var msgTimer = null;
	var msgHideTimer = null;
	function message(text, kind) {
		if (!elMsg) {
			return;
		}
		// Reset any in-flight auto-hide so repeated saves restart the 10s timer.
		if (msgTimer) {
			clearTimeout(msgTimer);
		}
		if (msgHideTimer) {
			clearTimeout(msgHideTimer);
		}
		elMsg.textContent = text;
		elMsg.classList.remove('hidden', 'is-success', 'is-error', 'is-leaving');
		elMsg.classList.add(kind === 'error' ? 'is-error' : 'is-success');

		// Auto-hide after 5s with a short fade-out.
		msgTimer = setTimeout(function () {
			elMsg.classList.add('is-leaving');
			msgHideTimer = setTimeout(function () {
				elMsg.classList.add('hidden');
				elMsg.classList.remove('is-leaving');
			}, 350);
		}, 5000);
	}

	// ---- Confirmation dialog ----
	function confirmDialog(onOk) {
		if (!dlg || typeof dlg.showModal !== 'function') {
			if (window.confirm(i18n.removeBody || 'Remove?')) {
				onOk();
			}
			return;
		}
		dlg.querySelector('.nera-sl-confirm-title-text').textContent = i18n.removeTitle || 'Remove this period?';
		dlg.querySelector('.nera-sl-confirm-body').textContent = i18n.removeBody || '';
		dlg.querySelector('.nera-sl-confirm-cancel').textContent = i18n.cancel || 'Cancel';
		dlg.querySelector('.nera-sl-confirm-ok').textContent = i18n.remove || 'Remove';

		var cancel = dlg.querySelector('.nera-sl-confirm-cancel');
		var ok = dlg.querySelector('.nera-sl-confirm-ok');

		function cleanup() {
			cancel.removeEventListener('click', onCancel);
			ok.removeEventListener('click', onConfirm);
		}
		function onCancel() {
			cleanup();
			dlg.close();
		}
		function onConfirm() {
			cleanup();
			dlg.close();
			onOk();
		}
		cancel.addEventListener('click', onCancel);
		ok.addEventListener('click', onConfirm);
		dlg.showModal();
	}

	// ---- Populate selects ----
	function buildOptions(select, items, selected) {
		select.innerHTML = '';
		items.forEach(function (it) {
			var o = document.createElement('option');
			o.value = it.value;
			o.textContent = it.label;
			if (it.value === selected) {
				o.selected = true;
			}
			select.appendChild(o);
		});
	}

	// ---- Amount (free numeric text field, no upper cap) ----
	// Returns the entered amount as a number, or 0 when blank/invalid. The server
	// enforces the "at least 1" rule on save.
	function readAmount() {
		var v = parseFloat(String(elNumber.value).replace(/[^0-9.]/g, ''));
		return isNaN(v) || v < 0 ? 0 : v;
	}
	function initAmount() {
		// Keep only digits and a single decimal point as the customer types.
		elNumber.addEventListener('input', function () {
			var cleaned = elNumber.value.replace(/[^0-9.]/g, '');
			var parts = cleaned.split('.');
			if (parts.length > 2) {
				cleaned = parts.shift() + '.' + parts.join('');
			}
			if (elNumber.value !== cleaned) {
				elNumber.value = cleaned;
			}
		});
	}

	// ---- Calendar rendering ----
	function subtype() {
		return elSubtype ? elSubtype.value : 'day';
	}

	function renderCalendar() {
		if (!elCal) {
			return;
		}
		var st = subtype();
		if (st === 'day' || st === 'week') {
			renderDayWeek(st);
		} else if (st === 'month') {
			renderMonths();
		} else {
			renderYears();
		}
		renderChips();
	}

	function head(title, prevFn, nextFn) {
		var wrap = document.createElement('div');
		wrap.className = 'nera-sl-cal-head';
		var prev = document.createElement('button');
		prev.type = 'button';
		prev.className = 'nera-sl-cal-nav';
		prev.innerHTML = '&#8249;';
		prev.addEventListener('click', prevFn);
		var t = document.createElement('div');
		t.className = 'nera-sl-cal-title';
		t.textContent = title;
		var next = document.createElement('button');
		next.type = 'button';
		next.className = 'nera-sl-cal-nav';
		next.innerHTML = '&#8250;';
		next.addEventListener('click', nextFn);
		wrap.appendChild(prev);
		wrap.appendChild(t);
		wrap.appendChild(next);
		return wrap;
	}

	var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	var DOW = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

	function renderDayWeek(st) {
		elCal.innerHTML = '';
		var y = viewDate.getFullYear();
		var m = viewDate.getMonth();
		elCal.appendChild(
			head(MONTHS[m] + ' ' + y,
				function () { viewDate = new Date(y, m - 1, 1); renderCalendar(); },
				function () { viewDate = new Date(y, m + 1, 1); renderCalendar(); }
			)
		);

		var grid = document.createElement('div');
		grid.className = 'nera-sl-cal-grid is-days';

		DOW.forEach(function (d) {
			var c = document.createElement('div');
			c.className = 'nera-sl-cal-dow';
			c.textContent = d;
			grid.appendChild(c);
		});

		var first = new Date(y, m, 1);
		var offset = (first.getDay() + 6) % 7; // Monday-based leading blanks.
		for (var b = 0; b < offset; b++) {
			var empty = document.createElement('div');
			empty.className = 'nera-sl-cal-cell is-empty';
			grid.appendChild(empty);
		}

		var daysInMonth = new Date(y, m + 1, 0).getDate();
		var todayTok = dayToken(new Date());
		for (var day = 1; day <= daysInMonth; day++) {
			(function (dayNum) {
				var cellDate = new Date(y, m, dayNum);
				var cell = document.createElement('button');
				cell.type = 'button';
				cell.className = 'nera-sl-cal-cell';
				cell.textContent = String(dayNum);

				var tok = st === 'day' ? dayToken(cellDate) : dayToken(mondayOf(cellDate));
				if (periods.has(tok)) {
					cell.classList.add('is-selected');
				}
				if (dayToken(cellDate) === todayTok) {
					cell.classList.add('is-today');
				}
				cell.addEventListener('click', function () {
					toggle(tok);
				});
				grid.appendChild(cell);
			})(day);
		}
		elCal.appendChild(grid);
	}

	function renderMonths() {
		elCal.innerHTML = '';
		var y = viewDate.getFullYear();
		elCal.appendChild(
			head(String(y),
				function () { viewDate = new Date(y - 1, 0, 1); renderCalendar(); },
				function () { viewDate = new Date(y + 1, 0, 1); renderCalendar(); }
			)
		);
		var grid = document.createElement('div');
		grid.className = 'nera-sl-cal-grid is-months';
		for (var m = 0; m < 12; m++) {
			(function (month) {
				var cell = document.createElement('button');
				cell.type = 'button';
				cell.className = 'nera-sl-cal-cell';
				cell.textContent = MONTHS[month].slice(0, 3);
				var tok = monthToken(y, month);
				if (periods.has(tok)) {
					cell.classList.add('is-selected');
				}
				cell.addEventListener('click', function () {
					toggle(tok);
				});
				grid.appendChild(cell);
			})(m);
		}
		elCal.appendChild(grid);
	}

	function renderYears() {
		elCal.innerHTML = '';
		var base = viewYearBase;
		elCal.appendChild(
			head(base + ' – ' + (base + 11),
				function () { viewYearBase -= 12; renderCalendar(); },
				function () { viewYearBase += 12; renderCalendar(); }
			)
		);
		var grid = document.createElement('div');
		grid.className = 'nera-sl-cal-grid is-years';
		for (var i = 0; i < 12; i++) {
			(function (year) {
				var cell = document.createElement('button');
				cell.type = 'button';
				cell.className = 'nera-sl-cal-cell';
				cell.textContent = String(year);
				var tok = String(year);
				if (periods.has(tok)) {
					cell.classList.add('is-selected');
				}
				cell.addEventListener('click', function () {
					toggle(tok);
				});
				grid.appendChild(cell);
			})(base + i);
		}
		elCal.appendChild(grid);
	}

	function toggle(tok) {
		if (periods.has(tok)) {
			periods.delete(tok);
			renderCalendar();
		} else {
			periods.add(tok);
			renderCalendar();
		}
	}

	// ---- Chips ----
	function chipLabel(tok) {
		var st = subtype();
		if (st === 'day') {
			return tok;
		}
		if (st === 'week') {
			var d = new Date(tok + 'T00:00:00');
			var end = new Date(d.getFullYear(), d.getMonth(), d.getDate() + 6);
			return tok + ' → ' + dayToken(end);
		}
		if (st === 'month') {
			var parts = tok.split('-');
			return MONTHS[parseInt(parts[1], 10) - 1] + ' ' + parts[0];
		}
		return tok; // year
	}

	function renderChips() {
		if (!elChips) {
			return;
		}
		elChips.innerHTML = '';
		var sorted = Array.from(periods).sort();
		sorted.forEach(function (tok) {
			var chip = document.createElement('span');
			chip.className = 'nera-sl-chip';
			var text = document.createElement('span');
			text.textContent = chipLabel(tok);
			chip.appendChild(text);

			var rm = document.createElement('button');
			rm.type = 'button';
			rm.className = 'nera-sl-chip-remove';
			rm.setAttribute('aria-label', 'Remove');
			rm.innerHTML = '&times;';
			rm.addEventListener('click', function () {
				confirmDialog(function () {
					periods.delete(tok);
					renderCalendar();
				});
			});
			chip.appendChild(rm);
			elChips.appendChild(chip);
		});
	}

	// ---- Enable toggle ----
	function applyEnabledVisibility() {
		var on = elToggle ? elToggle.checked : true;
		show(elFields, on);
	}

	// ---- Type switching ----
	function applyTypeVisibility() {
		var isCustom = elType.value === 'custom';
		show(elSubtypeWrap, isCustom);
		show(elCalWrap, isCustom);
		if (isCustom) {
			if (elCalHint) {
				elCalHint.textContent = i18n.selectPeriods || '';
			}
			renderCalendar();
		}
	}

	// ---- Save ----
	function save() {
		var body = new URLSearchParams();
		body.append('action', cfg.saveAction);
		body.append('nonce', cfg.nonce);
		body.append('enabled', elToggle && elToggle.checked ? '1' : '0');
		body.append('amount', String(readAmount()));
		body.append('type', elType.value);
		if (elType.value === 'custom') {
			body.append('custom_subtype', subtype());
			Array.from(periods).forEach(function (tok) {
				body.append('custom_periods[]', tok);
			});
		}

		elSave.disabled = true;
		var original = elSave.innerHTML;
		elSave.innerHTML = '<span class="material-symbols-outlined text-xl">hourglass_top</span>' + (i18n.saving || 'Saving…');

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (res) {
				if (res && res.success) {
					if (elStatus && res.data && res.data.statusHtml) {
						elStatus.innerHTML = res.data.statusHtml;
					}
					message((res.data && res.data.message) || i18n.saved, 'success');
				} else {
					message((res && res.data && res.data.message) || i18n.genericError, 'error');
				}
			})
			.catch(function () {
				message(i18n.genericError, 'error');
			})
			.finally(function () {
				elSave.disabled = false;
				elSave.innerHTML = original;
			});
	}

	// ---- Init ----
	var types = cfg.enabledTypes || [];
	buildOptions(elType, types, startCfg.type || cfg.defaultType);

	// When the CMS exposes a single limit type, hide the dropdown and force that
	// type — the customer has no choice to make.
	if (types.length <= 1) {
		var typeRow = document.getElementById('nera-sl-type-row');
		if (typeRow) {
			typeRow.classList.add('hidden');
		}
		if (types.length === 1) {
			elType.value = types[0].value;
		}
	}

	if (elSubtype) {
		buildOptions(elSubtype, cfg.customSubtypes || [], startCfg.custom_subtype || 'day');
	}
	initAmount();

	if (elToggle) {
		elToggle.addEventListener('change', applyEnabledVisibility);
	}
	elType.addEventListener('change', applyTypeVisibility);
	if (elSubtype) {
		elSubtype.addEventListener('change', function () {
			// Tokens differ per subtype; clear when the subtype changes.
			periods.clear();
			renderCalendar();
		});
	}
	elSave.addEventListener('click', save);

	applyEnabledVisibility();
	applyTypeVisibility();
})();
