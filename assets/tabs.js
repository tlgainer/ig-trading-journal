/* Move existing controls without replacing forms or their event listeners. */
(() => {
 'use strict';
 const nav = document.getElementById('tgit-tabs');
 if (!nav) return;
 const content = document.getElementById('tgit-content');
 const tabs = [...nav.querySelectorAll('[role="tab"]')];
 const panels = new Map();
 const section = (id) => document.getElementById(`tgit-${id}`).closest('section');
 const groups = {
  overview: [section('accounts'), section('holdings')],
  transactions: [document.getElementById('tgit-entry'), section('transactions')],
  journal: [document.getElementById('tgit-journal-section')],
  strategies: [document.getElementById('tgit-strategy-section')],
  settings: ['management', 'opening-section', 'members-section', 'media-settings-section'].map((id) => document.getElementById(`tgit-${id}`))
 };
 for (const tab of tabs) {
  const panel = document.createElement('div');
  panel.id = tab.getAttribute('aria-controls');
  panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', tab.id); panel.tabIndex = 0;
  for (const element of groups[tab.dataset.tab]) panel.append(element);
  panels.set(tab.dataset.tab, panel); content.append(panel);
 }
 let active = 'overview';
 function select(name, focus = false) {
  const target = tabs.find((tab) => tab.dataset.tab === name && !tab.hidden);
  if (!target) return;
  active = name;
  for (const tab of tabs) {
   const selected = tab === target;
   tab.setAttribute('aria-selected', String(selected)); tab.tabIndex = selected ? 0 : -1;
   panels.get(tab.dataset.tab).hidden = !selected;
  }
  if (focus) target.focus();
 }
 for (const tab of tabs) {
  tab.addEventListener('click', () => select(tab.dataset.tab));
  tab.addEventListener('keydown', (event) => {
   const visible = tabs.filter((item) => !item.hidden); const index = visible.indexOf(tab);
   let next;
   if (event.key === 'ArrowRight') next = visible[(index + 1) % visible.length];
   if (event.key === 'ArrowLeft') next = visible[(index + visible.length - 1) % visible.length];
   if (event.key === 'Home') next = visible[0];
   if (event.key === 'End') next = visible[visible.length - 1];
   if (next) { event.preventDefault(); select(next.dataset.tab, true); }
  });
 }
 window.addEventListener('tgit-workspace', (event) => {
  tabs.find((tab) => tab.dataset.tab === 'settings').hidden = !['owner', 'manager'].includes(event.detail.role);
  select(tabs.find((tab) => tab.dataset.tab === active).hidden ? 'overview' : active);
 });
 select('overview'); nav.hidden = false;
})();
