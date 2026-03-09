# Views Mailing Recipients

Module Drupal qui expose les **contacts destinataires d'un mailing CiviCRM** comme une relation Views.

## Prérequis

- Drupal 10+
- Module `civicrm_entity` activé
- CiviCRM avec le module CiviMail

## Installation

Copier le dossier `views_mailing_recipients` dans `modules/custom/` puis activer :

```bash
drush en views_mailing_recipients -y
drush cr
```

## Utilisation dans Views

1. **Créer une vue** avec comme base : `CiviCRM Mailings`
2. **Ajouter une relation** : `Contacts destinataires`
   - Cocher "Require this relationship" si tu veux uniquement les mailings ayant des destinataires
3. **Ajouter des champs** depuis `CiviCRM Contact` **en sélectionnant la relation** :
   - Nom (`display_name`)
   - Email (`email_primary`)
   - Téléphone, adresse, etc.
4. **Ajouter des filtres** sur les champs Contact si besoin
5. **Ajouter un argument** sur `civicrm_mailing.id` pour filtrer par mailing_id

## Fonctionnement technique

La relation construit une chaîne de 3 JOINs SQL :

```
civicrm_mailing (id)
  → civicrm_activity (source_record_id, activity_type_id = Bulk Email)
  → civicrm_activity_contact (activity_id, record_type_id = 3 = Target)
  → civicrm_contact (id)
```

L'`activity_type_id` de "Bulk Email" est récupéré dynamiquement via l'API CiviCRM v4
et mis en cache statique pour éviter les requêtes répétées.

## Inspiration

Basé sur le plugin `CiviCrmActivityContact` du module `civicrm_entity`.
