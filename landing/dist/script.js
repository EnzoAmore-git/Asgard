'use strict';
const roles = {
  fighter: {
    kicker: 'БОЙЦУ',
    title: ['Когда приходить', 'и сколько платить'],
    description: 'Откройте расписание, отметьте, что придёте, и посмотрите свои оплаты. Если перевели деньги — сообщите об этом в приложении.',
    points: ['Адрес и время тренировки', 'Сколько осталось заплатить', 'Сообщение, если тренировку перенесли'],
    icon: 'calendar', example: 'Запись на тренировку',
    exampleDescription: 'Выбрали занятие и нажали «Иду». Организатор увидит, что вы собираетесь прийти.',
    footer: 'Если планы изменились, ответ можно поменять.'
  },
  organizer: {
    kicker: 'ТРЕНЕРУ И ОРГАНИЗАТОРУ',
    title: ['Знать, кого ждать', 'на тренировке'],
    description: 'Не нужно собирать ответы по сообщениям. Откройте список, а после занятия отметьте тех, кто пришёл.',
    points: ['Повторяющиеся занятия в расписании', 'Запись бойцов и список пришедших', 'Другой ответственный на одну тренировку'],
    icon: 'people', example: 'Пришёл без записи?',
    exampleDescription: 'Добавьте бойца в список после тренировки. Когда всё проверили, подтвердите список — Асгард посчитает абонементы.',
    footer: 'Отмечать посещения можно без доступа к клубным деньгам.'
  },
  treasurer: {
    kicker: 'КАЗНАЧЕЮ',
    title: ['Видеть, кто оплатил', 'и сколько осталось'],
    description: 'В одном месте будут взносы бойцов, расходы клуба и деньги у тех, кто принял наличные. Если ошиблись — исправление сохранится в истории.',
    points: ['Кто должен и кто заплатил вперёд', 'Деньги на карте и наличные у ответственных', 'Оплаты, расходы и таблица для Excel'],
    icon: 'coins', example: 'Деньги у тренера',
    exampleDescription: 'Тренер принял 5 000 ₽. Из них 3 000 ₽ перевёл на клубную карту. Асгард покажет, что ещё 2 000 ₽ остаются у него.',
    footer: 'Перевод между тренером и картой не считается новой оплатой.'
  }
};
const tabs = [...document.querySelectorAll('[role="tab"]')];
const panel = document.getElementById('role-panel');
function selectRole(tab, focus = false) {
  const role = roles[tab.dataset.role];
  if (!role) return;
  tabs.forEach(button => {
    const selected = button === tab;
    button.setAttribute('aria-selected', String(selected));
    button.tabIndex = selected ? 0 : -1;
  });
  panel.setAttribute('aria-labelledby', tab.id);
  document.getElementById('role-kicker').textContent = role.kicker;
  const title = document.getElementById('role-title');
  title.replaceChildren(document.createTextNode(role.title[0]), document.createElement('br'), document.createTextNode(role.title[1]));
  document.getElementById('role-description').textContent = role.description;
  document.getElementById('role-points').replaceChildren(...role.points.map(text => {
    const li = document.createElement('li'); li.textContent = text; return li;
  }));
  document.querySelector('#role-icon use').setAttribute('href', '#' + role.icon);
  document.getElementById('example-title').textContent = role.example;
  document.getElementById('example-description').textContent = role.exampleDescription;
  document.getElementById('example-footer').textContent = role.footer;
  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && panel.animate) {
    panel.animate([{ opacity: 0.6, transform: 'translateY(5px)' }, { opacity: 1, transform: 'translateY(0)' }], {
      duration: 220, easing: 'cubic-bezier(.2,.7,.3,1)'
    });
  }
  if (focus) tab.focus();
}
tabs.forEach((tab, index) => {
  tab.addEventListener('click', () => selectRole(tab));
  tab.addEventListener('keydown', event => {
    let next;
    if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
    if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = tabs.length - 1;
    if (next !== undefined) { event.preventDefault(); selectRole(tabs[next], true); }
  });
});
