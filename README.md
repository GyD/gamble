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
- **Cotes** : en `fixed_odds`, le bookmaker saisit lui-même la cote de chaque choix ; la cote annoncée à la création de la mise est conservée à titre informatif et la cote contractuelle est figée **au paiement** ; en `pari_mutuel`, les cotes sont indicatives jusqu'à la clôture et dépendent du pool net final.
- **Rémunération du bookmaker** : deux notions distinctes et indépendantes. En `fixed_odds`, la **marge bookmaker** n'est pas saisie : elle se déduit des cotes elles-mêmes (`somme(1 / cote) - 1`) et n'entraîne aucun prélèvement au règlement, le bookmaker pouvant donc perdre s'il a mal coté. En `pari_mutuel`, la **commission bookmaker** est configurable par pari, vaut 10 % par défaut et est prélevée sur le pool avant la répartition des gains.
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
- rôle `bookmaker` autorisé à gérer les paris et les mises de tous les utilisateurs, ainsi qu'à archiver, réactiver ou supprimer les contacts et les groupes selon leurs règles métier ;
- administration des utilisateurs et de leurs accès ;
- protection des routes et masquage des actions selon les permissions ;
- navigation indiquant la page ou la rubrique active, y compris sur les sous-pages ;
- bandeau d'environnement affiché en haut de toutes les pages lorsque `APP_ENV` vaut `development` ou `test`, masqué en `production` ;
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
- accès partagé aux paris : tout utilisateur disposant de la permission requise agit sur l'ensemble des paris et des mises, quel que soit leur créateur ;
- permissions et audit atomique des opérations sur les paris, l'auteur réel de chaque action restant journalisé ;
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
- filtres statistiques sur 7 jours, 30 jours ou tout l'historique, calculés sur l'ensemble des paris ;
- choix du mode `fixed_odds` ou `pari_mutuel` à la création du pari, verrouillé dès qu'une mise existe ;
- explications du fonctionnement de chaque mode de pari dans les formulaires de création et de modification, complétées d'un tableau comparatif repliable, et rappel du mode actif sur la page de consultation ;
- conversion des paris antérieurs en `pari_mutuel`, leur marge bookmaker devenant leur commission mutuelle, afin de préserver leur comportement financier historique ;
- cotes de chaque choix saisies à la main par le bookmaker en `fixed_odds`, un choix non coté n'acceptant aucune mise ;
- marge bookmaker déduite des cotes saisies et affichée en lecture seule, sans aucun paramètre de marge à renseigner ;
- page « Ajuster les cotes » exposant, choix par choix, le montant encaissé, le montant en attente, les gains à verser et le résultat du bookmaker si ce choix gagne, puis permettant de republier les cotes ;
- modes d'évolution des cotes `fixed`, `dynamic_low`, `dynamic_normal` et `dynamic_high` en `fixed_odds`, `fixed` par défaut, expliqués dans les formulaires par un bloc chiffré propre au mode sélectionné, complété d'un tableau comparatif repliable ;
- dérive des cotes proposées orientée par l'exposition réellement prise et pondérée par le volume échangé depuis la dernière cotation manuelle, plafonnée à 25 % et jamais sous `minimum_odds` ;
- recotation manuelle réinitialisant la dérive, les mises antérieures ne l'alimentant plus ;
- cote contractuelle immuable `odds_at_bet` capturée au paiement de la mise `fixed_odds`, la cote annoncée à la création étant conservée dans `quoted_odds` à titre purement informatif ;
- gain estimé à la cote actuellement proposée tant que la mise n'est pas payée, gain garanti à la cote contractuelle une fois payée ;
- exposition séparée en dette contractuelle, portée par les seules mises payées à leur cote figée, et projection indicative des mises impayées à la cote actuellement proposée ;
- assistant facultatif de cotation initiale en `fixed_odds`, préremplissant les cotes de tous les choix à partir des probabilités saisies et d'une marge cible, sans jamais persister de probabilité ;
- poids de marché réduit des mises impayées via la configuration `unpaid_bet_market_weight` ;
- affichage de la cote actuellement proposée en `fixed_odds`, cette cote ne devenant contractuelle qu'au paiement, et purement indicative en `pari_mutuel` ;
- commission bookmaker propre au `pari_mutuel`, configurable par pari, à 10 % par défaut, prélevée sur le pool lors de la clôture ;
- affichage du seul paramètre correspondant au mode sélectionné dans le formulaire de pari, l'autre étant masqué ;
- paramètres de marché centralisés dans `config/settings.php` : écart maximal par mode d'évolution, référence de liquidité, cote plancher et poids des mises impayées ;
- en `pari_mutuel`, répartition du pool net entre les gagnants proportionnellement à leurs mises gagnantes, avec distribution déterministe des unités restantes ;
- absence de rémunération du bookmaker lorsqu'un pari est annulé ;
- suivi et affichage du pot total, de la rémunération prélevée par le bookmaker, du montant effectivement redistribué et du résultat net du bookmaker ;
- en `pari_mutuel`, conservation de la totalité du pot par le bookmaker lorsque le choix gagnant ne comporte aucune mise payée ;
- enregistrement d'un état financier définitif lors du règlement afin que les cotes retenues, la rémunération du bookmaker et les gains ne varient plus après le règlement ;
- sérialisation des opérations de marché sur la ligne du pari afin que deux mises simultanées ne figent pas des cotes incohérentes.

