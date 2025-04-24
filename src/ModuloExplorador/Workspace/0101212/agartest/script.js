// --- Configuración Inicial ---
const canvas = document.getElementById('gameCanvas');
const ctx = canvas.getContext('2d');

// Ajustar tamaño del canvas (puedes hacerlo fijo o dinámico)
// Usaremos un tamaño fijo para este ejemplo
canvas.width = 1000; // Un poco más grande
canvas.height = 800; // Un poco más grande

// --- Estado del Juego ---
let mouseX = canvas.width / 2;
let mouseY = canvas.height / 2;

// ***** ESTRUCTURA DEL JUGADOR: Array de Células *****
let playerCells = [];
const MAX_PLAYER_CELLS = 16; // Límite de células divididas
const MIN_RADIUS_TO_SPLIT = 25; // Radio mínimo para poder dividirse
const SPLIT_EJECTION_SPEED = 15; // Velocidad a la que salen las células al dividirse
const MERGE_COOLDOWN = 10000; // 10 segundos antes de que las células puedan empezar a fusionarse (reducido un poco)
const MERGE_SPEED_FACTOR = 0.8; // Qué tan rápido se atraen las células para fusionarse

// Pellets (Comida pequeña)
let pellets = [];
const PELLET_COUNT = 150; // Aumentamos más
const PELLET_RADIUS = 6;

// Viruses (Arbustos Verdes)
let viruses = [];
const VIRUS_COUNT = 10; // Aumentamos un poco
const VIRUS_RADIUS = 60; // Un poco más grandes
const VIRUS_COLOR = 'green';
const VIRUS_MASS_PENALTY_FACTOR = 0.6; // Factor de reducción de radio al chocar
const EJECTED_MASS_RADIUS = 9; // Ligeramente más grande
const EJECTED_MASS_COST = EJECTED_MASS_RADIUS**2; // Coste de masa para expulsar/alimentar
const VIRUS_SPLIT_THRESHOLD = 7 * EJECTED_MASS_COST; // Masa necesaria para dividir un virus
const VIRUS_SHOOT_SPEED = 15; // Velocidad a la que se dispara el nuevo virus
const VIRUS_MAX_COUNT = 20; // Límite de virus en pantalla

// Masa Expulsada ('W')
let ejectedMasses = [];
const EJECTED_MASS_SPEED = 12; // Un poco más rápida
const PLAYER_MIN_RADIUS_TO_EJECT = EJECTED_MASS_RADIUS * 1.5; // Radio mínimo para poder expulsar
const EAT_OWN_MASS_DELAY = 500; // Retraso para comer masa propia (aún no implementado por célula)


// --- Funciones Auxiliares ---

function drawCircle(x, y, radius, color) {
    ctx.beginPath();
    ctx.arc(x, y, radius, 0, Math.PI * 2); // Dibuja un círculo completo
    ctx.fillStyle = color;
    ctx.fill();
    ctx.closePath();
}

