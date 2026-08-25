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
- **Cotes** : cotes mutuelles dynamiques calculées à partir de la répartition des mises actives et du montant redistribuable ; elles restent indicatives jusqu'à la fermeture du pari.
- **Part du bookmaker** : commission prélevée sur le pot d'un pari réglé avant la répartition des gains.
- **Mises** : participations rattachées à un pari et à un contact, saisies, stockées et affichées en dollars entiers.
- **Paiements** : suivi des mises payées, non payées ou à rembourser et des gains versés ou à verser ; les transferts sont réalisés hors de l'application.
- **Statistiques** : vues agrégées des paris, mises et résultats.
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
- navigation indiquant la page ou la rubrique active, y compris sur les sous-pages ;
- protection CSRF des mutations ;
- audit atomique des changements de statut, de rôles et de permissions ;
- tests automatisés du socle d'identité, des accès, des contacts, des groupes, des paris, des mises, des statistiques et des gains ;
- gestion des contacts avec nom et numéro de téléphone obligatoire, note facultative, archivage, réactivation et suppression définitive après archivage ;
- permissions et audit atomique des opérations sur les contacts ;
- gestion des groupes avec note facultative, archivage, suppression définitive après archivage et membres visibles en lecture seule ;
- gestion des appartenances aux groupes depuis la fiche du contact, avec la permission `contacts.edit` ;
- création des paris avec 2 à 20 choix uniques, une description et une date limite facultatives ;
- modification des choix d'un pari uniquement tant qu'aucune mise n'existe ;
- cycle de vie des paris `open` → `closed` → `settled`, avec annulation depuis les états `open` ou `closed` ;
- désignation obligatoire d'un choix gagnant lors du règlement ;
- permissions, contrôle de propriété et audit atomique des opérations sur les paris ;
- gestion de plusieurs mises par contact et par pari, sur un ou plusieurs choix ;
- sélection recherchable des contacts dans les formulaires de mise, avec affichage de leurs groupes ;
- création et modification des mises tant que le pari est ouvert ;
- annulation possible d’une mise payée ou non payée, et obligatoire avant sa suppression définitive ;
- une mise annulée et payée peut être marquée remboursée, sans devoir être réactivée ;
- suppression définitive d’une mise uniquement lorsqu’elle est annulée et non payée ;
- montants de mises en dollars entiers de `1` à `999999`, avec statut payé/non payé, permissions dédiées et audit atomique ;
- migration des anciennes mises au montant entier le plus proche (minimum `1`), avec recalcul des pots, commissions, cotes finales et gains des paris réglés sans modifier leurs statuts de paiement ;
- résumé séparant le nombre et le montant des mises payées et non payées, hors mises annulées ;
- sur un pari annulé, une mise payée est à rembourser et une mise non payée est considérée comme remboursée ;
- suppression définitive d'un pari annulé uniquement lorsqu'aucune mise ne reste payée ;
- calcul des gains après règlement : le pot de toutes les mises payées et non annulées est réparti entre les gagnants proportionnellement à leurs mises gagnantes, avec distribution déterministe des centimes restants ;
- affichage des gagnants, du montant à leur verser et suivi individuel du statut `gain à verser` ou `gain versé` ;
- statistiques par contact, classement des contacts et répartition des mises sur chaque pari ;
- filtres statistiques sur 7 jours, 30 jours ou tout l'historique, limités aux paris de l'organisateur sauf permission de voir tous les paris.

### À construire

- cotes mutuelles dynamiques recalculées à partir des mises non annulées pendant toute la période d'ouverture du pari, qu'elles soient payées ou non ;
- affichage des cotes comme indicatives pendant l'ouverture, puis comme finales après la fermeture du pari ;
- part du bookmaker configurable pour chaque pari, fixée à 10 % par défaut et limitée à une valeur comprise entre 0 % et 25 % ;
- calcul de la part théorique du bookmaker sur le pot total des mises payées et non annulées d'un pari réglé ;
- garantie d'une cote minimale de `1,00` : la part réelle du bookmaker est limitée aux mises perdantes afin que les gagnants récupèrent au minimum leur mise ;
- répartition du montant redistribuable entre les gagnants proportionnellement à leurs mises gagnantes, avec distribution déterministe des centimes restants ;
- absence de part du bookmaker lorsqu'un pari est annulé ;
- suivi et affichage du pot total, de la part du bookmaker et du montant effectivement redistribué ;
- conservation de la totalité du pot par le bookmaker lorsque le choix gagnant ne comporte aucune mise active ;
- enregistrement d'un état financier définitif lors du règlement afin que la cote finale, la part du bookmaker et les gains ne varient plus après le règlement ;
- ajout du résultat net, du montant retourné et du retour sur investissement aux statistiques ;
- extension de l'audit aux futurs modules métier.

### Règles prévues pour les cotes et la part du bookmaker

Les cotes sont mutuelles : elles ne sont pas fixées à l'avance par le bookmaker, mais dépendent des montants misés sur chaque choix. Tant que le pari est ouvert, elles retiennent toutes les mises non annulées, payées ou non. Après sa fermeture, elles ne retiennent que les mises payées et non annulées. Pour un choix comportant au moins une mise retenue, la cote est calculée ainsi :

```text
pot brut des cotes = somme de toutes les mises retenues
part théorique du bookmaker = pot brut × taux du bookmaker
mises du choix = somme des mises retenues sur le choix
pertes disponibles = pot brut - mises du choix
part applicable du bookmaker = minimum(part théorique du bookmaker, pertes disponibles)
montant redistribuable = pot brut - part applicable du bookmaker
cote du choix = montant redistribuable / mises du choix
```

La limitation de la part du bookmaker aux pertes disponibles garantit une cote minimale de `1,00`. Le montant retourné à un gagnant inclut donc toujours au moins sa mise. Une cote n'est pas calculable pour un choix sans mise active.

Lors du règlement, si le choix gagnant comporte des mises actives, le montant redistribuable est partagé entre les gagnants proportionnellement à leurs mises sur ce choix. Si le choix gagnant ne comporte aucune mise active, aucun gain n'est distribué et le bookmaker conserve la totalité du pot.

Les cotes évoluent après chaque création, modification, paiement ou annulation de mise tant que le pari est ouvert. Une mise non payée entre dans les cotes indicatives pendant cette période, mais jamais dans le pot, les statistiques ou les gains. Dès la fermeture, les cotes ne retiennent plus que les mises payées et non annulées. Les données financières sont figées lors du règlement afin de conserver un historique stable et auditable.

Le produit dispose actuellement de son socle d'identité, de sécurité et d'administration, ainsi que des modules Contacts, Groupes, Paris, Mises et Statistiques.

## Roadmap fonctionnelle

L'ordre prévu tient compte des dépendances entre les concepts :

1. contacts et groupes ;
2. paris et définition de leur cycle de vie ;
3. mises ;
4. clôture et règlement des paris ;
5. statistiques ;

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

Les valeurs de connexion à la base DDEV sont déjà présentes dans `.env.example`. Renseigner les autres variables dans `.env`, notamment `APP_NAME` pour le nom public de l'application, puis appliquer les migrations :

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
