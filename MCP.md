# optisight — how to drive the MCP surface

This file is the single source of the guide served by `optisight.guide`. It is
written for an agent that has to audit a site and act on the findings.

## The loop

```
sites.list            → pick a site
site.get              → its root URL, its auth, its page sources
crawl.trigger         → refresh the page list  (an audit does NOT crawl)
crawl.status          → wait for it, read what was discarded and why
audit.trigger         → analyse every crawled page
audit.get             → poll until the status is terminal
audit.findings        → the findings, grouped, worst leverage first
page.inspect          → the measured facts behind one finding
page.ai_analyze       → optional, on demand: a hypothesis about the cause
audit.compare         → what a change fixed, broke, or left alone
```

## Tools

### Sites and crawling

| Tool | Arguments | Returns |
|---|---|---|
| `optisight.sites.list` | — | every site with its root URL and crawl state |
| `optisight.site.get` | `siteId` | one site: URLs, auth, exclusion rules, page count |
| `optisight.crawl.trigger` | `siteId`, `dedup?` | starts `app:crawl` detached; returns the crawl id and the log path |
| `optisight.crawl.status` | `siteId` | crawl state, page count, and the tail of the crawl log — each discarded URL with its motive (`duplicate_shape`, `redirect_external`, `not_html`, `http_error`) |

### Audits

| Tool | Arguments | Returns |
|---|---|---|
| `optisight.audit.trigger` | `siteId` | starts `app:rgaa` detached on the **stored** page list |
| `optisight.audits.list` | `siteId?` | past audits with their scores |
| `optisight.audit.get` | `auditId` | status, page count, average scores |
| `optisight.audit.findings` | `auditId`, `groupBy?` (`shape`\|`rule`\|`page`), `limit?` | the findings of an audit, grouped; every group carries its rule, the node pattern, the pages and a representative |
| `optisight.audit.compare` | `baseAuditId`, `headAuditId`, `threshold?` | fixed / appeared / unchanged per rule and per page, plus `pass` |
| `optisight.pages.list` | `auditId` | every page with its scores |
| `optisight.page.get` | `pageId` | one page: scores, counts, its crawl metadata |
| `optisight.page.inspect` | `pageId`, `selector` or `rule`+`index` | the node and its **measured** facts: snippet, computed colour, background, opacity, contrast ratio, target size, associated label |
| `optisight.page.analyze` | `pageId` | re-runs both engines on that page and updates its report |
| `optisight.page.ai_analyze` | `pageId`, `indicator`, `rule?`, `selector?` | a model's reading of that page, focused on one rule when given |
| `optisight.guide` | `topic?` | this guide, in full or for one topic |

## Traps worth knowing

- **An audit does not crawl.** `audit.trigger` replays the page list stored on
  the site. Change the site's URL and the audit will happily re-measure the old
  pages: `crawl.trigger` first, then `audit.trigger`.
- **A run can be dead while `audit.get` says `running`.** A PHP memory
  exhaustion used to leave the row behind. `audit.get` now carries the last
  progress, so check that it moves.
- **A green RGAA score does not mean accessible.** Pa11y's WCAG2AA mapping does
  not cover contrast at small sizes, pointer target size, accessible names on
  ARIA widgets, list validity or console errors. Read Lighthouse's four
  categories too, never the one number.
- **A score can move for a reason outside the code.** Serving the site over HTTP
  costs 22 points of best practices. An instance whose base URL points at the
  internal port renders without its stylesheet: accessibility 86, best practices
  73, RGAA still 100.
- **The crawl produces variants of the same page** — filters, pagination,
  identifiers. One page per shape is kept (`duplicate_shape`), and the sites
  declare what a shape cannot be guessed from: `CRAWL_SHAPE_ID_PREFIXES` for
  readable identifiers (`mbr-`, `mdl-`), `CRAWL_SHAPE_MASK_AFTER` for parents
  whose child is an identifier (`orgs`).
- **A placeholder is a name the browser accepts and the audit refuses.** On an
  input with only a `placeholder`, `page.inspect` answers
  `accessibleName: {source: "placeholder"}` while Pa11y still reports
  `H91.*.Name`. The name exists for assistive technology, but no rule accepts
  it as a label: read the *source*, not just the presence of a value.
- **A generated identifier makes a selector useless.** Pa11y stores what it
  saw, often `#id-XXXX`, which matches nothing on the next render.
  `page.inspect` derives a stable selector from the finding's HTML (`name`
  first) and returns both, the derived one in `selector`, Pa11y's in
  `pa11ySelector`.

## Recipes

**Find the leverage.** `audit.findings` with `groupBy: shape`. One group is one
component: five groups on sixty screens means five fixes, not three hundred.

**Name a cause.** `page.inspect` on the representative node gives the measured
facts (2.60:1, opacity 0.6, a 14px target). Ask `page.ai_analyze` only when the
facts do not already name it, and give it the rule and the selector so its
answer stays focused.

**Verify a change.** `page.analyze` on the pages the change touched, or
`audit.compare` between the run before and the run after — the latter returns
`pass: false` as soon as a rule appears that was not there before.

**Prepare a review.** `audit.compare` supplies the fixed / appeared / unchanged
tables and the score deltas; `audit.findings` supplies the cause of each family.

## What is not there yet

- `optisight.audit.health`: a reliability verdict per run (pages without
  Lighthouse data, pages where the crawler landed on a sign-in page, external
  redirects discarded).
- The two engines run one after the other, each starting its own Chromium:
  about 14 seconds per page, of which five are spent waiting.
- Canonicals, sitemaps and hand-provided URL lists as sources of pages.