// Dibuja Virus (con indicación visual de masa recibida)
function drawVirus(virus) {
    // Dibuja el círculo base
    drawCircle(virus.x, virus.y, virus.radius, virus.color);

    // Dibuja un círculo interior para indicar cuánta masa ha recibido
    if (virus.massReceived > 0) {
        // El radio interior crece proporcionalmente a la masa recibida
        const innerRadiusFactor = Math.min(1, virus.massReceived / VIRUS_SPLIT_THRESHOLD);
        const innerRadius = virus.radius * innerRadiusFactor;
        ctx.beginPath();
        ctx.arc(virus.x, virus.y, innerRadius, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(0, 0, 0, 0.3)'; // Negro semitransparente
        ctx.fill();
        ctx.closePath();
    }
    // TODO: Añadir efecto de picos si se desea
}

function getRandomColor() {
    const letters = '0123456789ABCDEF';
    let color = '#';
    for (let i = 0; i < 6; i++) {
        color += letters[Math.floor(Math.random() * 16)];
    }
    return color;
}

function getDistance(x1, y1, x2, y2) {
    let dx = x2 - x1;
    let dy = y2 - y1;
    return Math.sqrt(dx * dx + dy * dy);
}

// --- Funciones de Creación de Elementos ---

function createPellets() {
    pellets = [];
    for (let i = 0; i < PELLET_COUNT; i++) {
        pellets.push({
            x: Math.random() * canvas.width,
            y: Math.random() * canvas.height,
            radius: PELLET_RADIUS,
            color: getRandomColor()
        });
    }
}

// Añade un nuevo virus (controlando el límite)
function addNewVirus(x, y, initialVelocityX = 0, initialVelocityY = 0) {
     if (viruses.length >= VIRUS_MAX_COUNT) return; // No añadir si ya hay demasiados

    viruses.push({
        x: x,
        y: y,
        radius: VIRUS_RADIUS,
        color: VIRUS_COLOR,
        massReceived: 0, // Masa acumulada para dividirse
        velocityX: initialVelocityX, // Para el disparo inicial
        velocityY: initialVelocityY  // Para el disparo inicial
    });
}

function createViruses() {
    viruses = [];
    for (let i = 0; i < VIRUS_COUNT; i++) {
        addNewVirus(Math.random() * canvas.width, Math.random() * canvas.height);
    }
}

// Inicializa la(s) célula(s) del jugador
function initializePlayer() {
    playerCells = []; // Limpiar array
    playerCells.push({
        id: Date.now(), // ID único para esta célula
        x: canvas.width / 2,
        y: canvas.height / 2,
        radius: 30, // Radio inicial
        color: 'blue',
        velocityX: 0, // Velocidad residual (para split/merge)
        velocityY: 0,
        canMergeTime: Date.now() + MERGE_COOLDOWN // Cuándo puede empezar a fusionarse
    });
}


// --- Manejo de Eventos (Input) ---

canvas.addEventListener('mousemove', (event) => {
    const rect = canvas.getBoundingClientRect(); // Obtiene pos y tamaño del canvas relativo al viewport
    mouseX = event.clientX - rect.left;
    mouseY = event.clientY - rect.top;
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'w' || event.key === 'W') {
        ejectMass();
    } else if (event.code === 'Space') { // Usar 'code' es más fiable para teclas no imprimibles
         event.preventDefault(); // Previene acciones por defecto (como scroll)
        splitPlayerCells();
    }
});


// --- Lógica de Acciones del Jugador ---

// Expulsar masa ('W')
function ejectMass() {
    // Expulsar desde cada célula que pueda (hasta un límite por W?)
    let ejectedCount = 0;
    const maxEjectPerPress = 2; // Limitar cuántas masas salen por pulsación

    playerCells.forEach(cell => {
        if (ejectedCount >= maxEjectPerPress) return;

        const currentArea = cell.radius**2;
        // Asegurarse de que tenga suficiente masa para expulsar
        if (currentArea > EJECTED_MASS_COST * 1.2) { // Necesita un poco más de masa que la que va a expulsar

             // Reducir radio de la célula actual
            cell.radius = Math.sqrt(currentArea - EJECTED_MASS_COST);

            // Calcular dirección hacia el ratón desde esta célula
            let dx = mouseX - cell.x;
            let dy = mouseY - cell.y;
            let distance = Math.sqrt(dx * dx + dy * dy);
            // Evitar división por cero y asegurar dirección si está quieto
            let directionX = (distance > 0) ? dx / distance : 1;
            let directionY = (distance > 0) ? dy / distance : 0;

            // Calcular posición inicial de la masa expulsada (justo fuera de la célula)
            let ejectX = cell.x + directionX * (cell.radius + EJECTED_MASS_RADIUS + 2);
            let ejectY = cell.y + directionY * (cell.radius + EJECTED_MASS_RADIUS + 2);

            // Crear la masa expulsada
            ejectedMasses.push({
                x: ejectX,
                y: ejectY,
                radius: EJECTED_MASS_RADIUS,
                color: cell.color, // Mismo color que la célula
                velocityX: directionX * EJECTED_MASS_SPEED,
                velocityY: directionY * EJECTED_MASS_SPEED,
                creationTime: Date.now()
                // TODO: Añadir 'ownerCellId: cell.id' si queremos implementar bien EAT_OWN_MASS_DELAY
            });
            ejectedCount++;
            // TODO: Implementar canEatOwnMassTime por célula
            // cell.canEatOwnMassTime = Date.now() + EAT_OWN_MASS_DELAY;
        }
    });
}

