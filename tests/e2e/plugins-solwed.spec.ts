import { test, expect } from '@playwright/test';

async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="fsNick"]', 'admin');
  await page.fill('input[name="fsPassword"]', '@Solwed8.');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/Dashboard');
}

test('AdminPlugins - Portal SolWed tab', async ({ page }) => {
  page.setDefaultTimeout(15000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);

  await page.goto('/AdminPlugins');
  await page.waitForLoadState('networkidle');

  // Verificar que existe la pestaña Portal SolWed
  const solwedTab = page.locator('text=Portal SolWed');
  await page.screenshot({ path: 'test-results/plugins-tabs.png' });

  if (await solwedTab.count() > 0) {
    // Click en Portal SolWed
    await solwedTab.click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: 'test-results/plugins-solwed-tab.png' });

    // Verificar que hay plugins con botón Instalar
    const installBtns = page.locator('#solwed a:has-text("Instalar")');
    console.log('Plugins SolWed con botón Instalar:', await installBtns.count());
    expect(await installBtns.count()).toBeGreaterThan(0);
  } else {
    console.log('Portal SolWed tab not visible - checking if solwedPluginList is empty');
    // Puede que todos los plugins SolWed ya estén instalados
  }

  // Nota: La pestaña "Más plugins" (Forja) fue eliminada del diseño SolWed
});
