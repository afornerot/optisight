#!/usr/bin/env node
//
// Mesure, dans la page rendue, les faits d'un nœud : couleurs calculées, opacité
// cumulée des ancêtres, ratio de contraste effectif, taille de cible, nom
// accessible. C'est ce que l'analyse d'accessibilité ne dit pas — elle nomme la
// règle, pas la cause — et ce qu'un agent doit lire pour nommer la cause.
//
// Usage :
//   node bin/inspect.js --url <url> [--selector <css>] [--cookie <en-tête>]
//                       [--wait <ms>] [--viewport 1280x900]
//
// Sortie : un JSON sur stdout. `found: false` si le sélecteur ne trouve rien.

const puppeteer = require('puppeteer-core');

const args = process.argv.slice(2);

function getArg(name) {
    const idx = args.indexOf('--' + name);
    return idx !== -1 && idx + 1 < args.length ? args[idx + 1] : null;
}

const url = getArg('url');
const selector = getArg('selector');
const cookie = getArg('cookie');
const wait = parseInt(getArg('wait') || '1500', 10);
const viewport = (getArg('viewport') || '1280x900').split('x').map(Number);

if (!url || !selector) {
    console.error(JSON.stringify({ error: 'Missing required arguments: --url, --selector' }));
    process.exit(1);
}

(async () => {
    let browser;
    try {
        browser = await puppeteer.launch({
            executablePath: process.env.CHROME_BINARY || '/usr/bin/chromium',
            headless: 'new',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
            ],
        });

        const page = await browser.newPage();
        await page.setViewport({ width: viewport[0] || 1280, height: viewport[1] || 900 });

        if (cookie) {
            await page.setExtraHTTPHeaders({ Cookie: cookie });
        }

        const response = await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
        await new Promise((resolve) => setTimeout(resolve, wait));

        const result = await page.evaluate((wantedSelector) => {
            // ── Couleurs : le navigateur calcule en oklch/lab, on lit des pixels.
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 1;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });

            const toRgba = (css) => {
                ctx.clearRect(0, 0, 1, 1);
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, 1, 1);
                ctx.fillStyle = css;
                ctx.fillRect(0, 0, 1, 1);
                const d = ctx.getImageData(0, 0, 1, 1).data;
                return [d[0], d[1], d[2], d[3] / 255];
            };

            const channel = (c) => {
                c /= 255;
                return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
            };
            const luminance = (rgb) => 0.2126 * channel(rgb[0]) + 0.7152 * channel(rgb[1]) + 0.0722 * channel(rgb[2]);
            const ratio = (a, b) => {
                const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
                return Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100;
            };
            const blend = (fg, bg, alpha) => fg.map((value, i) => Math.round(value * alpha + bg[i] * (1 - alpha)));

            const describe = (el) => {
                if (!el || !el.tagName) return null;
                const id = el.id ? '#' + el.id : '';
                const classes = (el.className && typeof el.className === 'string')
                    ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.')
                    : '';
                return el.tagName.toLowerCase() + id + classes;
            };

            // Opacité cumulée des ancêtres, et premier fond non transparent.
            const contextOf = (el) => {
                let opacity = 1;
                let background = null;
                const opacityChain = [];
                for (let node = el; node && node !== document.documentElement; node = node.parentElement) {
                    const style = getComputedStyle(node);
                    const value = parseFloat(style.opacity || '1');
                    if (value < 1) {
                        opacityChain.push({ selector: describe(node), opacity: value });
                    }
                    opacity *= value;
                    const bg = toRgba(style.backgroundColor);
                    if (!background && bg[3] > 0) {
                        background = bg.slice(0, 3);
                    }
                }
                return { opacity, background: background || [255, 255, 255], opacityChain };
            };

            const accessibleNameOf = (el) => {
                const ariaLabel = el.getAttribute('aria-label');
                if (ariaLabel && ariaLabel.trim() !== '') return { source: 'aria-label', value: ariaLabel.trim() };

                const labelledBy = el.getAttribute('aria-labelledby');
                if (labelledBy) {
                    const text = labelledBy.split(/\s+/)
                        .map((id) => {
                            const ref = document.getElementById(id);
                            return ref ? ref.textContent.trim() : '';
                        })
                        .filter((t) => t !== '')
                        .join(' ');
                    if (text !== '') return { source: 'aria-labelledby', value: text };
                }

                if (el.labels && el.labels.length > 0) {
                    const text = Array.from(el.labels).map((l) => l.textContent.trim()).filter((t) => t !== '').join(' ');
                    if (text !== '') return { source: 'label', value: text };
                }

                const title = el.getAttribute('title');
                if (title && title.trim() !== '') return { source: 'title', value: title.trim() };

                const role = el.getAttribute('role');
                const isButtonLike = el.tagName === 'BUTTON' || el.tagName === 'A' || role === 'button' || role === 'link';
                if (isButtonLike) {
                    const text = (el.textContent || '').trim();
                    if (text !== '') return { source: 'content', value: text };
                }

                const placeholder = el.getAttribute('placeholder');
                if (placeholder && placeholder.trim() !== '') return { source: 'placeholder', value: placeholder.trim() };

                return { source: 'none', value: null };
            };

            const el = document.querySelector(wantedSelector);
            if (!el) {
                return { found: false, selector: wantedSelector };
            }

            const style = getComputedStyle(el);
            const box = el.getBoundingClientRect();
            const context = contextOf(el);
            const foreground = toRgba(style.color);
            const rendered = blend(foreground.slice(0, 3), context.background, context.opacity * foreground[3]);

            return {
                found: true,
                selector: wantedSelector,
                node: {
                    tag: el.tagName.toLowerCase(),
                    selector: describe(el),
                    snippet: el.outerHTML.slice(0, 400),
                    text: (el.textContent || '').trim().slice(0, 120) || null,
                    role: el.getAttribute('role'),
                },
                accessibleName: accessibleNameOf(el),
                styles: {
                    color: foreground.slice(0, 3).join(','),
                    renderedColor: rendered.join(','),
                    background: context.background.join(','),
                    effectiveOpacity: Math.round(context.opacity * 100) / 100,
                    fontSize: style.fontSize,
                    fontWeight: style.fontWeight,
                    display: style.display,
                    visibility: style.visibility,
                },
                opacityChain: context.opacityChain,
                contrastRatio: ratio(rendered, context.background),
                targetSize: { width: Math.round(box.width * 10) / 10, height: Math.round(box.height * 10) / 10 },
                visible: style.display !== 'none' && style.visibility !== 'hidden' && box.width > 0 && box.height > 0,
            };
        }, selector);

        console.log(JSON.stringify({
            url,
            finalUrl: page.url(),
            httpStatus: response ? response.status() : null,
            ...result,
        }, null, 2));
    } catch (error) {
        console.error(JSON.stringify({ error: String(error && error.message ? error.message : error) }));
        process.exit(1);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
})();