// Dividir células del jugador ('Space')
function splitPlayerCells() {
    const currentTime = Date.now();
    let cellsToSplit = []; // Almacena las células que se van a dividir en esta pulsación

    // Itera sobre una copia para evitar problemas al modificar el array original
    const currentCells = [...playerCells];

    for (const cell of currentCells) {
        // Condiciones: Aún no hemos alcanzado el límite Y la célula es suficientemente grande
        if (playerCells.length < MAX_PLAYER_CELLS && cell.radius >= MIN_RADIUS_TO_SPLIT) {
             cellsToSplit.push(cell);
        }
        // Detener si ya hemos seleccionado suficientes para alcanzar el límite
        if (playerCells.length + cellsToSplit.length >= MAX_PLAYER_CELLS) break;
    }

    // Ahora procesa la división para las células seleccionadas
    for (const cell of cellsToSplit) {
        if (playerCells.length >= MAX_PLAYER_CELLS) break; // Doble chequeo por seguridad

        // Dividir el área (masa) por la mitad
        const currentArea = cell.radius**2;
        const newArea = currentArea / 2;
        const newRadius = Math.sqrt(newArea);

        // Actualizar la célula original
        cell.radius = newRadius;
        cell.canMergeTime = currentTime + MERGE_COOLDOWN; // Reiniciar temporizador de fusión

        // Calcular dirección de eyección (hacia el cursor)
        let dx = mouseX - cell.x;
        let dy = mouseY - cell.y;
        let distance = Math.sqrt(dx * dx + dy * dy);
        let directionX = (distance > 0) ? dx / distance : 1;
        let directionY = (distance > 0) ? dy / distance : 0;

        // Crear la nueva célula
        const newCell = {
            id: Date.now() + Math.random(), // ID único
            x: cell.x, // Inicia en la misma posición
            y: cell.y,
            radius: newRadius,
            color: cell.color,
            // Asignar velocidad para "dispararla"
            velocityX: directionX * SPLIT_EJECTION_SPEED,
            velocityY: directionY * SPLIT_EJECTION_SPEED,
            canMergeTime: currentTime + MERGE_COOLDOWN // También con cooldown
        };

        // Aplicar velocidad opuesta a la célula original para separarlas
        cell.velocityX = -directionX * SPLIT_EJECTION_SPEED;
        cell.velocityY = -directionY * SPLIT_EJECTION_SPEED;

        playerCells.push(newCell); // Añadir la nueva célula al juego
        console.log("Split! Células:", playerCells.length);
    }
}

// --- Funciones de Actualización de Estado (Movimiento, etc.) ---

