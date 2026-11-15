// Отправка заявок на сервер (send.php) и уведомление о cookie — общее для всех страниц.
(() => {
  // Предварительная версия на GitHub Pages: PHP там не работает, поэтому заявки не отправляются,
  // а форма только показывает, как будет выглядеть успешная отправка.
  const PREVIEW = /\.github\.io$/.test(location.hostname);
  if (PREVIEW) {
    const meta = document.createElement('meta');
    meta.name = 'robots'; meta.content = 'noindex, nofollow';
    document.head.appendChild(meta);
    const tag = document.createElement('div');
    tag.textContent = 'Предварительная версия сайта для согласования · заявки в демо-режиме не отправляются';
    tag.style.cssText = 'position:fixed;left:50%;top:0;transform:translateX(-50%);z-index:200;background:#FFC27A;color:#0A0B0D;font:600 12px/1.4 "Inter Tight",system-ui,sans-serif;padding:5px 14px;text-align:center;max-width:100%;';
    document.addEventListener('DOMContentLoaded', () => document.body.appendChild(tag));
  }

  window.sendLead = async payload => {
    if (PREVIEW) {
      await new Promise(r => setTimeout(r, 600));
      return { ok: true, id: 'ДЕМО' };
    }
    const res = await fetch('send.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    let data = {};
    try { data = await res.json(); } catch (e) {}
    if (!res.ok || !data.ok) {
      const err = new Error(data.error || 'network');
      err.code = data.error || 'network';
      throw err;
    }
    return data;
  };

  window.leadError = code => ({
    name: 'Укажите имя.',
    phone: 'Проверьте номер телефона.',
    consent: 'Нужно согласие на обработку персональных данных.',
    empty_cart: 'Корзина пуста.',
    rate: 'Слишком много заявок подряд. Попробуйте через несколько минут или позвоните нам.'
  })[code] || 'Не удалось отправить заявку. Позвоните нам: +7 962 556-59-97.';

  window.phoneMask = input => input.addEventListener('input', () => {
    let d = input.value.replace(/\D/g, '');
    if (d.startsWith('8')) d = '7' + d.slice(1);
    if (!d.startsWith('7')) d = '7' + d;
    d = d.slice(0, 11);
    let s = '+7';
    if (d.length > 1) s += ' (' + d.slice(1, 4);
    if (d.length >= 4) s += ')';
    if (d.length > 4) s += ' ' + d.slice(4, 7);
    if (d.length > 7) s += '-' + d.slice(7, 9);
    if (d.length > 9) s += '-' + d.slice(9, 11);
    input.value = s;
  });

  // уведомление о cookie
  let seen = false;
  try { seen = localStorage.getItem('sh-cookie') === '1'; } catch (e) {}
  if (seen) return;
  const bar = document.createElement('div');
  bar.className = 'cookie';
  bar.innerHTML = '<p>Сайт использует cookie и локальное хранилище браузера, чтобы работала корзина. Подробнее — в <a href="privacy.html#cookie">политике обработки персональных данных</a>.</p><button type="button">Понятно</button>';
  const style = document.createElement('style');
  style.textContent = '.cookie{position:fixed;left:16px;bottom:16px;z-index:100;max-width:440px;display:flex;gap:16px;align-items:center;padding:14px 16px;background:rgba(20,23,28,.96);border:1px solid rgba(255,255,255,.16);backdrop-filter:blur(14px);font:13px/1.5 "Inter Tight",system-ui,sans-serif;color:#C9CCD1;box-shadow:0 20px 60px -20px rgba(0,0,0,.8)}.cookie a{color:#ECECEA}.cookie button{flex:none;height:38px;padding:0 16px;background:#ECECEA;color:#0A0B0D;border:0;font:600 13px "Inter Tight",system-ui,sans-serif;cursor:pointer}@media (max-width:640px){.cookie{right:16px;bottom:88px;max-width:none}}';
  document.head.appendChild(style);
  document.body.appendChild(bar);
  bar.querySelector('button').addEventListener('click', () => {
    try { localStorage.setItem('sh-cookie', '1'); } catch (e) {}
    bar.remove();
  });
})();
