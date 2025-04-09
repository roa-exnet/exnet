function showRestoreModal(id, name, type, token) {
    document.getElementById('restoreBackupName').textContent = name;
    
    const form = document.getElementById('restoreForm');
    form.action = "{{ path('backups_restore', {'id': '0'}) }}".replace('/0', '/' + id);
    
    const tokenInput = form.querySelector('input[name="_token"]');
    tokenInput.value = token;
    
    const modal = new bootstrap.Modal(document.getElementById('restoreModal'));
    modal.show();
}

function showDeleteModal(id, name, token) {
    document.getElementById('deleteBackupName').textContent = name;
    
    const form = document.getElementById('deleteForm');
    form.action = "{{ path('backups_delete', {'id': '0'}) }}".replace('/0', '/' + id);
    
    const tokenInput = form.querySelector('input[name="_token"]');
    tokenInput.value = token;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

document.getElementById('confirmationInput').addEventListener('input', function() {
    const confirmationBtn = document.getElementById('confirmRestoreBtn');
    const confirmationToken = document.getElementById('confirmationToken');
    
    if (this.value === 'RESTORE-BACKUP') {
        confirmationBtn.disabled = false;
        confirmationToken.value = this.value;
    } else {
        confirmationBtn.disabled = true;
        confirmationToken.value = '';
    }
});

document.getElementById('backupForm').addEventListener('submit', function(e) {
    if (!e.defaultPrevented) {
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Creando Backup...';
    }
});