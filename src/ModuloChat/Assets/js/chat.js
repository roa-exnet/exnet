document.addEventListener('DOMContentLoaded', function() {
    // const userId = {{ userId|json_encode|raw }};
    // const userName = {{ userName|json_encode|raw }};
    // const userToken = {{ userToken|json_encode|raw }};
    // const websocketUrl = {{ websocketUrl|json_encode|raw }};
    
    localStorage.setItem('exnet_chat_user_id', userId);
    localStorage.setItem('exnet_chat_user_name', userName);
    localStorage.setItem('exnet_chat_user_token', userToken);
    
    console.log('Información de usuario almacenada en localStorage');
    
    let pendingCallOffers = {};
    
    const storedUserId = localStorage.getItem('exnet_chat_user_id') || userId;
    const storedUserName = localStorage.getItem('exnet_chat_user_name') || userName;
    const storedUserToken = localStorage.getItem('exnet_chat_user_token') || userToken;
    
    console.log('Inicializando chat con:', { userId: storedUserId, userName: storedUserName, websocketUrl });
    
    const socket = io(websocketUrl);
    let currentRoomId = null;
    let isAuthenticated = false;
    let voiceCallManager = null;
    
    // Keep track of sent messages to avoid duplicates
    const sentMessageIds = new Set();
    // Keep track of messages sent by the current user to avoid adding them twice
    const locallyAddedMessages = new Set();
    
    socket.on('connect', () => {
        console.log('Conectado al servidor WebSocket');
        
        console.log('Enviando autenticación con token basado en IP');
        socket.emit('authenticate', { 
            userId: storedUserId, 
            userName: storedUserName,
            token: storedUserToken 
        });
    });
    
    socket.on('authenticated', (data) => {
        console.log('Autenticado con éxito como:', data);
        isAuthenticated = true;
        
        if (!voiceCallManager) {
            voiceCallManager = new VoiceCallManager(socket, storedUserId, storedUserName, websocketUrl);
        }
        
        loadUserRooms();
    });
    
    socket.on('disconnect', () => {
        console.log('Desconectado del servidor WebSocket');
        isAuthenticated = false;
    });
    
    socket.on('error', (error) => {
        console.error('Error WebSocket:', error);
        if (error.message && error.message.includes('not authenticated')) {
            socket.emit('authenticate', { 
                userId: storedUserId, 
                userName: storedUserName,
                token: storedUserToken
            });
        }
    });
    
    socket.on('message', (message) => {
        console.log('Mensaje recibido del servidor WebSocket:', message);
        if (message.roomId === currentRoomId) {
            // Check if the message was already added locally (sent by the current user)
            const tempMessageId = `temp_${message.timestamp}`;
            
            // Only add the message if it wasn't already added locally
            // or it wasn't sent by the current user
            if (!sentMessageIds.has(message.id) && 
                !(message.senderId === storedUserId.toString() && locallyAddedMessages.has(message.content))) {
                
                addMessageToChat({
                    id: message.id,
                    senderId: message.senderId,
                    senderName: message.senderName,
                    content: message.content,
                    type: message.type || 'text',
                    timestamp: new Date(message.timestamp)
                });
                
                sentMessageIds.add(message.id);
                
                if (sentMessageIds.size > 100) {
                    const iterator = sentMessageIds.values();
                    sentMessageIds.delete(iterator.next().value);
                }
            }
        }
    });
    
    socket.on('user_typing', (data) => {
        if (data.roomId === currentRoomId && data.userId !== storedUserId) {
            updateTypingIndicator(data);
        }
    });
    
    socket.on('room_created', (data) => {
        console.log('Nueva sala creada:', data);
        loadUserRooms();
    });
    
    socket.on('voice_call_invite', (data) => {
        console.log('Invitación a llamada recibida:', data);
        
        if (voiceCallManager && voiceCallManager.isCallActive) {
            console.log('Ya hay una llamada activa. Rechazar automáticamente.');
            socket.emit('voice_call_reject', {
                roomId: data.roomId,
                callerId: data.callerId,
                userId: storedUserId,
                userName: storedUserName,
                reason: 'busy'
            });
            return;
        }
        
        const offerKey = `${data.roomId}_${data.callerId}`;
        if (pendingCallOffers[offerKey]) {
            console.log('Invitación duplicada recibida, ignorando.');
            return;
        }
        
        pendingCallOffers[offerKey] = {
            timestamp: Date.now(),
            data: data
        };
        
        if (!voiceCallManager) {
            voiceCallManager = new VoiceCallManager(socket, storedUserId, storedUserName, websocketUrl);
        }
        
        if (voiceCallManager.showIncomingCallUI) {
            voiceCallManager.showIncomingCallUI(data.roomId, data.callerId, data.callerName);
        }
        
        setTimeout(() => {
            delete pendingCallOffers[offerKey];
        }, 30000);
    });
    
    const chatModal = document.getElementById('chatModal');
    const btnNewChat = document.getElementById('btnNewChat');
    const closeBtn = document.querySelector('.close');
    
    const userSearch = document.getElementById('userSearch');
    const searchResults = document.getElementById('searchResults');
    const selectedUsers = document.getElementById('selectedUsers');
    const recipientIdsInput = document.getElementById('recipientIds');
    let selectedRecipients = [];
    
    let searchTimeout;
    userSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        if (query.length < 2) {
            searchResults.innerHTML = '';
            searchResults.style.display = 'none';
            return;
        }
        
        searchTimeout = setTimeout(() => {
            simulateUserSearch(query);
        }, 300);
    });
    
    function simulateUserSearch(query) {
        searchResults.innerHTML = '<div class="loading">Buscando usuarios...</div>';
        searchResults.style.display = 'block';
        
        // Asegurarse de enviar el token para validar la autenticación
        fetch(`/kc/chat/api/users/search?q=${encodeURIComponent(query)}&token=${encodeURIComponent(storedUserToken)}&userId=${storedUserId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error en la respuesta del servidor: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    console.log(`Encontrados ${data.users.length} usuarios para la consulta: "${query}"`);
                    // Los nombres ya vienen desencriptados desde el servidor
                    displaySearchResults(data.users);
                } else {
                    console.error('Error en la búsqueda:', data.error || 'Error desconocido');
                    searchResults.innerHTML = '<div class="no-results">Error al buscar usuarios</div>';
                    searchResults.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error al buscar usuarios:', error);
                searchResults.innerHTML = '<div class="no-results">Error al conectar con el servidor</div>';
                searchResults.style.display = 'block';
            });
    }
    
    function displaySearchResults(users) {
        searchResults.innerHTML = '';
        
        if (!users || users.length === 0) {
            searchResults.innerHTML = '<div class="no-results">No se encontraron usuarios</div>';
            searchResults.style.display = 'block';
            return;
        }
        
        users.forEach(user => {
            if (selectedRecipients.some(selected => selected.id === user.id)) {
                return;
            }
            
            const userElement = document.createElement('div');
            userElement.className = 'user-result';
            userElement.innerHTML = `
                <div class="user-info">
                    <div class="user-name">${user.nombre}</div>
                    <div class="user-email">${user.email}</div>
                </div>
            `;
            
            userElement.addEventListener('click', () => {
                selectUser(user);
                searchResults.style.display = 'none';
                userSearch.value = '';
            });
            
            searchResults.appendChild(userElement);
        });
        
        searchResults.style.display = 'block';
    }
    
    function selectUser(user) {
        if (selectedRecipients.some(selected => selected.id === user.id)) {
            return;
        }
        
        selectedRecipients.push(user);
        updateSelectedUsers();
        updateRecipientIds();
    }
    
    function updateSelectedUsers() {
        selectedUsers.innerHTML = '';
        
        selectedRecipients.forEach(user => {
            const userTag = document.createElement('div');
            userTag.className = 'user-tag';
            userTag.innerHTML = `
                <span>${user.nombre}</span>
                <button type="button" class="remove-user" data-id="${user.id}">&times;</button>
            `;
            
            selectedUsers.appendChild(userTag);
        });
        
        document.querySelectorAll('.remove-user').forEach(button => {
            button.addEventListener('click', function() {
                const userId = parseInt(this.getAttribute('data-id'));
                selectedRecipients = selectedRecipients.filter(user => user.id !== userId);
                updateSelectedUsers();
                updateRecipientIds();
            });
        });
        
        selectedUsers.style.display = selectedRecipients.length > 0 ? 'flex' : 'none';
    }
    
    function updateRecipientIds() {
        const ids = selectedRecipients.map(user => user.id);
        recipientIdsInput.value = ids.join(',');
    }
    
    btnNewChat.addEventListener('click', function() {
        chatModal.style.display = "block";
        document.getElementById('chatForm').reset();
        selectedRecipients = [];
        updateSelectedUsers();
        updateRecipientIds();
    });
    
    closeBtn.addEventListener('click', function() {
        chatModal.style.display = "none";
    });
    
    window.addEventListener('click', function(event) {
        if (event.target == chatModal) {
            chatModal.style.display = "none";
        }
    });
    
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.search-container')) {
            searchResults.style.display = 'none';
        }
    });
    
    let isSubmitting = false;
    
    document.getElementById('chatForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (isSubmitting) return;
        isSubmitting = true;
        
        const chatName = document.getElementById('chatName').value;
        const recipientIds = document.getElementById('recipientIds').value.split(',');
        const submitButton = this.querySelector('button[type="submit"]');
        
        if (recipientIds.length === 0 || recipientIds[0] === '') {
            alert('Debes seleccionar al menos un destinatario');
            isSubmitting = false;
            return;
        }
        
        if (submitButton) submitButton.disabled = true;
        
        createRoom(chatName, recipientIds)
            .then(data => {
                isSubmitting = false;
                if (submitButton) submitButton.disabled = false;
                
                chatModal.style.display = "none";
                loadUserRooms();
            })
            .catch(error => {
                isSubmitting = false;
                if (submitButton) submitButton.disabled = false;
                console.error('Error:', error);
                alert('Ha ocurrido un error al crear el chat.');
            });
    });
    
    function createRoom(name, participantIds) {
        return fetch(`/kc/chat/rooms`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                name,
                participantIds,
                token: storedUserToken
            })
        }).then(response => {
            if (!response.ok) {
                throw new Error('Error al crear la sala');
            }
            return response.json();
        });
    }

    function loadUserRooms() {
        console.log('Cargando salas del usuario...');
        fetch(`/kc/chat/user/rooms?token=${encodeURIComponent(storedUserToken)}&userId=${storedUserId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Error de servidor: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Salas obtenidas:', data);
                const chatList = document.querySelector('.chat-list');
                
                if (!data.success) {
                    chatList.innerHTML = `<div style="padding: 15px;">Error: ${data.error || 'Error desconocido'}</div>`;
                    return;
                }
                
                if (!data.rooms || data.rooms.length === 0) {
                    chatList.innerHTML = '<div style="padding: 15px;">No tienes chats disponibles</div>';
                    return;
                }
                
                chatList.innerHTML = '';
                
                data.rooms.sort((a, b) => {
                    if (a.lastActivity && b.lastActivity) {
                        return new Date(b.lastActivity) - new Date(a.lastActivity);
                    }
                    return new Date(b.createdAt) - new Date(a.createdAt);
                });
                
                data.rooms.forEach(room => {
                    const roomElement = document.createElement('div');
                    roomElement.className = 'chat-list-item';
                    roomElement.setAttribute('data-room-id', room.id);
                    roomElement.innerHTML = `
                        <strong>${room.name}</strong>
                        <div>
                            <small>${room.participants} participante(s)</small>
                        </div>
                    `;
                    
                    roomElement.addEventListener('click', function() {
                        document.querySelectorAll('.chat-list-item').forEach(el => {
                            el.classList.remove('active');
                        });
                        this.classList.add('active');
                        
                        const roomId = this.getAttribute('data-room-id');
                        loadRoom(roomId);
                    });
                    
                    chatList.appendChild(roomElement);
                });
            })
            .catch(error => {
                console.error('Error al cargar las salas del usuario:', error);
                document.querySelector('.chat-list').innerHTML = 
                    `<div style="padding: 15px; color: red;">Error al cargar las salas: ${error.message}</div>`;
            });
    }
            
    function loadRoom(roomId) {
        if (!isAuthenticated) {
            console.warn('Intentando cargar sala sin estar autenticado, reintentando autenticación...');
            socket.emit('authenticate', { 
                userId: storedUserId, 
                userName: storedUserName,
                token: storedUserToken
            });
            setTimeout(() => {
                if (isAuthenticated) loadRoom(roomId);
            }, 1000);
            return;
        }
        
        console.log(`Cargando sala ${roomId}...`);
        
        if (currentRoomId) {
            console.log(`Saliendo de la sala anterior ${currentRoomId}...`);
            socket.emit('leave_room', { roomId: currentRoomId, userId: storedUserId });
        }
        
        currentRoomId = roomId;
        
        // Clear the locally added messages set when changing rooms
        locallyAddedMessages.clear();
        
        console.log(`Uniéndose a la sala ${roomId}...`);
        socket.emit('join_room', { roomId, userId: storedUserId });
        
        fetch(`/kc/chat/rooms/${roomId}?token=${encodeURIComponent(storedUserToken)}&userId=${storedUserId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Error de servidor: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Datos de la sala recibidos:', data);
                
                if (!data.success) {
                    throw new Error(data.error || 'Error desconocido');
                }
                
                const chatMain = document.getElementById('chatMain');
                
                chatMain.style.position = 'relative';
                
                chatMain.innerHTML = `
                    <div class="chat-header">
                        <div class="header-title-area">
                            <h2>${data.room.name}</h2>
                            <button id="voice-call-button" class="voice-call-button" title="Iniciar llamada de voz">
                                <i class="fas fa-phone"></i> Llamar
                            </button>
                        </div>
                        <div class="participants-count">${data.room.participants.length} participantes</div>
                    </div>
                    <div class="chat-messages"></div>
                    <div class="chat-input">
                        <input type="text" id="messageInput" placeholder="Escribe un mensaje...">
                        <button id="sendButton">Enviar</button>
                    </div>
                `;
                
                const typingIndicator = document.createElement('div');
                typingIndicator.className = 'typing-indicator';
                typingIndicator.id = 'typingIndicator';
                typingIndicator.style.display = 'none';
                typingIndicator.textContent = 'Alguien está escribiendo...'; 
                
                const chatInput = chatMain.querySelector('.chat-input');
                chatMain.insertBefore(typingIndicator, chatInput);
                
                const messagesContainer = document.querySelector('.chat-messages');
                
                if (data.messages && data.messages.length > 0) {
                    console.log(`Mostrando ${data.messages.length} mensajes en orden cronológico`);
                    
                    // Keep track of received message IDs to avoid duplicates
                    sentMessageIds.clear();
                    
                    data.messages.forEach(message => {
                        sentMessageIds.add(message.id);
                        
                        addMessageToChatOrdered({
                            id: message.id,
                            senderId: message.senderId,
                            senderName: message.senderName,
                            content: message.content,
                            type: message.type,
                            timestamp: new Date(message.sentAt)
                        }, false);
                    });
                    
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                } else {
                    messagesContainer.innerHTML = '<div class="no-messages">Aún no hay mensajes en este chat.</div>';
                }
                
                const messageInput = document.getElementById('messageInput');
                const sendButton = document.getElementById('sendButton');
                
                sendButton.addEventListener('click', () => {
                    sendMessage();
                });
                
                messageInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        sendMessage();
                    }
                });
                
                let typingTimeout;
                messageInput.addEventListener('input', () => {
                    socket.emit('typing', { roomId, isTyping: true });
                    
                    clearTimeout(typingTimeout);
                    typingTimeout = setTimeout(() => {
                        socket.emit('typing', { roomId, isTyping: false });
                    }, 1000);
                });
                
                const callButton = document.getElementById('voice-call-button');
                if (callButton) {
                    callButton.replaceWith(callButton.cloneNode(true));
                    
                    document.getElementById('voice-call-button').addEventListener('click', () => {
                        if (!voiceCallManager) {
                            voiceCallManager = new VoiceCallManager(socket, storedUserId, storedUserName, websocketUrl);
                        }
                        
                        if (voiceCallManager.isCallActive) {
                            voiceCallManager.showCallUI();
                        } else {
                            voiceCallManager.startCall(roomId);
                        }
                    });
                }
            })
            .catch(error => {
                console.error('Error al cargar los mensajes:', error);
                currentRoomId = null;
                
                const chatMain = document.getElementById('chatMain');
                chatMain.innerHTML = `
                    <div style="display: flex; height: 100%; align-items: center; justify-content: center; flex-direction: column;">
                        <h3>Error al cargar el chat</h3>
                        <p>${error.message}</p>
                        <button id="btnRetry">Reintentar</button>
                    </div>
                `;
                
                document.getElementById('btnRetry').addEventListener('click', () => {
                    loadRoom(roomId);
                });
            });
    }
        
    function sendMessage() {
        if (!isAuthenticated) {
            console.warn('Intentando enviar mensaje sin estar autenticado, reintentando autenticación...');
            socket.emit('authenticate', { 
                userId: storedUserId, 
                userName: storedUserName,
                token: storedUserToken
            });
            return;
        }
        
        const messageInput = document.getElementById('messageInput');
        const content = messageInput.value.trim();
        
        if (!content || !currentRoomId) return;
    
        console.log('Enviando mensaje al WebSocket:', { roomId: currentRoomId, content, type: 'text' });
        
        // Eliminar el mensaje "Aún no hay mensajes" inmediatamente
        const noMessagesEl = document.querySelector('.no-messages');
        if (noMessagesEl) {
            noMessagesEl.remove();
        }
        
        // Add message content to tracking set to avoid duplicates
        locallyAddedMessages.add(content);
        
        // Agregar el mensaje localmente de inmediato para mejor UX
        addMessageToChatOrdered({
            id: 'temp_' + Date.now(),
            senderId: storedUserId,
            senderName: storedUserName,
            content: content,
            type: 'text',
            timestamp: new Date()
        }, true);
        
        socket.emit('send_message', {
            roomId: currentRoomId,
            content,
            type: 'text'
        });
        
        fetch(`/kc/chat/rooms/${currentRoomId}/messages`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                content: content,
                type: 'text',
                senderId: storedUserId,
                senderName: storedUserName,
                token: storedUserToken
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error al enviar el mensaje');
            }
            return response.json();
        })
        .then(data => {
            console.log('Respuesta del servidor Symfony:', data);
            
            // After successful sending, remove the content from the tracking set
            // after a short delay to make sure the socket has had time to process
            setTimeout(() => {
                locallyAddedMessages.delete(content);
            }, 5000);
        })
        .catch(error => {
            console.error('Error al enviar el mensaje a Symfony:', error);
            addSystemMessage('Error al enviar el mensaje. Inténtalo de nuevo.');
            
            // Remove from tracking set on error
            locallyAddedMessages.delete(content);
        });
        
        messageInput.value = '';
    }
        
function addMessageToChat(message, scroll = true) {
    const messagesContainer = document.querySelector('.chat-messages');
    if (!messagesContainer) return;
    
    // Check if the message already exists in the DOM
    if (message.id && document.querySelector(`.message[data-id="${message.id}"]`)) {
        console.log(`Mensaje con ID ${message.id} ya existe en el DOM, ignorando duplicado`);
        return;
    }
    
    const messageDiv = document.createElement('div');
    
    // Add data attributes to help identify the message
    if (message.id) {
        messageDiv.setAttribute('data-id', message.id);
    }
    
    if (message.type === 'system') {
        messageDiv.className = 'message message-system';
        messageDiv.textContent = message.content;
    } else {
        if (message.senderName == "Anonymous User") {
            return;
        }
        
        const isSentByMe = message.senderId === storedUserId.toString();
        messageDiv.className = `message ${isSentByMe ? 'message-sent' : 'message-received'}`;
        
        let timestamp = message.timestamp;
        if (typeof timestamp === 'string') {
            timestamp = new Date(timestamp);
        }
        
        const formattedTime = timestamp instanceof Date 
            ? timestamp.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) 
            : 'ahora';
        
        messageDiv.innerHTML = `
            <div class="message-header">${message.senderName}</div>
            <div class="message-content">${message.content}</div>
            <div class="message-timestamp">${formattedTime}</div>
        `;
    }
    
    messagesContainer.appendChild(messageDiv);
    
    if (scroll) {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
}


function addMessageToChatOrdered(message, scroll = true) {
    const messagesContainer = document.querySelector('.chat-messages');
    if (!messagesContainer) return;
    
    // Eliminar el mensaje "Aún no hay mensajes" si existe
    const noMessagesEl = document.querySelector('.no-messages');
    if (noMessagesEl) {
        noMessagesEl.remove();
    }
    
    // Check if message already exists with the same ID
    const existingMessage = message.id ? document.querySelector(`.message[data-id="${message.id}"]`) : null;
    if (existingMessage) {
        console.log(`Mensaje con ID ${message.id} ya existe, ignorando duplicado`);
        return;
    }
    
    if (!message.content) {
        console.warn('Intentando mostrar mensaje sin contenido', message);
        return;
    }
    
    const messageDiv = document.createElement('div');
    
    messageDiv.setAttribute('data-id', message.id || `temp_${Date.now()}`);
    messageDiv.setAttribute('data-sender', message.senderId || 'unknown');
    messageDiv.setAttribute('data-time', message.timestamp?.getTime() || Date.now());
    
    if (message.type === 'system') {
        messageDiv.className = 'message message-system';
        messageDiv.textContent = message.content;
    } else {
        if (message.senderName === "Anonymous User" || !message.senderName) {
            message.senderName = "Usuario " + (message.senderId || "Desconocido");
        }
        
        const isSentByMe = message.senderId === storedUserId.toString();
        messageDiv.className = `message ${isSentByMe ? 'message-sent' : 'message-received'}`;
        
        let timestamp = message.timestamp;
        if (typeof timestamp === 'string') {
            timestamp = new Date(timestamp);
        }
        
        if (!timestamp || isNaN(timestamp)) {
            timestamp = new Date();
        }
        
        const formattedTime = timestamp instanceof Date 
            ? timestamp.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})
            : 'ahora';
        
        messageDiv.innerHTML = `
            <div class="message-header"><strong>${message.senderName}</strong></div>
            <div class="message-content">${message.content}</div>
            <div class="message-timestamp"><small>${formattedTime}</small></div>
        `;
    }
    
    messagesContainer.appendChild(messageDiv);
    
    if (scroll) {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
}
        
    function updateTypingIndicator(data) {
        console.log('Actualizando indicador de escritura:', data);
        
        let typingIndicator = document.getElementById('typingIndicator');
        
        if (!typingIndicator) {
            console.warn('No se encontró el indicador de escritura, intentando crear uno nuevo');
            
            const chatMain = document.getElementById('chatMain');
            if (!chatMain) {
                console.error('No se encontró el contenedor del chat');
                return;
            }
            
            typingIndicator = document.createElement('div');
            typingIndicator.className = 'typing-indicator';
            typingIndicator.id = 'typingIndicator';
            typingIndicator.style.display = 'none';
            
            const chatInput = chatMain.querySelector('.chat-input');
            if (chatInput) {
                chatMain.insertBefore(typingIndicator, chatInput);
            } else {
                chatMain.appendChild(typingIndicator);
            }
            
            chatMain.style.position = 'relative';
        }
        
        if (data.isTyping) {
            typingIndicator.textContent = `${data.userName} está escribiendo...`;
            typingIndicator.style.display = 'block';
            
            typingIndicator.style.opacity = '1';
            typingIndicator.style.visibility = 'visible';
            
            console.log('Mostrando indicador:', typingIndicator.textContent);
        } else {
            console.log('Ocultando indicador de escritura');
            
            setTimeout(() => {
                if (typingIndicator) {
                    typingIndicator.style.display = 'none';
                }
            }, 500);
        }
    }

    function addSystemMessage(content) {
        const messagesContainer = document.querySelector('.chat-messages');
        if (!messagesContainer) return;
        
        const messageDiv = document.createElement('div');
        messageDiv.className = 'message message-system';
        messageDiv.textContent = content;
        
        messagesContainer.appendChild(messageDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

class VoiceCallManager {
    constructor(socket, userId, userName, websocketUrl) {
        this.socket = socket;
        this.userId = userId;
        this.userName = userName;
        this.websocketUrl = websocketUrl;
        this.userToken = localStorage.getItem('exnet_chat_user_token');
        
        this.isCallActive = false;
        this.activeRoom = null;
        this.isMuted = false;
        this.audioContext = null;
        this.mediaStream = null;
        this.audioProcessor = null;
        this.audioPlayers = {};
        
        this.initSocketEvents();
        
        this.modal = document.getElementById('voice-call-modal');
        this.callStatus = document.getElementById('call-status');
        this.muteToggle = document.getElementById('mute-toggle');
        this.endCallButton = document.getElementById('end-call');
        this.remoteParticipantsContainer = document.getElementById('remote-participants');
        this.localParticipant = document.getElementById('local-participant');

        this.initUIEvents();
    }
    
    initSocketEvents() {
        this.socket.on('voice_call_invite', (data) => {
            console.log('Invitación a llamada recibida:', data);
            this.showIncomingCallUI(data.roomId, data.callerId, data.callerName);
        });
        
        this.socket.on('voice_call_joined', (data) => {
            console.log('Usuario se unió a la llamada:', data);
            if (data.userId !== this.userId.toString()) {
                this.addParticipantElement(data.userId, data.userName);
                this.updateCallStatus(`Llamada en curso - ${Object.keys(this.audioPlayers).length + 1} participantes`);
            }
        });
        
        this.socket.on('voice_call_left', (data) => {
            console.log('Usuario abandonó la llamada:', data);
            this.removeParticipantElement(data.userId);
        });
        
        this.socket.on('voice_call_end', (data) => {
            console.log('La llamada ha finalizado:', data);
            this.endCall(false);
        });
        
        this.socket.on('voice_audio_chunk', (data) => {
            if (this.isCallActive && data.roomId === this.activeRoom && data.userId !== this.userId.toString()) {
                this.playAudioChunk(data.userId, data.userName, data.audioChunk);
            }
        });
    }
    
    initUIEvents() {
        const closeButton = document.querySelector('.voice-call-close');
        if (closeButton) {
            closeButton.addEventListener('click', () => {
                if (confirm('¿Seguro que deseas salir de la llamada?')) {
                    this.endCall(true);
                }
            });
        }
        
        if (this.muteToggle) {
            this.muteToggle.addEventListener('click', () => {
                this.toggleMute();
            });
        }
        
        if (this.endCallButton) {
            this.endCallButton.addEventListener('click', () => {
                this.endCall(true);
            });
        }
        
        window.addEventListener('beforeunload', (e) => {
            if (this.isCallActive) {
                this.endCall(true);
            }
        });
    }
    
    async startCall(roomId) {
        try {
            console.log('Iniciando llamada en sala:', roomId);
            
            await this.requestMediaPermissions();
            
            const response = await fetch(`/kc/chat/voice/call/${roomId}`, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json' 
                },
                body: JSON.stringify({
                    token: this.userToken,
                    userId: this.userId
                })
            });
            
            const contentType = response.headers.get("content-type");
            if (!contentType || !contentType.includes("application/json")) {
                console.error("La respuesta no es JSON:", await response.text());
                throw new Error("Respuesta inesperada del servidor. Verifica la consola para más detalles.");
            }
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Error al iniciar la llamada');
            }
            
            this.activeRoom = roomId;
            this.isCallActive = true;
            
            this.showCallUI();
            
            this.socket.emit('voice_call_start', {
                roomId: roomId,
                userId: this.userId,
                userName: this.userName,
                token: this.userToken
            });
            
            this.socket.emit('voice_call_invite_all', {
                roomId: roomId,
                callerId: this.userId,
                callerName: this.userName
            });
            
            this.startAudioTransmission(roomId);
            
            return true;
        } catch (error) {
            console.error('Error al iniciar la llamada:', error);
            this.stopMediaStream();
            return false;
        }
    }
    
    async requestMediaPermissions() {
        try {
            this.mediaStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: false
            });
            
            console.log('Permisos de audio concedidos');
            return true;
        } catch (error) {
            console.error('Error al solicitar permisos de audio:', error);
            
            if (error.name === 'NotAllowedError') {
                alert('Para usar las llamadas de voz, debes permitir el acceso al micrófono.');
            } else {
                alert('Error al acceder al micrófono: ' + error.message);
            }
            
            throw error;
        }
    }
    
    startAudioTransmission(roomId) {
        try {
            this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const source = this.audioContext.createMediaStreamSource(this.mediaStream);
            
            this.audioProcessor = this.audioContext.createScriptProcessor(4096, 1, 1);
            
            this.audioProcessor.onaudioprocess = (e) => {
                if (this.isCallActive && !this.isMuted) {
                    const inputBuffer = e.inputBuffer;
                    const samples = inputBuffer.getChannelData(0);
                    
                    const sampleArray = Array.from(samples);
                    const base64Data = btoa(String.fromCharCode.apply(null, 
                        new Uint8Array(new Float32Array(sampleArray).buffer)
                    ));
                    
                    this.socket.emit('voice_audio_chunk', {
                        roomId: roomId,
                        userId: this.userId,
                        userName: this.userName,
                        audioChunk: base64Data,
                        token: this.userToken
                    });
                }
            };
            
            source.connect(this.audioProcessor);
            this.audioProcessor.connect(this.audioContext.destination);
            
            console.log('Transmisión de audio iniciada');
        } catch (error) {
            console.error('Error al iniciar la transmisión de audio:', error);
        }
    }
    
    playAudioChunk(senderId, senderName, audioChunkBase64) {
        try {
            if (senderId === this.userId.toString()) {
                console.log("Ignorando chunk de audio del usuario local");
                return;
            }
            
            if (!this.audioContext) {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }
            
            const binaryString = atob(audioChunkBase64);
            const len = binaryString.length;
            const bytes = new Uint8Array(len);
            
            for (let i = 0; i < len; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }
            
            const audioData = new Float32Array(bytes.buffer);
            
            const buffer = this.audioContext.createBuffer(1, audioData.length, this.audioContext.sampleRate);
            buffer.getChannelData(0).set(audioData);
            
            const source = this.audioContext.createBufferSource();
            source.buffer = buffer;
            
            if (!this.audioPlayers[senderId]) {
                this.audioPlayers[senderId] = {
                    name: senderName,
                    sources: []
                };
                
                this.addParticipantElement(senderId, senderName);
            }
            
            this.audioPlayers[senderId].sources.push(source);
            
            source.connect(this.audioContext.destination);
            source.start(0);
            
            source.onended = () => {
                const index = this.audioPlayers[senderId].sources.indexOf(source);
                if (index !== -1) {
                    this.audioPlayers[senderId].sources.splice(index, 1);
                }
            };
            
            this.animateAudioIndicator(senderId);
        } catch (error) {
            console.error('Error al reproducir audio:', error);
        }
    }
    
    animateAudioIndicator(participantId) {
        const userId = this.userId.toString();
        const particId = participantId.toString();
        
        if (particId === userId && this.localParticipant) {
            const audioWaves = this.localParticipant.querySelectorAll('.audio-wave');
            
            audioWaves.forEach(wave => {
                wave.classList.add('active');
                setTimeout(() => {
                    wave.classList.remove('active');
                }, 300);
            });
            return;
        }
        
        const participantElement = document.getElementById(`participant-${participantId}`);
        if (participantElement) {
            const audioWaves = participantElement.querySelectorAll('.audio-wave');
            
            audioWaves.forEach(wave => {
                wave.classList.add('active');
                setTimeout(() => {
                    wave.classList.remove('active');
                }, 300);
            });
        }
    }
    
    showIncomingCallUI(roomId, callerId, callerName) {
        if (this.isCallActive && this.activeRoom) {
            console.log('Ya hay una llamada activa. No mostrando diálogo de llamada entrante.');
            return;
        }
        
        const callResponseKey = `call_response_${roomId}_${callerId}`;
        if (sessionStorage.getItem(callResponseKey)) {
            console.log('Ya respondimos a esta llamada anteriormente.');
            return;
        }
        
        if (confirm(`Llamada entrante de ${callerName}. ¿Deseas aceptar?`)) {
            sessionStorage.setItem(callResponseKey, 'accepted');
            this.acceptCall(roomId, callerId, callerName);
        } else {
            sessionStorage.setItem(callResponseKey, 'rejected');
            this.rejectCall(roomId, callerId);
        }
    }
    
    async acceptCall(roomId, callerId, callerName) {
        try {
            if (this.isCallActive) {
                console.log('Ya estamos en una llamada activa. No aceptando nueva llamada.');
                return false;
            }
            
            await this.requestMediaPermissions();
            
            this.activeRoom = roomId;
            this.isCallActive = true;
            
            this.showCallUI();
            
            this.socket.emit('voice_call_accept', {
                roomId: roomId,
                callerId: callerId,
                userId: this.userId,
                userName: this.userName,
                token: this.userToken
            });
            
            this.startAudioTransmission(roomId);
            
            return true;
        } catch (error) {
            console.error('Error al aceptar la llamada:', error);
            this.stopMediaStream();
            return false;
        }
    }
    
    rejectCall(roomId, callerId) {
        this.socket.emit('voice_call_reject', {
            roomId: roomId,
            callerId: callerId,
            userId: this.userId,
            userName: this.userName,
            token: this.userToken
        });
    }
    
    showCallUI() {
        if (this.modal) {
            this.modal.style.display = 'block';
            this.updateCallStatus('Llamada en curso');
        }
        
        this.ensureLocalUserElement();
        
        this.addActiveCallIndicator();
    }
    
    ensureLocalUserElement() {
        if (this.localParticipant) {
            this.localParticipant.style.display = 'flex';
            
            const nameElement = this.localParticipant.querySelector('.participant-name');
            if (nameElement && nameElement.textContent !== 'Tú') {
                nameElement.textContent = 'Tú';
            }
            
            return;
        }
        
        const userSpecificElement = document.getElementById(`participant-${this.userId}`);
        if (userSpecificElement) {
            const nameElement = userSpecificElement.querySelector('.participant-name');
            if (nameElement && nameElement.textContent !== 'Tú') {
                nameElement.textContent = 'Tú';
            }
            
            return;
        }
        
        if (this.remoteParticipantsContainer) {
            const selfDiv = document.createElement('div');
            selfDiv.id = `participant-${this.userId}`;
            selfDiv.className = 'participant';
            selfDiv.innerHTML = `
                <div class="audio-indicator">
                    <div class="audio-wave"></div>
                    <div class="audio-wave"></div>
                    <div class="audio-wave"></div>
                </div>
                <div class="participant-name">Tú</div>
            `;
            
            this.remoteParticipantsContainer.appendChild(selfDiv);
        }
    }
    
    addActiveCallIndicator() {
        const chatHeader = document.querySelector('.chat-header');
        if (chatHeader && !document.querySelector('.active-call-indicator')) {
            const indicator = document.createElement('span');
            indicator.className = 'active-call-indicator';
            indicator.innerHTML = '<i class="fas fa-phone"></i> Llamada activa';
            chatHeader.appendChild(indicator);
        }
    }
    
    removeActiveCallIndicator() {
        const indicator = document.querySelector('.active-call-indicator');
        if (indicator) {
            indicator.remove();
        }
    }
    
    updateCallStatus(status) {
        if (this.callStatus) {
            this.callStatus.textContent = status;
        }
    }
    
    addParticipantElement(participantId, participantName) {
        const userId = this.userId.toString();
        const particId = participantId.toString();
        
        if (particId === userId) {
            this.ensureLocalUserElement();
            return;
        }
        
        if (document.getElementById(`participant-${participantId}`)) {
            return;
        }
        
        if (this.remoteParticipantsContainer) {
            const participantDiv = document.createElement('div');
            participantDiv.id = `participant-${participantId}`;
            participantDiv.className = 'participant';
            participantDiv.innerHTML = `
                <div class="audio-indicator">
                    <div class="audio-wave"></div>
                    <div class="audio-wave"></div>
                    <div class="audio-wave"></div>
                </div>
                <div class="participant-name">${participantName}</div>
            `;
            
            this.remoteParticipantsContainer.appendChild(participantDiv);
        }
    }
    
    removeParticipantElement(participantId) {
        const participantElement = document.getElementById(`participant-${participantId}`);
        if (participantElement) {
            participantElement.remove();
        }
        
        if (this.audioPlayers[participantId]) {
            delete this.audioPlayers[participantId];
        }
    }
    
    toggleMute() {
        this.isMuted = !this.isMuted;
        
        if (this.muteToggle) {
            if (this.isMuted) {
                this.muteToggle.innerHTML = '<i class="fas fa-microphone-slash"></i>';
                this.muteToggle.classList.add('muted');
            } else {
                this.muteToggle.innerHTML = '<i class="fas fa-microphone"></i>';
                this.muteToggle.classList.remove('muted');
            }
        }
    }
    
    endCall(notifyServer = true) {
        if (!this.isCallActive) return;
        
        this.stopAudioProcessing();
        this.stopMediaStream();
        
        if (notifyServer) {
            try {
                fetch(`/kc/chat/voice/call/${this.activeRoom}/end`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        userId: this.userId,
                        userName: this.userName,
                        token: this.userToken
                    })
                })
                .then(response => response.json())
                .catch(error => {
                    console.error('Error al notificar el fin de la llamada:', error);
                });
                
                this.socket.emit('voice_call_end', {
                    roomId: this.activeRoom,
                    userId: this.userId,
                    userName: this.userName,
                    token: this.userToken
                });
            } catch (error) {
                console.error('Error al notificar el fin de la llamada:', error);
            }
        }
        
        this.audioPlayers = {};
        
        const oldRoomId = this.activeRoom;
        this.activeRoom = null;
        this.isCallActive = false;
        
        if (oldRoomId) {
            for (let i = 0; i < sessionStorage.length; i++) {
                const key = sessionStorage.key(i);
                if (key && key.startsWith(`call_response_${oldRoomId}`)) {
                    sessionStorage.removeItem(key);
                }
            }
        }
        
        this.hideCallUI();
        
        return true;
    }
    
    stopAudioProcessing() {
        if (this.audioProcessor) {
            this.audioProcessor.disconnect();
            this.audioProcessor = null;
        }
        
        if (this.audioContext) {
            this.audioContext.close().catch(error => {
                console.error('Error al cerrar el contexto de audio:', error);
            });
            this.audioContext = null;
        }
    }
    
    stopMediaStream() {
        if (this.mediaStream) {
            this.mediaStream.getTracks().forEach(track => {
                track.stop();
            });
            this.mediaStream = null;
        }
    }
    
    hideCallUI() {
        if (this.modal) {
            this.modal.style.display = 'none';
        }
        
        if (this.remoteParticipantsContainer) {
            this.remoteParticipantsContainer.innerHTML = '';
        }
        
        if (this.localParticipant) {
            const nameElement = this.localParticipant.querySelector('.participant-name');
            if (nameElement) {
                nameElement.textContent = 'Tú';
            }
        }
        
        this.removeActiveCallIndicator();
    }
}
});