'use strict';

const lengthInput = document.querySelector('#length');
const preview = document.querySelector('#code-preview');
const example = 'aB3xZ7kP2mQ9nR4sT6vW8yD1eF5gH0jL';
if (lengthInput && preview) {
  lengthInput.addEventListener('input', () => {
    const length = Number(lengthInput.value);
    if (!Number.isInteger(length) || length < 3 || length > 32) return;
    const code = document.createElement('strong');
    code.textContent = example.slice(0, length);
    preview.replaceChildren(document.createTextNode(preview.dataset.base), code);
  });
}

let toastTimer;
function showToast(message) {
  const toast = document.querySelector('#toast');
  if (!toast) return;
  clearTimeout(toastTimer);
  toast.textContent = message;
  toast.hidden = false;
  toastTimer = setTimeout(() => { toast.hidden = true; }, 3500);
}

async function copyLink(value) {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(value);
      return;
    } catch (_) { /* A kézi másolás akkor is működik, ha a böngésző tiltja a vágólapot. */ }
  }
  window.prompt('Másold ki a rövid linket (Ctrl+C vagy ⌘C):', value);
  throw new Error('manual-copy');
}

document.querySelectorAll('[data-copy]').forEach((button) => {
  button.addEventListener('click', async () => {
    try {
      await copyLink(button.dataset.copy);
      showToast('A rövid link a vágólapra került.');
    } catch (_) {
      showToast('A linket a megjelenő mezőből másolhatod ki.');
    }
  });
});

const form = document.querySelector('#shorten-form');
if (form) {
  form.addEventListener('submit', () => {
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    button.textContent = 'Rövidítés…';
  });
  window.addEventListener('pageshow', () => {
    const button = form.querySelector('[type="submit"]');
    if (button.disabled) {
      button.disabled = false;
      button.textContent = 'Link rövidítése →';
    }
  });
}

const result = document.querySelector('#result');
if (result) result.focus({ preventScroll: true });