// Actualiza la posición y velocidad de TODAS las células del jugador
function updatePlayerCells() {
    playerCells.forEach(cell => {
        // 1. Mover hacia el ratón
        let dx = mouseX - cell.x;
        let dy = mouseY - cell.y;
        let distance = getDistance(cell.x, cell.y, mouseX, mouseY);

        // Calcular velocidad basada en tamaño (más grande = más lento)
        const baseSpeed = 4;
        // Ajuste logarítmico para que la velocidad no caiga tan bruscamente
        let speed = baseSpeed * Math.log10(30) / Math.log10(cell.radius + 20);
        speed = Math.max(0.8, speed); // Velocidad mínima

        // Mover solo si no está ya muy cerca del cursor
        if (distance > speed) { // Mover como máximo la distancia de la velocidad calculada
             cell.x += (dx / distance) * speed;
             cell.y += (dy / distance) * speed;
        } else if (distance > 1) { // Si está muy cerca pero no encima, moverse exacto
             cell.x = mouseX;
             cell.y = mouseY;
        }


         // 2. Aplicar velocidad residual (de split/merge) y fricción
        if (cell.velocityX || cell.velocityY) {
            cell.x += cell.velocityX;
            cell.y += cell.velocityY;
            // Fricción para detener el movimiento residual
            cell.velocityX *= 0.88; // Ajustar este valor para controlar cuánto dura el impulso
            cell.velocityY *= 0.88;
            // Detener si es muy lento
            if (Math.abs(cell.velocityX) < 0.1 && Math.abs(cell.velocityY) < 0.1) {
                cell.velocityX = 0;
                cell.velocityY = 0;
            }
        }

        // 3. Mantener dentro de los límites del canvas
        cell.x = Math.max(cell.radius, Math.min(canvas.width - cell.radius, cell.x));
        cell.y = Math.max(cell.radius, Math.min(canvas.height - cell.radius, cell.y));
    });
}

// Actualiza la posición y estado de la masa expulsada
function updateEjectedMass() {
    for (let i = ejectedMasses.length - 1; i >= 0; i--) {
        const mass = ejectedMasses[i];
        mass.x += mass.velocityX;
        mass.y += mass.velocityY;

        // Fricción
        mass.velocityX *= 0.96; // Menos fricción que las células
        mass.velocityY *= 0.96;

        // Eliminar si es muy lenta (se detuvo)
         if (Math.abs(mass.velocityX) < 0.1 && Math.abs(mass.velocityY) < 0.1) {
              // Podríamos simplemente detenerla (velocityX=0, velocityY=0) para que se quede quieta
              ejectedMasses.splice(i, 1); // O eliminarla
              continue; // Saltar al siguiente ciclo
         }

        // Eliminar si sale de los límites del canvas
        if (mass.x < -mass.radius || mass.x > canvas.width + mass.radius || mass.y < -mass.radius || mass.y > canvas.height + mass.radius) {
            ejectedMasses.splice(i, 1);
        }
    }
}

// Actualiza estado de los virus (movimiento post-disparo)
function updateViruses() {
    viruses.forEach(virus => {
        // Solo mover si tiene velocidad (recién disparado)
        if (virus.velocityX || virus.velocityY) {
            virus.x += virus.velocityX;
            virus.y += virus.velocityY;

            // Fricción para que el virus disparado se detenga
            virus.velocityX *= 0.94; // Ajustar para controlar la distancia de disparo
            virus.velocityY *= 0.94;

            // Detener completamente si es muy lento
            if (Math.abs(virus.velocityX) < 0.1 && Math.abs(virus.velocityY) < 0.1) {
                virus.velocityX = 0;
                virus.velocityY = 0;
            }

            // Asegurar que se quede dentro del canvas mientras se mueve
            virus.x = Math.max(virus.radius, Math.min(canvas.width - virus.radius, virus.x));
            virus.y = Math.max(virus.radius, Math.min(canvas.height - virus.radius, virus.y));
        }
    });
}

// --- Lógica de Fusión y Colisiones ---

