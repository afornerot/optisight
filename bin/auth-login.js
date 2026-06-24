#!/usr/bin/env node

const puppeteer = require('puppeteer-core');

const args = process.argv.slice(2);

function getArg(name) {
    const idx = args.indexOf('--' + name);
    return idx !== -1 && idx + 1 < args.length ? args[idx + 1] : null;
}

const loginUrl = getArg('login-url');
const usernameField = getArg('username-field');
const passwordField = getArg('password-field');
const username = getArg('username');
const password = getArg('password');
const waitAfterLogin = parseInt(getArg('wait') || '3000', 10);

if (!loginUrl || !usernameField || !passwordField || !username || !password) {
    console.error(JSON.stringify({ error: 'Missing required arguments' }));
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
        await page.setUserAgent('Mozilla/5.0 (compatible; iargaaseo-auth/1.0)');

        await page.goto(loginUrl, { waitUntil: 'networkidle2', timeout: 30000 });

        await page.type(usernameField, username);
        await page.type(passwordField, password);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }),
            page.keyboard.press('Enter'),
        ]);

        await new Promise(resolve => setTimeout(resolve, waitAfterLogin));

        const cookies = await page.cookies();

        const loginHost = new URL(loginUrl).hostname;
        const pageUrl = page.url();
        const pageHost = new URL(pageUrl).hostname;

        const result = {
            success: true,
            finalUrl: pageUrl,
            loginHost: loginHost,
            pageHost: pageHost,
            cookies: cookies.map(c => ({
                name: c.name,
                value: c.value,
                domain: c.domain,
                path: c.path,
                expires: c.expires,
                httpOnly: c.httpOnly,
                secure: c.secure,
                sameSite: c.sameSite,
            })),
        };

        console.log(JSON.stringify(result));
    } catch (e) {
        console.log(JSON.stringify({
            success: false,
            error: e.message,
            cookies: [],
        }));
    } finally {
        if (browser) {
            await browser.close();
        }
    }
})();
