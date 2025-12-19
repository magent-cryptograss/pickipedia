# PickiPedia Preview Container
# Builds MediaWiki with extensions for local testing
#
# Build args:
#   MEDIAWIKI_VERSION - MediaWiki version to install (default: 1.43.0)

FROM php:8.2-apache

ARG MEDIAWIKI_VERSION=1.43.0

# Install dependencies
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    imagemagick \
    git \
    unzip \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        intl \
        mysqli \
        opcache \
        gd \
        zip \
        calendar \
    && rm -rf /var/lib/apt/lists/*

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Download and extract MediaWiki
RUN MW_MAJOR=$(echo ${MEDIAWIKI_VERSION} | cut -d. -f1,2) \
    && curl -fSL "https://releases.wikimedia.org/mediawiki/${MW_MAJOR}/mediawiki-${MEDIAWIKI_VERSION}.tar.gz" -o mediawiki.tar.gz \
    && tar -xzf mediawiki.tar.gz --strip-components=1 -C /var/www/html \
    && rm mediawiki.tar.gz

# Copy our composer.json for extensions
COPY composer.json /var/www/html/composer.local.json

# Install extensions via composer
WORKDIR /var/www/html
RUN composer update --no-dev --optimize-autoloader

# Install YouTube extension (not available via Composer)
RUN git clone --depth 1 https://github.com/wikimedia/mediawiki-extensions-YouTube.git extensions/YouTube

# Copy custom extensions (if any)
COPY extensions/ /var/www/html/custom-extensions/

# Copy custom assets (logo, etc.)
COPY assets/ /var/www/html/assets/

# Apache configuration - enable rewrite and AllowOverride for .htaccess
RUN a2enmod rewrite
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
RUN chown -R www-data:www-data /var/www/html

# PHP configuration for MediaWiki
RUN echo "memory_limit = 256M" > /usr/local/etc/php/conf.d/mediawiki.ini \
    && echo "upload_max_filesize = 100M" >> /usr/local/etc/php/conf.d/mediawiki.ini \
    && echo "post_max_size = 100M" >> /usr/local/etc/php/conf.d/mediawiki.ini

EXPOSE 80
