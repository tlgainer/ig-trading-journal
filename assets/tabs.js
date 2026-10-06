/* Move existing controls without replacing forms or their event listeners. */
(() => {
 'use strict';
 const nav = document.getElementById('tgit-tabs');
 if (!nav) return;
 const content = document.getElementById('tgit-content');
 const tabs = [...nav.querySelectorAll('[role="tab"]')];
 nav.setAttribute('aria-orientation', 'vertical');
 const panels = new Map();
 const section = (id) => document.getElementById(`tgit-${id}`).closest('section');
 const groups = {
  overview: [section('accounts'), section('holdings')],
  transactions: [document.getElementById('tgit-entry'), section('transactions')],
  journal: [document.getElementById('tgit-journal-section')],
  strategies: [document.getElementById('tgit-strategy-section')],
  calculators: [document.getElementById('tgit-calculators-section')],
  research: [document.getElementById('tgit-research-section')],
  reports: [document.getElementById('tgit-reports-section')],
  settings: ['management', 'opening-section', 'members-section', 'media-settings-section', 'market-data-section'].map((id) => document.getElementById(`tgit-${id}`))
 };
 for (const tab of tabs) {
  const panel = document.createElement('div');
  panel.id = tab.getAttribute('aria-controls');
  panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', tab.id); panel.tabIndex = 0;
  for (const element of groups[tab.dataset.tab]) panel.append(element);
  panels.set(tab.dataset.tab, panel); content.append(panel);
 }
 const header = document.createElement('div'); header.className = 'tgit-page-header';
 const heading = document.createElement('h2'); heading.id = 'tgit-page-title';
 const menu = document.createElement('button'); menu.type = 'button'; menu.className = 'button tgit-menu-toggle';
 menu.textContent = 'Sections'; menu.setAttribute('aria-controls', nav.id); menu.setAttribute('aria-expanded', 'false');
 header.append(heading, menu); content.prepend(header);
 const body = document.createElement('div'); body.className = 'tgit-shell';
 const pages = document.createElement('div'); pages.className = 'tgit-pages';
 header.after(body); body.append(nav, pages);
 for (const panel of panels.values()) pages.append(panel);
 menu.addEventListener('click', () => {
  const expanded = menu.getAttribute('aria-expanded') !== 'true';
  menu.setAttribute('aria-expanded', String(expanded)); nav.classList.toggle('tgit-nav-open', expanded);
 });
 function routeSection() {
  return new URL(location.href).searchParams.get('tgit_section') || 'overview';
 }
 let active = routeSection();
 function select(name, focus = false) {
  const target = tabs.find((tab) => tab.dataset.tab === name && !tab.hidden);
  if (!target) return;
  active = name;
  heading.textContent = target.textContent;
  if (!focus) {
   const returnFocus = nav.contains(document.activeElement) && menu.offsetParent !== null;
   menu.setAttribute('aria-expanded', 'false'); nav.classList.remove('tgit-nav-open');
   if (returnFocus) menu.focus();
  }
  for (const tab of tabs) {
   const selected = tab === target;
   tab.setAttribute('aria-selected', String(selected)); tab.tabIndex = selected ? 0 : -1;
   panels.get(tab.dataset.tab).hidden = !selected;
  }
  if (focus) target.focus();
 }
 for (const tab of tabs) {
  tab.addEventListener('click', () => {
   select(tab.dataset.tab);
   const url = new URL(location.href); url.searchParams.set('tgit_section', active);
   if (active !== 'journal') url.searchParams.delete('tgit_trade');
   history.pushState(null, '', url);
  });
  tab.addEventListener('keydown', (event) => {
   const visible = tabs.filter((item) => !item.hidden); const index = visible.indexOf(tab);
   let next;
   if (['ArrowRight', 'ArrowDown'].includes(event.key)) next = visible[(index + 1) % visible.length];
   if (['ArrowLeft', 'ArrowUp'].includes(event.key)) next = visible[(index + visible.length - 1) % visible.length];
   if (event.key === 'Escape' && menu.offsetParent !== null) {
    menu.setAttribute('aria-expanded', 'false'); nav.classList.remove('tgit-nav-open'); menu.focus();
   }
   if (event.key === 'Home') next = visible[0];
   if (event.key === 'End') next = visible[visible.length - 1];
   if (next) { event.preventDefault(); select(next.dataset.tab, true); }
  });
 }
 window.addEventListener('tgit-workspace', (event) => {
  tabs.find((tab) => tab.dataset.tab === 'settings').hidden = !['owner', 'manager'].includes(event.detail.role);
  select(tabs.find((tab) => tab.dataset.tab === active && !tab.hidden) ? active : 'overview');
 });
 window.addEventListener('popstate', () => select(routeSection()));
 window.tgitSelectSection = (name) => select(name);
 select(tabs.some((tab) => tab.dataset.tab === active) ? active : 'overview'); nav.hidden = false;
})();
