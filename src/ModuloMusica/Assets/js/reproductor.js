/**
 * Reproductor de audio para el Módulo Música
 * Adaptado del reproductor de streaming, con barra de progreso personalizada corregida
 */

document.addEventListener('DOMContentLoaded', () => {
    // Elementos del DOM
    const audioPlayer = document.getElementById('audioPlayer');
    const playPauseButton = document.getElementById('playPauseButton');
    const playPauseIcon = document.getElementById('playPauseIcon');
    const progressBar = document.getElementById('progressBar');
    const progress = document.getElementById('progress');
    const currentTimeDisplay = document.getElementById('currentTime');
    const durationDisplay = document.getElementById('duration');
    const prevButton = document.getElementById('prevButton');
    const nextButton = document.getElementById('nextButton');

    // Verificar elementos necesarios
    if (!audioPlayer || !progressBar || !progress || !currentTimeDisplay || !durationDisplay) {
        console.error('Elementos del reproductor no encontrados');
        return;
    }

    // ID de la canción para localStorage
    const songId = audioPlayer.getAttribute('data-song-id');

    /**
     * Utilidades
     */
    function formatTime(seconds) {
        if (isNaN(seconds) || seconds < 0) return '0:00';
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = Math.floor(seconds % 60);
        return `${minutes}:${remainingSeconds < 10 ? '0' : ''}${remainingSeconds}`;
    }

    function updateProgressUI() {
        if (audioPlayer.duration && !isNaN(audioPlayer.duration)) {
            const position = audioPlayer.currentTime / audioPlayer.duration;
            progress.style.width = `${position * 100}%`;
            currentTimeDisplay.textContent = formatTime(audioPlayer.currentTime);
        }
    }

    function setAudioPosition(position) {
        if (!audioPlayer.duration || isNaN(audioPlayer.duration)) {
            console.warn('Duración no disponible, no se puede establecer posición');
            return;
        }
        position = Math.max(0, Math.min(1, position));
        audioPlayer.currentTime = position * audioPlayer.duration;
        updateProgressUI();
    }

    function savePosition() {
        if (songId && !isNaN(audioPlayer.currentTime)) {
            localStorage.setItem(`audioTime_${songId}`, audioPlayer.currentTime);
        }
    }

    /**
     * Manejadores de eventos
     */
    function handlePlayPause() {
        if (audioPlayer.paused) {
            audioPlayer.play()
                .then(() => playPauseIcon.className = 'fas fa-pause')
                .catch(error => console.error('Error al reproducir:', error));
        } else {
            audioPlayer.pause();
            playPauseIcon.className = 'fas fa-play';
            savePosition();
        }
    }

    function handlePrevButton() {
        if (audioPlayer.duration && !isNaN(audioPlayer.duration)) {
            audioPlayer.currentTime = Math.max(0, audioPlayer.currentTime - 10);
            updateProgressUI();
        }
    }

    function handleNextButton() {
        if (audioPlayer.duration && !isNaN(audioPlayer.duration)) {
            audioPlayer.currentTime = Math.min(audioPlayer.duration, audioPlayer.currentTime + 10);
            updateProgressUI();
        }
    }

    // Función corregida para manejar clics en la barra de progreso
    function handleProgressClick(e) {
        // Obtener la posición del clic relativa a la barra de progreso
        const rect = progressBar.getBoundingClientRect();
        let clientX;
        
        // Manejar tanto eventos de ratón como de táctiles
        if (e.type.includes('touch')) {
            clientX = e.touches[0].clientX;
        } else {
            clientX = e.clientX;
        }
        
        // Calcular la posición relativa (entre 0 y 1)
        const clickPosition = (clientX - rect.left) / rect.width;
        
        // Validar y ajustar la posición
        if (isNaN(clickPosition)) return;
        const position = Math.max(0, Math.min(1, clickPosition));
        
        // Aplicar la nueva posición
        setAudioPosition(position);
        
        // Si el audio estaba pausado, dejarlo pausado
        if (audioPlayer.paused) {
            playPauseIcon.className = 'fas fa-play';
        }
    }

    // Función para manejar el arrastre (drag) en la barra de progreso
    function handleProgressDrag() {
        let isDragging = false;
        
        // Inicio del arrastre
        function startDrag(e) {
            e.preventDefault();
            isDragging = true;
            handleProgressClick(e);
            
            // Agregar eventos para seguir el arrastre
            document.addEventListener('mousemove', moveDrag);
            document.addEventListener('touchmove', moveDrag, { passive: false });
            document.addEventListener('mouseup', endDrag);
            document.addEventListener('touchend', endDrag);
        }
        
        // Durante el arrastre
        function moveDrag(e) {
            if (isDragging) {
                e.preventDefault();
                handleProgressClick(e);
            }
        }
        
        // Fin del arrastre
        function endDrag() {
            if (isDragging) {
                isDragging = false;
                document.removeEventListener('mousemove', moveDrag);
                document.removeEventListener('touchmove', moveDrag);
                document.removeEventListener('mouseup', endDrag);
                document.removeEventListener('touchend', endDrag);
            }
        }
        
        // Agregar eventos de inicio de arrastre
        progressBar.addEventListener('mousedown', startDrag);
        progressBar.addEventListener('touchstart', startDrag, { passive: false });
    }

    function handleTimeUpdate() {
        updateProgressUI();
    }

    function handleMetadataLoaded() {
        if (audioPlayer.duration && !isNaN(audioPlayer.duration)) {
            durationDisplay.textContent = formatTime(audioPlayer.duration);
            const savedTime = localStorage.getItem(`audioTime_${songId}`);
            if (savedTime && !isNaN(parseFloat(savedTime))) {
                audioPlayer.currentTime = parseFloat(savedTime);
                updateProgressUI();
            }
        }
    }

    function handleEnded() {
        playPauseIcon.className = 'fas fa-play';
        localStorage.removeItem(`audioTime_${songId}`);
        localStorage.setItem(`audioComplete_${songId}`, 'true');
        updateProgressUI();
    }

    /**
     * LocalStorage y eventos (adaptado del reproductor de streaming)
     */
    if (audioPlayer) {
        setInterval(savePosition, 5000);
        window.addEventListener('beforeunload', savePosition);
        audioPlayer.addEventListener('timeupdate', handleTimeUpdate);
        audioPlayer.addEventListener('loadedmetadata', handleMetadataLoaded);
        audioPlayer.addEventListener('ended', handleEnded);
    }

    // Eventos de controles
    playPauseButton?.addEventListener('click', handlePlayPause);
    prevButton?.addEventListener('click', handlePrevButton);
    nextButton?.addEventListener('click', handleNextButton);
    
    // Inicializar el manejo de arrastre en la barra de progreso
    handleProgressDrag();

    // Agregar clase al body
    document.body.classList.add('audio-player-page');
});