const videoPlayer = document.getElementById('videoPlayer');

if (videoPlayer) {
    const savedTime = localStorage.getItem('videoTime_{{ video.id }}');
    if (savedTime) {
        videoPlayer.currentTime = parseFloat(savedTime);
    }
    
    setInterval(() => {
        localStorage.setItem('videoTime_{{ video.id }}', videoPlayer.currentTime);
    }, 5000);
    
    window.addEventListener('beforeunload', () => {
        localStorage.setItem('videoTime_{{ video.id }}', videoPlayer.currentTime);
    });
    
    videoPlayer.addEventListener('ended', () => {
        localStorage.removeItem('videoTime_{{ video.id }}');
        localStorage.setItem('videoComplete_{{ video.id }}', 'true');
    });
}

document.body.classList.add('video-player-page');