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
  { name: 'productos', url: '/ListProducto' },
  { name: 'edit-cliente', url: '/EditCliente?code=1' },
  { name: 'edit-settings', url: '/EditSettings' },
  { name: 'plugins', url: '/AdminPlugins' },
  { name: 'asientos', url: '/ListAsiento' },
  { name: 'proveedores', url: '/ListProveedor' },
  { name: 'edit-producto', url: '/EditProducto?code=1' },
  { name: 'megasearch', url: '/MegaSearch' },
  { name: 'about', url: '/About' },
  { name: 'updater', url: '/Updater' },
];

test('Design review - light mode', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);
  // Force light mode
  await page.evaluate(() => {
    document.documentElement.setAttribute('data-theme', 'solwed-light');
    localStorage.setItem('solwed-theme', 'solwed-light');
  });

  for (const p of pages) {
    await page.goto(p.url);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: `test-results/design-light-${p.name}.png` });
  }

  // Login (logout first)
  await page.goto('/login');
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'test-results/design-light-login.png' });
});

test('Design review - dark mode', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);
  // Force dark mode
  await page.evaluate(() => {
    document.documentElement.setAttribute('data-theme', 'solwed-dark');
    localStorage.setItem('solwed-theme', 'solwed-dark');
  });

  for (const p of pages) {
    await page.goto(p.url);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: `test-results/design-dark-${p.name}.png` });
  }
});
