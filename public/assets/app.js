document.addEventListener('click', event => {
  const target = event.target.closest('button, a');
  if (!target) return;
  if (target.dataset.confirm && !window.confirm(target.dataset.confirm)) event.preventDefault();
  if (target.hasAttribute('data-print')) window.print();
  if (target.hasAttribute('data-back')) history.back();
  if (target.classList.contains('mobile-toggle')) {
    const open = document.body.classList.toggle('nav-open');
    target.setAttribute('aria-expanded', String(open));
  }
});
