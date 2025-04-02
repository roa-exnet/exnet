class AuthService {
    constructor() {
        this.apiEndpoint = '/api/auth';
        this.tokenKey = 'exnet_auth_token';
        this._currentUser = null;
    }

    async getCurrentUser() {
        if (this._currentUser) {
            return this._currentUser;
        }

        try {
            const response = await fetch(`${this.apiEndpoint}/verify-token`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                return null;
            }

            const data = await response.json();
            
            if (data.success && data.valid) {
                this._currentUser = {
                    id: data.userId,
                    name: data.userName,
                    authMethod: data.authMethod
                };
                return this._currentUser;
            }
        } catch (error) {
            console.error('Error al verificar autenticación:', error);
        }

        return null;
    }

    async getApiToken() {
        try {
            const cachedToken = localStorage.getItem(this.tokenKey);
            if (cachedToken) {
                const tokenData = JSON.parse(cachedToken);
                if (tokenData.expires > Date.now()) {
                    return tokenData.token;
                }
                localStorage.removeItem(this.tokenKey);
            }

            const response = await fetch(`${this.apiEndpoint}/generate-token`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                return null;
            }

            const data = await response.json();
            
            if (data.success && data.token) {
                const tokenData = {
                    token: data.token,
                    expires: Date.now() + (30 * 60 * 1000)
                };
                localStorage.setItem(this.tokenKey, JSON.stringify(tokenData));
                
                return data.token;
            }
        } catch (error) {
            console.error('Error al obtener token API:', error);
        }

        return null;
    }
    
    async isAuthenticated() {
        const user = await this.getCurrentUser();
        return !!user;
    }

    async logout() {
        try {
            await fetch('/jwt-logout', {
                method: 'GET',
                credentials: 'same-origin'
            });
            
            this._currentUser = null;
            localStorage.removeItem(this.tokenKey);
            
            return true;
        } catch (error) {
            console.error('Error al cerrar sesión:', error);
            return false;
        }
    }
}

window.authService = new AuthService();