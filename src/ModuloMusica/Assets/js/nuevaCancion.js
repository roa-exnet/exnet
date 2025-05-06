document.addEventListener('DOMContentLoaded', function() {
    // Elementos del DOM
    const imagenFileInput = document.getElementById('imagenFile');
    const imagenBase64Input = document.getElementById('imagenBase64');
    const imagePreview = document.getElementById('imagePreview');
    const imagePreviewContainer = document.getElementById('imagePreviewContainer');
    
    // Previsualización de imagen cuando se selecciona un archivo
    if (imagenFileInput) {
        imagenFileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                
                // Validar tamaño (2MB máximo)
                if (file.size > 2 * 1024 * 1024) {
                    alert('La imagen excede el tamaño máximo de 2MB.');
                    this.value = '';
                    imagePreviewContainer.style.display = 'none';
                    return;
                }
                
                // Validar tipo de archivo
                const allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Formato de imagen no permitido. Usa PNG, JPG o WEBP.');
                    this.value = '';
                    imagePreviewContainer.style.display = 'none';
                    return;
                }
                
                // Mostrar previsualización
                const reader = new FileReader();
                reader.onload = function(e) {
                    imagePreview.src = e.target.result;
                    imagePreviewContainer.style.display = 'block';
                    
                    // Extraer la parte Base64 y guardarla en el campo oculto
                    imagenBase64Input.value = e.target.result.split(',')[1];
                };
                reader.readAsDataURL(file);
            } else {
                imagePreviewContainer.style.display = 'none';
            }
        });
    }
});

// Procesamiento del formulario principal
document.getElementById('musicaForm').addEventListener('submit', async function(event) {
    event.preventDefault(); // Evitar envío normal del formulario

    const audioFileInput = document.getElementById('audioFile');
    const audioBase64Input = document.getElementById('audioBase64');
    const form = this;

    // Verificar archivo de audio seleccionado
    if (audioFileInput.files.length === 0) {
        alert('Por favor, selecciona un archivo de audio.');
        return;
    }

    const file = audioFileInput.files[0];

    // Validar tamaño del archivo de audio (20MB)
    if (file.size > 20 * 1024 * 1024) {
        alert('El archivo excede el tamaño máximo de 20MB.');
        return;
    }

    // Validar tipo de archivo de audio
    const allowedTypes = ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav'];
    if (!allowedTypes.includes(file.type)) {
        alert('Formato de archivo no permitido. Usa MP3, M4A, OGG o WAV.');
        return;
    }

    // Convertir audio a Base64
    try {
        const base64String = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result.split(',')[1]); // Extraer parte Base64
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });

        // Asignar string Base64 al input oculto
        audioBase64Input.value = base64String;

        // Mostrar indicador de carga
        const submitBtn = form.querySelector('.btn-submit');
        const originalText = submitBtn.textContent;
        submitBtn.textContent = 'Subiendo...';
        submitBtn.disabled = true;

        // Enviar formulario por AJAX
        const formData = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            body: formData,
            redirect: 'follow'
        })
        .then(response => {
            if (response.redirected) {
                window.location.href = response.url;
                return;
            }
            return response.json().then(data => {
                if (response.ok) {
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        alert('Canción guardada con éxito');
                        window.location.href = '/musica/admin';
                    }
                } else {
                    alert(data.message || 'Error al guardar la canción');
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                }
            });
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al enviar el formulario: ' + error.message);
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        });
    } catch (error) {
        console.error('Error al procesar los archivos:', error);
        alert('Error al procesar los archivos.');
    }
});