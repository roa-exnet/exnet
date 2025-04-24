    // Función para cargar la imagen
    function cargarImagen() {
        var filename = "{{ filename }}";
        var directory = "{{ directory }}";
        var loader = document.getElementById('imageLoader');
        
        if (loader) {
            loader.style.display = 'flex';
        }
        
        // Hacer la solicitud POST
        fetch("{{ path('ver_imagen') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                'filename': filename,
                'path': directory
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en la respuesta del servidor: ' + response.status);
            }
            return response.blob();
        })
        .then(imageBlob => {
            var img = URL.createObjectURL(imageBlob);
            document.getElementById('imageIframe').src = img;
            if (loader) {
                loader.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error cargando la imagen:', error);
            if (loader) {
                loader.querySelector('.loader-spinner').style.display = 'none';
                loader.querySelector('.loader-text').textContent = 'Error al cargar la imagen. Intente nuevamente.';
            }
        });
    }
    
    // Función que maneja el error al cargar la imagen en el iframe
    function handleImageError() {
        console.log('Error al cargar la imagen en el iframe.');
        var loader = document.getElementById('imageLoader');
        if (loader) {
            loader.querySelector('.loader-spinner').style.display = 'none';
            loader.querySelector('.loader-text').textContent = 'Error al cargar la imagen. Intente nuevamente.';
            loader.style.display = 'flex';
        }
    }

    // Ejecutar la función de carga de imagen al cargar la página
    document.addEventListener('DOMContentLoaded', cargarImagen);