document.addEventListener('DOMContentLoaded', function() {
    const ipInput = document.getElementById('ip_address');
    
    document.getElementById('editUserForm').addEventListener('submit', function(e) {
        let hasErrors = false;
        
        if (!document.getElementById('nombre').value || !document.getElementById('apellidos').value) {
            hasErrors = true;
            alert('Por favor, completa los campos de nombre y apellidos.');
        }
        
        if (ipInput.value && !validateIP(ipInput.value)) {
            hasErrors = true;
            alert('La dirección IP introducida no es válida.');
        }
        
        if (hasErrors) {
            e.preventDefault();
        }
    });
    
    function validateIP(ip) {
        const ipv4Pattern = /^(\d{1,3}\.){3}\d{1,3}$/;
        if (ipv4Pattern.test(ip)) {
            const parts = ip.split('.');
            for (let part of parts) {
                const num = parseInt(part, 10);
                if (num < 0 || num > 255) {
                    return false;
                }
            }
            return true;
        }
        
        return ip.includes(':') && ip.length >= 7;
    }
});