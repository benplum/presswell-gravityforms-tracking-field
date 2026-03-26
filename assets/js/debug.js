'use strict';
(function (window, document) {
  if (!window || !document) {
    return;
  }

  var closedFlagCookieKey = 'pwsrDebugClosed';
  var cookieMaxAgeSeconds = 60 * 60 * 24 * 365;
  var bound = false;

  function readCookie(name) {
    var cookieString = document.cookie || '';
    if (!cookieString) {
      return null;
    }

    var parts = cookieString.split(';');
    for (var i = 0; i < parts.length; i++) {
      var cookiePart = parts[i].trim();
      if (!cookiePart) {
        continue;
      }

      var separatorIndex = cookiePart.indexOf('=');
      if (separatorIndex < 0) {
        continue;
      }

      var key = cookiePart.slice(0, separatorIndex).trim();
      if (key !== name) {
        continue;
      }

      var value = cookiePart.slice(separatorIndex + 1);
      try {
        return decodeURIComponent(value);
      } catch (err) {
        return value;
      }
    }

    return null;
  }

  function writeCookie(name, value) {
    var cookieValue = encodeURIComponent(value);
    document.cookie = name + '=' + cookieValue + '; path=/; max-age=' + cookieMaxAgeSeconds + '; samesite=lax';
  }

  function readClosedFlag() {
    var raw = readCookie(closedFlagCookieKey);
    if (raw === null) {
      return false;
    }

    return raw === '1';
  }

  function writeClosedFlag(isClosed) {
    writeCookie(closedFlagCookieKey, isClosed ? '1' : '0');
  }

  function applyState(open) {
    var containers = document.querySelectorAll('.presswell-transceiver');
    if (!containers.length) {
      return;
    }

    containers.forEach(function (container) {
      var toggle = container.querySelector('.presswell-debug-toggle');
      var panel = container.querySelector('.presswell-debug-fields');
      if (!toggle || !panel) {
        return;
      }

      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      panel.hidden = !open;
    });
  }

  function bindToggles() {
    if (bound) {
      return;
    }

    document.addEventListener('click', function (event) {
      var toggle = event.target && event.target.closest ? event.target.closest('.presswell-debug-toggle') : null;
      if (!toggle) {
        return;
      }

      var currentlyOpen = toggle.getAttribute('aria-expanded') !== 'false';
      var nextOpen = !currentlyOpen;
      writeClosedFlag(!nextOpen);
      applyState(nextOpen);
    });

    bound = true;
  }

  function init() {
    var openByDefault = !readClosedFlag();
    applyState(openByDefault);
    bindToggles();

    if (typeof MutationObserver !== 'function') {
      return;
    }

    var observer = new MutationObserver(function () {
      applyState(!readClosedFlag());
    });

    observer.observe(document.documentElement, {
      childList: true,
      subtree: true
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
