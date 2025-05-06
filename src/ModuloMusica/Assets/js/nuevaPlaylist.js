document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('playlistForm');
    const imagenFileInput = document.getElementById('imagenFile');
    const imagenBase64Input = document.getElementById('imagenBase64');
    const imagePreview = document.getElementById('imagePreview');
    const imagePreviewContainer = document.getElementById('imagePreviewContainer');

    if (imagenFileInput) {
        imagenFileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const file = this.files[0];

                if (file.size > 2 * 1024 * 1024) {
                    alert('La imagen excede el tamaño máximo de 2MB.');
                    this.value = '';
                    imagePreviewContainer.style.display = 'none';
                    return;
                }

                const allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Formato de imagen no permitido. Usa PNG, JPG o WEBP.');
                    this.value = '';
                    imagePreviewContainer.style.display = 'none';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    imagePreview.src = e.target.result;
                    imagePreviewContainer.style.display = 'block';

                    imagenBase64Input.value = e.target.result.split(',')[1];
                };
                reader.readAsDataURL(file);
            } else {
                imagePreviewContainer.style.display = 'none';
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function(event) {

            if (imagenFileInput && imagenFileInput.files.length > 0) {
                event.preventDefault();

                const submitBtn = form.querySelector('.btn-submit');
                const originalText = submitBtn.textContent;
                submitBtn.textContent = 'Creando...';
                submitBtn.disabled = true;

                form.submit();
            }

        });
    }
});