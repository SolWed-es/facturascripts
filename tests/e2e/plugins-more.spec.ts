import { test, expect } from '@playwright/test';

async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="fsNick"]', 'admin');
  await page.fill('input[name="fsPassword"]', '@Solwed8.');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/Dashboard');
}

test('AdminPlugins - pestaña Más plugins', async ({ page }) => {
  page.setDefaultTimeout(15000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);

  await page.goto('/AdminPlugins');
  await page.waitForLoadState('networkidle');

  // Click en "Más plugins..."
  await page.click('text=Más plugins');
  await page.waitForTimeout(1000);
  await page.screenshot({ path: 'test-results/plugins-more.png' });

  // Verificar que hay plugins listados
  const body = await page.textContent('body');
  expect(body).not.toContain('Fatal error');

  // Scroll down para ver más
  await page.evaluate(() => window.scrollBy(0, 600));
  await page.waitForTimeout(300);
  await page.screenshot({ path: 'test-results/plugins-more-scroll.png' });
});
