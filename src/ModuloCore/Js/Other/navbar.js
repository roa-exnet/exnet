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
        
        const simpleMenuLinks = sideMenu.querySelectorAll('a:not(.dropdown-toggle)');
        simpleMenuLinks.forEach(function(link) {
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
    }

    const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
    
    dropdownToggles.forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const parent = this.parentElement;
            const submenu = parent.querySelector('.submenu');
            
            if (submenu) {
                if (submenu.classList.contains('show')) {
                    submenu.classList.remove('show');
                    this.querySelector('.dropdown-icon').classList.remove('rotated');
                } else {
                    document.querySelectorAll('.submenu.show').forEach(function(openSubmenu) {
                        if (openSubmenu !== submenu) {
                            openSubmenu.classList.remove('show');
                            openSubmenu.parentElement.querySelector('.dropdown-icon').classList.remove('rotated');
                        }
                    });
                    
                    submenu.classList.add('show');
                    this.querySelector('.dropdown-icon').classList.add('rotated');
                }
            }
        });
    });

    document.addEventListener('click', function(event) {
        if (!event.target.closest('.has-submenu')) {
            document.querySelectorAll('.submenu.show').forEach(function(submenu) {
                submenu.classList.remove('show');
                const toggle = submenu.parentElement.querySelector('.dropdown-icon');
                if (toggle) {
                    toggle.classList.remove('rotated');
                }
            });
        }
    });
    
    document.querySelectorAll('.submenu').forEach(function(submenu) {
        submenu.addEventListener('click', function(e) {
            if (!e.target.closest('a')) {
                e.stopPropagation();
            }
        });
    });
});