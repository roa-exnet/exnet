#!/bin/bash

# Crear la estructura de directorios
mkdir -p docker/php docker/nginx docker/websocket var/data

# Verificar si se crearon todos los archivos necesarios
echo "Verificando archivos de configuración..."

files=(
    "docker-compose.yml"
    "docker/php/Dockerfile"
    "docker/php/php.ini"
    "docker/nginx/default.conf"
    "docker/websocket/Dockerfile"
    "docker/websocket/server.js"
)

for file in "${files[@]}"; do
    if [ ! -f "$file" ]; then
        echo "Error: El archivo $file no existe."
        exit 1
    fi
done

# Dar permisos de ejecución al directorio var
chmod -R 777 var

echo "Configuración completada. Puedes iniciar los contenedores con:"
echo "docker-compose up -d"