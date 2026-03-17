import { test } from '@playwright/test';

async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="fsNick"]', 'admin');
  await page.fill('input[name="fsPassword"]', '@Solwed8.');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/Dashboard');
}

test('Detail screenshots', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);

  // Dashboard dark (default)
  await page.screenshot({ path: 'test-results/detail-dashboard-dark.png' });

  // Toggle to light via JS
  await page.evaluate(() => (window as any).solwedToggleTheme());
  await page.waitForTimeout(300);
  await page.screenshot({ path: 'test-results/detail-dashboard-light.png' });

  // Facturas light
  await page.goto('/ListFacturaCliente');
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'test-results/detail-facturas-light.png' });

  // Toggle to dark
  await page.evaluate(() => (window as any).solwedToggleTheme());
  await page.waitForTimeout(300);
  await page.screenshot({ path: 'test-results/detail-facturas-dark.png' });

  // Clientes dark
  await page.goto('/ListCliente');
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'test-results/detail-clientes-dark.png' });

  // Edit cliente dark
  await page.goto('/EditCliente?code=1');
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'test-results/detail-edit-dark.png' });

  // Toggle to light
  await page.evaluate(() => (window as any).solwedToggleTheme());
  await page.waitForTimeout(300);

  // Edit cliente light
  await page.goto('/EditCliente?code=1');
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'test-results/detail-edit-light.png' });

  // Settings light
  await page.goto('/EditSettings');
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'test-results/detail-settings-light.png' });
});
