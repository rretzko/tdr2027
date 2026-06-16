import { chromium } from 'playwright';

const BASE = 'http://localhost:8000';

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1100, height: 700 } });

await page.goto(`${BASE}/login`);
await page.getByLabel('Cell phone').fill('5550009994');
await page.getByLabel('Password', { exact: true }).fill('Tdr-Zx9Quokka!');
await page.getByRole('button', { name: 'Log in' }).click();
await page.waitForURL('**/dashboard', { timeout: 10000 });
await page.waitForTimeout(500);

await page.screenshot({ path: 'verify-events.png', fullPage: true });

await browser.close();
