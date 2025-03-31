FROM node:18-alpine

WORKDIR /var/www/html

# Instalar dependencias para el servidor websocket
RUN npm install ws express

# Crear un archivo simple para el servidor websocket
COPY docker/websocket/server.js .

EXPOSE 3088

CMD ["node", "server.js"]