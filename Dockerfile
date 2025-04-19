FROM php:8.2-apache

# Install dependencies
RUN apt-get update && apt-get install -y unzip libzip-dev && docker-php-ext-install zip

# Enable Apache rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy all files (including vendor, composer.json, etc.)
COPY . .

# Set public as the web root
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf

# Set proper permissions (optional)
RUN chown -R www-data:www-data /var/www/html

# Input by Github CoPilot...
RUN a2enmod rewrite
# Update Apache configuration to allow .htaccess overrides
RUN echo '<Directory "/var/www/html/public">\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Run Composer install
RUN composer install

# Expose port 80
EXPOSE 80