// Maneja la fusión entre células del mismo jugador
function handleMerging() {
    const currentTime = Date.now();

    // Compara cada par de células una sola vez (j = i + 1)
    for (let i = 0; i < playerCells.length; i++) {
        for (let j = i + 1; j < playerCells.length; j++) {

            // Asegurarse de que los índices siguen siendo válidos (por si se eliminó algo)
            if (!playerCells[i] || !playerCells[j]) continue;

            const cell1 = playerCells[i];
            const cell2 = playerCells[j];

            // Saltar si alguna de las dos no está lista para fusionarse
            if (currentTime < cell1.canMergeTime || currentTime < cell2.canMergeTime) {
                continue;
            }

            const distance = getDistance(cell1.x, cell1.y, cell2.x, cell2.y);
            const radiiSum = cell1.radius + cell2.radius;

            // Si están lo suficientemente cerca para interactuar
            if (distance < radiiSum) {

                 // Condición de fusión: cuando una está significativamente "dentro" de la otra
                 // Usamos el radio de la más grande como referencia
                 const mergeTriggerDistance = Math.max(cell1.radius, cell2.radius) * 0.8;

                 if (distance < mergeTriggerDistance) {
                     // ¡Fusión! La célula más grande absorbe a la más pequeña
                    let absorber, absorbed, absorbedIndex;
                    if (cell1.radius >= cell2.radius) {
                        absorber = cell1;
                        absorbed = cell2;
                        absorbedIndex = j;
                    } else {
                        absorber = cell2;
                        absorbed = cell1;
                        absorbedIndex = i; // Si cell2 absorbe, eliminaremos cell1 en el índice i
                    }

                    console.log(`¡Fusión! Célula ${absorber.id} absorbe a ${absorbed.id}`);

                    // Calcular nueva área/radio para la célula absorbente
                    const combinedArea = absorber.radius**2 + absorbed.radius**2;
                    absorber.radius = Math.sqrt(combinedArea);

                    // Eliminar la célula absorbida del array
                    // ¡Importante eliminar por índice y ajustar el bucle!
                    if (absorbedIndex === i) {
                        playerCells.splice(i, 1);
                        i--; // Ajustar índice i ya que el array cambió y perdimos un elemento en esta posición
                        break; // Salir del bucle interno (j) y re-evaluar desde la nueva 'i'
                    } else { // absorbedIndex === j
                        playerCells.splice(j, 1);
                        j--; // Ajustar índice j
                    }

                    // Opcional: Reiniciar cooldown de la célula absorbente
                    absorber.canMergeTime = currentTime + 500;

                 } else {
                     // Atracción suave si están cerca pero no fusionándose aún
                     const overlap = radiiSum - distance;
                     const moveFactor = overlap * 0.05 * MERGE_SPEED_FACTOR; // Pequeño factor de atracción

                     if (moveFactor > 0) {
                         const dx = cell2.x - cell1.x;
                         const dy = cell2.y - cell1.y;
                         const directionX = dx / distance;
                         const directionY = dy / distance;

                         // Moverlas una hacia la otra (podría causar 'temblores' si no se hace bien)
                         // Aplicamos la mitad del movimiento a cada una para ser más estable
                         cell1.x += directionX * moveFactor * 0.5;
                         cell1.y += directionY * moveFactor * 0.5;
                         cell2.x -= directionX * moveFactor * 0.5;
                         cell2.y -= directionY * moveFactor * 0.5;
                     }
                 }
            }
        }
    }
}


