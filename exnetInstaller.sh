#!/bin/bash

echo "  ______      _   _ ______ _______  "
echo " |  ____|    | \ | |  ____|__   __| "
echo " | |__  __  _|  \| | |__     | |    "
echo " |  __| \ \/ / . \` |  __|    | |    "
echo " | |____ >  <| |\  | |____   | |    "
echo " |______/_/\_\_| \_|______|  |_|    "
echo "                                    "
echo "╔═══════════════════════════════════════════╗"
echo "║                INFORMATION                ║"
echo "╠═══════════════════════════════════════════╣"
echo "║ WEB:       https://exnet.cloud            ║"
echo "╚═══════════════════════════════════════════╝"

check_internet_connection() {
    echo "[+] Verificando conexión a internet..."
    
    urls=(
        "http://www.google.com"
        "http://www.cloudflare.com"
        "http://www.microsoft.com"
    )
    
    for url in "${urls[@]}"; do
        if timeout 5 curl --silent --max-time 5 --head "$url" > /dev/null 2>&1; then
            echo "[+] Conexión a internet verificada."
            return 0
        fi
    done
    
    echo "[!] No se pudo conectar directamente. Verificando resolución DNS..."
    if nslookup google.com > /dev/null 2>&1; then
        echo "[!] DNS funciona pero hay problemas de conexión."
    else
        echo "[!] No hay resolución DNS disponible."
    fi
    
    if ip link show | grep -q "state UP"; then
        echo "[-] Error: Hay interfaces de red activas pero no hay conexión a internet."
        echo "[-] Posibles problemas:"
        echo "    - Firewall bloqueando conexiones salientes"
        echo "    - Problemas con el router/gateway"
        echo "    - DNS no funcional"
        echo "    - Proxy configurado que está bloqueando"
        echo "[-] Verifica tu conexión e inténtalo de nuevo."
    else
        echo "[-] Error: No hay interfaces de red activas."
        echo "[-] Sugerencias:"
        echo "    - Verifica cables de red"
        echo "    - Verifica si WiFi está activado"
        echo "    - Usa: 'ip link' para ver el estado de interfaces"
    fi
    
    return 1
}

