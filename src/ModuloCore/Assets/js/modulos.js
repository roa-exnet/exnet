let moduleIdToDelete = null;

document.addEventListener('DOMContentLoaded', function() {
    const openButtons = document.querySelectorAll('.open-module-btn');
    openButtons.forEach(button => {
        const moduleId = button.getAttribute('data-module-id');
        fetch(`/api/modulos/${moduleId}/get-url`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error al obtener la URL del módulo');
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.url) {
                button.setAttribute('href', data.url);
            } else {
                button.setAttribute('href', '#');
                button.classList.add('disabled');
                button.setAttribute('title', 'URL no disponible');
            }
        })
        .catch(error => {
            console.error('Error al cargar la URL del módulo:', error);
            button.setAttribute('href', '#');
            button.classList.add('disabled');
            button.setAttribute('title', 'Error al cargar la URL');
        });
    });
});

function showDeleteConfirm(moduleId, moduleName) {
    moduleIdToDelete = moduleId;
    document.getElementById('moduleNameToDelete').textContent = moduleName;
    document.getElementById('deleteConfirmModal').style.display = 'block';
    document.body.style.overflow = 'hidden';

    document.getElementById('confirmDeleteBtn').onclick = function() {
        deleteModule(moduleId);
    };
}

function closeDeleteConfirm() {
    document.getElementById('deleteConfirmModal').style.display = 'none';
    document.body.style.overflow = '';
    moduleIdToDelete = null;

    document.getElementById('deleteInProgress').style.display = 'none';
    document.getElementById('deleteModalButtons').style.display = 'flex';
    document.getElementById('deleteLog').innerHTML = '';
    document.getElementById('confirmDeleteBtn').textContent = 'Desinstalar'; 
}

function deleteModule(moduleId) {

    document.getElementById('deleteInProgress').style.display = 'block';
    document.getElementById('deleteModalButtons').style.display = 'none';

    const deleteLog = document.getElementById('deleteLog');
    deleteLog.innerHTML = '> Iniciando proceso de desinstalación...\n';

    fetch(`/api/modulos/${moduleId}/uninstall`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({})
    })
    .then(response => {

        window.location.href = '/modulos';
    })
    .catch(error => {

        window.location.href = '/modulos';
    });
}

window.onclick = function(event) {
    const modal = document.getElementById('deleteConfirmModal');
    if (event.target == modal && document.getElementById('deleteInProgress').style.display !== 'block') {
        closeDeleteConfirm();
    }
}