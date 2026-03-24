import { test, expect } from '@playwright/test';

async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="fsNick"]', 'admin');
  await page.fill('input[name="fsPassword"]', '@Solwed8.');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/Dashboard');
}

const pages = [
  '/Dashboard',
  '/ListFacturaCliente',
  '/ListCliente',
  '/ListProducto',
  '/ListProveedor',
  '/ListAlbaranCliente',
  '/ListAlbaranProveedor',
  '/ListFacturaProveedor',
  '/ListPedidoCliente',
  '/ListPresupuestoCliente',
  '/ListAsiento',
  '/ListCuenta',
  '/ListEjercicio',
  '/ListFormaPago',
  '/ListImpuesto',
  '/ListSerie',
  '/ListAlmacen',
  '/ListFamilia',
  '/ListFabricante',
  '/ListAtributo',
  '/ListAgenciaTransporte',
  '/ListAgente',
  '/ListPais',
  '/ListUser',
  '/ListEmpresa',
  '/ListLogMessage',
  '/ListAttachedFile',
  '/EditSettings',
  '/EditCliente?code=1',
  '/EditProducto?code=1',
  '/EditProveedor?code=1',
  '/AdminPlugins',
  '/ConfigEmail',
  '/MegaSearch',
  '/About',
  '/Updater',
  '/SendMail',
];

test('Full app scan - buscar errores PHP', async ({ page }) => {
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);

  const errors: string[] = [];

  for (const url of pages) {
    await page.goto(url);
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(300);

    const body = await page.textContent('body');

    // Buscar errores PHP
    const phpErrors = [
      'Fatal error',
      'Warning:',
      'Deprecated:',
      'Notice:',
      'Parse error',
      'Trying to access array offset',
      'Undefined array key',
      'Undefined variable',
      'Cannot access offset',
    ];

    for (const err of phpErrors) {
      if (body?.includes(err)) {
        errors.push(`${url}: ${err}`);
      }
    }
  }

  if (errors.length > 0) {
    console.log('\n=== ERRORES ENCONTRADOS ===');
    errors.forEach(e => console.log('  ' + e));
    console.log(`\nTotal: ${errors.length} errores en ${pages.length} páginas`);
  } else {
    console.log(`\n=== OK: ${pages.length} páginas sin errores PHP ===`);
  }

  expect(errors).toEqual([]);
});
