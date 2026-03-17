import { test } from '@playwright/test';

async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="fsNick"]', 'admin');
  await page.fill('input[name="fsPassword"]', '@Solwed8.');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/Dashboard');
}

const pages = [
  { name: '01-login', url: '/login', skipLogin: true },
  { name: '02-dashboard', url: '/Dashboard' },
  { name: '03-list-facturas', url: '/ListFacturaCliente' },
  { name: '04-list-clientes', url: '/ListCliente' },
  { name: '05-list-productos', url: '/ListProducto' },
  { name: '06-edit-settings', url: '/EditSettings' },
  { name: '07-edit-cliente', url: '/EditCliente?code=1' },
  { name: '08-list-asientos', url: '/ListAsiento' },
];

test('Captura de todas las páginas', async ({ page }) => {
  // Login primero
  await login(page);

  for (const p of pages) {
    if (p.skipLogin) {
      // Para login, abrir en otra pestaña sin sesión
      continue;
    }
    await page.goto(p.url);
    await page.waitForLoadState('networkidle');
    await page.screenshot({
      path: `test-results/review-${p.name}.png`,
      fullPage: true,
    });
  }

  // Screenshot del login (cerrar sesión)
  await page.goto('/login');
  await page.waitForLoadState('networkidle');
  await page.screenshot({
    path: `test-results/review-01-login.png`,
    fullPage: true,
  });
});

test('Captura dark y light mode dashboard', async ({ page }) => {
  await login(page);

  // Dark mode (default)
  await page.screenshot({ path: 'test-results/review-dashboard-dark.png', fullPage: true });

  // Toggle a light
  await page.click('#menuIconUser');
  await page.waitForTimeout(300);
  await page.click('text=Modo oscuro');
  await page.waitForTimeout(500);
  await page.screenshot({ path: 'test-results/review-dashboard-light.png', fullPage: true });
});

test('Captura mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  await page.screenshot({ path: 'test-results/review-mobile-dashboard.png', fullPage: true });

  await page.goto('/ListFacturaCliente');
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'test-results/review-mobile-facturas.png', fullPage: true });
});
