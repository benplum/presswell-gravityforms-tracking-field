'use strict';
(function (window, document) {
  if (!window || !document) {
    return;
  }

  var config = window.presswellSignalRelayConfig || {};
  var storageKey = config.storageKey || 'pwSignalRelay';
  var ttlMs = Math.max(parseInt(config.ttl, 10) || 0, 0) * 1000;
  var transceiverKeys = Array.isArray(config.transceiverKeys) ? config.transceiverKeys : [];

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
    if (!transceiverKeys.length) {
      return values;
    }

    var params = new URLSearchParams(window.location.search || '');
    transceiverKeys.forEach(function (key) {
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

  function hasFreshTrackedValues(fresh) {
    return !!(fresh && Object.keys(fresh).length);
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

    var inputs = document.querySelectorAll('[data-presswell-transceiver]');
    if (!inputs.length) {
      return;
    }

    inputs.forEach(function (input) {
      var key = input.getAttribute('data-presswell-transceiver');
      if (!key) {
        return;
      }
      input.value = values[key] || '';
    });
  }

  function injectForminatorFallbackInputs() {
    if (!transceiverKeys.length) {
      return;
    }

    var forms = document.querySelectorAll('.forminator-ui form');
    if (!forms.length) {
      return;
    }

    forms.forEach(function (form) {
      if (form.querySelector('[data-presswell-transceiver-adapter="forminator"]')) {
        return;
      }

      var wrapper = document.createElement('div');
      wrapper.className = 'presswell-transceiver presswell-forminator-transceiver';
      wrapper.setAttribute('data-presswell-transceiver', '1');
      wrapper.setAttribute('data-presswell-transceiver-adapter', 'forminator');
      wrapper.setAttribute('aria-hidden', 'true');
      wrapper.style.display = 'none';

      transceiverKeys.forEach(function (key) {
        if (!key) {
          return;
        }

        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = '';
        input.setAttribute('data-presswell-transceiver', key);
        wrapper.appendChild(input);
      });

      form.appendChild(wrapper);
    });
  }

  function buildTrackingPayload(values) {
    var payload = {};
    if (!values || typeof values !== 'object') {
      return payload;
    }

    Object.keys(values).forEach(function (key) {
      if (!key || values[key] === undefined || values[key] === null || values[key] === '') {
        return;
      }
      payload[key] = values[key];
    });

    return payload;
  }

  // function syncNinjaTrackingFields(values) {
  //   var payload = buildTrackingPayload(values);
  //   var json = JSON.stringify(payload);
  //   var forms = document.querySelectorAll('form[id^="nf-form-"]');

  //   if (!forms.length) {
  //     return;
  //   }

  //   forms.forEach(function (form) {
  //     var trackingInputs = form.querySelectorAll('.presswell_tracking-wrap input[type="hidden"], .presswell_tracking-container input[type="hidden"]');

  //     trackingInputs.forEach(function (input) {
  //       input.value = json;
  //     });
  //   });

  //   if (!Array.isArray(window.nfForms)) {
  //     return;
  //   }

  //   window.nfForms.forEach(function (nfForm) {
  //     if (!nfForm || !Array.isArray(nfForm.fields)) {
  //       return;
  //     }

  //     nfForm.fields.forEach(function (field) {
  //       var settings = field && field.settings ? field.settings : field;
  //       if (!settings || settings.type !== 'presswell_tracking') {
  //         return;
  //       }

  //       settings.value = json;
  //       if (field && typeof field === 'object') {
  //         field.value = json;
  //       }

  //       if (!settings.id) {
  //         return;
  //       }

  //       var domInput = document.getElementById('nf-field-' + settings.id);
  //       if (domInput) {
  //         domInput.value = json;
  //       }
  //     });
  //   });
  // }

  function syncSignals() {
    var stored = readStorage() || {};
    var fresh = getQueryValues();

    // If this page has tracked query params, treat it as a new attribution context.
    // Do not merge with prior persisted values from an older visit.
    var base = hasFreshTrackedValues(fresh) ? {} : stored;
    var merged = mergeValues(base, fresh);
    merged = ensureDerivedValues(merged);

    if (Object.keys(merged).length) {
      writeStorage(merged);
    }

    injectForminatorFallbackInputs();
    populateInputs(merged);
    // syncNinjaTrackingFields(merged);
  }

  function init() {
    syncSignals();

    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (form && form.matches && form.matches('form[id^="nf-form-"]')) {
        syncSignals();
      }
    }, true);

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) {
        return;
      }

      if (target.closest('form[id^="nf-form-"]')) {
        syncSignals();
      }
    }, true);

    if (typeof MutationObserver !== 'function') {
      return;
    }

    var observer = new MutationObserver(function () {
      syncSignals();
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
