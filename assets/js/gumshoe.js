'use strict';
(function (window, document) {
  if (!window || !document) {
    return;
  }

  var config = window.presswellGFGumshoeConfig || {};
  var storageKey = config.storageKey || 'gfGumshoe';
  var ttlMs = Math.max(parseInt(config.ttl, 10) || 0, 0) * 1000;
  var gumshoeKeys = Array.isArray(config.gumshoeKeys) ? config.gumshoeKeys : [];

  function encodePayload(payload) {
    try {
      var json = JSON.stringify(payload);
      if (typeof window.TextEncoder === 'function') {
        var bytes = new TextEncoder().encode(json);
        var binary = '';
        bytes.forEach(function (byte) {
          binary += String.fromCharCode(byte);
        });
        return window.btoa(binary);
      }
      return window.btoa(unescape(encodeURIComponent(json)));
    } catch (err) {
      return null;
    }
  }

  function decodePayload(raw) {
    if (!raw) {
      return null;
    }
    try {
      var binary = window.atob(raw);
      var json;
      if (typeof window.TextDecoder === 'function') {
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) {
          bytes[i] = binary.charCodeAt(i);
        }
        json = new TextDecoder().decode(bytes);
      } else {
        json = decodeURIComponent(escape(binary));
      }
      return JSON.parse(json);
    } catch (err) {
      try {
        return JSON.parse(raw);
      } catch (innerErr) {
        return null;
      }
    }
  }

  function readStorage() {
    try {
      var stored = decodePayload(window.localStorage.getItem(storageKey));
      if (!stored || typeof stored !== 'object') {
        return null;
      }
      var nowTs = Date.now();
      if (ttlMs > 0 && stored.timestamp && nowTs - stored.timestamp > ttlMs) {
        window.localStorage.removeItem(storageKey);
        return null;
      }
      return stored;
    } catch (err) {
      return null;
    }
  }

  function writeStorage(data) {
    try {
      data.timestamp = Date.now();
      var encoded = encodePayload(data);
      if (encoded) {
        window.localStorage.setItem(storageKey, encoded);
      } else {
        window.localStorage.setItem(storageKey, JSON.stringify(data));
      }
    } catch (err) {
      // no-op
    }
  }

  function getQueryValues() {
    var values = {};
    if (!gumshoeKeys.length) {
      return values;
    }
    var params = new URLSearchParams(window.location.search || '');
    gumshoeKeys.forEach(function (key) {
      if (!key) {
        return;
      }
      var paramValue = params.get(key);
      if (paramValue !== null && paramValue !== '') {
        values[key] = paramValue;
      }
    });
    return values;
  }

  function mergeValues(existing, fresh) {
    var merged = existing ? Object.assign({}, existing) : {};
    Object.keys(fresh || {}).forEach(function (key) {
      if (fresh[key] !== undefined && fresh[key] !== null) {
        merged[key] = fresh[key];
      }
    });
    return merged;
  }

  function ensureDerivedValues(values) {
    var updated = Object.assign({}, values);
    if (!updated.landing_page) {
      updated.landing_page = window.location.href;
    }
    if (!updated.landing_query) {
      updated.landing_query = window.location.search || '';
    }
    if (!updated.referrer && document.referrer) {
      updated.referrer = document.referrer;
    }
    return updated;
  }

  function populateInputs(values) {
    if (!values) {
      return;
    }
    var inputs = document.querySelectorAll('[data-presswell-gumshoe]');
    if (!inputs.length) {
      return;
    }
    inputs.forEach(function (input) {
      var key = input.getAttribute('data-presswell-gumshoe');
      if (!key) {
        return;
      }
      var val = values[key] || '';
      input.value = val;
    });
  }

  function init() {
    var stored = readStorage() || {};
    var fresh = getQueryValues();

    var merged = mergeValues(stored, fresh);
    merged = ensureDerivedValues(merged);

    if (Object.keys(merged).length) {
      writeStorage(merged);
    }

    populateInputs(merged);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
