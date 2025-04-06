document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const closeMenu = document.getElementById('closeMenu');
    const sideMenu = document.getElementById('sideMenu');

    if (menuToggle && closeMenu && sideMenu) {
        menuToggle.addEventListener('click', function() {
            sideMenu.style.left = '0';
            document.body.style.overflow = 'hidden';
        });

        closeMenu.addEventListener('click', function() {
            sideMenu.style.left = '-280px';
            document.body.style.overflow = '';
        });

        const menuLinks = sideMenu.querySelectorAll('a:not(.exnet-dropdown-toggle)');
        menuLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                sideMenu.style.left = '-280px';
                document.body.style.overflow = '';
            });
        });

        document.addEventListener('click', function(event) {
            if (sideMenu.style.left === '0px' && !sideMenu.contains(event.target) && event.target !== menuToggle) {
                sideMenu.style.left = '-280px';
                document.body.style.overflow = '';
            }
        });

        const dropdownTogglesMobile = sideMenu.querySelectorAll('.exnet-dropdown-toggle');
        dropdownTogglesMobile.forEach(function(toggle) {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = toggle.closest('.exnet-has-submenu');
                const submenu = parent.querySelector('.exnet-submenu');
                const icon = toggle.querySelector('.exnet-dropdown-icon');

                if (submenu.classList.contains('exnet-show')) {
                    submenu.classList.remove('exnet-show');
                    icon.classList.remove('exnet-rotated');
                } else {
                    sideMenu.querySelectorAll('.exnet-submenu.exnet-show').forEach(function(openSubmenu) {
                        openSubmenu.classList.remove('exnet-show');
                        openSubmenu.closest('.exnet-has-submenu').querySelector('.exnet-dropdown-icon').classList.remove('exnet-rotated');
                    });
                    submenu.classList.add('exnet-show');
                    icon.classList.add('exnet-rotated');
                }
            });
        });
    }

    const dropdownTogglesDesktop = document.querySelectorAll('.d-none.d-lg-block .exnet-dropdown-toggle');
    dropdownTogglesDesktop.forEach(function(toggle) {
        const parent = toggle.closest('.exnet-has-submenu');
        const submenu = parent.querySelector('.exnet-submenu');

        parent.addEventListener('mouseenter', function() {
            submenu.classList.add('exnet-show');
            toggle.querySelector('.exnet-dropdown-icon').classList.add('exnet-rotated');
        });

        parent.addEventListener('mouseleave', function() {
            submenu.classList.remove('exnet-show');
            toggle.querySelector('.exnet-dropdown-icon').classList.remove('exnet-rotated');
        });

        toggle.addEventListener('click', function(e) {
            const href = toggle.getAttribute('href');
            if (href && href !== '#' && href !== 'javascript:void(0)') {
                window.location.href = href;
            }
        });
    });

    const menuItemsWithoutSubmenu = document.querySelectorAll('.d-none.d-lg-block a:not(.exnet-dropdown-toggle)');
    menuItemsWithoutSubmenu.forEach(function(link) {
        link.addEventListener('click', function(e) {
            const href = link.getAttribute('href');
            if (href && href !== '#' && href !== 'javascript:void(0)') {
                window.location.href = href;
            }
        });
    });
});