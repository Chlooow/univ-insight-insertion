# Utiliser une image de base PostgreSQL
FROM postgres:latest

# Ajouter un script pour initialiser la base de données
COPY mld.sql /docker-entrypoint-initdb.d/

# Exposer le port 5432
EXPOSE 5432