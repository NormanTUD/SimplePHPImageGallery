FROM php:8.2-fpm

# Set the port for Apache to listen on
ENV APACHE_PORT 8080
ENV APACHE_DOCUMENT_ROOT /var/www/html

RUN apt-get update
RUN apt-get install libjpeg62-turbo-dev -y
RUN apt-get install zlib1g-dev -y
RUN docker-php-ext-configure gd --with-jpeg
RUN apt-get install -y libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
    # configure the GD extension to include support for JPEG and PNG image formats
    && docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-install exif
RUN apt-get install ca-certificates apt-transport-https apache2 -y
RUN apt-get update -yqq
RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype
RUN docker-php-ext-install gd

# Configure Apache
RUN sed -ri -e 's!/var/www/html!/var/www/html/!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!/var/www/html/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Copy the PHP files to the container
COPY . /var/www/html/

COPY .env /var/www/html/.env
RUN chmod +x /var/www/html/.env

RUN bash /var/www/html/install.sh
RUN rm install.sh Dockerfile docker-compose.yml docker-compose.custom.yml git_hash docker.sh

# Expose the Apache port
EXPOSE $APACHE_PORT

# Start Apache server
CMD ["apache2-foreground"]