check_dependencies() {
    echo "[+] Verificando dependencias necesarias..."
    
    dependencies=("curl" "timeout" "ip" "nslookup")
    missing_deps=()
    
    for dep in "${dependencies[@]}"; do
        if ! command -v "$dep" > /dev/null 2>&1; then
            missing_deps+=("$dep")
        fi
    done
    
    if [ ${#missing_deps[@]} -ne 0 ]; then
        echo "[-] Error: Faltan dependencias necesarias: ${missing_deps[*]}"
        echo "[!] Instalando dependencias..."
        sudo apt update
        sudo apt install -y curl iproute2 dnsutils
    else
        echo "[+] Todas las dependencias están disponibles."
    fi
    
    return 0
}

check_dependencies

if ! check_internet_connection; then
    echo
    echo "Por favor, resuelve los problemas de conexión antes de continuar."
    echo "Presiona Enter para continuar de todos modos o Ctrl+C para cancelar."
    read
fi

clear

echo "  ______      _   _ ______ _______  "
echo " |  ____|    | \ | |  ____|__   __| "
echo " | |__  __  _|  \| | |__     | |    "
echo " |  __| \ \/ / . \` |  __|    | |    "
echo " | |____ >  <| |\  | |____   | |    "
echo " |______/_/\_\_| \_|______|  |_|    "
echo "                                    "
echo "╔═══════════════════════════════════════════╗"
echo "║                INFORMATION                ║"
echo "╠═══════════════════════════════════════════╣"
echo "║ WEB:       https://exnet.cloud            ║"
echo "╚═══════════════════════════════════════════╝"

sudo apt update && sudo apt upgrade -y

sudo apt install -y apt-transport-https ca-certificates curl software-properties-common unzip wireguard resolvconf jq

echo "[+] Eliminando versiones antiguas de Docker..."
sudo apt-get remove -y docker docker-engine docker.io docker-compose containerd runc

echo "[+] Agregando repositorio de Docker..."
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt-get update

echo "[+] Instalando versiones específicas y estables de Docker..."

DOCKER_VERSION="5:23.0.3-1~ubuntu.$(lsb_release -rs)~$(lsb_release -cs)"
sudo apt-get install -y docker-ce=$DOCKER_VERSION docker-ce-cli=$DOCKER_VERSION containerd.io

echo "[+] Agregando usuario actual al grupo docker..."
sudo usermod -aG docker $USER

echo "[+] Instalando Docker Compose v2.17.2..."
DOCKER_CONFIG=${DOCKER_CONFIG:-$HOME/.docker}
mkdir -p $DOCKER_CONFIG/cli-plugins
curl -SL "https://github.com/docker/compose/releases/download/v2.17.2/docker-compose-linux-$(uname -m)" -o $DOCKER_CONFIG/cli-plugins/docker-compose
chmod +x $DOCKER_CONFIG/cli-plugins/docker-compose

sudo ln -sf $DOCKER_CONFIG/cli-plugins/docker-compose /usr/local/bin/docker-compose

echo "[+] Verificando la instalación de Docker..."
sudo systemctl enable docker
sudo systemctl start docker
docker --version
docker compose version

export DOCKER_BUILDKIT=1
export COMPOSE_DOCKER_CLI_BUILD=1

echo "[+] Descargando exnet-core..."
wget https://cdn.exnet.cloud/api/download/base_source/exnet-core.zip
unzip exnet-core.zip
cd exnet-core

clear

while true; do
    read -p "Introduce el número de clientes VPN (mínimo 2, máximo 30): " num_clients
    if [[ "$num_clients" =~ ^[0-9]+$ ]] && [ "$num_clients" -ge 2 ] && [ "$num_clients" -le 30 ]; then
        echo "[+] Número de clientes seleccionado: $num_clients"
        break
    else
        echo "[-] Error: introduce un número válido entre 2 y 30."
    fi
done

echo "[+] Creando una nueva red VPN en vpn.exnet.cloud..."
VPN_API="https://vpn.exnet.cloud/api"

response=$(curl -s -X POST "$VPN_API/deploy" \
  -H "Content-Type: application/json" \
  -d '{"peers":'"$num_clients"', "networkName":"exnet-auto-'"$(date +%s)"'"}')

deploymentId=$(echo "$response" | grep -oP '"deploymentId":"\K[^"]+')

if [ -z "$deploymentId" ]; then
  echo "[-] Error: No se pudo crear la VPN."
  echo "Respuesta: $response"
  exit 1
fi

echo "[+] VPN creada con ID: $deploymentId"

peers_response=$(curl -s "$VPN_API/deployments/$deploymentId/peers")
total_peers=$(echo "$peers_response" | jq '.totalPeers')
echo "[+] Clientes disponibles: $total_peers"

mkdir -p ~/exnet_peers

for ((i=0; i<total_peers; i++)); do
    peer_number=$(echo "$peers_response" | jq -r ".peers[$i].peerNumber")
    peer_config=$(echo "$peers_response" | jq -r ".peers[$i].config")
    
    if [ -n "$peer_config" ]; then
        peer_ip=$(echo "$peer_config" | grep -E "^Address" | awk '{print $3}' | cut -d '/' -f 1)
        
        if [ -z "$peer_ip" ]; then
            peer_ip="peer${peer_number}"
        fi
        
        config_decoded=$(echo "$peer_config" | sed 's/\\n/\n/g' | sed 's/\\"/"/g')
        echo "[#] Cliente $peer_ip (Peer${peer_number})"
        echo "----------------------------------"
        echo -e "$config_decoded"
        echo "----------------------------------"
        echo -e "$config_decoded" > ~/exnet_peers/${peer_ip}.conf
    fi
done

echo "[+] Configuraciones de clientes guardadas en ~/exnet_peers/"

first_peer_config=$(echo "$peers_response" | jq -r ".peers[0].config" | sed 's/\\n/\n/g' | sed 's/\\"/"/g')
first_peer_ip=$(echo "$first_peer_config" | grep -E "^Address" | awk '{print $3}' | cut -d '/' -f 1)

if [ -z "$first_peer_ip" ]; then
    first_peer_ip="wg0"
fi

echo -e "$first_peer_config" > ./${first_peer_ip}.conf
sudo cp ./${first_peer_ip}.conf /etc/wireguard/wg0.conf

sudo sysctl -w net.ipv4.ip_forward=1
sudo wg-quick up wg0

echo "[+] Levantando contenedores Docker..."
sudo docker-compose up --build -d

echo "[+] Esperando a que los contenedores estén listos..."
timeout=30
elapsed=0

while ! sudo docker ps | grep -q "Up"; do
  sleep 1
  elapsed=$((elapsed + 1))
  if [ $elapsed -ge $timeout ]; then
    echo "[-] Error: Los contenedores no se iniciaron en $timeout segundos."
    exit 1
  fi
done

clear

echo "  ______      _   _ ______ _______  "
echo " |  ____|    | \ | |  ____|__   __| "
echo " | |__  __  _|  \| | |__     | |    "
echo " |  __| \ \/ / . \` |  __|    | |    "
echo " | |____ >  <| |\  | |____   | |    "
echo " |______/_/\_\_| \_|______|  |_|    "
echo "                                    "
echo "╔═══════════════════════════════════════════╗"
echo "║                ADMIN SETUP                ║"
echo "╚═══════════════════════════════════════════╝"

read -p "Introduce el correo del admin: " admin_email
read -p "Introduce el nombre del admin: " admin_name
read -p "Introduce el apellido del admin: " admin_lastname
echo
read -p "Introduce la dirección IP del admin (presiona Enter para usar 10.0.0.3): " admin_ip

if [ -z "$admin_ip" ]; then
    admin_ip="10.0.0.3"
    echo "Usando IP predeterminada: $admin_ip"
fi

ws_port=$((10000 + RANDOM % 10001))

container_name=$(sudo docker ps -q --filter "name=php")
if [ -z "$container_name" ]; then
  echo "[-] Error: No se encontró el contenedor del servicio 'php'."
  sudo docker ps
  exit 1
fi

echo "[+] Configurando usuario administrador..."
sudo docker exec "$container_name" php bin/console exnet:setup \
  --admin-user="$admin_email" \
  --admin-name="$admin_name" \
  --admin-lastname="$admin_lastname" \
  --admin-ip="$admin_ip" \
  --app-env="prod" \
  --cors-url="*" \
  --install-modules="no" \
  --start-server="no" \
  --server-port="8080" \
  --force

echo "[+] Instalación completada correctamente."
echo
echo "Versiones instaladas:"
docker --version
docker compose version