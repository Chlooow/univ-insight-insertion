FROM php:8.2-apache

# Installation de l'extension PDO MySQL pour PHP
RUN docker-php-ext-install pdo_mysql

# Activation du module rewrite d'Apache (souvent utile pour le PHP)
RUN a2enmod rewrite

# Copie des fichiers du projet dans le dossier de travail d'Apache
COPY . /var/www/html/

# On s'assure que les permissions sont correctes
RUN chown -R www-data:www-data /var/www/html/
