# =========================================================
# STAGE 1: Compile Frontend Assets (Tailwind & Vite)
# =========================================================
FROM node:20-alpine AS frontend-builder
WORKDIR /app

# Copy dependency configs
COPY package*.json ./
COPY tailwind*.js ./
COPY vite.config.js ./

# Install dependencies
RUN npm ci

# Copy public assets and resources for compilation
COPY public/ ./public/
COPY resources/ ./resources/

# Compile Tailwind CSS files (removing watch mode "-w" from scripts)
RUN npx tailwindcss -c tailwind.config-backend.js -i public/lms/assets/css/input.scss -o public/lms/assets/css/output.min.css --minify
RUN npx tailwindcss -i public/lms/frontend/assets/css/input.css -o public/lms/frontend/assets/css/output.min.css --minify

# Compile general Vite assets if configured
RUN npm run build || true


# =========================================================
# STAGE 2: PHP & Nginx Monolithic Container
# =========================================================
FROM php:8.2-fpm-alpine

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    oniguruma-dev \
    libxml2-dev \
    postgresql-dev

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        pdo_pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        opcache

# Install Composer from official image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application source code
COPY . .

# Copy compiled frontend assets from Stage 1
COPY --from=frontend-builder /app/public/lms/assets/css/output.min.css ./public/lms/assets/css/output.min.css
COPY --from=frontend-builder /app/public/lms/frontend/assets/css/output.min.css ./public/lms/frontend/assets/css/output.min.css
# COPY --from=frontend-builder /app/public/build/ ./public/build/

# Install composer packages (optimized for production)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-plugins --no-scripts

# Copy custom Nginx configuration for Alpine Nginx
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Set permissions for Laravel storage and cache directories
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Make entrypoint script executable
RUN chmod +x docker/entrypoint.sh

# Expose HTTP port
EXPOSE 80

# Run entrypoint script
ENTRYPOINT ["docker/entrypoint.sh"]
