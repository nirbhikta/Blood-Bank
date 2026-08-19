
(function (global) {
  'use strict';

  // ---- Patterns (single source of truth; the PHP layer mirrors these) ----
  var VPATTERNS = {
    fullName: /^[a-zA-Z\s]{3,60}$/,
    phone:    /^98\d{8}$/,
    email:    /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
    password: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/,
    // Hospitals and emergency contacts use landlines (01-4221119), which the
    // mobile-only rule above would reject.
    anyPhone: /^[0-9][0-9\-\s()+]{6,19}$/
  };

  // ---- Ready-made rule sets ----
  var VRULES = {
    fullName: {
      required: true, pattern: VPATTERNS.fullName, minLength: 3, maxLength: 60,
      errorMsg: 'Full name must contain only letters and spaces (min 3 characters)'
    },
    phone: {
      required: true, pattern: VPATTERNS.phone, mask: 'phone',
      errorMsg: 'Phone number must start with 98 and be exactly 10 digits (e.g. 9800000000)'
    },
    anyPhone: {
      required: true, pattern: VPATTERNS.anyPhone,
      errorMsg: 'Please enter a valid phone number'
    },
    email: {
      required: true, pattern: VPATTERNS.email,
      errorMsg: 'Please enter a valid email address'
    },
    optionalEmail: {
      pattern: VPATTERNS.email,
      errorMsg: 'Please enter a valid email address'
    },
    password: {
      required: true, pattern: VPATTERNS.password,
      errorMsg: 'Password must be at least 8 characters with uppercase, lowercase and a number'
    },
    bloodGroup: {
      required: true,
      errorMsg: 'Please select your blood group'
    },
    address: {
      required: true, minLength: 7,
      errorMsg: 'Please enter your address'
    },
    dropdown: {
      required: true,
      errorMsg: 'Please select an option'
    },
    requiredText: {
      required: true,
      errorMsg: 'This field is required'
    }
  };

  // ---- DOM plumbing ----

  function fieldOf(input, rules) {
    if (rules && rules.container) {
      var explicit = document.querySelector(rules.container);
      if (explicit) return explicit;
    }
    return (input.closest && input.closest('.field')) || input.parentElement;
  }

  // A radio group reports the checked member, not whichever input we happened
  // to look up by name.
  function groupValue(input) {
    var scope = input.form || document;
    var checked = scope.querySelector('[name="' + input.name + '"]:checked');
    return checked ? String(checked.value) : '';
  }

  // Reuses the .error-msg span a page already ships; creates one otherwise.
  function errorSpanOf(input, rules) {
    var field = fieldOf(input, rules);
    if (!field) return null;

    var span = null;
    for (var i = 0; i < field.children.length; i++) {
      if (field.children[i].classList &&
          field.children[i].classList.contains('error-msg')) {
        span = field.children[i];
        break;
      }
    }
    if (!span) span = field.querySelector('.error-msg');

    if (!span) {
      span = document.createElement('span');
      span.className = 'error-msg';
      field.appendChild(span);
    }
    return span;
  }

  function setFieldState(input, error, rules) {
    var field   = fieldOf(input, rules);
    var span    = errorSpanOf(input, rules);
    var touched = input.type === 'radio'
      ? groupValue(input) !== ''
      : (input.value !== '' && input.value != null);

    input.classList.toggle('field-error', !!error);
    input.classList.toggle('field-success', !error && touched);
    if (field) field.classList.toggle('has-error', !!error);

    if (span) {
      if (error) {
        span.textContent = error;
        span.classList.add('show');
      } else {
        span.classList.remove('show');
      }
    }
  }

  function clearFieldState(input, rules) {
    var field = fieldOf(input, rules);
    var span  = errorSpanOf(input, rules);
    input.classList.remove('field-error', 'field-success');
    if (field) field.classList.remove('has-error');
    if (span) span.classList.remove('show');
  }

  // ---- The validator ----

  
  function validateField(input, rules) {
    if (!input) return true;
    rules = rules || {};

    var value;
    if (input.type === 'checkbox')   value = input.checked ? 'on' : '';
    else if (input.type === 'radio') value = groupValue(input);
    else value = String(input.value == null ? '' : input.value).trim();
    var error = '';

    if (rules.required && !value) {
      error = rules.requiredMsg || rules.errorMsg || 'This field is required';
    } else if (value) {
      if (rules.minLength && value.length < rules.minLength) {
        error = rules.errorMsg || ('Must be at least ' + rules.minLength + ' characters');
      } else if (rules.maxLength && value.length > rules.maxLength) {
        error = rules.errorMsg || ('Must be at most ' + rules.maxLength + ' characters');
      } else if (rules.pattern && !rules.pattern.test(value)) {
        error = rules.errorMsg || 'Please check this value';
      } else if (rules.match) {
        var other = typeof rules.match === 'function'
          ? rules.match()
          : (input.form ? input.form.querySelector(rules.match) : null);
        var otherValue = other && other.value != null ? String(other.value) : String(other || '');
        if (otherValue !== String(input.value)) error = rules.errorMsg || 'Values do not match';
      }

      if (!error && typeof rules.custom === 'function') {
        error = rules.custom(value, input) || '';
      }
    }

    setFieldState(input, error, rules);
    return !error;
  }

  // ---- Phone input mask: digits only, capped at 10 ----
  function maskPhone(input, maxLength) {
    if (!input) return;
    var max = maxLength || 10;
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('maxlength', String(max));
    input.addEventListener('input', function () {
      var digits = this.value.replace(/\D/g, '').slice(0, max);
      if (digits !== this.value) this.value = digits;
    });
  }

  // ---- Wiring ----

  // Accepts a field name ("phone"), an id ("#f_phone") or any CSS selector.
  function resolveInput(form, key) {
    if (!form) return null;
    if (key.charAt(0) === '#' || key.charAt(0) === '.' || key.charAt(0) === '[') {
      return form.querySelector(key) || document.querySelector(key);
    }
    return form.querySelector('[name="' + key + '"]') || form.querySelector('#' + key);
  }

 
  function bindValidation(form, rulesMap) {
    if (!form) return;
    Object.keys(rulesMap).forEach(function (key) {
      var input = resolveInput(form, key);
      if (!input) return;
      var rules = rulesMap[key];

      if (rules.mask === 'phone') maskPhone(input, rules.maskLength || 10);

      var live = (input.tagName === "SELECT" || input.type === "checkbox" ||
                  input.type === "radio" || input.type === "date")
        ? ["change"]
        : ["input", "blur"];

      // Radio groups: listen on every member, validate through the first.
      var targets = input.type === "radio"
        ? Array.prototype.slice.call(form.querySelectorAll("[name=\"" + input.name + "\"]"))
        : [input];

      targets.forEach(function (el) {
        live.forEach(function (evt) {
          el.addEventListener(evt, function () { validateField(input, rules); });
        });
      });

      // Re-check the confirmation box whenever the field it mirrors changes.
      if (rules.match && typeof rules.match !== 'function') {
        var source = form.querySelector(rules.match);
        if (source) {
          source.addEventListener('input', function () {
            if (input.value) validateField(input, rules);
          });
        }
      }
    });
  }

  
  function validateForm(form, rulesMap) {
    if (!form) return true;
    var firstBad = null;

    Object.keys(rulesMap).forEach(function (key) {
      var input = resolveInput(form, key);
      if (!input) return;
      if (!validateField(input, rulesMap[key]) && !firstBad) firstBad = input;
    });

    if (firstBad) {
      if (firstBad.scrollIntoView) {
        firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      try { firstBad.focus({ preventScroll: true }); } catch (e) { firstBad.focus(); }
      return false;
    }
    return true;
  }

  function clearValidation(form, rulesMap) {
    if (!form) return;
    Object.keys(rulesMap).forEach(function (key) {
      var input = resolveInput(form, key);
      if (input) clearFieldState(input, rulesMap[key]);
    });
  }

  
  function applyServerErrors(form, fields) {
    if (!form || !fields) return false;
    var firstBad = null;

    Object.keys(fields).forEach(function (key) {
      var input = resolveInput(form, key);
      if (!input) return;
      setFieldState(input, fields[key]);
      if (!firstBad) firstBad = input;
    });

    if (firstBad && firstBad.scrollIntoView) {
      firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    return !!firstBad;
  }

  // ---- Submit button busy state ----
  function setSubmitting(btn, busy, idleLabel) {
    if (!btn) return;
    if (busy) {
      if (!btn.dataset.idleLabel) btn.dataset.idleLabel = btn.textContent.trim();
      btn.disabled = true;
      btn.classList.add('is-loading');
      btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span>' +
                      (btn.dataset.busyLabel || 'Please wait...');
    } else {
      btn.disabled = false;
      btn.classList.remove('is-loading');
      btn.textContent = idleLabel || btn.dataset.idleLabel || 'Submit';
    }
  }

  // ---- Export ----
  global.VPATTERNS         = VPATTERNS;
  global.VRULES            = VRULES;
  global.validateField     = validateField;
  global.validateForm      = validateForm;
  global.bindValidation    = bindValidation;
  global.clearValidation   = clearValidation;
  global.applyServerErrors = applyServerErrors;
  global.maskPhone         = maskPhone;
  global.setSubmitting     = setSubmitting;

})(window);