// Comprueba todas las colisiones relevantes
function checkCollisions() {
    const currentTime = Date.now();

    // --- Colisiones que involucran CÉLULAS DEL JUGADOR ---
    for (let i = playerCells.length - 1; i >= 0; i--) {
         // Verificar que la célula aún existe (por si fue fusionada)
         if (!playerCells[i]) continue;
         const playerCell = playerCells[i];

         // 1. Célula del Jugador come Pellets
        for (let p = pellets.length - 1; p >= 0; p--) {
            const pellet = pellets[p];
            // Usar radios cuadrados para evitar raíces cuadradas (más rápido)
            const distSq = (playerCell.x - pellet.x)**2 + (playerCell.y - pellet.y)**2;
            if (distSq < playerCell.radius**2) { // Simplificado: si el centro del pellet está dentro
                 playerCell.radius = Math.sqrt(playerCell.radius**2 + pellet.radius**2); // Aumentar área
                 pellets.splice(p, 1); // Eliminar pellet
                 // Añadir nueva pellet para mantener la cantidad
                 if (pellets.length < PELLET_COUNT) {
                     pellets.push({
                         x: Math.random() * canvas.width,
                         y: Math.random() * canvas.height,
                         radius: PELLET_RADIUS,
                         color: getRandomColor()
                     });
                 }
            }
        }

        // 2. Célula del Jugador choca con Virus ("Explosión")
        for (let v = viruses.length - 1; v >= 0; v--) {
             const virus = viruses[v];
             const distance = getDistance(playerCell.x, playerCell.y, virus.x, virus.y);
             // Condición: Hay colisión Y la célula es >10% más grande que el virus
             if (distance < playerCell.radius + virus.radius - (virus.radius * 0.2) && playerCell.radius > virus.radius * 1.1) {
                 const originalRadius = playerCell.radius;
                 // Aplicar penalización de radio
                 playerCell.radius *= VIRUS_MASS_PENALTY_FACTOR;
                 console.log(`Célula ${playerCell.id.toFixed(0)} explotó en virus. Radio: ${originalRadius.toFixed(1)} -> ${playerCell.radius.toFixed(1)}`);

                 // Eliminar el virus chocado
                 viruses.splice(v, 1);
                 // Añadir un nuevo virus en un lugar aleatorio
                 addNewVirus(Math.random() * canvas.width, Math.random() * canvas.height);

                 // Opcional: Forzar una división de la célula afectada aquí si es > MIN_RADIUS_TO_SPLIT
                 // splitSingleCell(playerCell); // Necesitaría una función para dividir una célula específica
             }
        }

        // 3. Célula del Jugador come Masa Expulsada
        for (let m = ejectedMasses.length - 1; m >= 0; m--) {
             const mass = ejectedMasses[m];
             const distSq = (playerCell.x - mass.x)**2 + (playerCell.y - mass.y)**2;
              // Condición: Colisión Y (TODO: verificar si no es masa propia recién expulsada)
             if (distSq < playerCell.radius**2) { // Simplificado: si el centro de la masa está dentro
                 // if (currentTime > playerCell.canEatOwnMassTime) { // Necesitaría 'canEatOwnMassTime' en cell
                     playerCell.radius = Math.sqrt(playerCell.radius**2 + mass.radius**2); // Aumentar área
                     ejectedMasses.splice(m, 1); // Eliminar masa
                 // }
             }
        }
    } // Fin del bucle por cada célula del jugador


    // --- Colisiones que NO involucran directamente al jugador ---

    // 4. Masa Expulsada alimenta/divide Virus
    for (let i = ejectedMasses.length - 1; i >= 0; i--) {
        const mass = ejectedMasses[i];
        // Comprobar colisión con cada virus
        for (let j = viruses.length - 1; j >= 0; j--) {
            const virus = viruses[j];
            const distance = getDistance(mass.x, mass.y, virus.x, virus.y);

            // Si la masa expulsada toca al virus
            if (distance < virus.radius + mass.radius) {
                virus.massReceived += EJECTED_MASS_COST; // El virus "come" la masa
                const massVelocityX = mass.velocityX; // Guardar velocidad para la dirección del disparo
                const massVelocityY = mass.velocityY;
                ejectedMasses.splice(i, 1); // Eliminar la masa comida

                // Comprobar si el virus alcanza el umbral para dividirse
                if (virus.massReceived >= VIRUS_SPLIT_THRESHOLD) {
                    virus.massReceived = 0; // Reiniciar masa del virus original

                    // Calcular dirección normalizada del disparo basada en la velocidad de la masa
                    let shootDirectionX = 0;
                    let shootDirectionY = 0;
                    const speed = Math.sqrt(massVelocityX**2 + massVelocityY**2);
                    if (speed > 0.1) { // Solo si la masa tenía velocidad significativa
                         shootDirectionX = massVelocityX / speed;
                         shootDirectionY = massVelocityY / speed;
                    } else {
                         // Si la masa estaba quieta, disparar en dirección aleatoria? O predeterminada?
                         shootDirectionX = Math.random() * 2 - 1; // Dirección X aleatoria
                         shootDirectionY = Math.random() * 2 - 1; // Dirección Y aleatoria
                         const magnitude = Math.sqrt(shootDirectionX**2 + shootDirectionY**2);
                          if (magnitude > 0){ // Normalizar el vector aleatorio
                               shootDirectionX /= magnitude;
                               shootDirectionY /= magnitude;
                          } else {
                               shootDirectionX = 1; // Default a derecha si falla la aleatoriedad
                               shootDirectionY = 0;
                          }
                    }

                    // Calcular posición inicial del nuevo virus (ligeramente separado)
                    const newVirusX = virus.x + shootDirectionX * (virus.radius * 1.5);
                    const newVirusY = virus.y + shootDirectionY * (virus.radius * 1.5);

                    // Añadir el nuevo virus con velocidad inicial para el efecto de disparo
                     if (viruses.length < VIRUS_MAX_COUNT) {
                        addNewVirus(
                            newVirusX,
                            newVirusY,
                            shootDirectionX * VIRUS_SHOOT_SPEED, // Velocidad inicial X
                            shootDirectionY * VIRUS_SHOOT_SPEED  // Velocidad inicial Y
                        );
                         console.log("¡Virus dividido por alimentación!");
                    }
                }
                // Importante: Salir del bucle de virus (j) ya que esta masa (i) ya fue comida
                gotoNextMass; // Usar una etiqueta para salir del bucle interno y continuar con la siguiente masa
            }
        }
        // Etiqueta para el 'goto' (alternativa a 'break' anidado)
        nextMass:;
    }
}


