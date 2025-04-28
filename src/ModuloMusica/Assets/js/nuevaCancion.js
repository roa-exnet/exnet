function previewImage(url) {
    const previewContainer = document.getElementById('imagePreviewContainer');
    const previewImage = document.getElementById('imagePreview');
    
    if (url) {
        previewImage.src = url;
        previewContainer.style.display = 'block';
    } else {
        previewContainer.style.display = 'none';
    }
}

document.getElementById('musicaForm').addEventListener('submit', async function(event) {
    event.preventDefault(); // Prevent default form submission

    const audioFileInput = document.getElementById('audioFile');
    const audioBase64Input = document.getElementById('audioBase64');
    const form = this;

    // Check if a file is selected
    if (audioFileInput.files.length === 0) {
        alert('Por favor, selecciona un archivo de audio.');
        return;
    }

    const file = audioFileInput.files[0];

    // Validate file size (20MB)
    if (file.size > 20 * 1024 * 1024) {
        alert('El archivo excede el tamaño máximo de 20MB.');
        return;
    }

    // Validate file type
    const allowedTypes = ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav'];
    if (!allowedTypes.includes(file.type)) {
        alert('Formato de archivo no permitido. Usa MP3, M4A, OGG o WAV.');
        return;
    }

    // Convert file to Base64
    try {
        const base64String = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result.split(',')[1]); // Get Base64 part after comma
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });

        // Set Base64 string in hidden input
        audioBase64Input.value = base64String;

        // Log for debugging
        console.log('Base64 string length:', base64String.length);

        // Submit the form via AJAX
        const formData = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            body: formData,
            redirect: 'follow'
        })
        .then(response => {
            console.log('Response status:', response.status);
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
                }
            });
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al enviar el formulario: ' + error.message);
        });
    } catch (error) {
        console.error('Error converting file to Base64:', error);
        alert('Error al procesar el archivo de audio.');
    }
});