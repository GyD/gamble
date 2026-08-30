# Gamble

Application mobile-first, légère et auto-hébergée pour organiser et suivre des paris privés.

## Objectif produit

Gamble doit centraliser la gestion d'un cercle de paris privés, depuis l'organisation d'un pari jusqu'au suivi des mises, des résultats et des paiements.

L'application s'appuie sur Twitch pour identifier ses utilisateurs. Son système de rôles et de permissions permet de séparer l'administration de l'application, l'organisation des paris et leur consultation.

## Concepts métier principaux

- **Utilisateurs et accès** : comptes Twitch autorisés à utiliser l'application, avec un statut et des rôles dont les permissions sont définies dans la configuration.
- **Contacts** : participants aux paris, qu'ils disposent ou non d'un compte utilisateur dans l'application.
- **Groupes** : ensembles de contacts permettant d'organiser les participants.
- **Paris** : événements ou propositions créés par un organisateur, puis ouverts, fermés et réglés selon leur cycle de vie.
- **Cotes** : calculées selon le mode du pari (`fixed_odds` ou `pari_mutuel`) ; en `fixed_odds`, une cote contractuelle est capturée au moment du paiement de chaque mise ; en `pari_mutuel`, les cotes sont indicatives jusqu'à la clôture et dépendent du pool net final.
- **Rémunération du bookmaker** : deux paramètres distincts, configurables par pari et indépendants l'un de l'autre. La **marge bookmaker** de `fixed_odds` existe déjà au niveau du pari, vaut 10 % par défaut et est intégrée aux cotes proposées via l'overround, sans prélèvement au règlement. La **commission bookmaker** de `pari_mutuel` est une notion séparée, également configurable par pari, qui vaut 10 % par défaut et est prélevée sur le pool avant la répartition des gains.
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
- permissions définies dans `config/permissions.php`, attribuées aux rôles, puis rôles attribués aux utilisateurs ;
- rôle `bookmaker` autorisé à consulter tous les paris et à archiver, réactiver ou supprimer les contacts et les groupes selon leurs règles métier ;
- administration des utilisateurs et de leurs accès ;
- protection des routes et masquage des actions selon les permissions ;
- navigation indiquant la page ou la rubrique active, y compris sur les sous-pages ;
- protection CSRF des mutations ;
- audit atomique des changements de statut et de rôles ;
- tests automatisés du socle d'identité, des accès, des contacts, des groupes, des paris, des mises, des statistiques et des gains ;
- gestion des contacts avec nom et numéro de téléphone obligatoire, note facultative, archivage, réactivation et suppression définitive après archivage ;
- permissions et audit atomique des opérations sur les contacts ;
- gestion des groupes avec note facultative, archivage, suppression définitive après archivage et membres visibles en lecture seule ;
- gestion de l'appartenance facultative à un seul groupe depuis la fiche du contact, avec la permission `contacts.edit` ;
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
- calcul des gains après règlement : le pot de toutes les mises payées et non annulées est réparti entre les gagnants proportionnellement à leurs mises gagnantes, avec distribution déterministe des unités restantes ;
- affichage des gagnants, du montant à leur verser et suivi individuel du statut `gain à verser` ou `gain versé` ;
- statistiques par contact, classement des contacts et répartition des mises sur chaque pari ;
- filtres statistiques sur 7 jours, 30 jours ou tout l'historique, limités aux paris de l'organisateur sauf permission de voir tous les paris ;
- choix du mode `fixed_odds` ou `pari_mutuel` à la création du pari, verrouillé dès qu'une mise existe ;
- probabilités initiales et courantes par option, avec assistant de préréglages pour les paris à deux options ;
- modes d'évolution des cotes `fixed`, `dynamic_low`, `dynamic_normal` et `dynamic_high` en `fixed_odds` ;
- cote informative `quoted_odds` à la création d'une mise et cote contractuelle immuable `odds_at_bet` capturée au paiement ;
- poids de marché réduit des mises impayées via la configuration `unpaid_bet_market_weight` ;
- affichage des cotes comme indicatives tant qu'elles concernent de futures mises, une mise `fixed_odds` payée conservant sa cote contractuelle ;
- réutilisation de la marge bookmaker existante du pari en `fixed_odds`, conservée à 10 % par défaut et limitée entre 0 % et 25 %, appliquée aux cotes proposées via l'overround ;
- commission bookmaker distincte pour `pari_mutuel`, configurable par pari, à 10 % par défaut, prélevée sur le pool lors de la clôture ;
- affichage du seul paramètre correspondant au mode sélectionné dans le formulaire de pari, l'autre étant masqué ;
- garde-fous centralisés du recalcul dynamique : probabilité minimale, probabilité maximale, variation maximale par recalcul et référence de liquidité ;
- en `pari_mutuel`, répartition du pool net entre les gagnants proportionnellement à leurs mises gagnantes, avec distribution déterministe des unités restantes ;
- absence de rémunération du bookmaker lorsqu'un pari est annulé ;
- suivi et affichage du pot total, de la rémunération prélevée par le bookmaker, du montant effectivement redistribué et du résultat net du bookmaker ;
- en `pari_mutuel`, conservation de la totalité du pot par le bookmaker lorsque le choix gagnant ne comporte aucune mise payée ;
- enregistrement d'un état financier définitif lors du règlement afin que les cotes retenues, la rémunération du bookmaker et les gains ne varient plus après le règlement ;
- sérialisation des opérations de marché sur la ligne du pari afin que deux paiements simultanés ne capturent pas des cotes incohérentes.

