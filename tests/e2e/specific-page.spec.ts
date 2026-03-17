import { test } from '@playwright/test';

async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="fsNick"]', 'admin');
  await page.fill('input[name="fsPassword"]', '@Solwed8.');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/Dashboard');
}

test('EditContacto page', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);

  // Dark mode
  await page.evaluate(() => {
    document.documentElement.setAttribute('data-theme', 'solwed-dark');
    localStorage.setItem('solwed-theme', 'solwed-dark');
  });
  await page.goto('/EditContacto?code=1703');
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'test-results/specific-editcontacto-dark.png' });

  // Light mode
  await page.evaluate(() => {
    document.documentElement.setAttribute('data-theme', 'solwed-light');
    localStorage.setItem('solwed-theme', 'solwed-light');
  });
  await page.goto('/EditContacto?code=1703');
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: 'test-results/specific-editcontacto-light.png' });
});
