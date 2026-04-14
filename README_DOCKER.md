# 🚀 Guide d'installation avec Docker - Univ Insight

Ce projet est configuré pour fonctionner avec **Docker**. Cela permet de lancer le serveur web et la base de données avec une seule commande, sans avoir à configurer manuellement MAMP, XAMPP ou WAMP.

---

## 📋 Prérequis

Vous devez avoir installé **Docker Desktop** sur votre machine :
- [Télécharger Docker Desktop (Windows/Mac/Linux)](https://www.docker.com/products/docker-desktop/)

---

## 🛠️ Lancement du projet

1. **Ouvrez un terminal** dans le dossier du projet.
2. **Lancez les conteneurs** avec la commande suivante :
   ```bash
   docker-compose up --build
   ```
3. **Attendez l'initialisation** : 
   - Au premier lancement, Docker va télécharger les images et importer automatiquement la base de données (`univ_insight`). 
   - Cela peut prendre 1 à 2 minutes selon votre connexion.
   - Ne fermez pas le terminal tant que vous voyez des logs défiler.

---

## 🌐 Accès aux outils

Une fois que les conteneurs sont lancés, vous pouvez accéder aux services via votre navigateur :

| Service | Adresse URL | Description |
| :--- | :--- | :--- |
| **Site Web** | [http://localhost:8080/client](http://localhost:8080/client) | L'interface principale du projet |
| **phpMyAdmin** | [http://localhost:8081](http://localhost:8081) | Pour gérer et interroger la base de données |

---

## 🗄️ Connexion à la base de données (Logiciels tiers)

Si vous utilisez un logiciel comme **DBeaver**, **TablePlus** ou **HeidiSQL**, utilisez les paramètres suivants :
- **Hôte** : `localhost`
- **Port** : `3307`
- **Utilisateur** : `root`
- **Mot de passe** : `root`
- **Base de données** : `univ_insight`

---

## ⚠️ En cas de conflit de ports

Si vous avez déjà un serveur (MAMP, XAMPP, etc.) qui utilise les ports **8080** ou **8081**, vous aurez un message d'erreur. 
Pour corriger cela, ouvrez le fichier `docker-compose.yml` et modifiez les numéros à gauche du `:` dans la section `ports` :

```yaml
# Exemple pour changer le port du site de 8080 à 9000
ports:
  - "9000:80" 
```

---

## 🛑 Arrêter le projet

Pour arrêter proprement les serveurs, faites `Ctrl + C` dans le terminal ou tapez :
```bash
docker-compose down
```
