/**
 * Plandalf for MemberPress — front end glue.
 *
 * 1. Identify the logged-in member to Plandalf (signed server-side).
 * 2. When a Plandalf checkout completes, send the buyer to the MemberPress
 *    thank-you page for that membership, tagged with the invoice number.
 * 3. On the thank-you page, wait until the site has applied Plandalf's
 *    signed event for that invoice before confirming access.
 */
(function () {
  'use strict';

  var config = window.PlandalfMepr || {};

  if (config.identity && typeof window.plandalf === 'function') {
    window.plandalf('identify', config.identity);
  }

  function firstInvoice(detail) {
    if (detail && detail.invoice) {
      return detail.invoice;
    }
    var session = (detail && detail.session) || {};
    var invoices = session.completed_invoices || [];
    return invoices[0] || session.invoice || {};
  }

  var redirecting = false;

  function redirectAfterPurchase(event) {
    if (redirecting) {
      return;
    }
    var host = event.target && event.target.closest ? event.target.closest('[data-plandalf-membership]') : null;
    var membershipId = host ? host.getAttribute('data-plandalf-membership') : null;
    var membership = membershipId && config.memberships ? config.memberships[membershipId] : null;
    if (!membership || !membership.thankYouUrl) {
      return;
    }

    var invoice = firstInvoice(event.detail);
    var number = invoice.invoice_number || invoice.ulid;
    if (!number || !invoice.ulid) {
      return;
    }
    redirecting = true;
    var url = new URL(membership.thankYouUrl, window.location.href);
    url.searchParams.set('plandalf_invoice', number);
    // ReadyLaunch looks up its thank-you transaction with trans_num.
    url.searchParams.set('trans_num', number);
    // The private invoice id lets a brand-new member go straight to setting a password.
    url.searchParams.set('plandalf_ref', invoice.ulid);
    window.location.assign(url.toString());
  }

  // Checkout designs may continue to a success page after payment, so their
  // flow-level complete event can arrive much later or never arrive at all.
  // The SDK bridge emits the handle's short `purchase` event on the mount.
  document.addEventListener('plandalf:purchase', redirectAfterPurchase);
  document.addEventListener('plandalf:flow-purchase', redirectAfterPurchase);
  document.addEventListener('plandalf:complete', redirectAfterPurchase);

  if (!config.waitingFor || !config.statusUrl) {
    return;
  }

  var banner = document.createElement('div');
  banner.setAttribute('role', 'status');
  banner.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;' +
    'max-width:560px;padding:14px 20px;border-radius:10px;background:#111827;color:#fff;' +
    'font:15px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.25)';
  banner.textContent = config.i18n.activating;
  document.body.appendChild(banner);

  // Keep the private invoice id out of the address bar, history and bookmarks.
  if (window.history && window.history.replaceState) {
    var clean = new URL(window.location.href);
    clean.searchParams.delete('plandalf_ref');
    window.history.replaceState(null, '', clean.toString());
  }

  var retry = document.createElement('button');
  retry.type = 'button';
  retry.textContent = config.i18n.retry || 'Check membership status';
  retry.style.cssText = 'display:block;margin-top:10px;padding:6px 10px;border:1px solid currentColor;border-radius:6px;background:transparent;color:inherit;cursor:pointer';
  retry.hidden = true;
  var started = Date.now();
  var checking = false;
  retry.addEventListener('click', function () {
    if (checking) return;
    started = Date.now();
    retry.hidden = true;
    banner.textContent = config.i18n.activating;
    poll();
  });

  function poll() {
    checking = true;
    var url = new URL(config.statusUrl, window.location.href);
    url.searchParams.set('invoice', config.waitingFor);
    if (config.ref) {
      url.searchParams.set('ref', config.ref);
    }

    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) { return response.json(); })
      .catch(function () { return {}; })
      .then(function (body) {
        checking = false;
        if (body && body.set_password_url) {
          banner.textContent = config.i18n.setPassword;
          banner.style.background = '#065f46';
          setTimeout(function () { window.location.assign(body.set_password_url); }, 1200);
          return;
        }
        if (body && body.applied) {
          banner.textContent = config.i18n.ready;
          banner.style.background = '#065f46';
          if (document.querySelector('.plandalf-mepr-pending')) {
            setTimeout(function () { window.location.reload(); }, 1200);
            return;
          }
          setTimeout(function () { banner.remove(); }, 6000);
          return;
        }
        if (Date.now() - started > 30000) {
          banner.textContent = config.i18n.slow;
          retry.hidden = false;
          banner.appendChild(retry);
          return;
        }
        setTimeout(poll, 1500);
      });
  }
  poll();
})();
