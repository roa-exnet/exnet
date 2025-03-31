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
        
        const menuLinks = sideMenu.querySelectorAll('a');
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
    }
});