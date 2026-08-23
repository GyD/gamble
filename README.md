# Gamble

Application mobile-first, légère et auto-hébergée pour organiser et suivre des paris privés.

## Prérequis

- Docker
- DDEV
- HTTPS en production sur le NAS

## Installation locale

Les commandes suivantes créent ou modifient des fichiers. Elles sont volontairement laissées à exécuter manuellement :

```bash
ddev start
ddev composer install
cp .env.example .env
```

Créer ensuite la base et renseigner `.env`, puis appliquer les migrations :

Les valeurs de base de données DDEV sont déjà présentes dans `.env.example`. Appliquer ensuite les migrations :

```bash
ddev composer migrate
```

Points de contrôle :

- application : `https://gamble.ddev.site/`
- santé : `https://gamble.ddev.site/health`

## Tests

```bash
ddev composer test
```

## Architecture

- `config/` : assemblage de l'application et configuration
- `database/migrations/` : migrations SQL versionnées
- `public/` : front controller et ressources publiques
- `src/` : contrôleurs, domaine, repositories et services
- `templates/` : vues Twig
- `tests/` : tests unitaires et d'intégration

Les secrets sont lus depuis `.env`, qui ne doit jamais être ajouté à Git.
