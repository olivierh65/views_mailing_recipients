<?php

namespace Drupal\views_mailing_recipients\Plugin\views\relationship;

use Civi\Api4\OptionValue;
use Drupal\views\Attribute\ViewsRelationship;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\relationship\RelationshipPluginBase;
use Drupal\views\Views;

/**
 * Relation Views : CiviCRM Mailing → Contacts destinataires.
 *
 * Suit le même pattern que CiviCrmActivityContact dans civicrm_entity,
 * mais avec une table intermédiaire supplémentaire (civicrm_activity)
 * pour relier civicrm_mailing à civicrm_activity_contact.
 *
 * Chaîne de JOINs construite dans query() :
 *   civicrm_mailing (id)
 *     → civicrm_activity (source_record_id, filtré sur activity_type_id = Bulk Email)
 *     → civicrm_activity_contact (activity_id, filtré sur record_type_id = 3 = Target)
 *     → civicrm_contact (id)
 *
 * @ingroup views_relationship_handlers
 *
 * @ViewsRelationship("mailing_recipients_relationship")
 */
#[ViewsRelationship("mailing_recipients_relationship")]
class MailingRecipientsRelationship extends RelationshipPluginBase {

  /**
   * L'ID du type d'activité "Bulk Email" dans CiviCRM.
   * Récupéré dynamiquement via l'API v4 et mis en cache statique.
   */
  protected ?int $bulkEmailActivityTypeId = NULL;

  /**
   * {@inheritdoc}
   *
   * Surcharge pour éviter le warning de RelationshipPluginBase::calculateDependencies()
   * qui cherche une entité Drupal sur la table cible — or les tables CiviCRM
   * intermédiaires ne sont pas des entités Views connues.
   */
  public function calculateDependencies() {
    return ['module' => ['views_mailing_recipients']];
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * Récupère l'activity_type_id de "Bulk Email" via l'API CiviCRM v4.
   * Utilise un cache statique pour éviter les appels répétés.
   */
  protected function getBulkEmailActivityTypeId(): int {
    if ($this->bulkEmailActivityTypeId !== NULL) {
      return $this->bulkEmailActivityTypeId;
    }

    try {
      $result = OptionValue::get(FALSE)
        ->addWhere('option_group_id:name', '=', 'activity_type')
        ->addWhere('name', '=', 'Bulk Email')
        ->addSelect('value')
        ->execute()
        ->first();

      $this->bulkEmailActivityTypeId = (int) ($result['value'] ?? 0);
    }
    catch (\Exception $e) {
      \Drupal::logger('views_mailing_recipients')
        ->error('Impossible de récupérer le type Bulk Email : @msg', [
          '@msg' => $e->getMessage(),
        ]);
      $this->bulkEmailActivityTypeId = 0;
    }

    return $this->bulkEmailActivityTypeId;
  }

  /**
   * {@inheritdoc}
   *
   * Construit la chaîne de 3 JOINs :
   *   civicrm_mailing → civicrm_activity → civicrm_activity_contact → civicrm_contact.
   *
   * On utilise addRelationship() pour chaque étape intermédiaire afin que
   * Views ne supprime pas les tables non directement référencées.
   */
  public function query() {
    $this->ensureMyTable();

    $activity_type_id = $this->getBulkEmailActivityTypeId();
    $join_type = !empty($this->options['required']) ? 'INNER' : 'LEFT';

    // ----------------------------------------------------------------
    // JOIN 1 : civicrm_mailing → civicrm_activity
    //   ON civicrm_activity.source_record_id = civicrm_mailing.id
    //   AND civicrm_activity.activity_type_id = <Bulk Email>
    // ----------------------------------------------------------------
    $join_activity = Views::pluginManager('join')->createInstance('standard', [
      'type'       => $join_type,
      'left_table' => $this->tableAlias,
      'left_field' => 'id',
      'table'      => 'civicrm_activity',
      'field'      => 'source_record_id',
      'adjusted'   => TRUE,
      'extra'      => [
        [
          'field'   => 'activity_type_id',
          'value'   => $activity_type_id,
          'numeric' => TRUE,
        ],
      ],
    ]);

    $activity_alias = $this->query->addRelationship(
      'civicrm_activity_mailing',
      $join_activity,
      'civicrm_activity',
      $this->relationship
    );

    // ----------------------------------------------------------------
    // JOIN 2 : civicrm_activity → civicrm_activity_contact
    //   ON civicrm_activity_contact.activity_id = civicrm_activity.id
    //   AND civicrm_activity_contact.record_type_id = 3 (Target)
    // ----------------------------------------------------------------
    $join_ac = Views::pluginManager('join')->createInstance('standard', [
      'type'       => $join_type,
      'left_table' => $activity_alias,
      'left_field' => 'id',
      'table'      => 'civicrm_activity_contact',
      'field'      => 'activity_id',
      'adjusted'   => TRUE,
      'extra'      => [
        [
          'field'   => 'record_type_id',
          'value'   => 3,
          'numeric' => TRUE,
        ],
      ],
    ]);

    $ac_alias = $this->query->addRelationship(
      'civicrm_activity_contact_mailing',
      $join_ac,
      'civicrm_activity_contact',
      $activity_alias
    );

    // ----------------------------------------------------------------
    // JOIN 3 : civicrm_activity_contact → civicrm_contact
    //   ON civicrm_contact.id = civicrm_activity_contact.contact_id
    // ----------------------------------------------------------------
    $join_contact = Views::pluginManager('join')->createInstance('standard', [
      'type'       => $join_type,
      'left_table' => $ac_alias,
      'left_field' => 'contact_id',
      'table'      => 'civicrm_contact',
      'field'      => 'id',
      'adjusted'   => TRUE,
    ]);

    $this->alias = $this->query->addRelationship(
      'civicrm_contact_mailing_recipients',
      $join_contact,
      'civicrm_contact',
      $ac_alias
    );
  }

}
