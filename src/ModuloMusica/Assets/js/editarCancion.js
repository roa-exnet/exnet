document.addEventListener('DOMContentLoaded', function() {

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
                    return;
                }

                const allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Formato de imagen no permitido. Usa PNG, JPG o WEBP.');
                    this.value = '';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    imagePreview.src = e.target.result;
                    imagePreviewContainer.style.display = 'block';

                    imagenBase64Input.value = e.target.result.split(',')[1];
                };
                reader.readAsDataURL(file);
            }
        });
    }

    const audioFileInput = document.getElementById('audioFile');
    const audioBase64Input = document.getElementById('audioBase64');

    if (audioFileInput) {
        audioFileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const file = this.files[0];

                if (file.size > 20 * 1024 * 1024) {
                    alert('El archivo de audio excede el tamaño máximo de 20MB.');
                    this.value = '';
                    return;
                }

                const allowedTypes = ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Formato de audio no permitido. Usa MP3, M4A, OGG o WAV.');
                    this.value = '';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    audioBase64Input.value = e.target.result.split(',')[1];
                };
                reader.readAsDataURL(file);
            }
        });
    }

    const duracionInput = document.getElementById('duracion');
    if (duracionInput) {
        duracionInput.addEventListener('focusout', function() {
            const value = this.value.trim();

            if (value.includes(':')) {
                const parts = value.split(':');
                if (parts.length === 2) {
                    const minutes = parseInt(parts[0], 10);
                    const seconds = parseInt(parts[1], 10);

                    if (!isNaN(minutes) && !isNaN(seconds)) {
                        this.value = (minutes * 60) + seconds;
                    }
                }
            }
        });
    }
});