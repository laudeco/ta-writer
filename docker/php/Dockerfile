FROM php:8.4-cli

# PHP extensions, Node + mermaid-cli (diagrams), Chromium (mermaid and the AGIR browser session),
# Xvfb + x11vnc + noVNC (to log in to AGIR from a browser on the host, see make login-browser).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libonig-dev libxml2-dev libpng-dev libjpeg-dev libfreetype-dev \
        nodejs npm chromium fonts-dejavu-core ca-certificates \
        xvfb x11vnc novnc websockify \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install intl zip mbstring dom xml simplexml gd \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install sockets

RUN npm install -g @mermaid-js/mermaid-cli@11 \
    && npm cache clean --force

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini "$PHP_INI_DIR/conf.d/app.ini"
COPY puppeteer-config.json /etc/ta-writer/puppeteer-config.json
COPY novnc.sh /usr/local/bin/novnc.sh
RUN chmod +x /usr/local/bin/novnc.sh

# HOME must be writable for the uid the container runs as: Chromium writes its profile and crash database there
ENV HOME=/tmp \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    PUPPETEER_SKIP_DOWNLOAD=1 \
    MMDC_BIN=/usr/local/bin/mmdc \
    MMDC_PUPPETEER_CONFIG=/etc/ta-writer/puppeteer-config.json \
    CHROME_BIN=/usr/bin/chromium

WORKDIR /app
