/**
 * SolwedShadcn — Sidebar toggle y submenús
 */

// Toggle sidebar en móvil
function toggleSidebar() {
    var sidebar = document.getElementById('solwedSidebar');
    var overlay = document.getElementById('sidebarOverlay');

    if (sidebar) {
        sidebar.classList.toggle('mobile-open');
    }
    if (overlay) {
        overlay.classList.toggle('active');
    }
}

// Toggle submenú del sidebar
function toggleSubmenu(menuId) {
    var submenu = document.getElementById('submenu-' + menuId);
    var chevron = document.getElementById('chevron-' + menuId);

    if (submenu) {
        submenu.classList.toggle('hidden');
    }
    if (chevron) {
        chevron.style.transform = submenu && submenu.classList.contains('hidden')
            ? 'rotate(0deg)'
            : 'rotate(90deg)';
    }
}

// Cerrar sidebar al hacer clic en un link (móvil)
document.addEventListener('DOMContentLoaded', function () {
    var sidebarLinks = document.querySelectorAll('.solwed-sidebar a[href]:not([href="#"]):not([href="javascript:void(0)"])');
    sidebarLinks.forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth < 768) {
                toggleSidebar();
            }
        });
    });

    // Abrir submenú que contiene el item activo
    var activeItems = document.querySelectorAll('.solwed-sidebar .bg-accent');
    activeItems.forEach(function (item) {
        var parent = item.closest('[id^="submenu-"]');
        if (parent && parent.classList.contains('hidden')) {
            parent.classList.remove('hidden');
            var menuId = parent.id.replace('submenu-', '');
            var chevron = document.getElementById('chevron-' + menuId);
            if (chevron) {
                chevron.style.transform = 'rotate(90deg)';
            }
        }
    });
});
