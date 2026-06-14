/**
 * Nera Spending Limit — Checkout.
 * Reacts to the server-rendered status card (#nera-sl-checkout-card[data-state]):
 *  - over_blocked  : disable the Place order button (wallet can't cover).
 *  - over_soft     : intercept submit, ask the customer to confirm, then continue.
 *  - ok / none     : no interference.
 * The server (class-checkout.php) remains the authoritative enforcer.
 */
(function () {
	'use strict';

	var cfg = window.neraSpendLimitCheckout || {};
	var i18n = cfg.i18n || {};
	var ackField = cfg.ackField || 'nera_sl_ack';

	function form() {
		return document.querySelector('form.checkout, form.woocommerce-checkout');
	}
	function card() {
		return document.getElementById('nera-sl-checkout-card');
	}
	function placeBtn() {
		return document.getElementById('place_order');
	}
	function ackInput() {
		return document.querySelector('input[name="' + ackField + '"]');
	}
	function state() {
		var c = card();
		return c ? c.getAttribute('data-state') : 'none';
	}

	// ---- Confirmation dialog (built once, appended to body) ----
	var dlg = null;
	function buildDialog() {
		if (dlg) {
			return dlg;
		}
		dlg = document.createElement('dialog');
		dlg.id = 'nera-sl-checkout-confirm';
		dlg.className = 'nera-sl-dialog';
		dlg.innerHTML =
			'<div class="nera-sl-dialog-inner">' +
			'<div class="nera-sl-dialog-head">' +
			'<span class="nera-sl-dialog-badge is-warning"><span class="material-symbols-outlined">account_balance_wallet</span></span>' +
			'<h4 class="nera-sl-dialog-title nera-sl-co-title"></h4>' +
			'</div>' +
			'<div class="nera-sl-dialog-body"><p class="nera-sl-co-body"></p></div>' +
			'<div class="nera-sl-dialog-actions">' +
			'<button type="button" class="nera-sl-btn nera-sl-btn-ghost nera-sl-co-cancel"></button>' +
			'<button type="button" class="nera-sl-btn nera-sl-btn-primary nera-sl-co-ok"></button>' +
			'</div>' +
			'</div>';
		document.body.appendChild(dlg);
		return dlg;
	}

	function confirmMessage() {
		var c = card();
		var msg = c ? c.getAttribute('data-confirm-msg') : '';
		return msg && msg.trim() ? msg : (i18n.confirmBody || '');
	}

	function confirmContinue(onOk) {
		var d = buildDialog();
		d.querySelector('.nera-sl-co-title').textContent = i18n.confirmTitle || 'Over your spending limit';
		d.querySelector('.nera-sl-co-body').textContent = confirmMessage();
		var cancel = d.querySelector('.nera-sl-co-cancel');
		var ok = d.querySelector('.nera-sl-co-ok');
		cancel.textContent = i18n.cancel || 'Cancel';
		ok.textContent = i18n.continue || 'Continue anyway';

		function cleanup() {
			cancel.removeEventListener('click', onCancel);
			ok.removeEventListener('click', onConfirm);
		}
		function onCancel() {
			cleanup();
			d.close();
		}
		function onConfirm() {
			cleanup();
			d.close();
			onOk();
		}
		cancel.addEventListener('click', onCancel);
		ok.addEventListener('click', onConfirm);

		if (typeof d.showModal === 'function') {
			d.showModal();
		} else if (window.confirm((i18n.confirmTitle || '') + '\n\n' + confirmMessage())) {
			onConfirm();
		}
	}

	// ---- Place-order button enable/disable ----
	function syncButton() {
		var btn = placeBtn();
		if (!btn) {
			return;
		}
		if (state() === 'over_blocked') {
			btn.classList.add('nera-sl-disabled');
			btn.setAttribute('aria-disabled', 'true');
		} else {
			btn.classList.remove('nera-sl-disabled');
			btn.removeAttribute('aria-disabled');
		}
	}

	// ---- Submit interception (capture phase, before WooCommerce's handler) ----
	function onSubmitCapture(e) {
		var st = state();

		if (st === 'over_blocked') {
			e.preventDefault();
			e.stopImmediatePropagation();
			syncButton();
			return;
		}

		if (st === 'over_soft') {
			var ack = ackInput();
			if (ack && ack.value === '1') {
				return; // Already confirmed — let WooCommerce proceed.
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			confirmContinue(function () {
				if (ack) {
					ack.value = '1';
				}
				var f = form();
				if (f) {
					if (typeof f.requestSubmit === 'function') {
						f.requestSubmit();
					} else {
						f.submit();
					}
				}
			});
		}
	}

	function bind() {
		var f = form();
		if (f && !f.__neraSlBound) {
			f.addEventListener('submit', onSubmitCapture, true); // capture.
			f.__neraSlBound = true;
		}
		syncButton();
	}

	// Initial + after every AJAX checkout refresh.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind);
	} else {
		bind();
	}

	if (window.jQuery) {
		window.jQuery(document.body).on('updated_checkout', function () {
			// Fragment replaced the card; re-sync button (ack input was reset to 0).
			syncButton();
		});
	}
})();
