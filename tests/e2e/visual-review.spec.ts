import { test } from '@playwright/test';

async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="fsNick"]', 'admin');
  await page.fill('input[name="fsPassword"]', '@Solwed8.');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/Dashboard');
}

const pages = [
  { name: 'dashboard', url: '/Dashboard' },
  { name: 'facturas', url: '/ListFacturaCliente' },
  { name: 'clientes', url: '/ListCliente' },
  { name: 'edit-cliente', url: '/EditCliente?code=1' },
  { name: 'productos', url: '/ListProducto' },
  { name: 'edit-settings', url: '/EditSettings' },
  { name: 'plugins', url: '/AdminPlugins' },
  { name: 'about', url: '/About' },
  { name: 'updater', url: '/Updater' },
  { name: 'edit-contacto', url: '/EditContacto?code=1703' },
];

test('Dark mode - all pages', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);
  await page.evaluate(() => {
    document.documentElement.setAttribute('data-theme', 'solwed-dark');
    localStorage.setItem('solwed-theme', 'solwed-dark');
  });
  for (const p of pages) {
    await page.goto(p.url);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: `test-results/vr-dark-${p.name}.png` });
  }
});

test('Light mode - all pages', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);
  await page.evaluate(() => {
    document.documentElement.setAttribute('data-theme', 'solwed-light');
    localStorage.setItem('solwed-theme', 'solwed-light');
  });
  for (const p of pages) {
    await page.goto(p.url);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: `test-results/vr-light-${p.name}.png` });
  }
});

test('Login page', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/login');
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'test-results/vr-login.png' });
});
