FROM composer:2 AS composer

FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    libpq-dev \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        bcmath \
        gd \
        mysqli \
        pdo \
        pdo_pgsql \
        pgsql \
        simplexml \
        zip

# Enable Apache modules
RUN a2enmod rewrite

# Copy Apache config
COPY .docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Copy PHP config
COPY .docker/php.ini /usr/local/etc/php/conf.d/app.ini

# Copy Composer from multi-stage
COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy entrypoint
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