### À construire

- ajout du résultat net, du montant retourné et du retour sur investissement aux statistiques ;
- extension de l'audit aux futurs modules métier.

### Règles de cotes et de règlement financier

Les règles détaillées sont définies dans la section [Betting modes and odds](#betting-modes-and-odds) ci-dessous, qui constitue la référence fonctionnelle du projet sur ce sujet.

En résumé : en `fixed_odds`, la cote est capturée contractuellement au paiement de chaque mise et n'évolue plus ensuite ; en `pari_mutuel`, le payout dépend du pool net final et de la répartition des mises gagnantes. La rémunération du bookmaker suit la même distinction : marge intégrée aux cotes en `fixed_odds`, commission prélevée sur le pool en `pari_mutuel`. Dans les deux modes, une mise impayée peut influencer les estimations affichées, mais ne constitue jamais de l'argent disponible pour le règlement financier. Les données financières sont figées lors du règlement afin de conserver un historique stable et auditable.

Le produit dispose actuellement de son socle d'identité, de sécurité et d'administration, ainsi que des modules Contacts, Groupes, Paris, Mises et Statistiques.

## Roadmap fonctionnelle

L'ordre prévu tient compte des dépendances entre les concepts :

1. contacts et groupes ;
2. paris et définition de leur cycle de vie ;
3. mises ;
4. clôture et règlement des paris ;
5. statistiques ;

Chaque lot doit inclure son modèle de données, ses règles métier, ses permissions, son interface mobile-first, son audit et ses tests automatisés.

## Betting modes and odds

Cette section est la référence fonctionnelle du projet pour tout ce qui concerne les modes de pari, les probabilités, les cotes, les mises payées, impayées et annulées, le calcul dynamique du marché et le règlement financier.

### 1. Modes de pari

Chaque pari possède un mode :

- `fixed_odds`
- `pari_mutuel`

Le mode est choisi lors de la création du pari.

Il ne peut plus être modifié dès qu'au moins une mise existe.

Les paris existants sont considérés comme `fixed_odds`.

### 2. Fixed odds

En `fixed_odds`, les cotes proposées aux nouvelles mises peuvent évoluer au fil du temps. Une mise possède deux notions distinctes concernant la cote.

#### `quoted_odds` — cote informative

Cote observée ou annoncée lorsque la mise est créée.

Elle est informative uniquement. Elle peut notamment être enregistrée lorsqu'une personne téléphone pour annoncer une mise avant de la payer.

Elle n'est jamais utilisée pour le règlement financier.

#### `odds_at_bet` — cote contractuelle définitive

- reste `null` tant que la mise n'est pas payée ;
- est capturée au moment exact du paiement ;
- une fois définie, elle est immuable.

Exemple :

```text
mise créée alors que la cote vaut 2.10   →  quoted_odds = 2.10
mise non payée                           →  odds_at_bet = null
au paiement, la cote vaut 1.95           →  odds_at_bet = 1.95
```

Le règlement utilise ensuite :

```text
payout = stake × odds_at_bet
```

Ni `quoted_odds`, ni la cote actuelle, ni la cote de clôture ne sont utilisées pour calculer le gain.

### 3. Probabilités initiales

En `fixed_odds`, chaque option possède :

- `initial_probability` : probabilité fixée à l'ouverture ;
- `current_probability` : probabilité courante, recalculée dynamiquement.

À l'ouverture : `current_probability = initial_probability`.

Pour un pari à deux options, un assistant simple propose :

| Préréglage   | Option A        | Option B        |
|--------------|-----------------|-----------------|
| 50 / 50      | 50 %            | 50 %            |
| 52.5 / 47.5  | 52.5 %          | 47.5 %          |
| 55 / 45      | 55 %            | 45 %            |
| 60 / 40      | 60 %            | 40 %            |
| 65 / 35      | 65 %            | 35 %            |
| 70 / 30      | 70 %            | 30 %            |
| 80 / 20      | 80 %            | 20 %            |
| personnalisé | saisie manuelle | saisie manuelle |

Pour trois options ou plus, la saisie est manuelle.

Les probabilités sont toujours normalisées afin que leur somme fasse 100 %.

### 4. Conversion des probabilités en cotes et marge du bookmaker

Cote équitable :

```text
fair_odds = 1 / probability
```

En `fixed_odds`, la rémunération du bookmaker est une **marge intégrée aux cotes**, obtenue par overround. Ce paramètre existe déjà au niveau du pari : il est saisi en pourcentage, vaut **10 % par défaut** et reste limité entre 0 % et 25 %. Il est persisté via `bookmaker_rate_bps` (`1000` = 10 %) et exposé par `Bet::$bookmakerRateBps`. Ce mécanisme est conservé, car il représente bien cette marge.

La marge intervient uniquement lors de la transformation :

```text
current_probability  →  offered_odds
```

La cote proposée est donc systématiquement inférieure à la cote équitable, la différence constituant la marge du bookmaker.

Cette marge n'est jamais prélevée comme une commission sur un pot lors du règlement. Le payout d'une mise gagnante reste :

```text
payout = stake × odds_at_bet
```

Aucun prélèvement supplémentaire n'est appliqué au règlement d'un pari `fixed_odds` : la marge est déjà contenue dans `odds_at_bet`.

La commission du `pari_mutuel` est une notion **séparée**, documentée au point 10. Les deux paramètres valent 10 % par défaut, mais ils ne sont pas conceptuellement liés et doivent pouvoir évoluer indépendamment.

### 5. Modes d'évolution des cotes

Un pari `fixed_odds` dispose d'un mode d'évolution parmi :

| Mode             | Effet                                             |
|------------------|---------------------------------------------------|
| `fixed`          | les mises ne modifient pas les probabilités       |
| `dynamic_low`    | influence faible du marché sur les probabilités   |
| `dynamic_normal` | influence modérée du marché sur les probabilités  |
| `dynamic_high`   | influence forte du marché sur les probabilités    |

En mode dynamique, la probabilité courante d'une option est calculée ainsi :

```text
current_probability = (1 - w) × initial_probability + w × market_probability
```

Le poids du marché augmente progressivement avec le volume :

```text
volume_factor = total_effective_stake / (total_effective_stake + liquidity_reference)
w             = max_market_weight × volume_factor
```

Valeurs de départ de `max_market_weight` :

| Mode             | `max_market_weight` |
|------------------|---------------------|
| `dynamic_low`    | `0.20`              |
| `dynamic_normal` | `0.40`              |
| `dynamic_high`   | `0.65`              |

#### `liquidity_reference`

`liquidity_reference` est un paramètre métier centralisé et configurable. Il contrôle le volume à partir duquel le marché commence à avoir une influence significative sur les probabilités :

- lorsque `total_effective_stake` est très inférieur à `liquidity_reference`, le `volume_factor` reste proche de `0` et les probabilités restent proches des probabilités initiales ;
- lorsque `total_effective_stake` égale `liquidity_reference`, le `volume_factor` vaut `0.5` et le poids du marché atteint la moitié de `max_market_weight` ;
- lorsque `total_effective_stake` dépasse largement `liquidity_reference`, le `volume_factor` tend vers `1` et le poids du marché tend vers `max_market_weight`.

Aucune valeur ne doit être codée en dur dans le service de calcul. La valeur par défaut est `500` et est centralisée dans `config/settings.php`, au même endroit que les autres paramètres de marché.

#### Garde-fous du recalcul dynamique

Le recalcul dynamique doit être encadré par des garde-fous centralisés et configurables. Valeurs initiales proposées :

| Paramètre                                  | Valeur initiale | Rôle                                                        |
|--------------------------------------------|-----------------|-------------------------------------------------------------|
| `minimum_probability`                      | `0.02`          | probabilité plancher d'une option                           |
| `maximum_probability`                      | `0.98`          | probabilité plafond d'une option                            |
| `max_probability_change_per_recalculation` | `0.05`          | variation maximale d'une probabilité lors d'un seul recalcul |

Règles :

- une option ne doit jamais tomber sous `minimum_probability` ni dépasser `maximum_probability` ;
- un recalcul individuel ne doit pas faire varier une probabilité de plus de `max_probability_change_per_recalculation`, afin d'éviter les mouvements brusques provoqués par une mise isolée ;
- après application de ces limites, les probabilités sont renormalisées afin que leur somme reste égale à 100 %.

Ces paramètres, ainsi que `liquidity_reference`, `max_market_weight` et `unpaid_bet_market_weight`, doivent être centralisés en configuration et jamais dupliqués ou codés en dur dans plusieurs services.

### 6. Mises impayées

Les mises actives mais non payées influencent le marché avec un poids réduit, défini par la configuration globale `unpaid_bet_market_weight`, portée par `config/settings.php`, avec la valeur par défaut `0.50`.

| Statut de la mise    | Poids de marché            |
|----------------------|----------------------------|
| payée et active      | `1.00`                     |
| active et non payée  | `unpaid_bet_market_weight` |
| annulée ou refusée   | `0.00`                     |

### 7. Effective stake

Pour les estimations :

```text
effective_stake = paid_stake + unpaid_active_stake × unpaid_bet_market_weight
```

Une mise téléphonique impayée peut donc faire évoluer le marché avant son paiement.

### 8. Paiement d'une mise `fixed_odds`

Lorsqu'une mise passe de non payée à payée :

1. lire la cote actuellement disponible ;
2. enregistrer cette cote dans `odds_at_bet` ;
3. rendre `odds_at_bet` immuable ;
4. marquer la mise comme payée ;
5. passer son poids de marché de `unpaid_bet_market_weight` à `1.00` ;
6. recalculer ensuite les cotes destinées aux futures mises.

La cote doit être capturée avant le recalcul provoqué par le paiement.

Le passage impayé → payé est atomique afin d'éviter les incohérences en cas de paiements simultanés.

### 9. Annulation

Une mise annulée ou refusée a un poids de marché de `0`.

Elle n'influence plus les estimations.

### 10. Pari mutuel et commission du bookmaker

En `pari_mutuel`, aucune cote n'est garantie. Les mises alimentent un pool commun.

Contrairement à `fixed_odds`, la rémunération du bookmaker n'est pas une marge intégrée aux cotes : c'est une **commission prélevée sur le pool** à la clôture, avant toute redistribution.

Cette commission est une notion métier **distincte** de la marge `fixed_odds`. Elle est configurable par pari, avec une valeur par défaut proposée de **10 %**, et sera persistée dans son propre paramètre, par exemple `mutuel_commission_rate_bps` ou tout nom équivalent respectant les conventions du projet.

Un pari peut donc porter deux valeurs par défaut identiques sans qu'elles soient liées :

| Paramètre                              | Mode concerné  | Valeur par défaut | Nature                          |
|----------------------------------------|----------------|-------------------|---------------------------------|
| `bookmaker_rate_bps` (champ existant)  | `fixed_odds`   | 10 %              | marge intégrée aux cotes        |
| `mutuel_commission_rate_bps` (à créer) | `pari_mutuel`  | 10 %              | commission prélevée sur le pool |

Modifier l'une ne doit jamais modifier l'autre : chacune évolue indépendamment.

À la clôture, `commission_rate` désigne la commission `pari_mutuel` du pari :

```text
net_pool = final_pool × (1 - commission_rate)
```

Pour une mise gagnante :

```text
payout = bettor_stake / total_winning_stake × net_pool
```

Le payout inclut la mise initiale.

Le `net_pool` est ensuite intégralement redistribué aux gagnants : aucune marge supplémentaire n'est appliquée par cote, puisqu'il n'existe aucune cote contractuelle dans ce mode.

### 11. Mises impayées en pari mutuel

Les mises impayées peuvent influencer l'estimation affichée avant la clôture, avec le même `unpaid_bet_market_weight`, mais elles sont exclues du règlement financier.

Il faut donc distinguer deux pools :

| Pool             | Contenu                                                          | Utilisation             |
|------------------|------------------------------------------------------------------|-------------------------|
| `effective_pool` | mises payées + mises impayées actives × `unpaid_bet_market_weight` | estimations indicatives |
| `final_pool`     | uniquement les mises payées et financièrement éligibles           | règlement financier     |

### 12. Rendement indicatif en pari mutuel

Avant la clôture, on peut afficher :

```text
indicative_odds = estimated_net_pool / effective_stake_on_option
```

Cette valeur est uniquement indicative et doit être présentée comme non garantie. Le rendement définitif est calculé lors de la clôture, à partir du `final_pool`.

### 13. Différence fondamentale entre les deux modes

| Dimension                     | `fixed_odds`                                     | `pari_mutuel`                                   |
|-------------------------------|--------------------------------------------------|-------------------------------------------------|
| Cote contractuelle            | `odds_at_bet`, capturée au paiement              | aucune                                          |
| Payout                        | `stake × odds_at_bet`                            | `bettor_stake / total_winning_stake × net_pool` |
| Moment de fixation            | au paiement de chaque mise                       | à la clôture du pari                            |
| Cotes pendant l'ouverture     | indicatives, évolution dynamique possible        | indicatives                                     |
| Rémunération du bookmaker     | marge intégrée aux cotes via l'overround         | commission prélevée sur le pool                 |
| Moment du prélèvement         | aucun prélèvement au règlement                   | à la clôture, avant redistribution              |

En `fixed_odds`, une mise payée possède une cote contractuelle et la marge du bookmaker est déjà contenue dans cette cote. En `pari_mutuel`, aucune cote contractuelle n'existe : le payout dépend du pool net final, obtenu après prélèvement de la commission, et de la répartition des mises gagnantes.

#### État financier définitif

Le règlement fige un état financier composé de quatre montants, afin que les cotes retenues, la rémunération du bookmaker et les gains ne varient plus ensuite :

| Champ                    | Contenu                                                        |
|--------------------------|----------------------------------------------------------------|
| `final_pot`              | total des mises payées et financièrement éligibles              |
| `final_bookmaker_share`  | montant prélevé sur le pot avant redistribution                 |
| `final_redistributed`    | montant effectivement versé aux gagnants                        |
| `final_bookmaker_result` | résultat net du bookmaker, égal à `final_pot - final_redistributed` |

Le prélèvement et le résultat sont deux notions distinctes :

- en `fixed_odds`, aucun prélèvement n'a lieu au règlement, donc `final_bookmaker_share` vaut toujours `0`. Le résultat provient de l'overround déjà intégré aux cotes contractuelles et peut être négatif si les gagnants ont été payés plus que le pot ;
- en `pari_mutuel`, `final_bookmaker_share` correspond à la commission prélevée sur le pool. Lorsque le choix gagnant ne comporte aucune mise payée, rien n'est redistribué et le résultat vaut la totalité du pot.

Les cotes finales sont également figées : elles sont enregistrées telles que retenues au règlement et ne sont jamais recalculées à partir de mises ultérieures.

#### Paramètre affiché selon le mode

Le formulaire de pari n'expose que le paramètre correspondant au mode sélectionné, l'autre étant masqué :

| Mode           | Libellé affiché             | Paramètre sous-jacent                    |
|----------------|-----------------------------|------------------------------------------|
| `fixed_odds`   | **Marge bookmaker (%)**     | `bookmaker_rate_bps`, champ existant     |
| `pari_mutuel`  | **Commission bookmaker (%)**| commission `pari_mutuel` dédiée          |

Le libellé actuel « Taux bookmaker (%) » devra donc être remplacé par « Marge bookmaker (%) » en `fixed_odds`, afin de ne plus laisser croire à un prélèvement au règlement.

### 14. Règle essentielle concernant les impayés

Dans les deux modes, une mise impayée peut influencer les estimations.

Mais une mise impayée ne doit jamais être considérée comme de l'argent disponible pour le règlement financier.

Ne jamais confondre :

- **marché indicatif** : alimenté par l'`effective_stake`, qui inclut les impayés pondérés ;
- **règlement financier** : basé uniquement sur les mises payées et financièrement éligibles.

### 15. Historique futur

Les données suivantes doivent pouvoir être conservées ultérieurement.

Pour `fixed_odds` :

- évolution des probabilités ;
- évolution des cotes ;
- volume du marché ;
- poids du marché.

Pour `pari_mutuel` :

- évolution du pool ;
- répartition par option ;
- rendement indicatif.

Le graphique lui-même n'est pas requis maintenant : seules les structures de données doivent le rendre possible.

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
- HTTPS en production

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

## Déploiement sur Raspberry Pi

Le déploiement de production utilise Docker Compose avec trois services :

- `app` : Nginx et PHP-FPM 8.4 ;
- `db` : MariaDB 11.8 avec stockage persistant ;
- `cloudflared` : tunnel HTTPS sortant vers Cloudflare.

Les images utilisées prennent en charge l'architecture ARM64. Un système 64 bits est donc recommandé sur le Raspberry Pi. Aucun port du Raspberry Pi ou du routeur ne doit être ouvert : MariaDB reste sur un réseau Docker interne et l'application est publiée uniquement par Cloudflare Tunnel.

### Préparer Cloudflare et Twitch

1. Ajouter le domaine à Cloudflare.
2. Dans Cloudflare Zero Trust, créer un tunnel géré à distance et copier son jeton.
3. Ajouter une route d'application publiée avec le nom public souhaité et le service `http://app:8080`.
4. Dans la console développeur Twitch, déclarer exactement `https://<nom-public>/auth/twitch/callback` comme URL de redirection.

Cloudflare termine HTTPS sur son réseau, puis le tunnel joint `app` en HTTP à l'intérieur du réseau Docker privé. Il est aussi possible d'ajouter une politique Cloudflare Access, mais elle ne doit pas interrompre le flux de redirection Twitch.

### Configurer et démarrer

Cloner le dépôt sur le Raspberry Pi, créer `.env` à partir de `.env.example`, puis utiliser des valeurs de production :

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gamble.example.com
APP_SECRET=<longue-valeur-aleatoire>

DB_HOST=db
DB_PORT=3306
DB_DATABASE=gamble
DB_USERNAME=gamble
DB_PASSWORD=<mot-de-passe-fort>
MARIADB_ROOT_PASSWORD=<autre-mot-de-passe-fort>

TWITCH_CLIENT_ID=<identifiant-twitch>
TWITCH_CLIENT_SECRET=<secret-twitch>
TWITCH_REDIRECT_URI=https://gamble.example.com/auth/twitch/callback

SESSION_NAME=gamble_session
SESSION_SECURE=true
CLOUDFLARE_TUNNEL_TOKEN=<jeton-du-tunnel>
TZ=Europe/Paris
```

Les secrets ne doivent jamais être ajoutés à Git. Construire et démarrer ensuite la stack :

```bash
docker compose build --pull
docker compose up -d
docker compose ps
```

Les migrations restent volontaires et ne sont jamais lancées lors d'un simple redémarrage :

```bash
docker compose exec app php bin/migrate
```

Après une première connexion Twitch, promouvoir le premier administrateur :

```bash
docker compose exec app php bin/promote-admin <twitch-id>
```

Points de contrôle :

- `docker compose ps` indique les services `app` et `db` comme sains ;
- le tunnel apparaît `Healthy` dans Cloudflare Zero Trust ;
- `https://<nom-public>/health` répond avec succès ;
- aucun port `80`, `443`, `8080` ou `3306` n'est redirigé par le routeur.

### Mettre à jour

Depuis la copie du dépôt sur le Raspberry Pi :

```bash
git pull --ff-only
docker compose build --pull
docker compose up -d
docker compose exec app php bin/migrate
docker image prune
```

### Sauvegarder et restaurer MariaDB

Créer un répertoire de sauvegarde hors du dépôt, puis exporter la base :

```bash
mkdir -p ~/backups/gamble
docker compose exec -T db mariadb-dump \
  -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
  > ~/backups/gamble/gamble-$(date +%F-%H%M%S).sql
```

Pour restaurer une sauvegarde, arrêter d'abord les écritures sur l'application, puis exécuter :

```bash
docker compose stop app
docker compose exec -T db mariadb \
  -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
  < ~/backups/gamble/<sauvegarde>.sql
docker compose start app
```

Les commandes de sauvegarde supposent que les variables de `.env` ont été chargées dans le shell, par exemple avec `set -a; . ./.env; set +a`. Conserver les sauvegardes chiffrées sur un autre support que la carte SD du Raspberry Pi.

## Architecture

- `config/` : assemblage de l'application et configuration
- `database/migrations/` : migrations SQL versionnées
- `docker/` : configuration de l'image de production
- `public/` : front controller et ressources publiques
- `src/` : contrôleurs, domaine, repositories et services
- `templates/` : vues Twig
- `tests/` : tests unitaires et d'intégration

Les secrets sont lus depuis `.env`, qui ne doit jamais être ajouté à Git.
