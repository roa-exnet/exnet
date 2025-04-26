document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('userSearch');
    const filterButtons = document.querySelectorAll('[data-filter]');
    const tableRows = document.querySelectorAll('#usersTableBody tr');
    
    let currentFilter = 'all';
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        applyFilters(searchTerm, currentFilter);
    });
    
    filterButtons.forEach(button => {
        button.addEventListener('click', function() {
            const filter = this.getAttribute('data-filter');
            currentFilter = filter;
            
            filterButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            applyFilters(searchInput.value.toLowerCase().trim(), filter);
        });
    });
    
    function applyFilters(searchTerm, filter) {
        tableRows.forEach(row => {
            const userName = row.getAttribute('data-user-name').toLowerCase();
            const userEmail = row.getAttribute('data-user-email').toLowerCase();
            const isActive = row.getAttribute('data-user-active') === 'true';
            const isAdmin = row.getAttribute('data-user-admin') === 'true';
            
            const matchesSearch = userName.includes(searchTerm) || userEmail.includes(searchTerm);
            let matchesFilter = true;
            
            if (filter === 'active' && !isActive) {
                matchesFilter = false;
            } else if (filter === 'admin' && !isAdmin) {
                matchesFilter = false;
            }
            
            if (matchesSearch && matchesFilter) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
});