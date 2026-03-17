import { test, expect } from '@playwright/test';

// Login helper — reutilizable
async function login(page) {
  await page.goto('/login');
  await page.fill('input[name="fsNick"]', 'admin');
  await page.fill('input[name="fsPassword"]', '@Solwed8.');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/Dashboard');
}

test.describe('Tema SolWed — verificación visual', () => {

  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('Dashboard carga sin errores PHP', async ({ page }) => {
    // No debe haber warnings de Deprecated visibles
    const body = await page.textContent('body');
    expect(body).not.toContain('Deprecated');
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('Warning:');

    // Título del dashboard visible
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });

  test('Sidebar visible con menús', async ({ page }) => {
    // Sidebar con links de navegación
    const sidebar = page.locator('#solwedSidebar, .solwed-sidebar');
    await expect(sidebar).toBeVisible();

    // Al menos 3 secciones de menú
    const menuItems = sidebar.locator('a');
    expect(await menuItems.count()).toBeGreaterThan(5);
  });

  test('Header con usuario y acciones', async ({ page }) => {
    const header = page.locator('.solwed-header, header').first();
    await expect(header).toBeVisible();

    // Nombre de usuario visible en el botón del header
    await expect(page.locator('#menuIconUser')).toBeVisible();
  });

  test('ListFacturaCliente sin errores PHP', async ({ page }) => {
    await page.goto('/ListFacturaCliente');
    await page.waitForLoadState('networkidle');

    const body = await page.textContent('body');
    expect(body).not.toContain('Deprecated');
    expect(body).not.toContain('Fatal error');

    // Título de página visible
    const title = await page.title();
    expect(title).toContain('Facturas');

    // Botón Nuevo visible (el link, no el texto del sidebar)
    await expect(page.locator('a.btn:has-text("Nuevo")').first()).toBeVisible();
  });

  test('ListCliente sin errores PHP', async ({ page }) => {
    await page.goto('/ListCliente');
    await page.waitForLoadState('networkidle');

    const body = await page.textContent('body');
    expect(body).not.toContain('Deprecated');
    expect(body).not.toContain('Fatal error');

    await expect(page.locator('text=Clientes').first()).toBeVisible();
  });

  test('EditSettings sin errores PHP', async ({ page }) => {
    await page.goto('/EditSettings');
    await page.waitForLoadState('networkidle');

    const body = await page.textContent('body');
    expect(body).not.toContain('Deprecated');
    expect(body).not.toContain('Fatal error');
  });

  test('ListProducto sin errores PHP', async ({ page }) => {
    await page.goto('/ListProducto');
    await page.waitForLoadState('networkidle');

    const body = await page.textContent('body');
    expect(body).not.toContain('Deprecated');
    expect(body).not.toContain('Fatal error');

    await expect(page.locator('text=Productos').first()).toBeVisible();
  });

  test('Dark mode toggle funciona', async ({ page }) => {
    // Verificar tema inicial (dark por defecto según preferencias)
    const htmlTheme = await page.getAttribute('html', 'data-theme');

    // Click en menú de usuario
    await page.click('#menuIconUser');
    await page.waitForTimeout(300);

    // Click en toggle de tema
    await page.click('text=Modo oscuro >> visible=true');
    await page.waitForTimeout(300);

    // El atributo data-theme debería haber cambiado
    const newTheme = await page.getAttribute('html', 'data-theme');
    expect(newTheme).not.toBe(htmlTheme);
  });

  test('Login page carga sin errores', async ({ page }) => {
    // Cerrar sesión primero
    await page.goto('/login?action=logout&multireqtoken=skip');
    await page.waitForLoadState('networkidle');

    const body = await page.textContent('body');
    expect(body).not.toContain('Deprecated');
    expect(body).not.toContain('Fatal error');
  });
});
