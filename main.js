'use strict';

// Portfolio demo: all form interaction is local. No requests or personal data are sent.
const menuButton = document.querySelector('.menu-toggle');
const navigation = document.querySelector('#site-nav');
function closeMenu(restoreFocus = false) {
  menuButton.setAttribute('aria-expanded', 'false');
  menuButton.setAttribute('aria-label', 'Открыть меню');
  navigation.classList.remove('is-open');
  if (restoreFocus) menuButton.focus();
}
menuButton.addEventListener('click', () => {
  const open = menuButton.getAttribute('aria-expanded') !== 'true';
  menuButton.setAttribute('aria-expanded', String(open));
  menuButton.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
  navigation.classList.toggle('is-open', open);
});
navigation.addEventListener('click', event => {
  if (event.target.closest('a')) closeMenu();
});
document.addEventListener('click', event => {
  if (!event.target.closest('header')) closeMenu();
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && menuButton.getAttribute('aria-expanded') === 'true') closeMenu(true);
});
window.matchMedia('(min-width:901px)').addEventListener('change', event => {
  if (event.matches) closeMenu();
});

// Native dialog provides focus containment and Escape; restore focus to the source link.
const dialog = document.querySelector('#document-dialog');
const documentImage = document.querySelector('#document-image');
let documentTrigger;
for (const link of document.querySelectorAll('[data-lightbox]')) {
  link.addEventListener('click', event => {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || !dialog.showModal) return;
    event.preventDefault();
    documentTrigger = link;
    document.querySelector('#document-title').textContent = link.dataset.title;
    documentImage.src = link.href;
    documentImage.alt = link.dataset.title;
    document.querySelector('#document-original').href = link.href;
    dialog.showModal();
    document.querySelector('.document-scroll').scrollTop = 0;
    document.body.classList.add('dialog-open');
    document.querySelector('.dialog-close').focus();
  });
}
document.querySelector('.dialog-close').addEventListener('click', () => dialog.close());
dialog.addEventListener('keydown', event => {
  if (event.key !== 'Tab') return;
  const controls = [...dialog.querySelectorAll('button, a[href]')];
  const first = controls[0];
  const last = controls[controls.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
});
dialog.addEventListener('click', event => {
  const bounds = dialog.getBoundingClientRect();
  if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) dialog.close();
});
dialog.addEventListener('close', () => {
  document.body.classList.remove('dialog-open');
  documentTrigger?.focus();
});

const form = document.querySelector('#contact-form');
const nameField = form.elements.name;
const contactField = form.elements.contact;
const consentField = form.elements.personal_data_consent;
const requiredFields = [nameField, contactField, consentField];
consentField.checked = false;
const success = document.querySelector('#form-success');
const submitButton = form.querySelector('[type="submit"]');
submitButton.disabled = false;
function isReasonableEmail(value) {
  if (value.length > 254 || /\s/u.test(value)) return false;
  const parts = value.split('@');
  if (parts.length !== 2) return false;
  const [local, domain] = parts;
  if (!local || local.length > 64 || local.startsWith('.') || local.endsWith('.') || local.includes('..')) return false;
  // Practical unquoted addresses, including plus tags; not a full RFC parser.
  if (!/^[\p{L}\p{N}.!#$%&'*+\/=?^_`{|}~-]+$/u.test(local)) return false;
  const labels = domain.split('.');
  if (labels.length < 2) return false;
  if (!labels.every(label => label.length <= 63 && /^[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?$/u.test(label))) return false;
  const suffix = labels[labels.length - 1];
  return suffix.length >= 2 && /\p{L}/u.test(suffix);
}
function errorFor(field) {
  if (field === consentField) return field.checked ? '' : 'Для обработки обращения необходимо ваше согласие на обработку персональных данных.';
  const value = field.value.trim();
  if (field === nameField) return value ? '' : 'Укажите ваше имя.';
  if (!value) return 'Укажите телефон или e-mail для связи.';
  const email = isReasonableEmail(value);
  const digits = value.replace(/\D/g, '');
  const phone = /^\+?[\d\s().-]+$/.test(value) && digits.length >= 10 && digits.length <= 15;
  return email || phone ? '' : 'Введите e-mail или телефон: от 10 до 15 цифр.';
}
function validate(field) {
  const error = errorFor(field);
  field.setAttribute('aria-invalid', String(Boolean(error)));
  document.getElementById(field.getAttribute('aria-describedby')).textContent = error;
  return !error;
}
for (const field of [nameField, contactField]) {
  field.addEventListener('blur', () => { if (field.value || field.hasAttribute('aria-invalid')) validate(field); });
  field.addEventListener('input', () => { if (field.hasAttribute('aria-invalid')) validate(field); });
}
consentField.addEventListener('change', () => validate(consentField));
const formError = document.querySelector('#form-error');
form.addEventListener('submit', event => {
  event.preventDefault();
  if (submitButton.disabled) return;
  const validity = requiredFields.map(validate);
  if (validity.includes(false)) {
    requiredFields[validity.indexOf(false)].focus();
    return;
  }
  formError.hidden = true;
  formError.textContent = '';
  // Only the validation UX is demonstrated. Never serialize or transmit entered values.
  form.reset();
  consentField.checked = false;
  for (const field of requiredFields) {
    field.removeAttribute('aria-invalid');
    document.getElementById(field.getAttribute('aria-describedby')).textContent = '';
  }
  success.hidden = false;
  success.focus();
  submitButton.disabled = true;
});
document.querySelector('#form-again').addEventListener('click', () => {
  success.hidden = true;
  submitButton.disabled = false;
  nameField.focus();
});

if ('IntersectionObserver' in window) {
  const links = [...navigation.querySelectorAll('a[href^="#"]')];
  const observer = new IntersectionObserver(entries => {
    for (const entry of entries) {
      if (!entry.isIntersecting) continue;
      for (const link of links) {
        if (link.hash === '#' + entry.target.id) link.setAttribute('aria-current', 'location');
        else link.removeAttribute('aria-current');
      }
    }
  }, { rootMargin: '-15% 0px -65% 0px', threshold: 0 });
  for (const section of document.querySelectorAll('section[id], .hero')) observer.observe(section);
}