### À construire

- ajout du résultat net, du montant retourné et du retour sur investissement aux statistiques ;
- extension de l'audit aux futurs modules métier.

### Règles de cotes et de règlement financier

Les règles détaillées sont définies dans la section [Betting modes and odds](#betting-modes-and-odds) ci-dessous, qui constitue la référence fonctionnelle du projet sur ce sujet.

En résumé : en `fixed_odds`, le bookmaker saisit les cotes lui-même, la cote annoncée à la création reste informative et la cote contractuelle est figée au paiement, sans jamais évoluer ensuite ; en `pari_mutuel`, le payout dépend du pool net final et de la répartition des mises gagnantes. La rémunération du bookmaker suit la même distinction : marge portée par les cotes saisies en `fixed_odds`, commission prélevée sur le pool en `pari_mutuel`. Dans les deux modes, une mise impayée peut influencer les cotes proposées aux prochains parieurs, mais ne constitue jamais de l'argent disponible pour le règlement financier. Les données financières sont figées lors du règlement afin de conserver un historique stable et auditable.

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

Cette section est la référence fonctionnelle du projet pour tout ce qui concerne les modes de pari, les cotes saisies par le bookmaker, son exposition, les mises payées, impayées et annulées, la dérive des cotes proposées et le règlement financier.

### 1. Modes de pari

Chaque pari possède un mode :

- `fixed_odds`
- `pari_mutuel`

Le mode est choisi lors de la création du pari.

Il ne peut plus être modifié dès qu'au moins une mise existe.

Les paris antérieurs à cette fonctionnalité sont convertis en `pari_mutuel`, et leur marge bookmaker devient leur commission mutuelle. C'était en effet leur comportement financier réel : une commission était prélevée sur le pot à la clôture, puis le pool net était réparti entre les mises gagnantes proportionnellement à leur montant. Les conserver en `fixed_odds` aurait remboursé chaque gagnant à la cote `1,00`, faute de cote contractuelle sur les mises déjà payées. Les paris déjà réglés ne sont pas modifiés, leur état financier étant figé.

### 2. Fixed odds

En `fixed_odds`, **le bookmaker saisit lui-même la cote de chaque choix**. Aucune probabilité n'est demandée ni stockée : la cote est la seule donnée de cotation.

Une option non cotée reste `null` et **n'accepte aucune mise** : il n'y aurait aucun contrat à passer.

#### Deux cotes distinctes sur une mise

| Champ         | Rôle                                 | Fixé            | Usage financier |
|---------------|--------------------------------------|-----------------|-----------------|
| `quoted_odds` | cote annoncée/affichée à la création | à la création   | **aucun**       |
| `odds_at_bet` | cote contractuelle définitive        | **au paiement** | le règlement    |

`quoted_odds` n'est qu'une trace du prix présenté au parieur. Elle est renseignée à la création d'une mise `fixed_odds` lorsque le choix possède une cote proposée, reste `null` sinon, et n'entre jamais dans un règlement ni dans une exposition contractuelle. Elle ne devient jamais contractuelle.

`odds_at_bet` :

- reste `null` tant que la mise n'est pas payée ;
- est capturée **au paiement**, à partir de la cote alors proposée ;
- est immuable ensuite : ni une modification de montant, ni une recotation ne la changent ;
- reste `null` pour les mises antérieures à cette fonctionnalité et pour les mises `pari_mutuel`.

Cette règle existe pour les mises prises par téléphone : un parieur peut annoncer une mise avant de payer, mais il ne doit pas pouvoir **réserver** une cote avantageuse puis ne payer que si elle le reste.

Exemple :

```text
14:00  cote proposée 2.10  →  mise créée non payée  →  quoted_odds = 2.10, odds_at_bet = null
14:10  cote proposée 1.95  →  mise inchangée        →  quoted_odds = 2.10, odds_at_bet = null
14:25  cote proposée 1.95  →  mise payée            →  quoted_odds = 2.10, odds_at_bet = 1.95
14:40  bookmaker recote    →  mise inchangée        →  odds_at_bet = 1.95 (immuable)
```

Le règlement utilise ensuite :

```text
payout = stake × odds_at_bet
```

Une mise gagnante ne peut jamais rapporter moins que sa propre mise.

#### Gain affiché avant et après paiement

L'affichage du gain dépend strictement de l'existence d'une cote contractuelle.

**Mise `fixed_odds` non payée** — `odds_at_bet` est `null` et la cote peut encore évoluer. L'interface peut afficher la `quoted_odds` historique, la cote actuellement proposée, et le gain estimé si la mise était payée maintenant :

```text
estimated_payout = stake × current_offered_odds
```

Ce gain est purement indicatif et ne doit jamais être présenté comme garanti.

**Mise `fixed_odds` payée** — l'interface affiche `odds_at_bet` et le gain garanti :

```text
guaranteed_payout = stake × odds_at_bet
```

En synthèse :

```text
création non payée
→ quoted_odds = cote annoncée
→ odds_at_bet = null
→ gain estimé uniquement

paiement
→ capture de la cote courante dans odds_at_bet
→ gain désormais garanti
```

#### Modification d'une mise impayée

Modifier une mise avant son paiement ne crée aucun contrat : `odds_at_bet` reste `null` et `quoted_odds` conserve la cote annoncée lors de la création, afin de garder la trace du prix réellement présenté.

#### Mise créée directement payée

La création d'une mise déjà payée lit la cote proposée, crée la mise, y inscrit `odds_at_bet`, la marque payée, puis seulement ensuite recalcule le marché. L'ensemble est atomique.

#### Mise gagnante sans cote contractuelle

Une mise `fixed_odds` impayée ne peut pas être réglée comme gagnante payable : elle n'a pas de `odds_at_bet`. Ce cas est une **incohérence métier** pour les données créées après cette règle, et non un remboursement automatique à la cote `1,00`. Les anciennes données migrées sans cote contractuelle restent tolérées pour compatibilité.

### 3. Marge du bookmaker en fixed odds

La marge n'est pas un paramètre saisi : elle **se lit dans les cotes**. Un livre entièrement coté vaut :

```text
marge = somme(1 / cote) - 1
```

Elle est affichée à titre indicatif et reste `null` tant qu'un choix n'est pas coté. Trois choix cotés `2.73` portent ainsi une marge d'environ 10 %.

Cette marge n'est jamais prélevée comme une commission sur un pot lors du règlement : elle est déjà contenue dans `odds_at_bet`. Le bookmaker peut donc perdre de l'argent sur un pari `fixed_odds` s'il a mal coté.

La commission du `pari_mutuel` est une notion **séparée**, documentée au point 9.

#### Assistant facultatif de cotation initiale

À la création d'un pari `fixed_odds` ou lors de sa première cotation, un assistant **facultatif** aide à générer les cotes de départ. Il ne remplace pas les champs de cote : il ne fait que les **préremplir**.

Le bookmaker saisit une probabilité par choix, quel que soit leur nombre, puis une marge cible. La **marge cible** vaut `10 %` par défaut et ne sert qu'à générer les cotes :

```text
probabilité_normalisée = probabilité / somme(probabilités)
cote = 1 / (probabilité_normalisée × (1 + marge_cible))
```

Les probabilités sont toujours normalisées à 100 % avant le calcul : leur somme n'a pas besoin d'être exacte. Aucun niveau d'avantage prédéfini n'est proposé, et aucun assistant statistique plus élaboré n'est prévu.

| Choix saisis           | Probabilités   | Cotes à 10 % de marge cible | Marge réelle |
|------------------------|----------------|-----------------------------|--------------|
| Deux choix équilibrés  | `50 / 50`      | `1.82 / 1.82`               | `9,89 %`     |
| Deux choix, un favori  | `60 / 40`      | `1.52 / 2.27`               | `9,84 %`     |
| Trois choix équilibrés | `50 / 50 / 50` | `2.73 / 2.73 / 2.73`        | `9,89 %`     |

Les champs de probabilité sont vides au départ : tant qu'un choix n'a pas de probabilité strictement positive, l'assistant refuse de générer les cotes et le signale.

Une fois les cotes générées :

- le bookmaker peut les corriger librement ;
- elles deviennent de simples cotes saisies et servent d'ancrage ;
- le moteur d'exposition et de dérive reprend la main pour leur évolution.

#### Aucune probabilité persistée

Les probabilités de l'assistant sont un **outil de saisie**, pas un état du marché. Elles ne sont pas stockées, et la marge cible n'est pas un paramètre métier du pari. Les champs `initial_probability` et `current_probability`, ainsi que tout moteur d'évolution basé sur les probabilités, restent supprimés.

L'interface affiche toujours la marge **réellement portée par les cotes finales**, calculée avec `somme(1 / cote) - 1`. Elle diffère légèrement de la marge cible à cause des arrondis et des corrections manuelles.

### 4. Exposition du bookmaker

Pour coter, le bookmaker consulte son exposition choix par choix, depuis la page **Ajuster les cotes**. Elle sépare strictement deux natures d'engagement :

| Nature                   | Mises concernées       | Cote utilisée              | Statut                              |
|--------------------------|------------------------|----------------------------|-------------------------------------|
| exposition contractuelle | payées et non annulées | `odds_at_bet` figée        | dette réelle, déjà engagée          |
| exposition indicative    | actives non payées     | cote actuellement proposée | estimation, aucune dette contractée |

Une mise impayée ne doit **jamais** être présentée comme une dette financière déjà contractualisée : elle n'a pas de `odds_at_bet`, et son exposition n'est qu'une projection au prix du moment. Elle continue par ailleurs de peser sur le marché avec `unpaid_bet_market_weight`.

| Colonne                        | Contenu                                                        |
|--------------------------------|----------------------------------------------------------------|
| Misé                           | montant encaissé, et montant encore en attente de paiement      |
| À verser si gagnant            | somme des `stake × odds_at_bet` des mises payées du choix       |
| Projection des impayés         | somme des `stake × cote proposée` des mises actives non payées   |
| Si ce choix gagne (encaissé)   | total encaissé − à verser, sur l'argent réellement collecté     |
| …une fois tout encaissé        | même calcul en supposant les impayés payés à la cote proposée    |

Un résultat très négatif signale un choix trop chargé au prix actuel : sa cote doit être raccourcie, ou celle des autres allongée.

### 5. Modes d'évolution des cotes

Un pari `fixed_odds` dispose d'un mode d'évolution parmi :

| Mode             | Écart maximal | Effet                                              |
|------------------|---------------|----------------------------------------------------|
| `fixed`          | `0 %`         | les cotes restent celles saisies par le bookmaker  |
| `dynamic_low`    | `5 %`         | les cotes s'écartent légèrement des cotes saisies  |
| `dynamic_normal` | `12 %`        | équilibre recommandé                               |
| `dynamic_high`   | `25 %`        | les cotes suivent nettement l'exposition prise     |

Le mode par défaut est `fixed` : les cotes saisies à la main sont respectées tant que le bookmaker ne demande pas explicitement une dérive.

La dérive ne touche que les cotes **proposées aux prochaines mises**. Les mises déjà payées conservent la cote figée à leur paiement.

#### Sens de la dérive

Le sens vient de l'exposition : la part de gains potentiels portée par un choix est comparée à la part que **sa propre cote** implique.

```text
part_impliquée = (1 / cote) / somme(1 / cote)
part_exposée   = gains_potentiels_du_choix / gains_potentiels_totaux
```

Un choix qui concentre plus d'exposition que son prix ne le suppose voit sa cote baisser, les autres montent. Un livre déjà équilibré n'est pas touché, et un livre partiellement coté est publié tel quel.

#### Intensité de la dérive

L'intensité vient du volume échangé, afin qu'un marché fin ne bouge presque pas :

```text
volume_factor = effective_volume / (effective_volume + liquidity_reference)
écart_maximal = max_odds_drift × volume_factor
```

Seules les mises prises **après la dernière cotation manuelle** (`odds_anchored_at`) alimentent le volume et l'exposition de la dérive. Recoter à la main remet donc le compteur à zéro : seules les mises suivantes peuvent faire bouger les nouvelles cotes.

Les cotes proposées sont publiées à deux décimales et ne descendent jamais sous `minimum_odds`.

#### Paramètres centralisés

Aucune valeur n'est codée en dur dans les services de marché. Tout est porté par `config/settings.php` :

| Paramètre                  | Valeur par défaut                           | Rôle                                                   |
|----------------------------|---------------------------------------------|--------------------------------------------------------|
| `max_odds_drift_bps`       | `0` / `500` / `1200` / `2500` selon le mode | écart maximal autorisé, en points de base              |
| `liquidity_reference`      | `500`                                       | volume à partir duquel la dérive devient significative  |
| `minimum_odds`             | `1.01`                                      | cote plancher publiable                                |
| `unpaid_bet_market_weight` | `0.50`                                      | poids d'une mise active non payée                      |

Aucun mode ne peut dépasser un écart de 25 %, plafond absolu porté par le code.

`liquidity_reference` contrôle le volume à partir duquel le marché pèse réellement :

- très en dessous, le `volume_factor` reste proche de `0` et les cotes restent celles saisies ;
- à égalité, le `volume_factor` vaut `0.5` et la moitié de l'écart maximal s'applique ;
- très au-dessus, le `volume_factor` tend vers `1` et l'écart maximal est presque atteint.

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

Une mise téléphonique impayée peut donc faire évoluer les cotes proposées avant son paiement.

### 8. Paiement et annulation d'une mise

Le paiement est le moment où le contrat se noue. Il suit un ordre strict :

1. verrouiller la mise et l'état de marché nécessaire ;
2. lire la cote actuellement proposée sur le choix ;
3. l'enregistrer dans `odds_at_bet` ;
4. marquer la mise payée ;
5. faire passer son poids de marché de `unpaid_bet_market_weight` à `1.00` ;
6. recalculer ensuite les cotes destinées aux futures mises.

La cote est donc capturée **avant** le recalcul provoqué par ce paiement : une mise ne se paie jamais à un prix qu'elle a elle-même déplacé. L'ensemble est atomique, afin d'éviter les incohérences en cas de paiements simultanés.

#### La capture n'a lieu qu'une seule fois

`odds_at_bet` est capturée au **premier** passage d'une mise `fixed_odds` sans cote contractuelle vers un état réellement payé :

```text
si fixed_odds && passage_a_payé && odds_at_bet === null  →  capturer la cote proposée
sinon                                                    →  odds_at_bet inchangée
```

Deux conséquences en découlent.

**Dépaiement.** Une mise déjà payée qui repasse temporairement en non payée **conserve** sa `odds_at_bet` : elle n'est jamais remise à `null`, ni recalculée lors d'un repaiement ultérieur. Le statut de paiement peut changer, l'engagement contractuel historique reste attaché à la mise. Un repaiement ne permet donc pas d'obtenir une nouvelle cote.

**Remboursement.** Toute opération liée au statut de remboursement est une correction de trésorerie, pas un nouveau contrat. Si le remboursement ou son annulation fait techniquement repasser la mise dans un état payé, aucune cote n'est capturée, `odds_at_bet` n'est pas modifiée et la mise n'est pas réancrée au prix courant.

Une mise annulée ou refusée a un poids de marché de `0` et n'influence plus les estimations.

### 9. Pari mutuel et commission du bookmaker

En `pari_mutuel`, aucune cote n'est garantie. Les mises alimentent un pool commun.

Contrairement à `fixed_odds`, la rémunération du bookmaker n'est pas une marge intégrée aux cotes : c'est une **commission prélevée sur le pool** à la clôture, avant toute redistribution.

Cette commission est une notion métier **distincte** de la marge `fixed_odds`. Elle est configurable par pari, vaut **10 % par défaut**, reste limitée entre 0 % et 25 % et est persistée dans `mutuel_commission_rate_bps` (`1000` = 10 %).

Les deux rémunérations sont indépendantes :

| Rémunération                 | Mode concerné | Valeur par défaut | Nature                          |
|------------------------------|---------------|-------------------|---------------------------------|
| marge lue dans les cotes     | `fixed_odds`  | portée par les cotes saisies | marge intégrée aux cotes |
| `mutuel_commission_rate_bps` | `pari_mutuel` | 10 %              | commission prélevée sur le pool |

En `fixed_odds`, il n'existe plus aucun paramètre de marge à saisir : la marge découle des cotes. Le champ `bookmaker_rate_bps` a été supprimé.

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

### 10. Mises impayées en pari mutuel

Les mises impayées peuvent influencer l'estimation affichée avant la clôture, avec le même `unpaid_bet_market_weight`, mais elles sont exclues du règlement financier.

Il faut donc distinguer deux pools :

| Pool             | Contenu                                                          | Utilisation             |
|------------------|------------------------------------------------------------------|-------------------------|
| `effective_pool` | mises payées + mises impayées actives × `unpaid_bet_market_weight` | estimations indicatives |
| `final_pool`     | uniquement les mises payées et financièrement éligibles           | règlement financier     |

### 11. Rendement indicatif en pari mutuel

Avant la clôture, on peut afficher :

```text
indicative_odds = estimated_net_pool / effective_stake_on_option
```

Cette valeur est uniquement indicative et doit être présentée comme non garantie. Le rendement définitif est calculé lors de la clôture, à partir du `final_pool`.

### 12. Différence fondamentale entre les deux modes

| Dimension                 | `fixed_odds`                                     | `pari_mutuel`                                   |
|---------------------------|--------------------------------------------------|-------------------------------------------------|
| Cotes                     | saisies à la main par le bookmaker               | aucune, seul le pool compte                     |
| Cote contractuelle        | `odds_at_bet`, figée au paiement de la mise      | aucune                                          |
| Payout                    | `stake × odds_at_bet`                            | `bettor_stake / total_winning_stake × net_pool` |
| Moment de fixation        | au paiement de chaque mise                       | à la clôture du pari                            |
| Cotes pendant l'ouverture | annoncées à la création, garanties au paiement   | indicatives                                     |
| Rémunération du bookmaker | marge lue dans les cotes saisies                 | commission prélevée sur le pool                 |
| Moment du prélèvement     | aucun prélèvement au règlement                   | à la clôture, avant redistribution              |
| Risque du bookmaker       | réel, il peut perdre s'il a mal coté             | nul, il ne verse que le pool encaissé           |

En `fixed_odds`, chaque mise possède une cote contractuelle et la marge du bookmaker est déjà contenue dans cette cote. En `pari_mutuel`, aucune cote contractuelle n'existe : le payout dépend du pool net final, obtenu après prélèvement de la commission, et de la répartition des mises gagnantes.

#### État financier définitif

Le règlement fige un état financier composé de quatre montants, afin que les cotes retenues, la rémunération du bookmaker et les gains ne varient plus ensuite :

| Champ                    | Contenu                                                        |
|--------------------------|----------------------------------------------------------------|
| `final_pot`              | total des mises payées et financièrement éligibles              |
| `final_bookmaker_share`  | montant prélevé sur le pot avant redistribution                 |
| `final_redistributed`    | montant effectivement versé aux gagnants                        |
| `final_bookmaker_result` | résultat net du bookmaker, égal à `final_pot - final_redistributed` |

Le prélèvement et le résultat sont deux notions distinctes :

- en `fixed_odds`, aucun prélèvement n'a lieu au règlement, donc `final_bookmaker_share` vaut toujours `0`. Le résultat provient de la marge déjà contenue dans les cotes contractuelles et peut être négatif si les gagnants ont été payés plus que le pot ;
- en `pari_mutuel`, `final_bookmaker_share` correspond à la commission prélevée sur le pool. Lorsque le choix gagnant ne comporte aucune mise payée, rien n'est redistribué et le résultat vaut la totalité du pot.

Les cotes finales sont également figées dans `final_odds` : elles sont enregistrées telles que retenues au règlement et ne sont jamais recalculées à partir de mises ultérieures.

#### Paramètre affiché selon le mode

Le formulaire de pari n'expose que ce qui concerne le mode sélectionné :

| Mode          | Affiché                                                             |
|---------------|---------------------------------------------------------------------|
| `fixed_odds`  | une cote par choix, le mode d'évolution, et la marge en lecture seule |
| `pari_mutuel` | **Commission bookmaker (%)**, alimentant `mutuel_commission_rate_bps` |

La page **Ajuster les cotes** complète le formulaire : elle affiche l'exposition choix par choix et permet de republier les cotes à tout moment tant que le pari est ouvert.

### 13. Règle essentielle concernant les impayés

Dans les deux modes, une mise impayée peut influencer les estimations.

Mais une mise impayée ne doit jamais être considérée comme de l'argent disponible pour le règlement financier.

Ne jamais confondre :

- **marché indicatif** : alimenté par l'`effective_stake`, qui inclut les impayés pondérés ;
- **règlement financier** : basé uniquement sur les mises payées et financièrement éligibles.

### 14. Historique futur

Les données suivantes doivent pouvoir être conservées ultérieurement.

Pour `fixed_odds` :

- évolution des cotes saisies et des cotes proposées ;
- écart entre `quoted_odds` annoncée et `odds_at_bet` capturée au paiement ;
- évolution de l'exposition par choix ;
- volume du marché depuis la dernière cotation.

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

`APP_ENV` accepte uniquement `development`, `test` ou `production`. La valeur est normalisée en minuscules ; toute autre valeur retombe sur `production`. Hors production, un bandeau collé en haut de chaque page rappelle l'environnement courant afin de distinguer les instances de test de l'instance de production.

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
# development, test ou production ; hors production un bandeau d'environnement est affiché
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

### Déployer une seconde instance

Plusieurs instances indépendantes peuvent cohabiter sur le même Raspberry Pi. Chacune s'appuie sur sa propre copie du dépôt, placée sur la branche souhaitée, et sur son propre fichier `.env`.

L'isolation repose sur deux variables :

- `COMPOSE_PROJECT_NAME` : préfixe des conteneurs, des volumes et des réseaux du projet Docker Compose ;
- `APP_IMAGE` : nom de l'image applicative construite localement.

Ces deux valeurs doivent impérativement être distinctes d'une instance à l'autre. Sans `APP_IMAGE` propre, la construction d'une instance écraserait l'image de l'autre, qui repartirait alors sur un code inattendu au redémarrage suivant.

Dans le `.env` de la seconde copie, différencier au minimum :

```dotenv
COMPOSE_PROJECT_NAME=gamble-v2
APP_IMAGE=gamble-app-v2:local

APP_NAME=Gamble v2
APP_URL=https://gamble-v2.example.com
APP_SECRET=<autre-valeur-aleatoire>

DB_PASSWORD=<autre-mot-de-passe-fort>
MARIADB_ROOT_PASSWORD=<autre-mot-de-passe-fort>

TWITCH_REDIRECT_URI=https://gamble-v2.example.com/auth/twitch/callback

SESSION_NAME=gamble_v2_session
CLOUDFLARE_TUNNEL_TOKEN=<jeton-du-second-tunnel>
```

`DB_HOST` reste `db` : chaque instance dispose de son propre réseau `backend` interne et de sa propre base MariaDB. Aucun port n'étant publié, les instances n'entrent jamais en conflit sur l'hôte.

Côté services externes :

- créer un second tunnel dans Cloudflare Zero Trust, avec sa route publiée vers `http://app:8080` ;
- déclarer la nouvelle URL de redirection dans la console développeur Twitch, en complément de la première ou dans une application Twitch dédiée.

Démarrer ensuite l'instance depuis sa propre copie du dépôt :

```bash
docker compose build --pull
docker compose up -d
docker compose exec app php bin/migrate
```

Points de contrôle :

- `docker compose ls` liste les deux projets ;
- `docker volume ls` montre deux jeux de volumes distincts, préfixés par le nom de chaque projet ;
- chaque nom public répond sur `/health`.

Le script `bin/update` s'utilise tel quel dans chaque copie : il se place dans son propre répertoire et met à jour la branche qui y est active.

Faire tourner deux instances double la consommation de mémoire et les écritures disque, chacune exécutant sa propre base MariaDB. Vérifier les ressources disponibles sur le Raspberry Pi et privilégier un stockage plus robuste que la carte SD.

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
