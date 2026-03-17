import { test, expect } from '@playwright/test';

async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="fsNick"]', 'admin');
  await page.fill('input[name="fsPassword"]', '@Solwed8.');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/Dashboard');
}

test('AdminPlugins - verificar lista de plugins', async ({ page }) => {
  page.setDefaultTimeout(15000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);

  await page.goto('/AdminPlugins');
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'test-results/plugins-main.png' });

  // Verificar que no hay errores PHP
  const body = await page.textContent('body');
  expect(body).not.toContain('Fatal error');
  expect(body).not.toContain('Deprecated');

  console.log('Page title:', await page.title());
  console.log('URL:', page.url());
});
