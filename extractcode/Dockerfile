FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV PUPPETEER_SKIP_DOWNLOAD=true
ENV NODE_OPTIONS=--openssl-legacy-provider

RUN apt-get update && apt-get install -y \
    ca-certificates fonts-liberation libappindicator3-1 libasound2 \
    libatk-bridge2.0-0 libatk1.0-0 libcups2 libdbus-1-3 libgdk-pixbuf2.0-0 \
    libnspr4 libnss3 libnss3-tools libx11-xcb1 libxcomposite1 libxdamage1 libxrandr2 \
    xdg-utils libu2f-udev libvulkan1 libgbm1 libxshmfence1 wget unzip curl git gnupg \
    xvfb xauth \
    && rm -rf /var/lib/apt/lists/*

# Instalar Google Chrome stable (NO Chrome for Testing).
# Razón: Chrome for Testing ignora enterprise policies por diseño, lo que rompe
# AutoSelectCertificateForUrls — sin esa política Chrome cuelga en headless
# pidiendo el cert para mTLS. google-chrome-stable sí respeta las policies en
# /etc/opt/chrome/policies/managed/, igual que Chrome en Windows.
RUN wget -q -O /tmp/google-chrome-key.pub https://dl.google.com/linux/linux_signing_key.pub && \
    gpg --dearmor < /tmp/google-chrome-key.pub > /usr/share/keyrings/google-chrome.gpg && \
    echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-chrome.gpg] http://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list && \
    apt-get update && \
    apt-get install -y google-chrome-stable && \
    rm -rf /var/lib/apt/lists/* /tmp/google-chrome-key.pub

# Instalar Node con NVM
ENV NVM_DIR=/root/.nvm
RUN curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash && \
    . "$NVM_DIR/nvm.sh" && \
    nvm install 18.12.1 && \
    nvm use 18.12.1 && \
    nvm alias default 18.12.1 && \
    npm install -g pm2

ENV NODE_PATH=$NVM_DIR/versions/node/v18.12.1/lib/node_modules
ENV PATH=$NVM_DIR/versions/node/v18.12.1/bin:$PATH

# Copiar y preparar app
WORKDIR /app
COPY . .

# Solo dependencias de Node — Chrome ya está instalado arriba.
RUN npm install --omit=dev

# Exponer puerto y arrancar
EXPOSE 3000
CMD ["pm2-runtime", "start", "server.js", "--name", "extractor"]