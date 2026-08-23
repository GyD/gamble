# Gamble

Application mobile-first, légère et auto-hébergée pour organiser et suivre des paris privés.

## Objectif produit

Gamble doit centraliser la gestion d'un cercle de paris privés, depuis l'organisation d'un pari jusqu'au suivi des mises, des résultats et des paiements.

L'application s'appuie sur Twitch pour identifier ses utilisateurs. Son système de rôles et de permissions permet de séparer l'administration de l'application, l'organisation des paris et leur consultation.

## Concepts métier principaux

- **Utilisateurs et accès** : comptes Twitch autorisés à utiliser l'application, avec un statut, des rôles et des permissions individuelles.
- **Contacts** : participants aux paris, qu'ils disposent ou non d'un compte utilisateur dans l'application.
- **Groupes** : ensembles de contacts permettant d'organiser les participants.
- **Paris** : événements ou propositions créés par un organisateur, puis ouverts, fermés et réglés selon leur cycle de vie.
- **Mises** : participations rattachées à un pari et à un contact.
- **Paiements** : mouvements permettant de suivre les sommes effectivement réglées entre les participants.
- **Statistiques** : vues agrégées des paris, mises, résultats et paiements.
- **Paramètres** : configuration fonctionnelle de l'application.
- **Audit** : historique des opérations sensibles avec leur acteur et les états avant/après.

Les règles détaillées de cycle de vie, de règlement et de calcul seront précisées avant l'implémentation de chaque module métier. Le présent document décrit le périmètre fonctionnel sans figer les règles qui ne sont pas encore arbitrées.

## État d'avancement

### Disponible

- authentification Twitch sans scope additionnel ;
- création automatique des utilisateurs inconnus avec le statut `pending` ;
- activation, suspension et réactivation des utilisateurs ;
- rôles et permissions, avec autorisations ou interdictions individuelles ;
- administration des utilisateurs et de leurs accès ;
- protection des routes et masquage des actions selon les permissions ;
- protection CSRF des mutations ;
- audit atomique des changements de statut, de rôles et de permissions ;
- tests automatisés du socle d'identité, des accès et des contacts ;
- gestion des contacts avec nom et numéro de téléphone RP obligatoire, note facultative, archivage et réactivation ;
- permissions et audit atomique des opérations sur les contacts.
- gestion des groupes avec note facultative, archivage et membres visibles en lecture seule ;
- gestion des appartenances aux groupes depuis la fiche du contact, avec la permission `contacts.edit`.
- création des paris avec un nombre libre de choix, description et date limite facultatives ;
- cycle de vie des paris `open` → `closed` → `settled`, avec annulation définitive depuis l'état `open` ;
- désignation obligatoire d'un choix gagnant lors du règlement ;
- permissions, contrôle de propriété et audit atomique des opérations sur les paris.

### À construire

- gestion des mises ;
- clôture et règlement des paris ;
- suivi des paiements et des soldes ;
- statistiques ;
- paramètres fonctionnels ;
- extension de l'audit aux futurs modules métier.

Le produit dispose actuellement de son socle d'identité, de sécurité et d'administration, ainsi que des modules Contacts, Groupes et Paris. Les fonctionnalités de gestion des mises restent à implémenter.

## Roadmap fonctionnelle

L'ordre prévu tient compte des dépendances entre les concepts :

1. contacts et groupes ;
2. paris et définition de leur cycle de vie ;
3. mises ;
4. clôture et règlement des paris ;
5. paiements et soldes ;
6. statistiques ;
7. paramètres fonctionnels.

Chaque lot doit inclure son modèle de données, ses règles métier, ses permissions, son interface mobile-first, son audit et ses tests automatisés.

## Principes d'implémentation

- Les contrôleurs valident les requêtes HTTP et délèguent les opérations aux services métier.
- Les services appliquent leurs propres invariants indépendamment de l'interface HTTP.
- Les accès aux données passent par des contrats de repository afin de rester testables.
- Une mutation et son entrée d'audit sont enregistrées dans une même transaction.
- Les routes et les actions d'interface sont protégées par des permissions explicites.
- Les mutations HTTP sont protégées contre les attaques CSRF.
- Les interfaces sont conçues d'abord pour les écrans mobiles.
- Toute nouvelle règle métier doit être couverte par des tests automatisés.

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

Les valeurs de connexion à la base DDEV sont déjà présentes dans `.env.example`. Renseigner les autres variables dans `.env`, puis appliquer les migrations :

```bash
ddev composer migrate
```

Points de contrôle :

- application : `https://gamble.ddev.site/`
- santé : `https://gamble.ddev.site/health`

## Authentification Twitch

Créer une application dans la console développeur Twitch et déclarer exactement cette URL de redirection locale :

```text
https://gamble.ddev.site/auth/twitch/callback
```

Renseigner ensuite dans `.env` :

- `TWITCH_CLIENT_ID`
- `TWITCH_CLIENT_SECRET`
- `TWITCH_REDIRECT_URI`

Le flux ne demande aucun scope Twitch. Un utilisateur inconnu est créé avec le statut `pending`.

Pour amorcer le premier administrateur :

1. se connecter une première fois avec Twitch ;
2. relever son identifiant numérique Twitch ;
3. exécuter :

```bash
ddev composer promote-admin -- <twitch-id>
```

La commande active le compte et lui affecte le rôle `admin`. Elle modifie la base et doit donc être lancée manuellement.

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
