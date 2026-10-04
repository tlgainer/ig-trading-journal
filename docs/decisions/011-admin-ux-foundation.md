# Admin UX foundation

Scope: UIR 02, 03, 05, 06, 24, 40 and initial shared collection support for UIR 07–14; not full redesign acceptance.

The current admin is PHP templates with plain JavaScript, not React. Evaluate Bootstrap 5.3 before React as the addendum requests. Bootstrap is MIT licensed, but global resets and shared class names would require scoping inside WordPress. Its responsive tables also require our own authorized server pagination, sorting, focus handling and accessible actions. Adding it now would add a second styling layer without supplying these behaviors.

Use the existing scoped components and a reusable semantic HTML table in plain JavaScript. The owner confirmed that React is unnecessary. No Bootstrap or MUI assets, React runtime, remote fonts, paid grids or new dependencies are introduced. This avoids a global reset or duplicate framework while keeping one component implementation for future screens. WordPress chrome remains the outer navigation; the plugin rail only switches its own sections.

Desktop sections use a compact rail. Below 782px, a labeled Sections button opens the navigation. Only the section name is stored in the URL; existing WordPress query parameters are preserved. Browser Back restores the section. Forms are moved, not recreated, so switching sections retains their input. This does not yet implement dirty-form navigation guards.

Workspace creation stays visible for onboarding, then becomes a collapsed action in Settings. Cash presentation removes redundant trailing zeros with at least two fractional digits, using strings only; nonzero extra precision remains visible. Stored values are unchanged. The malformed confidence range separator is fixed.

Cash accounts, watchlist items and authored research notes now share `assets/collection.js`: a semantic table with title/count, search, supported sorts, stable ID tie-breaks, 25/50/100-row pagination, column preferences and compact density. Preferences contain only column identifiers, density and page size, keyed by actor/workspace/collection. Financial values use string formatting, never browser floating point. Table overflow is confined to a labeled keyboard-focusable comparison region.

These adapters supply complete authorized datasets by following existing API cursors, so search and sorting never pretend to cover records beyond a loaded page. This is an interim strategy for these bounded-use collections; server-side filtering/sorting and large-history benchmarks remain redesign gates. Trades and images must not be wired into this component using incomplete API pages. No private notes or search text are stored in URLs or preferences.

Remaining: trade/transaction/image/strategy/settings/report-history tables, server query contracts and performance benchmarks, focused New/Edit workflows, trade detail routes, transaction linking, image workflows, form validation and dirty guards, collection route restoration, localization, actual hosting admin integration and full UXA acceptance. This is the first reviewable foundation, not completion of the UI/UX addendum.

References: [Bootstrap accessibility](https://getbootstrap.com/docs/5.3/getting-started/accessibility/) and [MIT license terms](https://getbootstrap.com/docs/5.3/about/license/).

Validation: 41 domain tests and 79 disposable WordPress/MySQL integration checks; REST URL fixtures; PHP/JavaScript syntax and WPCS. `tests/ux-shell-browser.cjs` checks section deep links, browser Back, form preservation, role visibility and no horizontal overflow at 360, 768 and 1440px. `tests/collection-browser.cjs` checks records beyond the first page, search/sort/pagination, workspace preference isolation, Unicode and exact large decimal display. These checks do not establish full UXA acceptance.

`tests/admin-shell-browser.cjs` additionally checks all eight actual rendered sections through the disposable WordPress HTTP adapter at 360/768/1440px and verifies collapsed workspace creation. Real research browser fixtures verify Unicode persistence and control height; desktop/mobile screenshots were inspected. Journal, opening, report and media workflows retain their established integration checks.
