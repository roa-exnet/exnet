if (typeof window.authService === 'undefined') {
    const authScript = document.createElement('script');
    authScript.src = '/js/auth.js';
    authScript.async = true;
    
    authScript.onload = function() {
        if (window.authService) {
            window.authService.isAuthenticated()
                .then(isAuthenticated => {
                    if (!isAuthenticated) {
                        const protectedPaths = ['/chat', '/api'];
                        const currentPath = window.location.pathname;
                        
                        for (const path of protectedPaths) {
                            if (currentPath.startsWith(path)) {
                                const redirectUrl = `/login?redirect=${encodeURIComponent(window.location.href)}`;
                                window.location.href = redirectUrl;
                                break;
                            }
                        }
                    }
                })
                .catch(error => {
                    console.error('Error al verificar autenticación:', error);
                });
        }
    };
    
    document.head.appendChild(authScript);
}