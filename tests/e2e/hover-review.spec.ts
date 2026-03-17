import { test } from '@playwright/test';

async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="fsNick"]', 'admin');
  await page.fill('input[name="fsPassword"]', '@Solwed8.');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/Dashboard');
}

test('Hover states - dark mode', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);
  await page.evaluate(() => {
    document.documentElement.setAttribute('data-theme', 'solwed-dark');
    localStorage.setItem('solwed-theme', 'solwed-dark');
  });

  // Facturas - hover sobre filas de tabla
  await page.goto('/ListFacturaCliente');
  await page.waitForLoadState('networkidle');
  const rows = page.locator('table tbody tr');
  if (await rows.count() > 2) {
    await rows.nth(2).hover();
    await page.waitForTimeout(300);
  }
  await page.screenshot({ path: 'test-results/hover-dark-table-row.png' });

  // Hover sobre botón Nuevo
  await page.locator('text=Nuevo').first().hover();
  await page.waitForTimeout(200);
  await page.screenshot({ path: 'test-results/hover-dark-btn.png' });

  // Dropdown user menu
  await page.click('#menuIconUser');
  await page.waitForTimeout(400);
  await page.screenshot({ path: 'test-results/hover-dark-dropdown.png' });

  // Input focus en EditCliente
  await page.goto('/EditCliente?code=1');
  await page.waitForLoadState('networkidle');
  await page.locator('input[name="nombre"]').click();
  await page.waitForTimeout(200);
  await page.screenshot({ path: 'test-results/hover-dark-input-focus.png' });
});

test('Hover states - light mode', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);
  await page.evaluate(() => {
    document.documentElement.setAttribute('data-theme', 'solwed-light');
    localStorage.setItem('solwed-theme', 'solwed-light');
  });

  // Facturas - hover sobre filas
  await page.goto('/ListFacturaCliente');
  await page.waitForLoadState('networkidle');
  const rows = page.locator('table tbody tr');
  if (await rows.count() > 2) {
    await rows.nth(2).hover();
    await page.waitForTimeout(300);
  }
  await page.screenshot({ path: 'test-results/hover-light-table-row.png' });

  // Dropdown user menu
  await page.click('#menuIconUser');
  await page.waitForTimeout(400);
  await page.screenshot({ path: 'test-results/hover-light-dropdown.png' });

  // Input focus
  await page.goto('/EditCliente?code=1');
  await page.waitForLoadState('networkidle');
  await page.locator('input[name="nombre"]').click();
  await page.waitForTimeout(200);
  await page.screenshot({ path: 'test-results/hover-light-input-focus.png' });
});
