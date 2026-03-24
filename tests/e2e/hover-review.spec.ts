import { test } from '@playwright/test';

async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="fsNick"]', 'admin');
  await page.fill('input[name="fsPassword"]', '@Solwed8.');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/Dashboard');
}

// Simula hover via JS para evitar problemas de visibilidad en contenedores overflow
async function hoverFirstRow(page) {
  await page.evaluate(() => {
    const rows = document.querySelectorAll('.tab-pane.active table tbody tr, table tbody tr');
    for (const row of rows) {
      const rect = (row as HTMLElement).getBoundingClientRect();
      if (rect.height > 0 && rect.width > 0) {
        (row as HTMLElement).dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
        (row as HTMLElement).classList.add('hovered');
        break;
      }
    }
  });
  await page.waitForTimeout(300);
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
  await page.waitForLoadState('domcontentloaded');
  await hoverFirstRow(page);
  await page.screenshot({ path: 'test-results/hover-dark-table-row.png' });

  // Hover sobre botón Nuevo
  await page.locator('text=Nuevo').first().hover();
  await page.waitForTimeout(200);
  await page.screenshot({ path: 'test-results/hover-dark-btn.png' });

  // Dropdown user menu
  await page.click('#menuIconUser');
  await page.waitForTimeout(400);
  await page.screenshot({ path: 'test-results/hover-dark-dropdown.png' });

  // Input focus en EditCliente — navegar a un cliente real
  await page.goto('/ListCliente');
  await page.waitForLoadState('domcontentloaded');
  const firstRowLink = await page.evaluate(() => {
    const a = document.querySelector('.tab-pane.active table tbody tr a, table tbody tr a') as HTMLAnchorElement;
    return a ? a.getAttribute('href') : null;
  });
  if (firstRowLink) {
    await page.goto(firstRowLink);
    await page.waitForLoadState('domcontentloaded');
    const input = page.locator('input[name="nombre"]').first();
    if (await input.count() > 0) await input.click();
  }
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
  await page.waitForLoadState('domcontentloaded');
  await hoverFirstRow(page);
  await page.screenshot({ path: 'test-results/hover-light-table-row.png' });

  // Dropdown user menu
  await page.click('#menuIconUser');
  await page.waitForTimeout(400);
  await page.screenshot({ path: 'test-results/hover-light-dropdown.png' });

  // Input focus en EditCliente — navegar a un cliente real
  await page.goto('/ListCliente');
  await page.waitForLoadState('domcontentloaded');
  const firstRowLink = await page.evaluate(() => {
    const a = document.querySelector('.tab-pane.active table tbody tr a, table tbody tr a') as HTMLAnchorElement;
    return a ? a.getAttribute('href') : null;
  });
  if (firstRowLink) {
    await page.goto(firstRowLink);
    await page.waitForLoadState('domcontentloaded');
    const input = page.locator('input[name="nombre"]').first();
    if (await input.count() > 0) await input.click();
  }
  await page.waitForTimeout(200);
  await page.screenshot({ path: 'test-results/hover-light-input-focus.png' });
});
