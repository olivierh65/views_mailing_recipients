<?php

namespace Drupal\views_mailing_recipients\Plugin\views\relationship;

use Drupal\views\Attribute\ViewsRelationship;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\Plugin\views\relationship\RelationshipPluginBase;
use Drupal\views\Views;

/**
 * Relation Views : CiviCRM Contact -> Mailing.
 *
 * Permets, depuis une vue listant des CiviCRM Contacts, de ne conserver
 * que ceux presents dans les destinataires d'un mailing donne.
 *
 * Chaine de JOINs construite dans query() :
 *   civicrm_contact (id)
 *     -> civicrm_mailing_event_queue (contact_id)
 *     -> civicrm_mailing_job        (id = event_queue.job_id)
 *     -> civicrm_mailing            (id = job.mailing_id)
 *
 * Une fois la relation ajoutee dans la vue, les filtres standards sur
 * civicrm_mailing_job.id et civicrm_mailing.id permettent de restreindre
 * par job_id et mailing_id.
 *
 * @ingroup views_relationship_handlers
 */
#[ViewsRelationship("contact_to_mailing_relationship")]
class ContactToMailingRelationship extends RelationshipPluginBase {

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return ['module' => ['views_mailing_recipients']];
  }

  /**
   * {@inheritdoc}
   *
   * Chaine : civicrm_contact -> event_queue -> mailing_job -> civicrm_mailing.
   */
  public function query() {
    $this->ensureMyTable();

    if (!$this->query instanceof Sql) {
      return;
    }

    $join_type = !empty($this->options['required']) ? 'INNER' : 'LEFT';

    // JOIN 1 : civicrm_contact.id = civicrm_mailing_event_queue.contact_id.
    $join_queue = Views::pluginManager('join')->createInstance('standard', [
      'type'       => $join_type,
      'left_table' => $this->tableAlias,
      'left_field' => 'id',
      'table'      => 'civicrm_mailing_event_queue',
      'field'      => 'contact_id',
      'adjusted'   => TRUE,
    ]);

    $queue_alias = $this->query->addRelationship(
      'civicrm_mailing_event_queue_contact',
      $join_queue,
      'civicrm_mailing_event_queue',
      $this->relationship
    );

    // JOIN 2 : civicrm_mailing_event_queue.job_id = civicrm_mailing_job.id.
    $join_job = Views::pluginManager('join')->createInstance('standard', [
      'type'       => $join_type,
      'left_table' => $queue_alias,
      'left_field' => 'job_id',
      'table'      => 'civicrm_mailing_job',
      'field'      => 'id',
      'adjusted'   => TRUE,
    ]);

    $job_alias = $this->query->addRelationship(
      'civicrm_mailing_job_contact',
      $join_job,
      'civicrm_mailing_job',
      $queue_alias
    );

    // JOIN 3 : civicrm_mailing_job.mailing_id = civicrm_mailing.id.
    $join_mailing = Views::pluginManager('join')->createInstance('standard', [
      'type'       => $join_type,
      'left_table' => $job_alias,
      'left_field' => 'mailing_id',
      'table'      => 'civicrm_mailing',
      'field'      => 'id',
      'adjusted'   => TRUE,
    ]);

    $this->alias = $this->query->addRelationship(
      'civicrm_mailing_from_contact',
      $join_mailing,
      'civicrm_mailing',
      $job_alias
    );
  }

}
