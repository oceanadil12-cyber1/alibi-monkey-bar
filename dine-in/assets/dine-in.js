/**
 * Customer-facing dine-in ordering — Option 1: Welcome Screen.
 *
 * Manages screen transitions and name validation.
 * The URL /dine-in/table/{N} never changes throughout the flow.
 */
(function () {
  'use strict';

  var app = document.getElementById('dinein-app');
  if (!app) return;

  var TABLE = parseInt(app.dataset.table, 10);

  // --- State ---
  var state = {
    screen: 'welcome',
    customerName: ''
  };

  // --- DOM refs ---
  var nameForm   = document.getElementById('dinein-name-form');
  var nameInput  = document.getElementById('dinein-name');
  var nameError  = document.getElementById('dinein-name-error');
  var startBtn   = document.getElementById('dinein-start-btn');

  // --- Name validation: letters and spaces only ---
  var NAME_RE = /^[A-Za-z][A-Za-z ]*$/;

  function validateName(value) {
    var trimmed = value.trim();
    if (trimmed.length === 0) return 'Please enter your name.';
    if (!NAME_RE.test(trimmed))  return 'Letters only, please.';
    if (trimmed.length < 2)      return 'Name must be at least 2 characters.';
    if (trimmed.length > 30)     return 'Name is too long.';
    return '';
  }

  function setFieldError(msg) {
    nameError.textContent = msg;
    if (msg) {
      nameInput.classList.add('dinein-field--invalid');
    } else {
      nameInput.classList.remove('dinein-field--invalid');
    }
  }

  // --- Filter non-alpha characters as the user types ---
  nameInput.addEventListener('input', function () {
    var raw = nameInput.value;
    var filtered = raw.replace(/[^A-Za-z ]/g, '');
    if (filtered !== raw) nameInput.value = filtered;

    if (nameInput.classList.contains('dinein-field--invalid')) {
      var err = validateName(filtered);
      if (!err) setFieldError('');
    }
  });

  nameInput.addEventListener('blur', function () {
    var err = validateName(nameInput.value);
    if (err && nameInput.value.trim().length > 0) setFieldError(err);
  });

  // --- Form submission ---
  nameForm.addEventListener('submit', function (e) {
    e.preventDefault();

    var err = validateName(nameInput.value);
    if (err) {
      setFieldError(err);
      nameInput.focus();
      return;
    }

    state.customerName = nameInput.value.trim();
    showScreen('menu');
  });

  // --- Screen management ---
  function showScreen(name) {
    var screens = app.querySelectorAll('.dinein-screen');
    for (var i = 0; i < screens.length; i++) {
      screens[i].classList.remove('dinein-screen--active');
    }
    var target = document.getElementById('screen-' + name);
    if (target) {
      target.classList.add('dinein-screen--active');
      state.screen = name;
    }

    window.scrollTo(0, 0);
  }

  /** Reset the entire flow back to the welcome screen (used after order completes). */
  function resetFlow() {
    nameInput.value = '';
    setFieldError('');
    state.customerName = '';
    showScreen('welcome');
    nameInput.focus();
  }

  // Expose for later options to use
  window.DineIn = {
    table:    TABLE,
    state:    state,
    showScreen: showScreen,
    resetFlow:  resetFlow
  };
})();
