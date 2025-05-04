let currentModule = null;
let currentCategory = null;

document.addEventListener('DOMContentLoaded', function() {
    loadModules();
    
    function loadModules() {
        fetch('/api/modulos/marketplace')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderModules(data.marketplace);
                } else {
                    showError(data.error || 'No se pudieron cargar los módulos');
                }
            })
            .catch(error => {
                showError('Error al conectar con el servidor: ' + error);
            });
    }
    
    function showError(message) {
        const container = document.getElementById('marketplace-container');
        container.innerHTML = `
            <div class="exnet-alert exnet-alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i> ${message}
            </div>
            <div class="text-center mt-4">
                <button class="exnet-btn exnet-btn-outline-light" onclick="window.location.reload()">
                    <i class="fas fa-sync me-2"></i> Reintentar
                </button>
            </div>
        `;
    }
    
    function formatCategoryName(category) {
        if (category.startsWith('modulo')) {
            const name = category.replace('modulo', '');
            return 'Módulo ' + name.charAt(0).toUpperCase() + name.slice(1);
        }
        return category.charAt(0).toUpperCase() + category.slice(1);
    }
    
    function renderModules(marketplace) {
        const container = document.getElementById('marketplace-container');
        container.innerHTML = '';
        
        if (Object.keys(marketplace).length === 0) {
            container.innerHTML = `
                <div class="exnet-alert exnet-alert-info">
                    <i class="fas fa-info-circle me-2"></i> No hay módulos disponibles en este momento.
                </div>
            `;
            return;
        }
        
        const installedModules = [];
        const updateableModules = [];
        const availableModules = {};
        
        for (const category in marketplace) {
            const modules = marketplace[category];
            if (modules.length === 0) continue;
            
            modules.forEach(module => {
                if (module.installed) {
                    if (module.needsUpdate) {
                        // Si necesita actualización, lo añadimos a la lista de actualizables
                        const exists = updateableModules.some(m => 
                            m.name.toLowerCase() === module.name.toLowerCase() ||
                            m.name.toLowerCase() === 'modulo' + module.name.toLowerCase()
                        );
                        if (!exists) {
                            updateableModules.push({...module, category});
                        }
                    } else {
                        // Si no necesita actualización, va a la lista de instalados
                        const exists = installedModules.some(m => 
                            m.name.toLowerCase() === module.name.toLowerCase() ||
                            m.name.toLowerCase() === 'modulo' + module.name.toLowerCase()
                        );
                        if (!exists) {
                            installedModules.push({...module, category});
                        }
                    }
                } else {
                    if (!availableModules[category]) {
                        availableModules[category] = [];
                    }
                    availableModules[category].push(module);
                }
            });
        }
        
        // Sección para módulos que necesitan actualización
        if (updateableModules.length > 0) {
            const updateTitle = document.createElement('h3');
            updateTitle.className = 'exnet-category-title';
            updateTitle.textContent = 'Actualizaciones disponibles';
            container.appendChild(updateTitle);
            
            const updateInfo = document.createElement('p');
            updateInfo.className = 'text-white-50 mb-4';
            updateInfo.textContent = 'Los siguientes módulos tienen nuevas versiones disponibles.';
            container.appendChild(updateInfo);
            
            const updateGrid = document.createElement('div');
            updateGrid.className = 'exnet-module-grid';
            
            updateableModules.forEach(module => {
                updateGrid.appendChild(createModuleCard(module, module.category, true));
            });
            
            container.appendChild(updateGrid);
        }
        
        // Sección para módulos ya instalados (sin actualizaciones pendientes)
        if (installedModules.length > 0) {
            const installedTitle = document.createElement('h3');
            installedTitle.className = 'exnet-category-title' + (updateableModules.length > 0 ? ' mt-5' : '');
            installedTitle.textContent = 'Módulos Instalados';
            container.appendChild(installedTitle);
            
            const installedInfo = document.createElement('p');
            installedInfo.className = 'text-white-50 mb-4';
            installedInfo.textContent = 'Estos módulos ya están instalados en tu sistema.';
            container.appendChild(installedInfo);
            
            const installedGrid = document.createElement('div');
            installedGrid.className = 'exnet-module-grid';
            
            installedModules.forEach(module => {
                installedGrid.appendChild(createModuleCard(module, module.category));
            });
            
            container.appendChild(installedGrid);
        }
        
        // Sección para módulos disponibles para instalar
        if (Object.keys(availableModules).length > 0) {
            const availableTitle = document.createElement('h3');
            availableTitle.className = 'exnet-category-title mt-5';
            availableTitle.textContent = 'Módulos Disponibles';
            container.appendChild(availableTitle);
            
            for (const category in availableModules) {
                const modules = availableModules[category];
                if (modules.length === 0) continue;
                
                const categoryTitle = document.createElement('h4');
                categoryTitle.className = 'exnet-category-subtitle';
                categoryTitle.textContent = formatCategoryName(category);
                container.appendChild(categoryTitle);
                
                const moduleGrid = document.createElement('div');
                moduleGrid.className = 'exnet-module-grid';
                
                modules.forEach(module => {
                    moduleGrid.appendChild(createModuleCard(module, category));
                });
                
                container.appendChild(moduleGrid);
            }
        }
    }
    
    function createModuleCard(module, category, needsUpdate = false) {
        const card = document.createElement('div');
        card.className = 'exnet-module-card';
        
        if (needsUpdate) {
            console.log(`[DEBUG] Módulo ${module.name} necesita actualización:`, {
                installedVersion: module.installedVersion,
                marketplaceVersion: module.version
            });
        }
        
        if (module.price === 'premium') {
            const premiumBadge = document.createElement('span');
            premiumBadge.className = 'exnet-premium-badge';
            premiumBadge.innerHTML = '<i class="fas fa-crown me-1"></i> Premium';
            card.appendChild(premiumBadge);
        }
        
        if (module.installed && !needsUpdate) {
            const installedBadge = document.createElement('span');
            installedBadge.className = 'exnet-installed-badge';
            installedBadge.innerHTML = '<i class="fas fa-check me-1"></i> Instalado';
            card.appendChild(installedBadge);
        }
        
        card.innerHTML += `
            <div class="exnet-module-icon">
                <i class="fas fa-puzzle-piece"></i>
            </div>
            <h4 class="exnet-module-title">${module.name}</h4>
            <div class="exnet-module-version">
                ${needsUpdate 
                    ? `<span class="current-version">v${module.installedVersion}</span> → <span class="new-version">v${module.version}</span>` 
                    : `v${module.version}`}
            </div>
            <p class="exnet-module-description">${module.description}</p>
        `;
        
        if (!module.installed || needsUpdate) {
            const installButton = document.createElement('button');
            
            if (needsUpdate) {
                installButton.className = 'exnet-btn exnet-btn-warning w-100';
                installButton.innerHTML = '<i class="fas fa-arrow-up me-2"></i> Actualizar';
            } else {
                installButton.className = 'exnet-btn exnet-btn-blue w-100';
                installButton.innerHTML = '<i class="fas fa-download me-2"></i> Instalar';
            }
            
            if (module.price === 'premium') {
                installButton.onclick = function() {
                    toggleLicenseForm(this, module, category);
                };
            } else {
                installButton.onclick = function() {
                    if (needsUpdate) {
                        updateModule(module, category);
                    } else {
                        installModule(module, category);
                    }
                };
            }
            
            card.appendChild(installButton);
            
            if (module.price === 'premium') {
                const licenseForm = document.createElement('div');
                licenseForm.className = 'exnet-license-form';
                licenseForm.innerHTML = `
                    <input type="text" class="exnet-license-input" placeholder="Introduce tu clave de licencia" />
                    <button class="exnet-btn exnet-btn-green w-100 mt-2 exnet-verify-license-btn">
                        <i class="fas fa-key me-2"></i> Verificar licencia
                    </button>
                `;
                
                const verifyButton = licenseForm.querySelector('.exnet-verify-license-btn');
                verifyButton.onclick = function() {
                    const licenseKey = licenseForm.querySelector('.exnet-license-input').value.trim();
                    if (licenseKey) {
                        verifyLicense(licenseKey, module, category, needsUpdate);
                    }
                };
                
                card.appendChild(licenseForm);
            }
        }
        
        return card;
    }
    
    function toggleLicenseForm(button, module, category) {
        const card = button.closest('.exnet-module-card');
        const licenseForm = card.querySelector('.exnet-license-form');
        
        if (licenseForm.style.display === 'block') {
            licenseForm.style.display = 'none';
            button.innerHTML = `<i class="fas fa-${module.needsUpdate ? 'arrow-up' : 'download'} me-2"></i> ${module.needsUpdate ? 'Actualizar' : 'Instalar'}`;
        } else {
            licenseForm.style.display = 'block';
            button.innerHTML = '<i class="fas fa-times me-2"></i> Cancelar';
        }
    }
    
    function verifyLicense(licenseKey, module, category, isUpdate = false) {
        fetch('/api/modulos/verify-license', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                licenseKey: licenseKey,
                moduleFilename: module.filename
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.valid) {
                if (isUpdate) {
                    updateModule(module, category, data.downloadToken);
                } else {
                    installModule(module, category, data.downloadToken);
                }
            } else {
                showError(data.message || 'Licencia inválida');
            }
        })
        .catch(error => {
            showError('Error al verificar la licencia: ' + error);
        });
    }
    
    function updateModule(module, category, downloadToken = null) {
        // Primero debemos desinstalar el módulo actual
        // Luego proceder con la instalación normal
        // Para simplificar, reutilizamos el mismo método de instalación que el original
        // ya que el Backend maneja la lógica de reemplazar un módulo existente
        installModule(module, category, downloadToken);
    }
    
    function installModule(module, category, downloadToken = null) {
        currentModule = module;
        currentCategory = category;

        const container = document.getElementById('marketplace-container');
        container.innerHTML = `
            <div class="d-flex justify-content-center p-5 flex-column align-items-center">
                <div class="exnet-loader" style="width: 40px; height: 40px; margin-bottom: 20px;"></div>
                <h4 class="text-white mb-3">${module.needsUpdate ? 'Actualizando' : 'Instalando'} módulo ${module.name}</h4>
                <p class="text-white-50">Este proceso puede tardar unos minutos. No cierres esta página.</p>
            </div>
        `;
        
        fetch('/api/modulos/install', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                moduleType: category,
                filename: module.filename,
                downloadToken: downloadToken
            })
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => {
                    throw new Error(data.message || `Error HTTP: ${response.status}`);
                });
            }
            return response.json();
        })
        .then(data => {
            console.log("Respuesta de instalación:", data);
            
            setTimeout(() => {
                window.location.href = '/modulos';
            }, 1000);
        })
        .catch(error => {
            console.error('Error en la instalación:', error);
            
            setTimeout(() => {
                window.location.href = '/modulos';
            }, 1000);
        });
    }
});