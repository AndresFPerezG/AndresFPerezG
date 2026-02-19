<?php

declare(strict_types=1);

namespace Drupal\powerbi_public_embed\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Bloque configurable para incrustar un reporte público por slug.
 *
 * @Block(
 *   id = "powerbi_public_embed_block",
 *   admin_label = @Translation("Power BI public embed"),
 *   category = @Translation("Custom")
 * )
 */
final class PowerBiPublicEmbedBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'report_slug' => '',
      'height' => 640,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['report_slug'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Slug del reporte'),
      '#description' => $this->t('Debe existir en la allowlist de configuración del módulo.'),
      '#default_value' => $this->configuration['report_slug'],
      '#required' => TRUE,
    ];
    $form['height'] = [
      '#type' => 'number',
      '#title' => $this->t('Alto del contenedor (px)'),
      '#default_value' => (int) $this->configuration['height'],
      '#min' => 320,
      '#max' => 2000,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['report_slug'] = trim((string) $form_state->getValue('report_slug'));
    $this->configuration['height'] = (int) $form_state->getValue('height');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $slug = (string) $this->configuration['report_slug'];
    $height = max(320, (int) $this->configuration['height']);

    if ($slug === '') {
      return [
        '#markup' => $this->t('Configura el slug del reporte en el bloque Power BI.'),
      ];
    }

    $endpoint = Url::fromRoute('powerbi_public_embed.embed_config', ['slug' => $slug])->toString();

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['powerbi-public-embed'],
        'data-endpoint' => $endpoint,
        'data-slug' => $slug,
        'data-height' => (string) $height,
      ],
      '#attached' => [
        'library' => [
          'powerbi_public_embed/powerbi_client_cdn',
          'powerbi_public_embed/powerbi_public_embed',
        ],
      ],
    ];
  }

}