// --- Bucle Principal del Juego (Game Loop) ---
function gameLoop() {
    // 1. Limpiar el canvas completamente
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // 2. Dibujar elementos estáticos o pasivos
    pellets.forEach(p => drawCircle(p.x, p.y, p.radius, p.color));
    viruses.forEach(v => drawVirus(v)); // Usar función específica si tiene detalles
    ejectedMasses.forEach(m => drawCircle(m.x, m.y, m.radius, m.color));

    // 3. Actualizar estado (posición, velocidad) de los elementos
    updatePlayerCells(); // Mueve todas las células del jugador
    updateEjectedMass(); // Mueve la masa expulsada
    updateViruses();     // Mueve los virus recién disparados

    // 4. Manejar interacciones complejas (fusión, colisiones)
    handleMerging();    // Comprueba y ejecuta la fusión entre células del jugador
    checkCollisions();  // Comprueba todas las colisiones (comer, explotar, alimentar virus)

    // 5. Dibujar las células del jugador (encima de todo lo demás)
    playerCells.forEach(cell => {
        drawCircle(cell.x, cell.y, cell.radius, cell.color);
        // Opcional: Dibujar anillo indicador de cooldown de fusión
        const currentTime = Date.now();
        if (cell.canMergeTime > currentTime) {
            ctx.strokeStyle = 'rgba(255, 0, 0, 0.4)'; // Rojo semitransparente
            ctx.lineWidth = 3; // Un poco más grueso
            ctx.beginPath();
            // Dibuja un arco parcial que representa el tiempo restante
            const startAngle = -Math.PI / 2; // Empezar arriba
            const endAngle = startAngle + (Math.PI * 2 * (1 - (cell.canMergeTime - currentTime) / MERGE_COOLDOWN));
            ctx.arc(cell.x, cell.y, cell.radius + 5, startAngle, endAngle); // Un poco fuera de la célula
            ctx.stroke();
        }
    });

    // 6. Solicitar el siguiente frame para continuar la animación
    requestAnimationFrame(gameLoop);
}

// --- Iniciar el Juego ---
initializePlayer(); // Crea la célula inicial del jugador
createPellets();    // Crea la comida inicial
createViruses();    // Crea los virus iniciales
gameLoop();         // Empieza el bucle principal del juego!